<?php

declare(strict_types=1);

namespace Nythros\Scheduler;

/**
 * 任务队列：按优先级分桶收集可延期任务，出队时快照当前全部任务并清空，保证执行期新入队的任务不进入本帧。
 * Task queue: collects deferrable tasks into per-priority buckets; dequeuing snapshots the whole queue and clears it, so tasks enqueued during execution never join the current frame.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class TaskQueue
{
    /** @var array<int, list<callable>> 任务队列：优先级映射到按提交顺序排列的任务列表 Task queue: priority mapped to a list of tasks in submission order. */
    private array $tasks = [];

    /**
     * 入队一个任务；数值越大的优先级越先执行（dequeueAll 内 krsort 降序）。
     * Enqueues a task; higher priority values run first (krsort descending inside dequeueAll).
     *
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function enqueue(callable $task, int $priority = 0): void
    {
        $this->tasks[$priority][] = $task;
    }

    /**
     * 出队本批全部任务：对当前队列做快照后立即清空，按优先级降序展平为单一列表返回。
     * Dequeues all pending tasks: snapshots the current queue, clears it immediately, then flattens the snapshot into a single list in descending priority order.
     *
     * @return list<callable> 按优先级降序排列的任务列表（同优先级保持提交顺序） Task list in descending priority order (submission order preserved within the same priority).
     */
    public function dequeueAll(): array
    {
        // 快照后立即清空：执行期 enqueue 写入的是全新队列，既不参与本批，也不会造成死循环 snapshot then clear immediately: tasks enqueued during execution land in a fresh queue, so they neither join this batch nor loop forever
        $snapshot = $this->tasks;
        $this->tasks = [];

        // 按键（优先级）降序排列，保证数值大的高优先级任务先执行 sort by key (priority) descending so higher-priority tasks run first
        krsort($snapshot);

        $result = [];
        foreach ($snapshot as $list) {
            foreach ($list as $task) {
                $result[] = $task;
            }
        }

        return $result;
    }

    /**
     * 返回当前队列中的任务总数。
     * Returns the total number of tasks currently pending in the queue.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->tasks as $list) {
            $total += count($list);
        }

        return $total;
    }
}
