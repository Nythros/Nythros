<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Mmorpg;

use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Quest\QuestChain;
use PHPUnit\Framework\TestCase;

/**
 * MmorpgConfigTest - mmorpg 类型模块配置解析测试（R4 试点）：缺省配置钉值、构造期不变量断言
 * （aggroRange/respawnMs/spawnDensity 必须为正、threatDecayPerSec/tauntMultiplier/maxThreat 必须非负）、
 * 自定义配置只读聚合与任务链配置解析。
 * MmorpgConfigTest - the mmorpg type-module config parsing tests (the R4 pilot): default-config value pins,
 * construction-time invariant assertions (aggroRange/respawnMs/spawnDensity must be positive,
 * threatDecayPerSec/tauntMultiplier/maxThreat must be non-negative), custom readonly aggregates and quest-chain
 * config parsing.
 */
final class MmorpgConfigTest extends TestCase
{
    public function testDefaultConfigPinsValues(): void
    {
        $config = MmorpgConfig::default();

        // 威胁参数组：aggroRange 10（与 MonsterActor 缺省巡逻半径同量级）、不衰减、嘲讽倍率 1.0、无上限
        // Threat group: aggroRange 10 (same magnitude as MonsterActor's default patrol radius), no decay,
        // taunt multiplier 1.0, no cap
        self::assertSame(10, $config->aggroRange);
        self::assertSame(0.0, $config->threatDecayPerSec);
        self::assertSame(1.0, $config->tauntMultiplier);
        self::assertSame(0, $config->maxThreat);

        // 重生参数组：5s 重生延迟、密度 1
        // Respawn group: 5s respawn delay, density 1
        self::assertSame(5000, $config->respawnMs);
        self::assertSame(1, $config->spawnDensity);

        // 任务链配置：缺省无任务链
        // Quest-chain config: no chains by default
        self::assertSame([], $config->questChains);
    }

    public function testCustomConfigAggregatesOverrideDefaults(): void
    {
        $config = new MmorpgConfig(
            aggroRange: 20,
            threatDecayPerSec: 2.5,
            tauntMultiplier: 3.0,
            maxThreat: 100,
            respawnMs: 3000,
            spawnDensity: 2,
            questChains: [new QuestChain('main-line', ['kill_wolves', 'collect_bones', 'talk_elder'])],
        );

        self::assertSame(20, $config->aggroRange);
        self::assertSame(2.5, $config->threatDecayPerSec);
        self::assertSame(3.0, $config->tauntMultiplier);
        self::assertSame(100, $config->maxThreat);
        self::assertSame(3000, $config->respawnMs);
        self::assertSame(2, $config->spawnDensity);
        self::assertCount(1, $config->questChains);
        self::assertSame('main-line', $config->questChains[0]->id);
        self::assertSame(['kill_wolves', 'collect_bones', 'talk_elder'], $config->questChains[0]->questIds);
    }

    /**
     * 构造期不变量：aggroRange/respawnMs/spawnDensity 必须为正。
     * Construction invariants: aggroRange/respawnMs/spawnDensity must be positive.
     */
    public function testRejectsNonPositiveAggroRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('aggroRange 必须为正');
        new MmorpgConfig(aggroRange: 0);
    }

    public function testRejectsNonPositiveRespawnMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('respawnMs 必须为正');
        new MmorpgConfig(respawnMs: 0);
    }

    public function testRejectsNonPositiveSpawnDensity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('spawnDensity 必须为正');
        new MmorpgConfig(spawnDensity: 0);
    }

    /**
     * 构造期不变量：threatDecayPerSec/tauntMultiplier/maxThreat 必须非负。
     * Construction invariants: threatDecayPerSec/tauntMultiplier/maxThreat must be non-negative.
     */
    public function testRejectsNegativeThreatDecay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('threatDecayPerSec 必须非负');
        new MmorpgConfig(threatDecayPerSec: -1.0);
    }

    public function testRejectsNegativeTauntMultiplier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tauntMultiplier 必须非负');
        new MmorpgConfig(tauntMultiplier: -0.5);
    }

    public function testRejectsNegativeMaxThreat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxThreat 必须非负');
        new MmorpgConfig(maxThreat: -1);
    }

    /**
     * 任务链配置边界：空任务链在构造期拒绝（装配期 fail-fast）。
     * Quest-chain boundary: an empty chain is rejected at construction (fail-fast at assembly time).
     */
    public function testRejectsEmptyQuestChain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('任务链至少需要一个任务');
        new QuestChain('empty-line', []);
    }
}
