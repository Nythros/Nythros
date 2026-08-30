<?php

declare(strict_types=1);

namespace Nythros\Scheduler;

use InvalidArgumentException;
use Nythros\Contracts\SchedulerInterface;

/**
 * 分区预算调度器：把任务按 region 分区，每区有独立时间预算；runFrame 用注入时钟测时，region 内按优先级降序执行，某区累计耗时达到预算即停止该区并整体延后后续所有区。
 * Region-budget scheduler: partitions tasks into regions, each with its own time budget; runFrame measures time via the injected clock, runs each region's tasks in descending priority order, and once a region's accumulated cost reaches its budget it stops that region and defers every later region as a whole.
 *
 * 测时语义：预算判定基于时钟闭包差值的累计，而不是真实耗时——每次任务执行前后各取一次 clock()，差值累加为该区耗时。
 * 注入「每次调用递增固定步长的假时钟」即可在测试中精确模拟每个任务的开销，从而精确验证预算截断点。
 * Timing semantics: budget checks are based on accumulated clock deltas rather than wall time — the clock is sampled before and after every task and the difference is added to the region's cost.
 * Injecting a fake clock that advances a fixed step per call lets tests simulate per-task cost precisely and verify the exact budget cutoff.
 *
 * deferred 计数语义：每延后一个任务加一（包括预算耗尽区内未执行完的剩余任务与被整体跳过的后续区任务）。
 * deferred counts each deferred task (the remainder of an exhausted region plus every task in wholly skipped later regions).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class RegionScheduler implements SchedulerInterface
{
    /** @var array<string, array{budgetMs: float, queue: list<array{task: callable, priority: int}>}> 分区表：region 名映射到预算与任务队列 Region table: region name mapped to its budget and task queue. */
    private array $regions = [];

    /** @var list<string> 分区注册顺序（runFrame 按此顺序遍历） Region registration order (runFrame iterates in this order). */
    private array $regionOrder = [];

    /** @var int 累计已执行任务数 Total tasks executed across all frames. */
    private int $executed = 0;

    /** @var int 累计被延后的任务数 Total tasks deferred across all frames. */
    private int $deferred = 0;

    /** @var float 最近一帧总耗时（毫秒，clock 差值） Total cost of the most recent frame (milliseconds, clock delta). */
    private float $lastElapsedMs = 0.0;

    /** @var callable(): float 时钟闭包：提供当前毫秒级时间（runFrame 内多次调用以测时） Clock closure supplying the current time in milliseconds (sampled repeatedly inside runFrame for timing). */
    private $clock;

    /**
     * 构造分区调度器并自动注册 default 区。
     * Creates a region scheduler and auto-registers the default region.
     *
     * @param float $totalBudgetMs default 区预算（毫秒） Budget of the default region (milliseconds).
     * @param callable|null $clock 时钟闭包，缺省返回毫秒精度时间戳 Clock closure, defaults to the millisecond-precision timestamp.
     */
    public function __construct(float $totalBudgetMs = 6.0, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true) * 1000.0;
        $this->registerRegion('default', $totalBudgetMs);
    }

    /**
     * 注册一个带预算的分区；重名抛 InvalidArgumentException。
     * Registers a region with a budget; a duplicate name throws InvalidArgumentException.
     *
     * 各区预算相互独立，多区预算之和允许超过总预算（是否整体超时由各帧实际测时决定）。
     * Region budgets are independent; their sum may exceed any overall budget (whether the frame as a whole runs long is decided by per-frame timing).
     *
     * @param string $name 分区名 Region name.
     * @param float $budgetMs 该区每帧时间预算（毫秒） This region's per-frame time budget (milliseconds).
     */
    public function registerRegion(string $name, float $budgetMs): void
    {
        if (isset($this->regions[$name])) {
            throw new InvalidArgumentException(sprintf('Region "%s" is already registered.', $name));
        }

        $this->regions[$name] = ['budgetMs' => $budgetMs, 'queue' => []];
        $this->regionOrder[] = $name;
    }

    /**
     * 向指定分区提交任务；未注册的分区抛 InvalidArgumentException。
     * Submits a task to a specific region; an unregistered region throws InvalidArgumentException.
     *
     * @param string $region 分区名 Region name.
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function addTaskToRegion(string $region, callable $task, int $priority = 0): void
    {
        if (!isset($this->regions[$region])) {
            throw new InvalidArgumentException(sprintf('Region "%s" is not registered.', $region));
        }

        $this->regions[$region]['queue'][] = ['task' => $task, 'priority' => $priority];
    }

    /**
     * 向 default 分区提交任务。
     * Submits a task to the default region.
     *
     * @param callable $task 待执行任务 The task to run.
     * @param int $priority 任务优先级，缺省 0 Task priority; defaults to 0.
     */
    public function addTask(callable $task, int $priority = 0): void
    {
        $this->addTaskToRegion('default', $task, $priority);
    }

    /**
     * 执行本帧：先对所有分区队列做快照并清空（执行期新入队的任务不进本帧），按注册顺序逐区、区内按优先级降序执行；
     * 每个任务前后取 clock 差值累加为该区耗时，某区累计耗时 >= 其预算时，该区剩余任务保序回队首、后续所有区的快照也保序回队首（均计入 deferred），本帧结束。
     * Runs the current frame: first snapshots and clears every region queue (tasks submitted during execution do not join this frame), then processes regions in registration order, each in descending priority order;
     * clock deltas before/after every task accumulate as the region's cost; once a region's cost reaches its budget, the remainder of that region is re-queued at the front in order, snapshots of all later regions are likewise re-queued at the front (all counted in deferred), and the frame ends.
     */
    public function runFrame(): void
    {
        $clock = $this->clock;
        $frameStart = (float) $clock();

        // 快照所有分区队列并立即清空：执行期新入队的任务只进新队列，不进本帧 snapshot every region queue and clear it immediately: tasks submitted during execution land in fresh queues and never join this frame
        $snapshot = [];
        foreach ($this->regionOrder as $name) {
            $snapshot[$name] = $this->regions[$name]['queue'];
            $this->regions[$name]['queue'] = [];
        }

        $regionCount = count($this->regionOrder);
        $budgetExhausted = false;

        for ($i = 0; $i < $regionCount; $i++) {
            $name = $this->regionOrder[$i];
            $budgetMs = $this->regions[$name]['budgetMs'];
            $spent = 0.0;

            $sorted = $this->sortByPriority($snapshot[$name]);
            $taskCount = count($sorted);

            for ($j = 0; $j < $taskCount; $j++) {
                $before = (float) $clock();
                ($sorted[$j]['task'])();
                $after = (float) $clock();

                // 时钟回退保护：负差值按零计 guard against clock regression: negative deltas count as zero
                $spent += max(0.0, $after - $before);
                $this->executed++;

                // 该区累计耗时达到预算：剩余任务保序回队首，后续所有区整体延后 region cost reached its budget: re-queue the remainder at the front in order and defer all later regions as a whole
                if ($spent >= $budgetMs) {
                    $remainder = array_slice($sorted, $j + 1);
                    if ($remainder !== []) {
                        $this->requeue($name, $remainder);
                        $this->deferred += count($remainder);
                    }

                    for ($k = $i + 1; $k < $regionCount; $k++) {
                        $later = $this->regionOrder[$k];
                        if ($snapshot[$later] !== []) {
                            $this->requeue($later, $snapshot[$later]);
                            $this->deferred += count($snapshot[$later]);
                        }
                    }

                    $budgetExhausted = true;

                    break;
                }
            }

            if ($budgetExhausted) {
                break;
            }
        }

        $frameEnd = (float) $clock();
        $this->lastElapsedMs = max(0.0, $frameEnd - $frameStart);
    }

    /**
     * 获取累计统计：已执行任务数、被延后任务数与最近一帧耗时（毫秒）。
     * Returns cumulative stats: executed task count, deferred task count, and the most recent frame's cost (milliseconds).
     *
     * @return array{executed: int, deferred: int, elapsedMs: float}
     */
    public function getStats(): array
    {
        return [
            'executed' => $this->executed,
            'deferred' => $this->deferred,
            'elapsedMs' => $this->lastElapsedMs,
        ];
    }

    /**
     * 按优先级降序稳定排序任务列表（同优先级保持提交顺序）。
     * Stably sorts a task list in descending priority order (submission order preserved within the same priority).
     *
     * @param list<array{task: callable, priority: int}> $tasks 待排序任务列表 Task list to sort.
     * @return list<array{task: callable, priority: int}> 排序后任务列表 Sorted task list.
     */
    private function sortByPriority(array $tasks): array
    {
        // 先按优先级分桶再展平：与 TaskQueue 相同的分桶风格，且天然稳定（桶内保持提交顺序） bucket by priority then flatten: same bucketing style as TaskQueue, naturally stable (submission order kept within a bucket)
        $buckets = [];
        foreach ($tasks as $item) {
            $buckets[$item['priority']][] = $item;
        }
        krsort($buckets);

        $sorted = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                $sorted[] = $item;
            }
        }

        return $sorted;
    }

    /**
     * 把任务列表保序放回分区队列队首（延后任务优先于执行期新入队任务）。
     * Re-queues a task list at the front of a region's queue, preserving order (deferred tasks run before tasks submitted during execution).
     *
     * @param string $name 分区名 Region name.
     * @param list<array{task: callable, priority: int}> $tasks 保序回队的任务列表 Task list to re-queue in order.
     */
    private function requeue(string $name, array $tasks): void
    {
        $this->regions[$name]['queue'] = array_merge($tasks, $this->regions[$name]['queue']);
    }
}
