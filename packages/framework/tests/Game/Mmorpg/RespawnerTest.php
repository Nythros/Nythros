<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Mmorpg;

use Nythros\Framework\Game\Mmorpg\Respawner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RespawnerTest - 重生调度组件测试（R4 试点）：死亡登记 → 定时重生回锚点的纯调度——
 * registerDeath 记录重生时刻（now + respawnMs）、due 到期查询、clear 摘除与 pending 状态。
 * RespawnerTest - the respawn-scheduler component tests (the R4 pilot): the pure scheduling of
 * death-registration → timed respawn — registerDeath records the respawn instant (now + respawnMs), due queries
 * the due ids, clear removes and pending reports the state.
 */
final class RespawnerTest extends TestCase
{
    public function testRegisterDeathSchedulesRespawnAfterDelay(): void
    {
        $respawner = new Respawner(5000);

        $respawner->registerDeath('monster-1', 100.0);

        self::assertSame([], $respawner->due(104.9), '延迟未到不重生 not due before the delay elapses');
        self::assertSame(['monster-1'], $respawner->due(105.0), '延迟到点即到期 due exactly at the delay');
        self::assertTrue($respawner->pending(), '有待重生登记 a respawn registration remains');
    }

    public function testDueDoesNotMutateState(): void
    {
        $respawner = new Respawner(1000);

        $respawner->registerDeath('monster-1', 100.0);
        $respawner->due(200.0);

        self::assertSame(['monster-1'], $respawner->due(200.0), 'due 是只读查询，重复查询仍返回同一到期集 due is a read-only query');
    }

    public function testClearRemovesRegistration(): void
    {
        $respawner = new Respawner(1000);

        $respawner->registerDeath('monster-1', 100.0);
        $respawner->clear('monster-1');

        self::assertSame([], $respawner->due(200.0), 'clear 后不再到期 cleared ids never come due');
        self::assertFalse($respawner->pending());
    }

    public function testMultipleDeathsScheduleIndependently(): void
    {
        $respawner = new Respawner(1000);

        $respawner->registerDeath('monster-1', 100.0);
        $respawner->registerDeath('monster-2', 103.0);

        self::assertSame(['monster-1'], $respawner->due(101.0), '各自按登记时刻独立到期 each id comes due at its own instant');
        self::assertSame(['monster-1', 'monster-2'], $respawner->due(104.0));
    }

    public function testRepeatedRegistrationOverwrites(): void
    {
        $respawner = new Respawner(1000);

        $respawner->registerDeath('monster-1', 100.0);
        $respawner->registerDeath('monster-1', 200.0);

        self::assertSame([], $respawner->due(200.9), '重复登记覆盖重生时刻（幂等） repeated registration overwrites the instant (idempotent)');
        self::assertSame(['monster-1'], $respawner->due(201.0));
    }

    /**
     * 构造期不变量（reviewer MINOR-3）：respawnMs 必须为正——零/负值会让死亡登记立即到期重生，
     * 与 MmorpgConfig 同口径 fail-fast。
     * Construction invariant (reviewer MINOR-3): respawnMs must be positive — zero/negative values would respawn
     * deaths immediately; fail-fast with the same convention as MmorpgConfig.
     */
    public static function provideNonPositiveRespawnMs(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-100];
    }

    /** @dataProvider provideNonPositiveRespawnMs */
    #[DataProvider('provideNonPositiveRespawnMs')]
    public function testRejectsNonPositiveRespawnMs(int $respawnMs): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('respawnMs 必须为正');
        new Respawner($respawnMs);
    }
}
