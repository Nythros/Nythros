<?php

declare(strict_types=1);

namespace Nythros\Event\Tests;

use Nythros\Contracts\EventEnvelope;
use Nythros\Event\SimpleEventBus;
use Nythros\Kernel\PerfProbe;
use PHPUnit\Framework\TestCase;

/**
 * SimpleEventBusTest - 覆盖 SimpleEventBus 的订阅分发、无订阅者发布、信封队列与丢弃策略。
 * Tests covering SimpleEventBus subscriber dispatch, publishing without subscribers, envelope queueing and the drop policy.
 */
final class SimpleEventBusTest extends TestCase
{
    public function testPublishTriggersAllSubscribersWithPayload(): void
    {
        $bus = new SimpleEventBus();
        $received = [];

        $bus->subscribe('player.moved', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });
        $bus->subscribe('player.moved', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('player.moved', ['id' => 'player-1']);

        self::assertSame(
            [['id' => 'player-1'], ['id' => 'player-1']],
            $received,
        );
    }

    public function testPublishWithoutSubscribersDoesNotError(): void
    {
        $bus = new SimpleEventBus();

        $bus->publish('unknown.event', ['id' => 'player-1']);

        self::assertTrue(true);
    }

    public function testPublishEnvelopeQueuesUntilFlush(): void
    {
        $bus = new SimpleEventBus();
        $received = [];

        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static function (EventEnvelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $envelope = $this->makeEnvelope('player-1', EventEnvelope::TYPE_AOI_ENTER);
        $bus->publishEnvelope($envelope);

        self::assertSame([], $received, '入队后不得立即分发。Enqueueing must not dispatch immediately.');

        $bus->flush();

        self::assertCount(1, $received);
        self::assertSame($envelope, $received[0], 'flush 时监听器必须收到 EventEnvelope 对象。Listeners must receive the EventEnvelope object on flush.');
    }

    public function testFlushDispatchesInEnqueueOrder(): void
    {
        $bus = new SimpleEventBus();
        $order = [];

        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static function (EventEnvelope $envelope) use (&$order): void {
            $order[] = $envelope->source;
        });

        $bus->publishEnvelope($this->makeEnvelope('first', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('second', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('third', EventEnvelope::TYPE_AOI_ENTER));

        $bus->flush();

        self::assertSame(['first', 'second', 'third'], $order, '分发顺序必须等于入队顺序。Dispatch order must equal enqueue order.');
    }

    public function testFullQueueDropsDroppableEnvelopes(): void
    {
        $bus = new SimpleEventBus(maxQueueSize: 2);
        $received = [];

        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static function (EventEnvelope $envelope) use (&$received): void {
            $received[] = $envelope->source;
        });

        $bus->publishEnvelope($this->makeEnvelope('first', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('second', EventEnvelope::TYPE_AOI_ENTER));

        // 队列已满：可丢弃信封被丢弃并计数 Queue full: droppable envelopes are dropped and counted
        $bus->publishEnvelope($this->makeEnvelope('dropped', EventEnvelope::TYPE_AOI_ENTER));
        self::assertSame(1, $bus->getDroppedCount());

        $bus->publishEnvelope($this->makeEnvelope('also-dropped', EventEnvelope::TYPE_AOI_ENTER));
        self::assertSame(2, $bus->getDroppedCount());

        // 探针口径 = 丢弃点增量：丢 2 个信封，探针恰 +2，不随 flush 次数膨胀
        // （修复前的缺陷：flush 重加生命周期累计值，导出速率随运行时长虚增）
        // Probe semantics = the per-drop delta: 2 drops → exactly +2, never inflated by flush count
        // (the fixed defect: flush re-added the lifetime cumulative, inflating the exported rate with uptime).
        $probe = PerfProbe::instance();
        $probe->collect(); // 清空窗口（可能含此前用例的计数） Clear the window (prior cases may have counted).
        $bus->publishEnvelope($this->makeEnvelope('third-drop', EventEnvelope::TYPE_AOI_ENTER));
        $bus->flush();
        $bus->flush();
        $bus->flush();
        $counters = $probe->collect()['counters'];
        self::assertSame(1, $counters['eventbus.dropped_total'] ?? 0, '探针只记本次丢弃增量，多次 flush 不得重加。');

        $bus->flush();

        self::assertSame(['first', 'second'], $received, '被丢弃的信封不得被分发。Dropped envelopes must not be dispatched.');
    }

    public function testFullQueueAppendsReliableEnvelopeToTailWithoutJumping(): void
    {
        $bus = new SimpleEventBus(maxQueueSize: 2);
        $received = [];

        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static function (EventEnvelope $envelope) use (&$received): void {
            $received[] = $envelope->source;
        });

        $bus->publishEnvelope($this->makeEnvelope('first', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('second', EventEnvelope::TYPE_AOI_ENTER));

        // 队列已满：可靠信封不得立即分发，而是追加到队尾（临时超出上限），不越过更早的可丢事件
        // Queue full: the reliable envelope must not dispatch immediately; it appends to the tail (temporarily over cap), never jumping earlier droppable events
        $bus->publishEnvelope($this->makeEnvelope('reliable', EventEnvelope::TYPE_AOI_ENTER, droppable: false, reliable: true));

        self::assertSame([], $received, '可靠信封必须入队等待 flush，不得插队立即分发。The reliable envelope must queue for flush, never jumping the queue.');
        self::assertSame(0, $bus->getDroppedCount(), '可靠信封追加不产生丢弃。Appending the reliable envelope drops nothing.');

        $bus->flush();

        // FIFO 保持：reliable 位于队尾，先入队的 first/second 仍先派发
        // FIFO preserved: reliable sits at the tail; the earlier first/second dispatch first
        self::assertSame(['first', 'second', 'reliable'], $received);
    }

    public function testFullQueueKeepsReliableEnvelopeOrderWhenNoDroppableRemains(): void
    {
        $bus = new SimpleEventBus(maxQueueSize: 2);
        $received = [];

        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static function (EventEnvelope $envelope) use (&$received): void {
            $received[] = $envelope->source;
        });

        // 队满后可靠事件追加到队尾（临时超出上限）：既保持彼此入队顺序，也不越过更早的可丢事件
        // Once full, reliable events append to the tail (temporarily over cap): keeping both their relative order and never jumping earlier droppable events
        $bus->publishEnvelope($this->makeEnvelope('first', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('second', EventEnvelope::TYPE_AOI_ENTER));
        $bus->publishEnvelope($this->makeEnvelope('reliable-1', EventEnvelope::TYPE_AOI_ENTER, droppable: false, reliable: true));
        $bus->publishEnvelope($this->makeEnvelope('reliable-2', EventEnvelope::TYPE_AOI_ENTER, droppable: false, reliable: true));

        $bus->flush();

        self::assertSame(['first', 'second', 'reliable-1', 'reliable-2'], $received, '可靠事件彼此之间必须保持入队顺序且不越过更早的可丢事件。Reliable events must keep their relative order and never jump earlier droppable events.');
    }

    public function testFlushClearsQueue(): void
    {
        $bus = new SimpleEventBus();
        $calls = 0;

        $bus->subscribe(EventEnvelope::TYPE_AOI_LEAVE, static function (EventEnvelope $envelope) use (&$calls): void {
            $calls++;
        });

        $bus->publishEnvelope($this->makeEnvelope('player-1', EventEnvelope::TYPE_AOI_LEAVE));

        $bus->flush();
        $bus->flush();

        self::assertSame(1, $calls, '第二次 flush 不得重复分发。A second flush must not dispatch again.');
    }

    public function testPublishStaysSynchronousRegardlessOfQueueing(): void
    {
        $bus = new SimpleEventBus(maxQueueSize: 1);
        $received = [];

        $bus->subscribe('player.moved', static function (array $payload) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('player.moved', ['id' => 'player-1']);

        self::assertSame([['id' => 'player-1']], $received, 'publish 必须保持同步并传递 array 负载，不受队列化影响。Publish must stay synchronous and pass array payloads, unaffected by queueing.');
    }

    /**
     * 构造测试用事件信封。
     * Builds a test event envelope.
     *
     * @param string $source 来源标识 Source identifier.
     * @param string $type 事件类型 Event type.
     * @param bool $droppable 是否允许丢弃 Whether the envelope may be dropped.
     * @param bool $reliable 是否可靠投递 Whether delivery is reliable.
     */
    private function makeEnvelope(string $source, string $type, bool $droppable = true, bool $reliable = false): EventEnvelope
    {
        return new EventEnvelope(
            source: $source,
            type: $type,
            timestamp: 0.0,
            targetScope: null,
            reliable: $reliable,
            droppable: $droppable,
            payload: ['source' => $source],
        );
    }
}
