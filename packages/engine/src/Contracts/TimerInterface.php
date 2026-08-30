<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 定时器契约：基于秒的定时回调调度，支持持久（重复触发）与单次定时器。
 * Timer contract: second-based scheduling of callbacks, supporting persistent (repeating) and one-shot timers.
 */
interface TimerInterface
{
    /**
     * 添加定时器，到期时调用回调；持久定时器到期后自动重新调度，直至被 cancel。
     * Add a timer that invokes the callback when due; persistent timers reschedule themselves automatically until cancelled.
     *
     * @param float $intervalSeconds 定时间隔（秒） Timer interval in seconds.
     * @param callable $callback 到期回调 Callback invoked when the timer fires.
     * @param bool $persistent 是否持久重复触发 Whether the timer repeats persistently.
     * @return int 定时器 id，用于 cancel Timer id used for cancel.
     */
    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int;

    /**
     * 取消指定 id 的定时器；id 不存在时应静默忽略。
     * Cancel the timer with the given id; unknown ids should be silently ignored.
     *
     * @param int $timerId 定时器 id The timer id.
     */
    public function cancel(int $timerId): void;
}
