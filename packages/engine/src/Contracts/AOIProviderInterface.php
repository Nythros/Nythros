<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * AOI（兴趣区域）提供者契约：维护实体空间索引，支持位置更新、移除与近邻查询。
 * 热路径扩展点（architecture.md §5）：AOI 邻域/AoE 批命中替换点 = 本接口（尤其 queryShape），
 * 实现可整体替换（GridAOI / UniversalAOI 等），已接口化、无需新代码。
 * AOI (Area of Interest) provider contract: maintains a spatial index of entities, supporting position updates, removal and neighbor queries.
 * Hot-path extension point (architecture.md §5): the AOI neighborhood / AoE batch-hit swap point is this interface
 * (queryShape in particular); implementations are wholesale swappable (GridAOI / UniversalAOI, etc.) — already
 * interfaced, no new code required.
 */
interface AOIProviderInterface
{    /**
     * 登记或更新实体的空间位置，使其与 AOI 索引保持同步，并返回视野变化差分。
     * Register or update an entity's spatial position so it stays in sync with the AOI index, and return the visibility delta.
     *
     * @param EntityInterface $entity 目标实体 The target entity.
     * @return array{entered: list<EntityInterface>, left: list<EntityInterface>} entered 为进入视野的邻居实体（排除自身）、left 为离开视野的邻居实体（排除自身） entered holds neighbors that entered visibility (self excluded), left holds neighbors that left visibility (self excluded).
     */
    public function updateEntity(EntityInterface $entity): array;

    /**
     * 从 AOI 索引中移除实体；未登记的实体应被静默忽略。
     * Remove an entity from the AOI index; entities that are not registered should be silently ignored.
     *
     * @param EntityInterface $entity 目标实体 The target entity.
     */
    public function remove(EntityInterface $entity): void;

    /**
     * 查询目标实体 AOI 范围内的可见实体集合：九宫格（当前格 + 周围 8 格）；是否包含自身由实现约定，GridAOI 含自身。
     * Query the set of entities visible within the target entity's AOI: the 3x3 neighborhood (current cell plus its 8 surrounding cells); whether the entity itself is included is implementation-defined (GridAOI includes it).
     *
     * @param EntityInterface $entity 查询中心实体 The query center entity.
     * @return list<EntityInterface> 可见实体列表 List of visible entities.
     */
    public function query(EntityInterface $entity): array;

    /**
     * 形状查询（AoE 批量命中管线原语）：返回形状覆盖范围内的实体。
     * Shape query (the AoE batch-hit pipeline primitive): returns entities covered by the shape.
     *
     * 语义口径：含自身若在内；按实体 id 去重；顺序不保证；只读——不得污染索引或 moved 标记，
     * 与 updateEntity 的差分语义无耦合。实现约定：GridAOI 按 bounds 覆盖格粗筛 + contains 精判
     * （O(覆盖格实体) 非 O(全房间)），UniversalAOI 全表过滤。实现可对超大形状施加覆盖格数量上限，
     * 超限抛 InvalidArgumentException 拒绝（GridAOI 已实现该语义，UniversalAOI 全表过滤不抛）。
     * Semantic contract: self included if inside; deduplicated by entity id; no guaranteed order; read-only —
     * must not pollute the index or moved flags, and stays decoupled from updateEntity's delta semantics.
     * Implementation notes: GridAOI coarse-filters by bounds-covered cells then applies contains precisely
     * (O(entities in covered cells), not O(whole room)); UniversalAOI filters the full table. Implementations
     * may impose a covered-cell cap on oversized shapes and reject with InvalidArgumentException (GridAOI does;
     * UniversalAOI's full-table filter never throws).
     *
     * @param ShapeInterface $shape 查询形状 The query shape.
     * @return list<EntityInterface> 形状覆盖内实体列表 List of entities covered by the shape.
     * @throws \InvalidArgumentException 形状覆盖格数超过实现上限时（如 GridAOI） When the shape covers more cells than the implementation's cap (e.g. GridAOI).
     */
    public function queryShape(ShapeInterface $shape): array;
}
