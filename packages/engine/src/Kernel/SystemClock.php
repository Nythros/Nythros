<?php

declare(strict_types=1);

namespace Nythros\Kernel;

use Nythros\Contracts\ClockInterface;

/**
 * 系统时钟：基于 microtime 的逻辑帧时钟，跟踪当前时间与帧间隔。
 * System clock: a logic-frame clock based on microtime, tracking the current time and the frame delta.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SystemClock implements ClockInterface
{
    /**
     * 最近一次 tick 采样的时间戳（秒，浮点精度）。
     * Timestamp sampled at the latest tick (seconds, float precision).
     */
    private float $now = 0.0;

    /**
     * 本帧与上一帧的时间差（秒）；首次 tick 前为 0。
     * Delta between the current and previous frame (seconds); 0 before the first tick.
     */
    private float $deltaTime = 0.0;

    public function tick(): void
    {
        // 采样当前高精度时间作为新帧时间 Sample the current high-resolution time as the new frame time
        $next = microtime(true);
        // 首次 tick 无法计算增量，强制为 0，避免产生巨大的首帧间隔 The first tick cannot compute a delta, so force it to 0 to avoid a huge first-frame gap
        $this->deltaTime = $this->now > 0.0 ? $next - $this->now : 0.0;
        $this->now = $next;
    }

    /**
     * 获取最近一次 tick 采样的时间。
     * Get the time sampled at the latest tick.
     *
     * @return float 当前时钟时间（秒） Current clock time in seconds.
     */
    public function now(): float
    {
        return $this->now;
    }

    /**
     * 获取最近一次 tick 的帧间隔；尚未 tick 过时为 0。
     * Get the frame delta of the latest tick; 0 if no tick has occurred yet.
     *
     * @return float 帧间隔（秒） Frame delta in seconds.
     */
    public function deltaTime(): float
    {
        return $this->deltaTime;
    }
}
