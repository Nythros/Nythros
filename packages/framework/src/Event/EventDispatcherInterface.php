<?php

declare(strict_types=1);

namespace Nythros\Framework\Event;

/**
 * 应用级事件派发契约：同步即时派发，与引擎 EventBus 职责分层、并行存在。
 * Application-level event dispatch contract: synchronous immediate dispatch, layered apart from
 * and coexisting with the engine's EventBus.
 */
interface EventDispatcherInterface
{
    /**
     * 注册事件监听器。
     * Registers an event listener.
     *
     * @param string $event 事件名 Event name.
     * @param callable $listener 监听器，签名 fn(array $payload): void The listener, signature fn(array $payload): void.
     */
    public function listen(string $event, callable $listener): void;

    /**
     * 同步即时派发事件，携带可选负载。
     * Dispatches an event synchronously and immediately with optional payload.
     *
     * @param string $event 事件名 Event name.
     * @param array<string, mixed> $payload 事件负载 The event payload.
     */
    public function dispatch(string $event, array $payload = []): void;

    /**
     * 按 event 精确移除首个匹配监听器；未命中静默忽略。
     * Removes the first matching listener for the event; missing listeners are silently ignored.
     *
     * @param string $event 事件名 Event name.
     * @param callable $listener 要移除的监听器 The listener to remove.
     */
    public function removeListener(string $event, callable $listener): void;
}
