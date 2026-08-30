<?php

declare(strict_types=1);

namespace Nythros\Aoi;

use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\ShapeInterface;

/**
 * 网格 AOI：按固定 cellSize 把世界切分为格子，实体登记到所在格子，查询返回九宫格（当前格 + 周围 8 格）内的实体，位置更新返回视野差分。
 * Grid-based AOI: partitions the world into fixed-size cells, registers each entity into its cell, queries return entities within the 3x3 neighborhood (current cell plus its 8 surrounding cells), and position updates return the visibility delta.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class GridAOI implements AOIProviderInterface
{
    /** queryShape 覆盖格数上限：bounds 换算出的格子数超过即拒绝——恶意超大形状（如 r=PHP_INT_MAX 的圆）会把双重循环拖成事件循环永久阻塞，引擎原语自防御，不依赖上层校验（R2 审查 MAJOR-1）。 queryShape's covered-cell cap: bounds-derived cell counts above it are rejected — a maliciously oversized shape (e.g. a circle with r=PHP_INT_MAX) would grind the double loop into a permanent event-loop stall; the engine primitive defends itself instead of relying on caller validation (R2 review MAJOR-1). */
    private const MAX_QUERY_SHAPE_CELLS = 4096;

    /** @var array<string, array<string, EntityInterface>> 格子索引：格子 key（"cx:cy"）映射到格子内以实体 id 为键的实体表 Cell index: cell key ("cx:cy") maps to a table of entities keyed by entity id. */
    private array $cells = [];

    /** @var array<string, string> 反查索引：实体 id 映射到其所在格子 key，使移除与更新免于全表扫描 Reverse index: entity id maps to its cell key, so removal and updates avoid full-table scans. */
    private array $entityCells = [];

    /**
     * 构造网格 AOI。
     * Creates a grid AOI.
     *
     * @param int $cellSize 单个格子的边长（世界单位） Side length of a single cell (world units).
     */
    public function __construct(
        private readonly int $cellSize,
    ) {
    }

    /**
     * 登记或更新实体位置：对比新旧格子，返回视野变化差分；同格移动走 fast path 直接返回空差分。
     * Registers or updates an entity's position: compares old and new cells and returns the visibility delta; same-cell moves take a fast path that returns an empty delta.
     *
     * @param EntityInterface $entity 要登记的实体 The entity to register.
     * @return array{entered: list<EntityInterface>, left: list<EntityInterface>} entered 为进入视野的邻居实体（排除自身）、left 为离开视野的邻居实体（排除自身） entered holds neighbors that entered visibility (self excluded), left holds neighbors that left visibility (self excluded).
     */
    public function updateEntity(EntityInterface $entity): array
    {
        $id = $entity->getId();
        $newKey = $this->cellKey($entity);
        $oldKey = $this->entityCells[$id] ?? null;

        // 同格 fast path：格子未变则九宫格与视野均不变，跳过差分计算 same-cell fast path: if the cell is unchanged, the neighborhood and visibility are unchanged, so delta computation is skipped
        if ($oldKey === $newKey) {
            return ['entered' => [], 'left' => []];
        }

        // 先基于旧格子收集旧邻居集（排除自身），新登记（$oldKey 为 null）时旧邻居为空集 collect old neighbors from the old cell first (self excluded); a fresh registration ($oldKey is null) has an empty old neighbor set
        $oldNeighbors = $oldKey === null ? [] : $this->neighborhood($oldKey, $id);

        // 迁移实体记录：从旧格移除、写入新格，并同步反查索引 migrate the entity's record: remove from the old cell, insert into the new cell, and sync the reverse index
        if ($oldKey !== null) {
            unset($this->cells[$oldKey][$id]);
        }
        $this->cells[$newKey][$id] = $entity;
        $this->entityCells[$id] = $newKey;

        // 基于新格子收集新邻居集（排除自身），再做集合差得到 entered / left collect new neighbors from the new cell (self excluded), then diff the sets to derive entered / left
        $newNeighbors = $this->neighborhood($newKey, $id);

        return [
            'entered' => $this->difference($newNeighbors, $oldNeighbors),
            'left' => $this->difference($oldNeighbors, $newNeighbors),
        ];
    }

    /**
     * 查询实体 AOI 范围内的可见实体：返回九宫格（当前格 + 周围 8 格）内全部实体（含自身），按实体 id 去重。
     * Queries entities visible within the entity's AOI: returns all entities in the 3x3 neighborhood (current cell plus its 8 surrounding cells), including itself, deduplicated by entity id.
     *
     * @param EntityInterface $entity 查询中心实体 The query center entity.
     * @return list<EntityInterface> 九宫格实体列表 List of entities in the 3x3 neighborhood.
     */
    public function query(EntityInterface $entity): array
    {
        return $this->neighborhood($this->cellKey($entity));
    }

    /**
     * 形状查询：先以 bounds() 求覆盖格集合粗筛（杜绝全房间扫描），再对候选实体逐点 contains 精判；
     * 按实体 id 去重、含自身若在内；只读，不触碰索引与 moved 标记。
     * 覆盖格数超过 MAX_QUERY_SHAPE_CELLS 时抛 InvalidArgumentException（防超大形状阻塞事件循环）。
     * Shape query: first derives the covered-cell set from bounds() as the coarse filter (never a whole-room
     * scan), then applies per-entity contains precision checks; deduplicated by entity id, self included if
     * inside; read-only — touches neither the index nor moved flags. Throws InvalidArgumentException when the
     * covered-cell count exceeds MAX_QUERY_SHAPE_CELLS (keeps an oversized shape from stalling the event loop).
     *
     * @return list<EntityInterface> 形状覆盖内实体列表 List of entities covered by the shape.
     *
     * @throws \InvalidArgumentException 形状覆盖格数超过上限 When the shape covers more cells than the cap.
     */
    public function queryShape(ShapeInterface $shape): array
    {
        ['minX' => $minX, 'minY' => $minY, 'maxX' => $maxX, 'maxY' => $maxY] = $shape->bounds();

        // 包围盒换算为格子索引范围（数学 floor，负坐标同样朝 -∞ 取整） convert the box to cell-index ranges (mathematical floor; negative coordinates floor toward -∞ too)
        $minCx = (int) floor($minX / $this->cellSize);
        $minCy = (int) floor($minY / $this->cellSize);
        $maxCx = (int) floor($maxX / $this->cellSize);
        $maxCy = (int) floor($maxY / $this->cellSize);

        // 覆盖格数阈值兜底：float 运算防 int 溢出（恶意大半径的格距可超 PHP_INT_MAX）
        // Covered-cell cap guard: float arithmetic avoids int overflow (a malicious huge radius can push the span past PHP_INT_MAX)
        $spanX = (float) $maxCx - (float) $minCx + 1.0;
        $spanY = (float) $maxCy - (float) $minCy + 1.0;
        if ($spanX * $spanY > self::MAX_QUERY_SHAPE_CELLS) {
            throw new \InvalidArgumentException(sprintf(
                'queryShape 形状覆盖格数 %d x %d 超过上限 %d，已拒绝执行 / shape covers %d x %d cells, exceeding the %d-cell cap',
                (int) $spanX,
                (int) $spanY,
                self::MAX_QUERY_SHAPE_CELLS,
                (int) $spanX,
                (int) $spanY,
                self::MAX_QUERY_SHAPE_CELLS,
            ));
        }

        $result = [];
        for ($cx = $minCx; $cx <= $maxCx; $cx++) {
            for ($cy = $minCy; $cy <= $maxCy; $cy++) {
                foreach ($this->cells[$cx . ':' . $cy] ?? [] as $entity) {
                    $id = $entity->getId();
                    // 以 id 为键去重（同一实体至多存于一格，此处防御归并重复） dedup by id (an entity lives in at most one cell; defensive against merge duplicates)
                    if (isset($result[$id])) {
                        continue;
                    }
                    ['x' => $x, 'y' => $y] = $entity->getPosition();
                    if ($shape->contains($x, $y)) {
                        $result[$id] = $entity;
                    }
                }
            }
        }

        return array_values($result);
    }

    /**
     * 从索引中移除实体：经反查索引 O(1) 定位格子并清除，从未登记过的实体会被静默忽略。
     * Removes an entity from the index: locates its cell in O(1) via the reverse index and clears it; entities that were never registered are silently ignored.
     *
     * @param EntityInterface $entity 要移除的实体 The entity to remove.
     */
    public function remove(EntityInterface $entity): void
    {
        $id = $entity->getId();
        // 反查索引拿不到 key 说明从未登记，直接返回 no key in the reverse index means the entity was never registered, so return directly
        $key = $this->entityCells[$id] ?? null;
        if ($key === null) {
            return;
        }

        unset($this->cells[$key][$id]);
        unset($this->entityCells[$id]);
    }

    /**
     * 计算实体所在格子的 key：坐标按 cellSize 向下取整得到格子索引，拼接为 "cx:cy"。
     * Computes the cell key for an entity: floors the coordinates by cellSize to get cell indices, joined as "cx:cy".
     *
     * @param EntityInterface $entity 目标实体 The target entity.
     * @return string 格子 key，形如 "cx:cy" Cell key in the form "cx:cy".
     */
    private function cellKey(EntityInterface $entity): string
    {
        ['x' => $x, 'y' => $y] = $entity->getPosition();

        // 数学 floor 向下取整划分格子：负坐标同样朝 -∞ 取整，保证格子边界在原点两侧一致 floor division maps coordinates to cells; negative coordinates floor toward -∞ as well, keeping cell boundaries consistent across the origin
        $cx = (int) floor($x / $this->cellSize);
        $cy = (int) floor($y / $this->cellSize);

        return $cx . ':' . $cy;
    }

    /**
     * 收集某个格子九宫格（cx-1..cx+1、cy-1..cy+1 共 9 格）内的全部实体，按实体 id 去重；可选排除指定实体。
     * Collects all entities in the 3x3 neighborhood of a cell (cx-1..cx+1, cy-1..cy+1, 9 cells total), deduplicated by entity id; optionally excludes a specific entity.
     *
     * @param string $cellKey 中心格子 key The center cell key.
     * @param string|null $excludeId 需要排除的实体 id（通常为自身），null 表示不排除 Entity id to exclude (usually self), or null to exclude nothing.
     * @return list<EntityInterface> 九宫格实体列表 List of entities in the 3x3 neighborhood.
     */
    private function neighborhood(string $cellKey, ?string $excludeId = null): array
    {
        [$cx, $cy] = array_map(
            static fn (string $part): int => (int) $part,
            explode(':', $cellKey),
        );

        $entities = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                $key = ($cx + $dx) . ':' . ($cy + $dy);
                foreach ($this->cells[$key] ?? [] as $entity) {
                    // 以实体 id 为键去重：同一实体最多存在于一个格子，但逐格归并时以 id 为键可天然防重 deduplicate by entity id: an entity lives in at most one cell, but keying by id while merging cells prevents duplicates naturally
                    $entities[$entity->getId()] = $entity;
                }
            }
        }

        if ($excludeId !== null) {
            unset($entities[$excludeId]);
        }

        return array_values($entities);
    }

    /**
     * 计算实体列表集合差（按实体 id 判定相等）：返回在 $from 中且不在 $exclude 中的实体，保持 $from 的原始顺序。
     * Computes the set difference of two entity lists (equality judged by entity id): returns entities present in $from but absent from $exclude, preserving $from's original order.
     *
     * @param list<EntityInterface> $from 被减集合 The minuend set.
     * @param list<EntityInterface> $exclude 减集合 The subtrahend set.
     * @return list<EntityInterface> 差集实体列表 List of entities in the difference set.
     */
    private function difference(array $from, array $exclude): array
    {
        $excludeIds = [];
        foreach ($exclude as $entity) {
            $excludeIds[$entity->getId()] = true;
        }

        $result = [];
        foreach ($from as $entity) {
            if (!isset($excludeIds[$entity->getId()])) {
                $result[] = $entity;
            }
        }

        return $result;
    }
}
