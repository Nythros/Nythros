<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 调度器契约：按优先级收集可延期任务，每帧集中执行一帧的任务队列。
 * Scheduler contract: collects deferrable tasks by priority and runs the current frame's task queue in one batch.
 */
interface SchedulerInterface
{
    /**
     * 提交一个任务，附带优先级；优先级语义（数值越大越先执行）由实现约定。
     * Submit a task with a priority; priority semantics (higher values run first) are implementation-defined.
     *
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级 Task priority.
     */
    public function addTask(callable $task, int $priority = 0): void;

    /**
     * 向指定分区提交任务；不支持分区的实现降级为 addTask（忽略 region 参数，不得抛异常）。
     * Submit a task to a specific region; implementations without region support degrade to addTask (ignore the region argument, never throw).
     *
     * @param string $region 分区名 Region name.
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级 Task priority.
     */
    public function addTaskToRegion(string $region, callable $task, int $priority = 0): void;

    /**
     * 执行本帧排定的全部任务（按优先级顺序）。
     * Run all tasks scheduled for the current frame (in priority order).
     */
    public function runFrame(): void;
}
