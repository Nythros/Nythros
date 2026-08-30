<?php

declare(strict_types=1);

namespace Nythros\KernelWorkerman;

use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\TimerInterface;

/**
 * 基于 Workerman Timer 驱动的游戏时钟：周期性 tick 累积逻辑时间，供帧循环读取。
 * Workerman Timer-driven game clock: ticks periodically to accumulate logical time for the frame loop to read.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class WorkermanClock implements ClockInterface
{
    /** @var float 当前逻辑时间（秒） Current logical time in seconds. */
    private float $now = 0.0;

    /** @var float 上一 tick 到本 tick 的间隔（秒） Elapsed time between the previous and current tick in seconds. */
    private float $deltaTime = 0.0;

    /**
     * 构造时钟。
     * Constructs the clock.
     *
     * @param TimerInterface $timer 定时器实现（驱动 tick） Timer implementation (drives the tick).
     * @param float $tickIntervalSeconds tick 间隔（秒） Tick interval in seconds.
     */
    public function __construct(
        private readonly TimerInterface $timer,
        private readonly float $tickIntervalSeconds = 0.05,
    ) {
    }

    /**
     * 启动时钟：注册 persistent 定时器持续 tick。
     * Starts the clock: registers a persistent timer that keeps ticking.
     */
    public function start(): void
    {
        $this->timer->add($this->tickIntervalSeconds, fn () => $this->tick(), true);
    }

    /**
     * 推进一帧：更新当前时间并计算 deltaTime。
     * Advances one tick: updates the current time and computes deltaTime.
     */
    public function tick(): void
    {
        $next = microtime(true);
        // 首帧 deltaTime 置 0，避免冷启动时出现巨大间隔 first tick yields zero deltaTime to avoid a huge gap on cold start
        $this->deltaTime = $this->now > 0.0 ? $next - $this->now : 0.0;
        $this->now = $next;
    }

    /**
     * 读取当前逻辑时间。
     * Returns the current logical time.
     *
     * @return float Unix 时间戳（秒） Unix timestamp in seconds.
     */
    public function now(): float
    {
        return $this->now;
    }

    /**
     * 读取上一 tick 的间隔。
     * Returns the elapsed time of the last tick.
     *
     * @return float 间隔（秒） Elapsed time in seconds.
     */
    public function deltaTime(): float
    {
        return $this->deltaTime;
    }
}
