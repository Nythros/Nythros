<?php

declare(strict_types=1);

namespace Nythros\Framework\Event;

/**
 * 同步即时事件派发器：按事件名维护监听器列表，dispatch 立即逐条调用。
 * Synchronous immediate event dispatcher: maintains a per-event listener list, invoking each
 * listener immediately on dispatch.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<string, list<callable>> event => 监听器列表 event => listener list
     */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    public function removeListener(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }
        $listeners = $this->listeners[$event];
        foreach ($listeners as $index => $registered) {
            if ($registered === $listener) {
                unset($listeners[$index]);
                $this->listeners[$event] = array_values($listeners);
                return;
            }
        }
    }
}
