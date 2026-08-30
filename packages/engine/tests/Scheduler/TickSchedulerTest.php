<?php

declare(strict_types=1);

namespace Nythros\Scheduler\Tests;

use Nythros\Scheduler\TickScheduler;
use PHPUnit\Framework\TestCase;

/**
 * TickSchedulerTest - 覆盖 TickScheduler 当帧任务与定时回调的混合时序、cancel 生效以及 runFrame「先定时后当帧」的执行顺序。
 * Tests covering TickScheduler mixed per-frame task and timer ordering, cancel behavior, and the runFrame "timers before frame tasks" order.
 */
final class TickSchedulerTest extends TestCase
{
    public function testRunFrameFiresDueTimersBeforeFrameTasks(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $scheduler = new TickScheduler($clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'frame-low';
        }, 1);
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'frame-high';
        }, 10);
        $scheduler->scheduleAfter(0.1, static function () use (&$order): void {
            $order[] = 'timer';
        });

        $now = 1000.2; // 推进 200ms，timer 已到期 advance 200ms, the timer is due
        $scheduler->runFrame();

        self::assertSame(['timer', 'frame-high', 'frame-low'], $order);
    }

    public function testMixedScheduleAtAndAfterFireInDeadlineOrder(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $scheduler = new TickScheduler($clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'frame';
        });
        $scheduler->scheduleAfter(0.1, static function () use (&$order): void { // 1000.1
            $order[] = 'after';
        });
        $scheduler->scheduleAt(1000.05, static function () use (&$order): void {
            $order[] = 'at';
        });

        $now = 1000.2;
        $scheduler->runFrame();

        // 到期顺序按 deadline：scheduleAt(1000.05) 先于 scheduleAfter(1000.1)，均先于当帧任务 due order follows deadlines: scheduleAt(1000.05) before scheduleAfter(1000.1), both before the frame task
        self::assertSame(['at', 'after', 'frame'], $order);
    }

    public function testCancelPreventsTimerFromFiring(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $scheduler = new TickScheduler($clock);
        $fired = [];

        $id = $scheduler->scheduleAfter(0.1, static function () use (&$fired): void {
            $fired[] = 'x';
        });
        $scheduler->cancel($id);

        $now = 1000.2;
        $scheduler->runFrame();

        self::assertSame([], $fired);
    }

    public function testTaskAddedDuringRunFrameWaitsForNextFrame(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $scheduler = new TickScheduler($clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order, $scheduler): void {
            $order[] = 'first';
            // 执行期提交：进入下一帧队列 submit during execution: joins the next frame's queue
            $scheduler->addTask(static function () use (&$order): void {
                $order[] = 'second';
            });
        });

        $scheduler->runFrame();
        self::assertSame(['first'], $order);

        $scheduler->runFrame(); // 时间未前进：无定时任务，仅执行当帧队列 time did not advance: no timers, only the frame queue runs
        self::assertSame(['first', 'second'], $order);
    }
}
