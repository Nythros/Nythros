<?php

declare(strict_types=1);

namespace Nythros\Scheduler\Tests;

use InvalidArgumentException;
use Nythros\Scheduler\RegionScheduler;
use PHPUnit\Framework\TestCase;

/**
 * RegionSchedulerTest - 覆盖 RegionScheduler 假时钟精确预算截断、延后保序、非法分区异常、default 区、统计计数与跨分区整体延后。
 * Tests covering RegionScheduler precise fake-clock budget cutoff, order-preserving deferral, invalid-region exceptions, the default region, stats counting, and cross-region whole deferral.
 */
final class RegionSchedulerTest extends TestCase
{
    public function testBudgetLimitsTasksExecutedPerFrame(): void
    {
        // 假时钟每次调用递增 0.5ms，每个任务前后各取一次 clock，因此每个任务被精确模拟为 0.5ms 开销 the fake clock advances 0.5ms per call; sampling before and after every task simulates each task as exactly 0.5ms of cost
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $t += 0.5;

            return $t;
        };

        $scheduler = new RegionScheduler(1.0, $clock); // default 区预算 1ms default region budget 1ms
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 1;
        });
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 2;
        });
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 3;
        });

        $scheduler->runFrame();

        // 1ms 预算只够执行 2 个 0.5ms 任务，第 3 个延后 a 1ms budget fits exactly 2 tasks of 0.5ms each; the third is deferred
        self::assertSame([1, 2], $order);
        self::assertSame(['executed' => 2, 'deferred' => 1, 'elapsedMs' => 2.5], $scheduler->getStats());

        $scheduler->runFrame();

        self::assertSame([1, 2, 3], $order);
        self::assertSame(['executed' => 3, 'deferred' => 1, 'elapsedMs' => 1.5], $scheduler->getStats());
    }

    public function testDeferredTasksKeepPriorityAndSubmissionOrder(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $t += 0.5;

            return $t;
        };

        $scheduler = new RegionScheduler(1.0, $clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'a';
        }, 1);
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'b';
        }, 5);
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'c';
        }, 5); // 与 b 同优先级，验证稳定保序 same priority as b, verifying stable ordering
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'd';
        }, 3);

        $scheduler->runFrame();

        // 优先级降序 b、c 先执行；d、a 延后且保序 descending priority runs b then c; d and a are deferred in order
        self::assertSame(['b', 'c'], $order);

        $scheduler->runFrame();

        // 延后任务下帧继续，顺序不变（d 优先级 3 仍先于 a 优先级 1，b 先于 c） deferred tasks resume next frame in the same order (d at priority 3 still precedes a at priority 1, and b precedes c)
        self::assertSame(['b', 'c', 'd', 'a'], $order);
        self::assertSame(2, $scheduler->getStats()['deferred']);
    }

    public function testAddTaskToUnregisteredRegionThrows(): void
    {
        $scheduler = new RegionScheduler();

        $this->expectException(InvalidArgumentException::class);
        $scheduler->addTaskToRegion('ghost', static fn (): null => null);
    }

    public function testRegisteringDuplicateRegionThrows(): void
    {
        $scheduler = new RegionScheduler();

        $this->expectException(InvalidArgumentException::class);
        $scheduler->registerRegion('default', 1.0); // 构造时已自动注册 default already auto-registered by the constructor
    }

    public function testDefaultRegionExistsAndRunsTasks(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $t += 0.5;

            return $t;
        };

        $scheduler = new RegionScheduler(6.0, $clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order): void { // 不抛异常即 default 区存在 no exception proves the default region exists
            $order[] = 'x';
        });

        $scheduler->runFrame();

        self::assertSame(['x'], $order);
    }

    public function testCrossRegionDeferralWhenEarlierRegionExhaustsBudget(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $t += 0.5;

            return $t;
        };

        $scheduler = new RegionScheduler(6.0, $clock); // default 区无任务 the default region has no tasks
        $scheduler->registerRegion('A', 1.0);
        $scheduler->registerRegion('B', 6.0);
        $scheduler->registerRegion('C', 6.0);

        $order = [];
        $scheduler->addTaskToRegion('A', static function () use (&$order): void {
            $order[] = 'a1';
        });
        $scheduler->addTaskToRegion('A', static function () use (&$order): void {
            $order[] = 'a2';
        });
        $scheduler->addTaskToRegion('A', static function () use (&$order): void {
            $order[] = 'a3';
        });
        $scheduler->addTaskToRegion('B', static function () use (&$order): void {
            $order[] = 'b1';
        });
        $scheduler->addTaskToRegion('C', static function () use (&$order): void {
            $order[] = 'c1';
        });

        $scheduler->runFrame();

        // A 区 1ms 预算执行 a1、a2 后耗尽：a3 回队，B、C 整体延后 region A exhausts its 1ms budget after a1 and a2: a3 is re-queued and B/C are wholly deferred
        self::assertSame(['a1', 'a2'], $order);
        self::assertSame(['executed' => 2, 'deferred' => 3, 'elapsedMs' => 2.5], $scheduler->getStats());

        $scheduler->runFrame();

        // 下帧：A 的延后任务先于 B、C，顺序不变 next frame: A's deferred task precedes B and C, order preserved
        self::assertSame(['a1', 'a2', 'a3', 'b1', 'c1'], $order);
        self::assertSame(['executed' => 5, 'deferred' => 3, 'elapsedMs' => 3.5], $scheduler->getStats());
    }

    public function testTasksAddedDuringRunFrameWaitForNextFrame(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            $t += 0.5;

            return $t;
        };

        $scheduler = new RegionScheduler(6.0, $clock);
        $order = [];

        $scheduler->addTask(static function () use (&$order, $scheduler): void {
            $order[] = 'first';
            // 执行期入队：快照已清空，只进下一帧 submit during execution: the snapshot is already cleared, so it joins the next frame only
            $scheduler->addTask(static function () use (&$order): void {
                $order[] = 'second';
            });
        });

        $scheduler->runFrame();
        self::assertSame(['first'], $order);

        $scheduler->runFrame();
        self::assertSame(['first', 'second'], $order);
    }
}
