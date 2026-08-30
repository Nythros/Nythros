<?php

declare(strict_types=1);

namespace Nythros\Network\Tests;

use Nythros\Network\SimpleTokenBucket;
use PHPUnit\Framework\TestCase;

/**
 * SimpleTokenBucketTest - 覆盖 SimpleTokenBucket 的容量消耗、超额拒绝、随时间补给与连接间隔离行为。
 * Tests covering SimpleTokenBucket capacity consumption, excess rejection, time-based refill, and per-connection isolation.
 */
final class SimpleTokenBucketTest extends TestCase
{
    public function testConsumesWithinCapacity(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 3, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertTrue($bucket->consume('c1'));
        self::assertTrue($bucket->consume('c1'));
    }

    public function testRejectsWhenExceeded(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 2, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c1'));
    }

    public function testRefillsOverTime(): void
    {
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c1'));

        $now += 1.0;

        self::assertTrue($bucket->consume('c1'));
    }

    public function testRefillNeverExceedsCapacity(): void
    {
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        $now += 100.0;

        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c1'));
    }

    public function testBucketsAreIndependentPerConnection(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertTrue($bucket->consume('c2'));
        self::assertFalse($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c2'));
    }

    public function testRejectsNonPositiveTokenRequest(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        self::assertFalse($bucket->consume('c1', 0));
    }

    public function testForgetResetsBucketToFullCapacity(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c1')); // 桶已空 bucket drained

        // 断连释放：同 id 重新消费按满桶初始化（无需等待补给）
        // Disconnect release: consuming with the same id re-initializes a full bucket (no need to wait for refill)
        $bucket->forget('c1');

        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c1')); // 满桶只补了一次容量，再消费仍超限 the re-initialized bucket holds capacity tokens only; consuming again still exceeds it
    }

    public function testForgetOnlyAffectsTargetConnection(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $bucket = new SimpleTokenBucket(refillPerSecond: 1.0, capacity: 1, clock: $clock);

        self::assertTrue($bucket->consume('c1'));
        self::assertTrue($bucket->consume('c2'));

        // 释放 c1 不影响 c2：c2 仍保持空桶状态 forgetting c1 never touches c2: c2 keeps its drained state
        $bucket->forget('c1');

        self::assertTrue($bucket->consume('c1'));
        self::assertFalse($bucket->consume('c2'));
    }
}
