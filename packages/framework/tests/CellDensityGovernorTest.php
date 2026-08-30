<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Game\Mmorpg\CellDensityGovernor;
use Nythros\Framework\Game\Mmorpg\HotCellPolicy;
use PHPUnit\Framework\TestCase;

/**
 * P9a 区域降频单测：HotCellPolicy 校验、CellDensityGovernor 档位推导（升温即时/降温滞回）、
 * 邻接格外扩取最热档。
 * The P9a region-downgrade unit tests: HotCellPolicy validation, CellDensityGovernor tier derivation
 * (immediate promotion / hysteresised demotion) and the neighbor-radius hottest-tier lookup.
 */
final class CellDensityGovernorTest extends TestCase
{
    /** 无界兜底档缺失 → fail-fast。 A missing unbounded backstop → fail-fast. */
    public function testPolicyRequiresBackstop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HotCellPolicy(tiers: [['untilPlayers' => 10, 'divisor' => 2]]);
    }

    /** divisor 非降序校验（密度越高频率必须越低）。 Divisor monotonicity (higher density must mean a lower rate). */
    public function testPolicyRejectsNonMonotonicDivisors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HotCellPolicy(tiers: [['untilPlayers' => 10, 'divisor' => 4], ['untilPlayers' => 20, 'divisor' => 2], ['untilPlayers' => 0, 'divisor' => 4]]);
    }

    /** untilPlayers 严格递增校验。 Strictly ascending density bounds. */
    public function testPolicyRejectsNonAscendingBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HotCellPolicy(tiers: [['untilPlayers' => 20, 'divisor' => 2], ['untilPlayers' => 20, 'divisor' => 4], ['untilPlayers' => 0, 'divisor' => 4]]);
    }

    /** 兜底档只能位于末位。 The backstop must be the last tier. */
    public function testPolicyRejectsMidTableBackstop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HotCellPolicy(tiers: [['untilPlayers' => 0, 'divisor' => 2], ['untilPlayers' => 10, 'divisor' => 4]]);
    }

    /**
     * 档位推导主路径：密度跨阈升温即时、回落后滞回才降温；空格子密度归零。
     * The main tier-derivation path: crossing the threshold promotes immediately, demotion waits out the
     * hysteresis after the crowd leaves, and empty cells drop to zero density.
     */
    public function testPromoteImmediateAndDemoteHysteresised(): void
    {
        $now = 1000.0;
        $governor = new CellDensityGovernor(
            10,
            new HotCellPolicy(tiers: [['untilPlayers' => 1, 'divisor' => 1], ['untilPlayers' => 0, 'divisor' => 4]], hysteresisSeconds: 5),
            static function () use (&$now): float {
                return $now;
            },
        );

        // 3 人同格（cell(0,0)）→ 兜底档 divisor 4（升温即时）
        // Three players in cell(0,0) → the backstop divisor 4 (immediate promotion).
        $governor->sample([['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2], ['x' => 3, 'y' => 3]]);
        self::assertSame(4, $governor->divisorFor(1, 1), '升温即时：密度超阈立即分频');

        // 人散（同格无人）→ 滞回期内保持热档
        // The crowd disperses (the cell empties) → the hot tier holds through the hysteresis.
        $now += 2.0;
        $governor->sample([['x' => 50, 'y' => 50]]);
        self::assertSame(4, $governor->divisorFor(1, 1), '滞回期内热档保持');

        // 滞回期满 → 降档回逐帧
        // The hysteresis elapses → back to per-frame.
        $now += 3.1;
        $governor->sample([['x' => 50, 'y' => 50]]);
        self::assertSame(1, $governor->divisorFor(1, 1), '滞回期满降档回逐帧');

        // 新聚集点（cell(5,5)）不受旧热区影响（区域独立）
        // A fresh gathering spot (cell(5,5)) is unaffected by the old hot zone (regions are independent).
        $governor->sample([['x' => 55, 'y' => 55], ['x' => 55, 'y' => 55], ['x' => 55, 'y' => 55]]);
        self::assertSame(4, $governor->divisorFor(55, 55), '新聚集点独立升温');
        self::assertSame(1, $governor->divisorFor(1, 1), '旧热区已回温');
    }

    /**
     * 邻接格外扩：自身格冷但邻格热 → 取最热档（格界梯度平滑）。
     * The neighbor-radius expansion: the own cell is cold but a neighbor is hot → the hottest tier
     * (a smoothed boundary gradient).
     */
    public function testNeighborRadiusTakesHottestTier(): void
    {
        $now = 1000.0;
        $governor = new CellDensityGovernor(
            10,
            new HotCellPolicy(tiers: [['untilPlayers' => 1, 'divisor' => 1], ['untilPlayers' => 0, 'divisor' => 4]], hysteresisSeconds: 5, neighborRadius: 1),
            static function () use (&$now): float {
                return $now;
            },
        );

        // 热点在 cell(1,1)，实体在 cell(2,1)（邻接格）
        // The hotspot in cell(1,1), the entity in cell(2,1) (an adjacent cell).
        $governor->sample([['x' => 15, 'y' => 15], ['x' => 15, 'y' => 15], ['x' => 15, 'y' => 15]]);
        self::assertSame(4, $governor->divisorFor(25, 15), '邻接格取最热档');
        self::assertSame(1, $governor->divisorFor(45, 15), '半径外不受影响');
    }
}
