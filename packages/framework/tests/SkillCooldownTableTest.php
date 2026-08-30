<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Combat\SkillCooldownTable;
use PHPUnit\Framework\TestCase;

/**
 * SkillCooldownTableTest - 覆盖冷却表收编：置冷/就绪判定/剩余秒数/按施法者清理与跨技能隔离。
 * Tests covering the absorbed cooldown table: chill-down, readiness, remaining seconds, per-caster reset and
 * cross-skill isolation.
 */
final class SkillCooldownTableTest extends TestCase
{
    public function testUnrecordedSkillIsReady(): void
    {
        $table = new SkillCooldownTable();

        self::assertTrue($table->isReady('c1', 'fireball', 100.0));
        self::assertSame(0.0, $table->remaining('c1', 'fireball', 100.0));
    }

    public function testStartChillsDownAndExpiresAtTheReadyInstant(): void
    {
        $table = new SkillCooldownTable();
        $table->start('c1', 'fireball', 2.0, 100.0);

        self::assertFalse($table->isReady('c1', 'fireball', 101.0), '冷却中未就绪。Not ready while chilled.');
        self::assertSame(1.0, $table->remaining('c1', 'fireball', 101.0));

        // 边界：now == readyAt 即就绪（含等于）。 Boundary: now == readyAt counts as ready (inclusive).
        self::assertTrue($table->isReady('c1', 'fireball', 102.0));
    }

    public function testCastersAndSkillsAreIsolated(): void
    {
        $table = new SkillCooldownTable();
        $table->start('c1', 'fireball', 2.0, 100.0);

        self::assertTrue($table->isReady('c2', 'fireball', 100.0), '其他施法者不受影响。Other casters stay unaffected.');
        self::assertTrue($table->isReady('c1', 'ice_bolt', 100.0), '其他技能不受影响。Other skills stay unaffected.');
    }

    public function testResetClearsOnlyOneCaster(): void
    {
        $table = new SkillCooldownTable();
        $table->start('c1', 'fireball', 5.0, 100.0);
        $table->start('c2', 'fireball', 5.0, 100.0);

        $table->reset('c1');

        self::assertTrue($table->isReady('c1', 'fireball', 100.0), '清理后立即就绪。Ready immediately after the reset.');
        self::assertFalse($table->isReady('c2', 'fireball', 100.0), '清理只作用于目标施法者。The reset only hits the target caster.');
    }

    public function testNonPositiveCooldownCountsAsInstantlyReady(): void
    {
        $table = new SkillCooldownTable();
        $table->start('c1', 'fireball', -1.0, 100.0);

        self::assertTrue($table->isReady('c1', 'fireball', 100.0), '非正冷却视为瞬时就绪。A non-positive cooldown counts as instantly ready.');
    }
}
