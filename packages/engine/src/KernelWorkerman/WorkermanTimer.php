<?php

declare(strict_types=1);

namespace Nythros\KernelWorkerman;

use Nythros\Contracts\TimerInterface;
use Workerman\Timer;

/**
 * TimerInterface 的 Workerman 适配器：把引擎统一定时器抽象桥接到 Workerman\Timer。
 * Workerman adapter for TimerInterface: bridges the engine's unified timer abstraction to Workerman\Timer.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class WorkermanTimer implements TimerInterface
{
    /**
     * 注册定时器（透传 Workerman\Timer::add）。
     * Registers a timer (delegates to Workerman\Timer::add).
     *
     * @param float $intervalSeconds 触发间隔（秒） Trigger interval in seconds.
     * @param callable $callback 定时回调 Timer callback.
     * @param bool $persistent true 表示周期触发；false 表示仅触发一次 true for recurring; false for one-shot.
     * @return int 定时器 ID（用于 cancel） Timer ID (used for cancel).
     */
    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        return Timer::add($intervalSeconds, $callback, [], $persistent);
    }

    /**
     * 取消定时器（透传 Workerman\Timer::del）。
     * Cancels a timer (delegates to Workerman\Timer::del).
     *
     * @param int $timerId 由 add 返回的定时器 ID Timer ID returned by add.
     */
    public function cancel(int $timerId): void
    {
        Timer::del($timerId);
    }
}
