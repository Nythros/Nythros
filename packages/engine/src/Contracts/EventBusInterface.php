<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 事件总线契约：事件发布与订阅的解耦通道，实现方负责把事件同步分发给全部订阅者。
 * Event bus contract: a decoupled channel for publishing and subscribing to events; implementations dispatch each event to all of its subscribers synchronously.
 */
interface EventBusInterface
{
    /**
     * 发布一个事件，携带可选载荷；发布时所有已订阅该事件的监听器都会收到通知。
     * Publish an event with an optional payload; every listener currently subscribed to the event is notified when it is published.
     *
     * @param string $event 事件名 Event name.
     * @param array<string, mixed> $payload 事件载荷（键值对） Event payload (key-value pairs).
     */
    public function publish(string $event, array $payload = []): void;

    /**
     * 订阅一个事件；同一监听器重复订阅的行为由实现约定（通常去重或后注册覆盖）。
     * Subscribe to an event; duplicate subscription of the same listener is implementation-defined (usually deduplicated or overwritten by the latest registration).
     *
     * @param string $event 事件名 Event name.
     * @param callable $listener 监听回调，由实现约定回调参数（通常为事件名与载荷） Listener callback whose arguments are implementation-defined (typically the event name and payload).
     */
    public function subscribe(string $event, callable $listener): void;

    /**
     * 发布一个事件信封；派发时机由实现约定——可入队待 flush 处理，也可同步分发。
     * Publish an event envelope; dispatch timing is implementation-defined — it may be queued until flush or dispatched synchronously.
     *
     * @param EventEnvelope $envelope 事件信封 Event envelope.
     */
    public function publishEnvelope(EventEnvelope $envelope): void;

    /**
     * 处理所有待派发（pending）的事件信封；对同步派发实现可为空操作。
     * Process all pending event envelopes; a no-op for implementations that dispatch synchronously.
     */
    public function flush(): void;
}
