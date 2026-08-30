<?php

declare(strict_types=1);

namespace Nythros\Scheduler\Tests;

use Nythros\Scheduler\TimerWheel;
use PHPUnit\Framework\TestCase;

/**
 * TimerWheelTest - 覆盖 TimerWheel 到期顺序、惰性取消、超 span clamp、跨圈推进、未到期留槽与注入时钟精确推进。
 * Tests covering TimerWheel due ordering, lazy cancellation, over-span clamping, multi-revolution advancement, not-yet-due retention, and precise injected-clock progression.
 */
final class TimerWheelTest extends TestCase
{
    public function testDueCallbacksFireInDeadlineOrder(): void
    {
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0); // 初始化指针 establish the pointer

        $fired = [];
        $wheel->schedule(1000.15, static function () use (&$fired): void {
            $fired[] = 'late';
        });
        $wheel->schedule(1000.05, static function () use (&$fired): void {
            $fired[] = 'early';
        });

        $this->advanceTo($wheel, 1000.20);

        self::assertSame(['early', 'late'], $fired);
    }

    public function testCancelledTaskIsSkippedOnAdvance(): void
    {
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0);

        $fired = [];
        $id = $wheel->schedule(1000.05, static function () use (&$fired): void {
            $fired[] = 'x';
        });
        $wheel->cancel($id);

        $this->advanceTo($wheel, 1000.10);

        self::assertSame([], $fired);
    }

    public function testFarFutureDeadlineIsClampedAndEventuallyFires(): void
    {
        // 小轮子便于验证：span = 400ms，超大 deadline 被 clamp 到指针前一槽，下一圈即可执行 a small wheel keeps the check quick: span = 400ms, a huge deadline is clamped to the slot before the pointer and fires on the next revolution
        $wheel = new TimerWheel(50, 8);
        $this->advanceTo($wheel, 1.0);

        $fired = [];
        // 100s 远超一个 span，clamp 后不应崩溃且最终可执行 100s is far beyond one span; after clamping it must neither crash nor get lost
        $wheel->schedule(100.0, static function () use (&$fired): void {
            $fired[] = 'far';
        });

        $this->advanceTo($wheel, 1.5); // 前进 500ms，超过 span 400ms advance 500ms, more than the 400ms span

        self::assertSame(['far'], $fired);
    }

    public function testDeadlineAcrossMultipleRevolutionsFiresOnTime(): void
    {
        // span = 400ms：两个 deadline 距指针 300/350ms 均未超 span，但槽位已绕轮多圈 span = 400ms: both deadlines (300/350ms away) stay within the span yet wrap the wheel several times
        $wheel = new TimerWheel(50, 8);
        $this->advanceTo($wheel, 1.0);

        $fired = [];
        $wheel->schedule(1.35, static function () use (&$fired): void {
            $fired[] = 'cross';
        });
        $wheel->schedule(1.30, static function () use (&$fired): void {
            $fired[] = 'near';
        });

        $this->advanceTo($wheel, 1.4);

        self::assertSame(['near', 'cross'], $fired);
    }

    public function testNotYetDueCallbacksStayInTheirSlot(): void
    {
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0);

        $fired = [];
        $wheel->schedule(1000.5, static function () use (&$fired): void {
            $fired[] = 'later';
        });

        $this->advanceTo($wheel, 1000.2); // 未到期：不触发 not yet due: nothing fires
        self::assertSame([], $fired);

        $this->advanceTo($wheel, 1000.6); // 跨过 deadline：触发 sweeping past the deadline: fires
        self::assertSame(['later'], $fired);
    }

    public function testInjectedClockControlsPreciseProgression(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $wheel = new TimerWheel(50, 1024, $clock);
        $this->advanceTo($wheel, $clock()); // 用假时钟建指针 establish the pointer with the fake clock

        $fired = [];
        $wheel->schedule(1000.05, static function () use (&$fired): void {
            $fired[] = 'tick';
        });

        $now = 1000.10; // 假时钟精确前进 100ms，恰好跨过 1000.05 的 deadline advance the fake clock exactly 100ms, just past the 1000.05 deadline
        $this->advanceTo($wheel, $clock());

        self::assertSame(['tick'], $fired);
    }

    public function testDeadlineEqualToLastNowFiresOnNextAdvance(): void
    {
        // 立即到期边界 1：deadline 恰等于指针时刻，落回 lastNow 所在 tick，下一次 advance 即到期（修复前延迟一圈）
        // Immediate-due boundary 1: the deadline exactly equals the pointer, landing back in the tick containing lastNow; the next advance fires it (before the fix it waited a full revolution)
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0);

        $fired = [];
        $wheel->schedule(1000.0, static function () use (&$fired): void {
            $fired[] = 'instant';
        });

        $this->advanceTo($wheel, 1000.05);

        self::assertSame(['instant'], $fired);
    }

    public function testDeadlineMidTickFiresOnNextAdvance(): void
    {
        // 立即到期边界 2：deadline 落在当前 tick 中段（与指针同槽），下一次 advance 扫过该 tick 即到期
        // Immediate-due boundary 2: the deadline lands mid-tick (the same slot as the pointer); the next advance sweeps that tick and fires it
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0);

        $fired = [];
        $wheel->schedule(1000.02, static function () use (&$fired): void {
            $fired[] = 'mid';
        });

        $this->advanceTo($wheel, 1000.05);

        self::assertSame(['mid'], $fired);
    }

    public function testClampedDeadlineFiresWhenNextRevolutionSweepsItsSlot(): void
    {
        // 立即到期边界 3（跨轮 clamp）：1.6s 距指针 0.6s 超 span（400ms），被 clamp 到指针前一槽（tick 19/slot 3）；
        // advance 扫过下圈该槽位置（tick 27）时即到期，不等 deadline 本身
        // Immediate-due boundary 3 (cross-revolution clamp): 1.6s is 0.6s past the pointer, beyond the span (400ms), so it is clamped to the slot before the pointer (tick 19/slot 3);
        // it fires as soon as advance sweeps that slot's next-revolution position (tick 27), without waiting for the deadline itself
        $wheel = new TimerWheel(50, 8);
        $this->advanceTo($wheel, 1.0);

        $fired = [];
        $wheel->schedule(1.6, static function () use (&$fired): void {
            $fired[] = 'clamped';
        });

        $this->advanceTo($wheel, 1.4); // 扫过 tick 20..28，其中 tick 27 命中 slot 3 sweeps ticks 20..28, where tick 27 hits slot 3

        self::assertSame(['clamped'], $fired);
    }

    public function testTaskScheduledInsideCallbackFiresInSameAdvance(): void
    {
        // 立即到期边界 4（同一次 advance）：yield 回调中 schedule 到本轮已扫 tick 的任务，经第二轮重扫在同一次 advance 到期
        // Immediate-due boundary 4 (same advance): a task scheduled inside a yielded callback into an already-swept tick of this round fires in the same advance via the second sweep
        $wheel = new TimerWheel(50, 1024);
        $this->advanceTo($wheel, 1000.0);

        $fired = [];
        $wheel->schedule(1000.01, static function () use (&$fired, $wheel): void {
            $fired[] = 'first';
            // 1000.03 与 1000.01 同处 tick 20000（本轮已扫），必须在同一次 advance 到期
            // 1000.03 shares tick 20000 with 1000.01 (already swept this round), so it must fire within the same advance
            $wheel->schedule(1000.03, static function () use (&$fired): void {
                $fired[] = 'nested';
            });
        });

        $this->advanceTo($wheel, 1000.05);

        self::assertSame(['first', 'nested'], $fired);
    }

    /**
     * 推进时间轮到指定时刻并执行全部到期回调（advance 是 generator，必须完整消费才会更新内部指针）。
     * Advances the wheel to the given time and runs every due callback (advance is a generator and must be fully consumed to update the internal pointer).
     */
    private function advanceTo(TimerWheel $wheel, float $now): void
    {
        foreach ($wheel->advance($now) as $callback) {
            $callback();
        }
    }
}
