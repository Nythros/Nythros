<?php

declare(strict_types=1);

namespace Nythros\Event;

use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Kernel\PerfProbe;

/**
 * 简单事件总线，提供两条发布路径：
 * 1) publish：按事件名把 array 负载同步分发给订阅的监听器（按订阅顺序），不经队列；
 * 2) publishEnvelope：信封进入有界队列（maxQueueSize），flush 时按入队顺序按 type 分发，监听器收到 EventEnvelope 对象；
 *    队列已满时，可丢弃信封被丢弃并计数（getDroppedCount）；可靠信封（droppable=false）追加到队尾（允许临时超出上限）——
 *    既不丢失、也保持彼此入队顺序，且不会越过更早的可丢信封被提前派发（修复可靠事件插队导致的同帧乱序）；
 *    队列每帧 flush 清空，超出部分有界于单帧可靠事件数。
 * Simple event bus with two publishing paths:
 * 1) publish: synchronously dispatches an array payload by event name to subscribed listeners in subscription order, bypassing the queue;
 * 2) publishEnvelope: envelopes enter a bounded queue (maxQueueSize) and are dispatched by type in enqueue order on flush, with listeners
 *    receiving the EventEnvelope object; when the queue is full, droppable envelopes are dropped and counted (getDroppedCount) while
 *    reliable ones (droppable=false) are dispatched synchronously right away so they are never lost.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class SimpleEventBus implements EventBusInterface
{
    /** @var array<string, list<callable>> 按事件名组织的监听器表，每个事件对应按订阅顺序排列的监听器列表 Listeners grouped by event name; each event maps to a list of listeners in subscription order. */
    private array $listeners = [];

    /**
     * 待 flush 分发的信封队列（FIFO，按入队顺序）。
     * Envelope queue awaiting flush dispatch (FIFO, enqueue order).
     *
     * @var list<EventEnvelope>
     */
    private array $pending = [];

    /**
     * 因队列已满而被丢弃的可丢弃事件数量。
     * Number of droppable events dropped because the queue was full.
     */
    private int $droppedCount = 0;

    /**
     * 信封队列上限；达到上限后按丢弃策略处理新信封。
     * Envelope queue size limit; once reached, new envelopes follow the drop policy.
     */
    private int $maxQueueSize;

    /**
     * 构造事件总线并指定信封队列上限。
     * Constructs the event bus with an envelope queue size limit.
     *
     * @param int $maxQueueSize 信封队列最大长度 Maximum envelope queue length.
     */
    public function __construct(int $maxQueueSize = 10000)
    {
        $this->maxQueueSize = $maxQueueSize;
    }

    /**
     * 发布事件（同步路径）：立即调用该事件的全部监听器，监听器收到 array 负载；没有订阅者时为空操作。
     * 注意：本路径不经队列，与 publishEnvelope 的入队 + flush 路径互不影响。
     * Publishes an event (synchronous path): immediately invokes all of its listeners, which receive an array payload;
     * a no-op when there are no subscribers. Note: this path bypasses the queue and is independent of the publishEnvelope + flush path.
     *
     * @param string $event 事件名 Event name.
     * @param array<string, mixed> $payload 事件负载 Event payload.
     */
    public function publish(string $event, array $payload = []): void
    {
        // ?? [] 兜底未订阅的事件，保证 publish 是静默空操作而非报错 ?? [] covers events with no subscribers, keeping publish a silent no-op instead of an error
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    /**
     * 订阅事件：追加到该事件的监听器列表末尾，之后按订阅顺序被 publish 或 flush 调用。
     * Subscribes to an event: appends to the end of that event's listener list, then invoked by publish or flush in subscription order.
     *
     * @param string $event 事件名 Event name.
     * @param callable $listener 事件监听器；publish 路径接收 array 负载，publishEnvelope 路径接收 EventEnvelope 对象
     *                           Event listener; receives an array payload on the publish path or an EventEnvelope object on the publishEnvelope path.
     */
    public function subscribe(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * 发布事件信封（入队路径）：队列未满时入队等待 flush 分发；队列已满时，可丢弃信封被丢弃并计数，
     * 可靠信封（droppable=false）立即同步分发以保证不丢失。监听器在 flush（或可靠事件立即分发）时收到 EventEnvelope 对象。
     * Publishes an envelope (queued path): enqueues it for flush dispatch while the queue has room; when full, droppable
     * envelopes are dropped and counted, while reliable ones (droppable=false) are dispatched synchronously right away so
     * they are never lost. Listeners receive the EventEnvelope object on flush (or immediately for reliable events when full).
     *
     * 与 publish 的差异：publish 同步传 array 负载；本方法入队，flush 时按 type 分发并传 EventEnvelope 对象。
     * Difference from publish: publish passes an array payload synchronously, while this method queues and dispatches by type on flush with an EventEnvelope object.
     *
     * @param EventEnvelope $envelope 事件信封 Event envelope.
     */
    public function publishEnvelope(EventEnvelope $envelope): void
    {
        if (count($this->pending) < $this->maxQueueSize) {
            $this->pending[] = $envelope;

            return;
        }

        // 队列已满且信封可丢弃：丢弃并在丢弃点即时上报探针 Queue full and the envelope is droppable: drop it,
        // reporting to the probe right at the drop site.
        if ($envelope->droppable) {
            $this->droppedCount++;
            // 探针口径 = 丢弃点增量（每次丢弃 +1）：此前在 flush 时重加生命周期累计值，20 次/秒的 flush
            // 把「累计值 × flush 次数」灌进单调累加的 Redis 键，仪表速率虚高数个量级（3h soak 的
            // 151 亿丢弃即此伪影，真实量级见修正后复测）。getDroppedCount() 的生命周期语义不变。
            // Probe semantics = the per-drop delta (+1 at each drop): the previous flush-time re-add of the
            // lifetime cumulative pumped "cumulative × flush count" into the monotonically accumulated Redis
            // key (20 flushes/s), overstating the apparent rate by orders of magnitude — the 3h soak's
            // 15.1B "drops" were this artifact. getDroppedCount()'s lifetime meaning is unchanged.
            PerfProbe::increment('eventbus.dropped_total', 1);

            return;
        }

        // 队列已满但信封不可丢弃（可靠事件）：追加到队尾，允许临时超出 maxQueueSize——
        // 可靠事件不丢失、彼此严格保持入队顺序，也不会越过更早的可丢事件被提前派发（修复「可靠事件插队」乱序）；
        // 队列每帧 flush 清空，超出部分有界于单帧可靠事件数，不会无限增长。
        // Queue full but the envelope is not droppable (reliable): append to the tail, temporarily exceeding maxQueueSize —
        // reliable events are never lost, keep strict enqueue order among themselves, and never jump ahead of earlier
        // droppable events (fixes the reliable-jump-the-queue reordering); the queue is drained every frame, so the
        // overflow is bounded by one frame's reliable events and cannot grow unbounded.
        $this->pending[] = $envelope;
    }

    /**
     * 处理待派发事件：按入队顺序依次分发队列中的全部信封（按 type 找监听器，监听器收到 EventEnvelope 对象），
     * 分发后清空队列；flush 期间新入队的信封留给下一次 flush。
     * Processes pending events: dispatches every queued envelope in enqueue order (listeners are looked up by type and receive
     * the EventEnvelope object), then empties the queue; envelopes enqueued during flush are left for the next flush.
     */
    public function flush(): void
    {
        // 运行期探针：单次 flush 分发的信封数（批量吞吐采样） Runtime probe: envelopes dispatched per flush (batch-throughput sampling)
        // 先取出并清空队列，避免 flush 期间监听器新入队的信封被本次 flush 重复或嵌套处理 Take and clear the queue up front so envelopes enqueued by listeners during flush are not dispatched twice or nested by this flush
        $pending = $this->pending;
        $this->pending = [];
        PerfProbe::record('eventbus.batch', count($pending));
        PerfProbe::increment('eventbus.envelopes_dispatched', count($pending));
        // dropped_total 不在 flush 上报：它由 publishEnvelope 的丢弃点按增量上报（探针窗口语义），
        // 在这里重加生命周期累计会让导出速率随运行时长虚增（已修复的仪表缺陷）。
        // dropped_total is NOT reported here: publishEnvelope reports the per-drop delta at the drop site
        // (the probe's window semantics); re-adding the lifetime cumulative here made the exported rate grow
        // with uptime (the fixed instrumentation defect).

        foreach ($pending as $envelope) {
            foreach ($this->listeners[$envelope->type] ?? [] as $listener) {
                $listener($envelope);
            }
        }
    }

    /**
     * 返回因队列已满而被丢弃的事件数量。
     * Returns the number of events dropped because the queue was full.
     */
    public function getDroppedCount(): int
    {
        return $this->droppedCount;
    }
}
