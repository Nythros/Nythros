<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 性能快照供给者契约：向采样管线暴露进程内性能指标（计数器/直方图/累计值）的只读与消费式读取。
 * 解耦依据（ADR-023 D2）：引擎内部探针为非公开实现细节，framework 采样器只依赖本契约，
 * 具体实现由组装层注入。快照结构三键固定：counters（事件计数）、histograms（metric => bucket => count）、
 * totals（metric => 累计值，如毫秒）。
 * Performance snapshot provider contract: exposes in-process performance metrics (counters/histograms/totals)
 * to sampling pipelines via read-only and consuming reads. Rationale (ADR-023 D2): the engine-internal probe is a
 * non-public implementation detail; the framework sampler depends only on this contract, with the concrete
 * implementation injected by the assembly layer. The snapshot shape has three fixed keys: counters (event counts),
 * histograms (metric => bucket => count), and totals (metric => accumulated value, e.g. milliseconds).
 */
interface PerfSnapshotProviderInterface
{
    /**
     * 消费式读取：取走并清零全部累计数据（采样窗口语义：每次调用后各表归零重新累计）。
     * Consuming read: takes and resets all accumulated data (sampling-window semantics: every call
     * zeroes each table so accumulation restarts).
     *
     * @return array{counters: array<string, int>, histograms: array<string, array<int, int>>, totals: array<string, float>} 本窗口累计快照 The accumulated snapshot of this window.
     */
    public function collect(): array;

    /**
     * 只读快照：读取当前全部累计但不清零（跨周期累计观测，如长时间漂移分析）。
     * Read-only snapshot: reads all current accumulation without clearing (for cross-window observation,
     * e.g. long-horizon drift analysis).
     *
     * @return array{counters: array<string, int>, histograms: array<string, array<int, int>>, totals: array<string, float>} 进程生命周期累计快照 The process-lifetime accumulated snapshot.
     */
    public function peek(): array;
}
