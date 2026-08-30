<?php

declare(strict_types=1);

namespace Nythros\Scheduler;

use Nythros\Contracts\SchedulerInterface;

/**
 * 帧调度器：组合任务队列与时间轮——addTask 进入当帧队列，scheduleAt/scheduleAfter 排定定时回调；runFrame 先执行到期定时任务，再执行当帧任务队列（按优先级）。
 * Frame scheduler: composes a task queue with a timer wheel — addTask queues per-frame work while scheduleAt/scheduleAfter schedule timed callbacks; runFrame first fires due timers, then drains the current frame's task queue in priority order.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class TickScheduler implements SchedulerInterface
{
    /** @var TaskQueue 当帧任务队列 Per-frame task queue. */
    private TaskQueue $queue;

    /** @var TimerWheel 定时时间轮（兼作时钟来源） Timer wheel for timed callbacks (also the clock source). */
    private TimerWheel $wheel;

    /**
     * 构造帧调度器。
     * Creates a frame scheduler.
     *
     * @param callable|null $clock 时钟闭包，缺省返回微秒时间戳换算的秒 Clock closure, defaults to seconds converted from the microsecond timestamp.
     * @param int $tickMs 时间轮单槽时长（毫秒） Timer wheel slot duration (milliseconds).
     * @param int $wheelSize 时间轮槽位数 Timer wheel slot count.
     */
    public function __construct(?callable $clock = null, int $tickMs = 50, int $wheelSize = 1024)
    {
        $this->queue = new TaskQueue();
        $this->wheel = new TimerWheel($tickMs, $wheelSize, $clock);

        // 构造即建立时间轮指针：使首帧 runFrame 就能从当前时刻推进并执行 scheduleAfter/scheduleAt 排定的到期任务 establish the wheel pointer at construction: the first runFrame then sweeps from now and fires tasks scheduled via scheduleAfter/scheduleAt
        foreach ($this->wheel->advance($this->wheel->now()) as $callback) {
            $callback();
        }
    }

    /**
     * 提交一个当帧任务；数值越大的优先级越先执行。
     * Submits a per-frame task; higher priority values run first.
     *
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function addTask(callable $task, int $priority = 0): void
    {
        $this->queue->enqueue($task, $priority);
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
     * 排定一个在指定时刻触发的回调，返回任务 id（用于 cancel）。
     * Schedules a callback at an absolute time and returns its task id (for cancel).
     *
     * @param float $when 触发时刻（秒，Unix 风格时间戳） Fire time (seconds, Unix-style timestamp).
     * @param callable $callback 到期回调 The callback to fire when due.
     * @return int 任务 id Task id.
     */
    public function scheduleAt(float $when, callable $callback): int
    {
        return $this->wheel->schedule($when, $callback);
    }

    /**
     * 排定一个在若干秒后触发的回调，返回任务 id（用于 cancel）。
     * Schedules a callback after a delay and returns its task id (for cancel).
     *
     * @param float $seconds 相对延迟（秒） Delay in seconds.
     * @param callable $callback 到期回调 The callback to fire when due.
     * @return int 任务 id Task id.
     */
    public function scheduleAfter(float $seconds, callable $callback): int
    {
        return $this->scheduleAt($this->wheel->now() + $seconds, $callback);
    }

    /**
     * 取消一个定时任务；取消不存在的 id 无副作用。
     * Cancels a timed task; cancelling an unknown id has no effect.
     *
     * @param int $taskId 任务 id Task id returned by scheduleAt()/scheduleAfter().
     */
    public function cancel(int $taskId): void
    {
        $this->wheel->cancel($taskId);
    }

    /**
     * 推进时间轮到 $now 并执行全部到期定时回调。
     * Advances the timer wheel to $now and runs every due timed callback.
     *
     * @param float $now 当前时刻（秒） Current time (seconds).
     */
    public function tick(float $now): void
    {
        foreach ($this->wheel->advance($now) as $callback) {
            $callback();
        }
    }

    /**
     * 执行本帧：先推进时间轮执行到期定时任务，再按优先级执行当帧任务队列；执行期新提交的任务留待下一帧。
     * Runs the current frame: first advances the timer wheel to fire due timed callbacks, then runs the current frame's task queue in priority order; tasks submitted during execution wait for the next frame.
     */
    public function runFrame(): void
    {
        // 先处理到期定时任务，再处理当帧队列：保证 runFrame 的执行顺序 = 定时回调在前、当帧任务在后 fire due timers first, then the frame queue: runFrame order = timed callbacks before per-frame tasks
        $this->tick($this->wheel->now());

        foreach ($this->queue->dequeueAll() as $task) {
            $task();
        }
    }
}
