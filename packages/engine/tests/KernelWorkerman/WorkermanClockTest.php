<?php

declare(strict_types=1);

namespace Nythros\KernelWorkerman\Tests;

use Nythros\Contracts\TimerInterface;
use Nythros\KernelWorkerman\WorkermanClock;
use PHPUnit\Framework\TestCase;

/**
 * WorkermanClockTest - 覆盖 WorkermanClock 定时器驱动的 tick/now/deltaTime 行为。
 * Tests covering WorkermanClock timer-driven tick/now/deltaTime behavior.
 */
final class WorkermanClockTest extends TestCase
{
    public function testStartRegistersPersistentCallback(): void
    {
        $timer = new FakeTimer();
        $clock = new WorkermanClock($timer, 0.05);

        $clock->start();

        self::assertSame(1, $timer->addCallCount);
        self::assertSame(0.05, $timer->lastInterval);
        self::assertTrue($timer->lastPersistent);
        self::assertIsCallable($timer->lastCallback);
    }

    public function testTickUpdatesNowMonotonically(): void
    {
        $timer = new FakeTimer();
        $clock = new WorkermanClock($timer);

        $clock->start();
        $callback = $timer->lastCallback;
        self::assertIsCallable($callback);

        $callback();
        $first = $clock->now();
        self::assertGreaterThan(0.0, $first);

        usleep(10_000);
        $callback();

        self::assertGreaterThan($first, $clock->now());
    }

    public function testDeltaTimeIsZeroAfterFirstTick(): void
    {
        $timer = new FakeTimer();
        $clock = new WorkermanClock($timer);

        $clock->start();
        $callback = $timer->lastCallback;
        self::assertIsCallable($callback);

        $callback();

        self::assertSame(0.0, $clock->deltaTime());
    }

    public function testDeltaTimeIsPositiveAfterSecondTick(): void
    {
        $timer = new FakeTimer();
        $clock = new WorkermanClock($timer);

        $clock->start();
        $callback = $timer->lastCallback;
        self::assertIsCallable($callback);

        $callback();
        usleep(10_000);
        $callback();

        self::assertGreaterThan(0.0, $clock->deltaTime());
    }
}

final class FakeTimer implements TimerInterface
{
    public int $addCallCount = 0;
    public float $lastInterval = 0.0;
    public bool $lastPersistent = false;
    /** @var null|callable(): void */
    public $lastCallback = null;

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        ++$this->addCallCount;
        $this->lastInterval = $intervalSeconds;
        $this->lastPersistent = $persistent;
        $this->lastCallback = $callback;

        return 1;
    }

    public function cancel(int $timerId): void
    {
    }
}
