<?php

declare(strict_types=1);

namespace Nythros\Kernel;

use Nythros\Contracts\PerfSnapshotProviderInterface;

/**
 * 轻量运行期性能探针：进程内静态计数器/直方图累加器，零外部依赖（不触碰 Redis/网络）。
 * 设计目标（非侵入）：引擎核心只负责「记账」，采样与汇聚由业务层（demo 组装）按需接管——
 * 引擎包不依赖存储/网络，demo 层用 Redis 周期性读取探针并把快照写入 Redis（Hash + HLL）供观测。
 * 线程安全说明：Workerman 单进程单 worker（fork 后每 worker 独立进程），静态数组无并发写竞争；
 * 若未来走多线程事件循环需要加锁，当前模型不需要。
 *
 * 消费方式：引擎内记账走静态 API（increment/recordDuration/record）；采样管线经
 * PerfSnapshotProviderInterface 契约消费（instance() 取实例注入），静态 drain/snapshot
 * 保留为进程内直读便利入口（ADR-023 D2 解耦：framework 只依赖契约不直依本类）。
 *
 * Lightweight runtime performance probe: in-process static counters and histogram accumulators with zero
 * external dependencies (no Redis/network). Non-invasive by design: the engine core only books metrics, while
 * sampling/aggregation is taken over by the business layer (demo assembly) — the engine package depends on no
 * storage/network, and the demo layer polls the probe with Redis and persists snapshots (Hash + HLL) for
 * observation. Thread-safety note: Workerman runs one process per worker (forked, one process each), so the
 * static arrays face no concurrent write races; a multi-thread event loop would need locking, which the current
 * model does not.
 * Consumption: in-engine booking uses the static API (increment/recordDuration/record); sampling pipelines consume
 * through the PerfSnapshotProviderInterface contract (take instance() for injection), while static drain/snapshot
 * remain as convenience direct-read entries (ADR-023 D2 decoupling: the framework depends on the contract only,
 * never on this class directly).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class PerfProbe implements PerfSnapshotProviderInterface
{
    /** 帧耗时桶边界（毫秒）：0-0.5 / 0.5-1 / 1-2 / 2-4 / 4-8 / 8-16 / 16-32 / 32-64 / ≥64。 Frame-cost bucket edges in ms. */
    public const FRAME_BUCKETS_MS = [0.0, 0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 64.0];

    /** @var array<string, int> 计数器表：events => count。 Counter table: events => count. */
    private static array $counters = [];

    /** @var array<string, array<int, int>> 直方图表：metric => bucket => count。 Histogram table: metric => bucket => count. */
    private static array $histograms = [];

    /** @var array<string, float> 累计值表：metric => total。 Accumulator table: metric => total. */
    private static array $totals = [];

    /** 进程级单例：采样管线经契约消费时的实例入口（状态本就全局，实例仅为契约挂载点）。 Process-level singleton: the instance handle for contract-based consumption (state is global already; the instance is only the contract mount point). */
    private static ?self $instance = null;

    private function __construct()
    {
    }

    /** 契约消费入口：返回绑定全局静态状态的唯一实例。 Contract consumption entry: returns the sole instance bound to the global static state. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** 消费式读取（drain 语义）：取走并清零全部累计数据。 Consuming read (drain semantics): takes and resets all accumulated data. */
    public function collect(): array
    {
        return self::drain();
    }

    /** 只读快照：读取当前累计但不清零。 Read-only snapshot: reads current accumulation without clearing. */
    public function peek(): array
    {
        return self::snapshot();
    }

    /** 计数 +1。 Counts one occurrence. */
    public static function increment(string $event, int $by = 1): void
    {
        self::$counters[$event] = (self::$counters[$event] ?? 0) + $by;
    }

    /** 记录一次耗时（落入 bucket 并累加 total）。 Records one duration (fits the bucket and adds to the total). */
    public static function recordDuration(string $metric, float $milliseconds): void
    {
        $bucket = self::bucketOf($milliseconds);
        self::$histograms[$metric][$bucket] = (self::$histograms[$metric][$bucket] ?? 0) + 1;
        self::$totals[$metric] = (self::$totals[$metric] ?? 0.0) + $milliseconds;
    }

    /** 记录一个直方图值（不参与 total）。 Records a histogram value (not added to totals). */
    public static function record(string $metric, float $value): void
    {
        $bucket = self::bucketOf($value);
        self::$histograms[$metric][$bucket] = (self::$histograms[$metric][$bucket] ?? 0) + 1;
    }

    /**
     * 读取并清零全部数据（采样后取走；不清零则整个进程生命周期累计）。
     * Reads and resets all data (take after sampling; without a reset the data accumulates for the process lifetime).
     *
     * @return array{counters: array<string, int>, histograms: array<string, array<int, int>>, totals: array<string, float>}
     */
    public static function drain(): array
    {
        $snapshot = [
            'counters' => self::$counters,
            'histograms' => self::$histograms,
            'totals' => self::$totals,
        ];
        self::$counters = [];
        self::$histograms = [];
        self::$totals = [];

        return $snapshot;
    }

    /**
     * 读取快照但保留数据（供「跨周期累计」场景，如长时间漂移观测）。
     * Reads a snapshot without clearing (for cross-window accumulation).
     *
     * @return array{counters: array<string, int>, histograms: array<string, array<int, int>>, totals: array<string, float>}
     */
    public static function snapshot(): array
    {
        return [
            'counters' => self::$counters,
            'histograms' => self::$histograms,
            'totals' => self::$totals,
        ];
    }

    /** 毫秒值落入的桶下标（FRAME_BUCKETS_MS 的右开区间）。 Bucket index for a millisecond value (right-open intervals of FRAME_BUCKETS_MS). */
    private static function bucketOf(float $milliseconds): int
    {
        $index = 0;
        foreach (self::FRAME_BUCKETS_MS as $i => $bound) {
            if ($milliseconds >= $bound) {
                $index = $i;
            }
        }

        return $index;
    }
}
