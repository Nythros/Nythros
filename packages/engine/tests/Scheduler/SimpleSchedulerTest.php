<?php

declare(strict_types=1);

namespace Nythros\Scheduler\Tests;

use Nythros\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;

/**
 * SimpleSchedulerTest - 覆盖 SimpleScheduler 按优先级降序执行任务与帧后清空行为。
 * Tests covering SimpleScheduler priority-descending task execution and post-frame cleanup.
 */
final class SimpleSchedulerTest extends TestCase
{
    public function testRunFrameExecutesTasksByPriorityDescending(): void
    {
        $scheduler = new SimpleScheduler();
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'low';
        }, 1);
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'high';
        }, 10);
        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'mid';
        }, 5);

        $scheduler->runFrame();

        self::assertSame(['high', 'mid', 'low'], $order);
    }

    public function testTasksAreClearedAfterRunFrame(): void
    {
        $scheduler = new SimpleScheduler();
        $calls = 0;

        $scheduler->addTask(static function () use (&$calls): void {
            $calls++;
        });

        $scheduler->runFrame();
        $scheduler->runFrame();

        self::assertSame(1, $calls);
    }

    public function testAddTaskToRegionDegradesToAddTask(): void
    {
        // 降级语义：SimpleScheduler 不支持分区，region 参数被忽略，任务照常按优先级执行（契约统一，免 instanceof 收窄）
        // Degradation semantics: SimpleScheduler has no region support, so the region argument is ignored and the task still runs by priority (unified contract, no instanceof narrowing)
        $scheduler = new SimpleScheduler();
        $order = [];

        $scheduler->addTask(static function () use (&$order): void {
            $order[] = 'plain';
        }, 1);
        $scheduler->addTaskToRegion('network', static function () use (&$order): void {
            $order[] = 'region';
        }, 10);

        $scheduler->runFrame();

        self::assertSame(['region', 'plain'], $order);
    }
}
