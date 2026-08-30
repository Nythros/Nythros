<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 时钟契约：逻辑帧的时间基准，通过 tick 推进时间并暴露当前时间。
 * Clock contract: the time base for logic frames, advanced by tick and exposing the current time.
 */
interface ClockInterface
{
    /**
     * 推进一次时钟节拍：采样最新时间并计算本帧间隔。
     * Advance the clock by one tick: sample the latest time and compute the frame delta.
     */
    public function tick(): void;

    /**
     * 获取最近一次 tick 采样的时间；未 tick 过时的返回值由实现约定（通常为 0）。
     * Get the time sampled at the latest tick; the value before any tick is implementation-defined (usually 0).
     *
     * @return float 当前时钟时间（秒） Current clock time in seconds.
     */
    public function now(): float;

    /**
     * 获取最近一次 tick 的帧间隔（秒）；未 tick 过时为 0。
     * Get the frame delta of the latest tick in seconds; 0 before any tick.
     *
     * @return float 帧间隔（秒） Frame delta in seconds.
     */
    public function deltaTime(): float;
}
