<?php

declare(strict_types=1);

namespace Nythros\Scheduler\Tests;

use Nythros\Scheduler\TaskQueue;
use PHPUnit\Framework\TestCase;

/**
 * TaskQueueTest - 覆盖 TaskQueue 优先级降序出队、快照语义（执行期入队不进本批）与 count 计数。
 * Tests covering TaskQueue priority-descending dequeue, snapshot semantics (tasks enqueued during execution do not join the current batch), and count.
 */
final class TaskQueueTest extends TestCase
{
    public function testDequeueAllReturnsTasksByPriorityDescending(): void
    {
        $queue = new TaskQueue();
        $order = [];

        $queue->enqueue(static function () use (&$order): void {
            $order[] = 'low';
        }, 1);
        $queue->enqueue(static function () use (&$order): void {
            $order[] = 'high';
        }, 10);
        $queue->enqueue(static function () use (&$order): void {
            $order[] = 'mid';
        }, 5);

        foreach ($queue->dequeueAll() as $task) {
            $task();
        }

        self::assertSame(['high', 'mid', 'low'], $order);
    }

    public function testTasksEnqueuedDuringExecutionDoNotJoinCurrentBatch(): void
    {
        $queue = new TaskQueue();
        $order = [];

        $queue->enqueue(static function () use (&$order, $queue): void {
            $order[] = 'first';
            // 执行期入队：写入的是快照后的新队列，不参与本批 enqueue during execution: lands in the fresh queue, not this batch's snapshot
            $queue->enqueue(static function () use (&$order): void {
                $order[] = 'second';
            });
        });

        foreach ($queue->dequeueAll() as $task) {
            $task();
        }

        self::assertSame(['first'], $order);

        foreach ($queue->dequeueAll() as $task) {
            $task();
        }

        self::assertSame(['first', 'second'], $order);
    }

    public function testCountReflectsPendingTasks(): void
    {
        $queue = new TaskQueue();

        self::assertSame(0, $queue->count());

        $queue->enqueue(static fn (): null => null, 1);
        $queue->enqueue(static fn (): null => null, 5);
        $queue->enqueue(static fn (): null => null, 5);

        self::assertSame(3, $queue->count());

        $queue->dequeueAll();

        self::assertSame(0, $queue->count());
    }
}
