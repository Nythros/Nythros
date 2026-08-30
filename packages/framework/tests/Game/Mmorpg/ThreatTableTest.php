<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Mmorpg;

use Nythros\Framework\Game\Mmorpg\ThreatRules;
use Nythros\Framework\Game\Mmorpg\ThreatTable;
use PHPUnit\Framework\TestCase;

/**
 * ThreatTableTest - 威胁表状态组件测试（R4 试点）：增删/衰减/选择/清空状态机——addThreat 累加与距离判定、
 * applyTaunt 嘲讽提升、decay 衰减到零自动摘除、topThreat/selectTarget 最高威胁者、remove/clear。
 * ThreatTableTest - the threat-table state-component tests (the R4 pilot): the add/remove/decay/select/clear state
 * machine — addThreat accumulation and the distance gate, applyTaunt taunt boost, decay auto-removing at zero,
 * topThreat/selectTarget highest threat, remove/clear.
 */
final class ThreatTableTest extends TestCase
{
    public function testAddThreatAccumulatesAndSelectsHighest(): void
    {
        $table = new ThreatTable(new ThreatRules());

        $table->addThreat('a', 10.0);
        $table->addThreat('b', 30.0);
        $table->addThreat('a', 5.0);

        self::assertSame(15.0, $table->threatOf('a'), '同 actor 威胁累加 same-actor threat accumulates');
        self::assertSame(30.0, $table->threatOf('b'));
        self::assertSame('b', $table->topThreat(), '最高威胁者胜出 the highest threat wins');
        self::assertSame('b', $table->selectTarget(), 'selectTarget 与 topThreat 同判据 selectTarget shares the topThreat criterion');
    }

    public function testAddThreatDistanceGateIgnoresBeyondAggroRange(): void
    {
        $table = new ThreatTable(new ThreatRules(aggroRange: 10));

        $table->addThreat('a', 10.0, 5.0);
        $table->addThreat('b', 10.0, 15.0);

        self::assertSame(10.0, $table->threatOf('a'), '范围内记威胁 in-range threat is recorded');
        self::assertSame(0.0, $table->threatOf('b'), '超 aggroRange 不记威胁 beyond aggroRange gains no threat');
        self::assertSame('a', $table->topThreat());
    }

    public function testAddThreatClampsToMaxThreat(): void
    {
        $table = new ThreatTable(new ThreatRules(maxThreat: 100));

        $table->addThreat('a', 80.0);
        $table->addThreat('a', 50.0);

        self::assertSame(100.0, $table->threatOf('a'), '累加超上限钳制到上限 accumulation past the cap clamps to it');
    }

    public function testAddThreatNegativeAmountNeverDrivesThreatNegative(): void
    {
        $table = new ThreatTable(new ThreatRules());

        $table->addThreat('a', 5.0);
        $table->addThreat('a', -10.0);

        self::assertSame(0.0, $table->threatOf('a'), '负值 amount 不把威胁压到负值 negative amount never drives the threat negative');
        self::assertSame([], $table->all(), '钳到零的 actor 与 decay 同口径摘除 actors clamped to zero are removed like decay');
        self::assertNull($table->topThreat(), '零威胁 actor 不出现在仇恨列表 zero-threat actors never appear in the hate list');
    }

    public function testApplyTauntRaisesToBoostedMagnitude(): void
    {
        $table = new ThreatTable(new ThreatRules(tauntMultiplier: 3.0));

        $table->addThreat('a', 10.0);
        $table->applyTaunt('a', 20.0);

        self::assertSame(60.0, $table->threatOf('a'), '嘲讽提升到 amount × 倍率（20 × 3 = 60）');
    }

    public function testApplyTauntNeverLowersThreat(): void
    {
        $table = new ThreatTable(new ThreatRules(tauntMultiplier: 2.0));

        $table->addThreat('a', 100.0);
        $table->applyTaunt('a', 10.0);

        self::assertSame(100.0, $table->threatOf('a'), '嘲讽取较大者，不压低现有威胁 taunt takes the larger, never lowering existing threat');
    }

    public function testDecayReducesThreatAndRemovesAtZero(): void
    {
        $table = new ThreatTable(new ThreatRules(threatDecayPerSec: 10.0));

        $table->addThreat('a', 20.0);
        $table->addThreat('b', 5.0);
        $table->decay(1.0);

        self::assertSame(10.0, $table->threatOf('a'), '20 - 10×1 = 10');
        self::assertSame(0.0, $table->threatOf('b'), '衰减到零的 actor 已摘除（查询回退 0）');
        self::assertSame(['a' => 10.0], $table->all(), '衰减到零的 actor 自动出仇恨列表');
        self::assertSame('a', $table->topThreat());
    }

    public function testDecayWithoutRateKeepsThreats(): void
    {
        $table = new ThreatTable(new ThreatRules(threatDecayPerSec: 0.0));

        $table->addThreat('a', 10.0);
        $table->decay(60.0);

        self::assertSame(10.0, $table->threatOf('a'), '不衰减配置下威胁保持不变');
    }

    public function testRemoveDropsActor(): void
    {
        $table = new ThreatTable(new ThreatRules());

        $table->addThreat('a', 10.0);
        $table->addThreat('b', 20.0);
        $table->remove('b');

        self::assertSame(0.0, $table->threatOf('b'));
        self::assertSame('a', $table->topThreat(), '摘除后最高威胁者回落');
    }

    public function testClearEmptiesTable(): void
    {
        $table = new ThreatTable(new ThreatRules());

        $table->addThreat('a', 10.0);
        $table->addThreat('b', 20.0);
        $table->clear();

        self::assertSame([], $table->all());
        self::assertNull($table->topThreat(), '清空后无目标');
        self::assertNull($table->selectTarget());
    }

    public function testEmptyTableHasNoTarget(): void
    {
        $table = new ThreatTable(new ThreatRules());

        self::assertNull($table->topThreat());
        self::assertNull($table->selectTarget());
    }
}
