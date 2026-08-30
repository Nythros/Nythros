<?php

declare(strict_types=1);

namespace Nythros\Aoi\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\EntityInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use PHPUnit\Framework\TestCase;

/**
 * GridAOICorrectnessTest - 规格正确性测试：9×9 格 100 实体固定种子随机移动，与独立朴素参照实现逐帧交叉验证，
 * 任何 query 集合或 entered/left 差分的差异都直接判失败（AOI 错误广播为 0 的门禁证据之一）。
 * Specification correctness test: 100 entities across a 9×9 cell field with fixed-seed random movement, cross-verified frame by frame
 * against an independent naive reference implementation; any mismatch in query sets or entered/left deltas fails the test
 * (one piece of evidence for the "zero AOI wrong-broadcast" gate).
 *
 * 参照实现与 GridAOI 的格子边界语义完全一致：双方都用数学 floor（PHP floor() 向 -∞ 取整，负数坐标同样朝 -∞）
 * 计算 cellKey，九宫格 = 当前格 ±1；entered = 新邻居集 − 旧邻居集、left = 旧邻居集 − 新邻居集（均按实体 id 判等、排除自身）。
 * The reference implementation matches GridAOI's cell-boundary semantics exactly: both compute cellKey with mathematical floor
 * (PHP floor() rounds toward -∞, negative coordinates included), the 3x3 neighborhood is the current cell ±1; entered = new neighbors − old
 * neighbors and left = old neighbors − new neighbors (equality by entity id, self excluded).
 */
final class GridAOICorrectnessTest extends TestCase
{
    /**
     * 格子边长，与 GridAOI 构造参数一致。
     * Cell side length, matching the GridAOI constructor argument.
     */
    private const CELL_SIZE = 10;

    public function testRandomMovementMatchesNaiveNineGridReference(): void
    {
        // 固定种子保证测试完全可重复；同一次运行内所有随机选择（实体抽取与 dx/dy）都走同一 Mersenne Twister 序列
        // The fixed seed makes the test fully reproducible; within one run every random choice (entity picks and dx/dy) comes from one Mersenne Twister sequence
        mt_srand(42);

        $aoi = new GridAOI(self::CELL_SIZE);

        /** @var list<BaseEntity> $entities 100 个被测实体 The 100 entities under test. */
        $entities = [];
        /** @var array<string, array<string, true>> $naiveCells 朴素索引：cellKey 映射到格子内实体 id 表 Naive index: cellKey maps to the cell's entity-id table. */
        $naiveCells = [];

        // 初始登记：100 个实体铺满 9×9 格（cx, cy ∈ {-4..4}，共 81 格，编号循环分配保证每格至少 1 个实体）
        // Initial registration: 100 entities spread over the 9×9 field (cx, cy ∈ {-4..4}, 81 cells; ids cycle so every cell holds at least one entity)
        for ($i = 0; $i < 100; $i++) {
            $cx = $i % 9 - 4;
            $cy = intdiv($i, 9) % 9 - 4;
            $entity = new BaseEntity(
                'e' . $i,
                new Position($cx * self::CELL_SIZE + 5, $cy * self::CELL_SIZE + 5),
            );
            $entities[] = $entity;

            $id = $entity->getId();
            $newKey = $this->naiveCellKey($cx * self::CELL_SIZE + 5, $cy * self::CELL_SIZE + 5);

            // 首次登记：旧邻居集为空，期望 entered = 登记后朴素索引中该实体九宫格内的既有实体（排除自身）
            // First registration: the old neighbor set is empty, so entered should equal the entities already in the naive index within the 3x3 neighborhood (self excluded)
            $expectedEntered = $this->naiveNeighborIds($newKey, $id, $naiveCells);

            $actual = $aoi->updateEntity($entity);
            $naiveCells[$newKey][$id] = true;

            $this->assertDelta($actual, $expectedEntered, [], 'register ' . $id);
        }

        // 1000 帧随机移动：每帧随机抽 20 个实体按 (-15..15, -15..15) 步进移动，逐帧交叉验证 diff 与 query
        // 1000 frames of random movement: each frame picks 20 entities at random and moves them by (-15..15, -15..15) steps, cross-verifying diff and query frame by frame
        for ($frame = 0; $frame < 1000; $frame++) {
            /** @var array<int, int> $chosenKeys 本帧被选中的实体下标（array_rand 走 mt 全局序列，互不重复） This frame's chosen entity indexes (array_rand uses the global mt sequence, no duplicates). */
            $chosenKeys = array_rand($entities, 20);

            foreach ($chosenKeys as $key) {
                $entity = $entities[$key];
                $id = $entity->getId();
                ['x' => $x, 'y' => $y] = $entity->getPosition();
                $oldKey = $this->naiveCellKey($x, $y);
                $oldNeighbors = $this->naiveNeighborIds($oldKey, $id, $naiveCells);

                $entity->move(mt_rand(-15, 15), mt_rand(-15, 15));

                ['x' => $newX, 'y' => $newY] = $entity->getPosition();
                $newKey = $this->naiveCellKey($newX, $newY);

                // 朴素索引同步迁移：跨格才搬，同格保持原位（与 GridAOI 的 fast path 语义对应）
                // Naive index migration: move only across cells; same-cell moves stay put (mirroring GridAOI's fast path semantics)
                if ($newKey !== $oldKey) {
                    unset($naiveCells[$oldKey][$id]);
                    $naiveCells[$newKey][$id] = true;
                }

                // 期望差分：新邻居集（朴素索引已更新、排除自身）与旧邻居集的集合差
                // Expected delta: set differences between the new neighbor set (naive index already updated, self excluded) and the old neighbor set
                $newNeighbors = $this->naiveNeighborIds($newKey, $id, $naiveCells);
                $expectedEntered = array_values(array_diff($newNeighbors, $oldNeighbors));
                $expectedLeft = array_values(array_diff($oldNeighbors, $newNeighbors));

                $actual = $aoi->updateEntity($entity);
                $this->assertDelta($actual, $expectedEntered, $expectedLeft, sprintf('frame %d entity %s', $frame, $id));
            }

            // 每帧移动结束后全量交叉验证 query：100 个实体各自的九宫格集合（含自身）与朴素参照逐一比对
            // Full query cross-check after each frame's moves: every entity's 3x3 set (self included) is compared one by one against the naive reference
            foreach ($entities as $entity) {
                ['x' => $qx, 'y' => $qy] = $entity->getPosition();
                $expectedIds = $this->naiveNeighborIds($this->naiveCellKey($qx, $qy), null, $naiveCells);

                $actualIds = array_map(
                    static fn (EntityInterface $neighbor): string => $neighbor->getId(),
                    $aoi->query($entity),
                );

                sort($expectedIds);
                sort($actualIds);

                self::assertSame(
                    $expectedIds,
                    $actualIds,
                    sprintf('query mismatch at frame %d entity %s', $frame, $entity->getId()),
                );
            }
        }
    }

    /**
     * 朴素格子 key：与 GridAOI::cellKey 完全一致的 floor 语义（负数坐标向 -∞ 取整）。
     * Naive cell key: floor semantics identical to GridAOI::cellKey (negative coordinates round toward -∞).
     *
     * @return string 形如 "cx:cy" 的格子 key Cell key in the form "cx:cy".
     */
    private function naiveCellKey(int $x, int $y): string
    {
        return ((int) floor($x / self::CELL_SIZE)) . ':' . ((int) floor($y / self::CELL_SIZE));
    }

    /**
     * 朴素九宫格查询：枚举中心格 ±1 共 9 格内的全部实体 id；可选排除指定 id（diff 计算时排除自身、query 校验时不排除）。
     * Naive 3x3 query: collects all entity ids across the center cell's ±1 neighborhood (9 cells); optionally excludes one id
     * (self is excluded for delta computation but kept for query verification).
     *
     * @param array<string, array<string, true>> $naiveCells 朴素格子索引 Naive cell index.
     *
     * @return list<string> 九宫格实体 id 列表（无序） Entity ids in the 3x3 neighborhood (unordered).
     */
    private function naiveNeighborIds(string $cellKey, ?string $excludeId, array $naiveCells): array
    {
        [$cx, $cy] = array_map('intval', explode(':', $cellKey));

        $ids = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                $key = ($cx + $dx) . ':' . ($cy + $dy);
                foreach ($naiveCells[$key] ?? [] as $id => $unused) {
                    $ids[$id] = true;
                }
            }
        }

        if ($excludeId !== null) {
            unset($ids[$excludeId]);
        }

        return array_keys($ids);
    }

    /**
     * 断言 GridAOI 的差分与期望一致：按 id 排序后比对，任意不一致立即失败并携带上下文。
     * Asserts GridAOI's delta against expectations: compared as sorted id lists; any mismatch fails immediately with context.
     *
     * @param array{entered: list<EntityInterface>, left: list<EntityInterface>} $actual GridAOI 返回的差分 The delta returned by GridAOI.
     * @param list<string> $expectedEnteredIds 期望 entered 的实体 id Expected entered entity ids.
     * @param list<string> $expectedLeftIds 期望 left 的实体 id Expected left entity ids.
     * @param string $context 失败信息上下文（帧号 / 实体 id） Failure message context (frame / entity id).
     */
    private function assertDelta(array $actual, array $expectedEnteredIds, array $expectedLeftIds, string $context): void
    {
        $actualEntered = array_map(
            static fn (EntityInterface $entity): string => $entity->getId(),
            $actual['entered'],
        );
        $actualLeft = array_map(
            static fn (EntityInterface $entity): string => $entity->getId(),
            $actual['left'],
        );

        sort($expectedEnteredIds);
        sort($expectedLeftIds);
        sort($actualEntered);
        sort($actualLeft);

        self::assertSame($expectedEnteredIds, $actualEntered, 'entered mismatch at ' . $context);
        self::assertSame($expectedLeftIds, $actualLeft, 'left mismatch at ' . $context);
    }
}
