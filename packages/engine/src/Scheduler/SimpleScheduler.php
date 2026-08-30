<?php

declare(strict_types=1);

namespace Nythros\Scheduler;

use Nythros\Contracts\SchedulerInterface;

/**
 * 简单调度器：按优先级收集任务，每帧对队列做快照后按优先级从高到低执行；执行期间新提交的任务留待下一帧。
 * Simple scheduler: collects tasks by priority and runs a snapshot of the queue in descending priority order each frame; tasks submitted during execution are deferred to the next frame.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SimpleScheduler implements SchedulerInterface
{
    /** @var array<int, list<callable>> 任务队列：优先级映射到按提交顺序排列的任务列表 Task queue: priority mapped to a list of tasks in submission order. */
    private array $tasks = [];

    /**
     * 提交一个任务；数值越大的优先级越先执行（runFrame 内 krsort 降序）。
     * Submits a task; higher priority values run first (krsort descending inside runFrame).
     *
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function addTask(callable $task, int $priority = 0): void
    {
        $this->tasks[$priority][] = $task;
    }

    /**
     * 降级语义：本调度器不支持分区，忽略 region 参数，等价 addTask（与 RegionScheduler 形成统一契约，供上层免 instanceof 收窄）。
     * Degradation semantics: this scheduler has no region support, so the region argument is ignored and the call is equivalent to addTask (a unified contract with RegionScheduler so upper layers never narrow with instanceof).
     *
     * @param string $region 分区名（本实现忽略） Region name (ignored by this implementation).
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function addTaskToRegion(string $region, callable $task, int $priority = 0): void
    {
        $this->addTask($task, $priority);
    }

    /**
     * 执行本帧任务：对当前队列做快照后按优先级降序执行，执行过程中新提交的任务不参与本帧。
     * Runs this frame's tasks: snapshots the current queue, executes it in descending priority order, and tasks submitted mid-run do not take part in the current frame.
     */
    public function runFrame(): void
    {
        // 按键（优先级）降序排列，保证数值大的高优先级任务先执行 sort by key (priority) descending so higher-priority tasks run first
        krsort($this->tasks);

        // 快照后立即清空：执行期间 addTask 写入的是全新队列，既不会影响本帧遍历，也不会造成死循环 snapshot then clear immediately: tasks added during execution land in a fresh queue, so the current frame's iteration is neither mutated nor able to loop forever
        $tasks = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as $list) {
            foreach ($list as $task) {
                $task();
            }
        }
    }
}
