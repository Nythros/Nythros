<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Horde;

use Nythros\Framework\Game\Horde\DropStormConfig;
use Nythros\Framework\Game\Horde\HordeConfig;
use Nythros\Framework\Game\Horde\SettlementRules;
use Nythros\Framework\Game\Horde\SpawnProtectionConfig;
use Nythros\Framework\Game\Horde\WaveDefinition;
use PHPUnit\Framework\TestCase;

/**
 * HordeConfigTest - horde 类型模块配置解析测试（R4 试点）：缺省配置与迁移前 demo 常量逐值对齐
 * （迁移期行为零变化）、波次网格坐标纯函数、自定义配置只读聚合与结算规则边界。
 * HordeConfigTest - the horde type-module config parsing tests (the R4 pilot): the default config aligns
 * value-for-value with the pre-migration demo constants (zero behavior change through the migration), the wave
 * grid coordinate pure function, custom readonly aggregates and settlement-rule boundaries.
 */
final class HordeConfigTest extends TestCase
{
    public function testDefaultConfigMatchesPreMigrationDemoConstants(): void
    {
        $config = HordeConfig::default();

        // 房间参数：horde tick 50ms（ADR-024 §D-B）、成员上限 512、AoE 半径上限 300（R2 审查 MAJOR-1）
        // Room parameters: the horde 50ms tick (ADR-024 §D-B), member cap 512, AoE radius cap 300 (R2 review MAJOR-1)
        self::assertSame(50, $config->periodMs);
        self::assertSame(512, $config->maxMembers);
        self::assertSame(300, $config->aoeMaxRadius);

        // 波次刷怪定义：网格 x 起点 24 / y 起点 -24 / 20 列 / 步距 2，怪 maxHp=12（fireball 单发致死确定性）
        // Wave definition: grid x-start 24 / y-start -24 / 20 columns / step 2, monster maxHp 12 (fireball one-shot determinism)
        self::assertCount(1, $config->waves);
        $wave = $config->waves[0];
        self::assertSame(200, $wave->count);
        self::assertSame(12, $wave->monsterMaxHp);
        self::assertSame(24, $wave->gridStartX);
        self::assertSame(-24, $wave->gridStartY);
        self::assertSame(20, $wave->columns);
        self::assertSame(2, $wave->step);

        // 掉落风暴/出生保护/结算规则缺省
        // Drop-storm / spawn-protection / settlement defaults
        self::assertSame(300, $config->dropStorm->dropLifetimeSeconds);
        self::assertSame(60, $config->spawnProtection->frames);
        self::assertSame(100, $config->settlement->minKillRatio);
    }

    public function testWavePositionAtDerivesRowMajorGridCoordinates(): void
    {
        $wave = new WaveDefinition(count: 200, monsterMaxHp: 12, gridStartX: 24, gridStartY: -24, columns: 20, step: 2);

        // 行优先：列内步进 x、换行步进 y；首格、行尾、次行首、末格四个采样点
        // Row-major: x steps within a row, y steps across rows; samples at the first cell, row end, next row start and last cell
        self::assertSame(['x' => 24, 'y' => -24], $wave->positionAt(0));
        self::assertSame(['x' => 62, 'y' => -24], $wave->positionAt(19), '第 20 格 = 首行行尾 the 20th cell = end of the first row');
        self::assertSame(['x' => 24, 'y' => -22], $wave->positionAt(20), '第 21 格 = 次行行首 the 21st cell = start of the second row');
        self::assertSame(['x' => 62, 'y' => -6], $wave->positionAt(199), '第 200 格 = 末行行尾 the 200th cell = end of the last row');
    }

    public function testCustomConfigAggregatesOverrideDefaults(): void
    {
        $config = new HordeConfig(
            waves: [new WaveDefinition(count: 30, monsterMaxHp: 40, gridStartX: 0, gridStartY: 0, columns: 6, step: 3)],
            periodMs: 30,
            maxMembers: 6,
            aoeMaxRadius: 120,
            dropStorm: new DropStormConfig(dropLifetimeSeconds: 60),
            spawnProtection: new SpawnProtectionConfig(frames: 20),
            settlement: new SettlementRules(minKillRatio: 80),
        );

        self::assertSame(30, $config->periodMs);
        self::assertSame(6, $config->maxMembers);
        self::assertSame(120, $config->aoeMaxRadius);
        self::assertSame(60, $config->dropStorm->dropLifetimeSeconds);
        self::assertSame(20, $config->spawnProtection->frames);
        self::assertSame(80, $config->settlement->minKillRatio);
        self::assertSame(40, $config->waves[0]->monsterMaxHp);
    }

    /**
     * 结算规则边界：空刷怪恒不可结算；全清达成；比例阈值 ceil 取整（80% × 30 = 24 杀即达成）。
     * Settlement-rule boundaries: zero spawns never settle; a full clear settles; ratio thresholds round up
     * (80% of 30 = 24 kills suffice).
     */
    public function testSettlementRulesBoundaries(): void
    {
        $rules = new SettlementRules();

        self::assertFalse($rules->isCleared(0, 0), '空刷怪不结算 zero spawns never settle');
        self::assertFalse($rules->isCleared(200, 199), '差一只未达成 one short of a full clear does not settle');
        self::assertTrue($rules->isCleared(200, 200), '全清达成 a full clear settles');

        $relaxed = new SettlementRules(minKillRatio: 80);
        self::assertFalse($relaxed->isCleared(30, 23));
        self::assertTrue($relaxed->isCleared(30, 24), 'ceil(30 × 80%) = 24 杀即达成 ceil(30 × 80%) = 24 kills suffice');
    }
}
