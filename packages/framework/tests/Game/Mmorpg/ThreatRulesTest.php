<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Mmorpg;

use Nythros\Framework\Game\Mmorpg\ThreatRules;
use PHPUnit\Framework\TestCase;

/**
 * ThreatRulesTest - 威胁/仇恨规则纯函数表驱动测试（R4 试点）：aggro 选择（最高威胁者/空表/同威胁平局）、
 * 衰减计算（线性衰减/衰减到零钳制）、嘲讽倍率应用与威胁上限钳制。
 * ThreatRulesTest - the threat/hate rules pure-function table-driven tests (the R4 pilot): aggro selection
 * (highest threat / empty table / equal-threat tie), decay computation (linear decay / zero clamp), taunt-multiplier
 * application and the threat-cap clamp.
 */
final class ThreatRulesTest extends TestCase
{
    public function testSelectTargetPicksHighestThreat(): void
    {
        $rules = new ThreatRules();

        self::assertSame('b', $rules->selectTarget(['a' => 10.0, 'b' => 30.0, 'c' => 20.0]), '最高威胁者胜出 the highest threat wins');
        self::assertSame('a', $rules->selectTarget(['a' => 10.0, 'b' => 5.0]), '单侧领先 the one-sided lead');
    }

    public function testSelectTargetEmptyTableReturnsNull(): void
    {
        $rules = new ThreatRules();

        self::assertNull($rules->selectTarget([]), '空表无目标 an empty table has no target');
    }

    public function testSelectTargetTieGoesToEarlierRecorded(): void
    {
        $rules = new ThreatRules();

        // 平局取先记录者（数组保持插入顺序）
        // A tie goes to the earlier-recorded actor (arrays keep insertion order)
        self::assertSame('a', $rules->selectTarget(['a' => 10.0, 'b' => 10.0]));
        self::assertSame('b', $rules->selectTarget(['b' => 10.0, 'a' => 10.0]));
    }

    public function testDecaySubtractsPerSecond(): void
    {
        $rules = new ThreatRules(threatDecayPerSec: 2.0);

        self::assertSame(6.0, $rules->decay(10.0, 2.0), 'threat - decay × dt = 10 - 2×2 = 6');
        self::assertSame(9.5, $rules->decay(10.0, 0.25), 'threat - decay × dt = 10 - 2×0.25 = 9.5');
    }

    public function testDecayClampsToZero(): void
    {
        $rules = new ThreatRules(threatDecayPerSec: 5.0);

        self::assertSame(0.0, $rules->decay(10.0, 3.0), '衰减越过零钳制到 0 decay past zero clamps to 0');
        self::assertSame(0.0, $rules->decay(10.0, 2.0), '恰好归零 exactly zero');
    }

    public function testDecayWithoutRateKeepsThreat(): void
    {
        $rules = new ThreatRules(threatDecayPerSec: 0.0);

        self::assertSame(10.0, $rules->decay(10.0, 60.0), '不衰减配置下威胁保持不变 no decay keeps the threat');
    }

    public function testApplyTauntMultiplies(): void
    {
        $rules = new ThreatRules(tauntMultiplier: 3.0);

        self::assertSame(30.0, $rules->applyTaunt(10.0), '嘲讽倍率 3×：10 → 30');
        self::assertSame(10.0, (new ThreatRules())->applyTaunt(10.0), '缺省倍率 1.0 原样');
    }

    public function testCapThreatClampsToMax(): void
    {
        $rules = new ThreatRules(maxThreat: 100);

        self::assertSame(100.0, $rules->capThreat(150.0), '超上限钳制到上限');
        self::assertSame(80.0, $rules->capThreat(80.0), '上限内原样');
        self::assertSame(150.0, (new ThreatRules())->capThreat(150.0), 'maxThreat=0 无上限');
    }

    public function testInAggroRange(): void
    {
        $rules = new ThreatRules(aggroRange: 10);

        self::assertTrue($rules->inAggroRange(10.0), '边界距离在范围内 boundary distance is in range');
        self::assertTrue($rules->inAggroRange(5.0));
        self::assertFalse($rules->inAggroRange(10.5), '超范围不在仇恨列表 beyond range is outside the hate list');
    }
}
