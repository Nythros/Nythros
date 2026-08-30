<?php

declare(strict_types=1);

namespace Nythros\Kernel\Tests;

use Nythros\Kernel\SystemClock;
use PHPUnit\Framework\TestCase;

/**
 * SystemClockTest - 覆盖 SystemClock 的 tick/now 单调性与 deltaTime 行为。
 * Tests covering SystemClock tick/now monotonicity and deltaTime behavior.
 */
final class SystemClockTest extends TestCase
{
    public function testNowIsMonotonicAcrossTicks(): void
    {
        $clock = new SystemClock();

        $clock->tick();
        $first = $clock->now();

        usleep(10_000);
        $clock->tick();

        self::assertGreaterThan($first, $clock->now());
    }

    public function testDeltaTimeIsZeroAfterFirstTick(): void
    {
        $clock = new SystemClock();

        $clock->tick();

        self::assertSame(0.0, $clock->deltaTime());
    }

    public function testDeltaTimeIsPositiveAfterSecondTick(): void
    {
        $clock = new SystemClock();

        $clock->tick();
        usleep(10_000);
        $clock->tick();

        self::assertGreaterThan(0.0, $clock->deltaTime());
    }
}
