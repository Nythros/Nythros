<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\SchedulerInterface;
use Nythros\Contracts\WorldType;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * WorldTest - 覆盖 World 每帧更新顺序与注入依赖的 getter 契约。
 * Tests covering World's per-frame update ordering and injected dependency getters.
 */
final class WorldTest extends TestCase
{
    public function testUpdateRunsActorSystemThenAoiThenScheduler(): void
    {
        $order = [];

        $entity = $this->createStub(EntityInterface::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('all')->willReturn([$entity]);
        $entityManager->method('walk')->willReturn([$entity]);
        $entityManager->method('drainMoved')->willReturn([$entity]);

        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('updateAll')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'actor';
        });

        $aoi = $this->createStub(AOIProviderInterface::class);
        $aoi->method('updateEntity')->willReturnCallback(static function () use (&$order): array {
            $order[] = 'aoi';

            return ['entered' => [], 'left' => []];
        });

        $eventBus = $this->createStub(EventBusInterface::class);

        $scheduler = $this->createStub(SchedulerInterface::class);
        $scheduler->method('runFrame')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'scheduler';
        });

        $world = new World($entityManager, $actorSystem, $aoi, $eventBus, $scheduler);

        $world->update();

        self::assertSame(['actor', 'aoi', 'scheduler'], $order);
    }

    public function testUpdatePublishesAoiEnterAndLeaveEnvelopes(): void
    {
        $a = new BaseEntity('a', new Position(0, 0)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(0, 0)); // 0:0 cell 0:0

        $entityManager = new SimpleEntityManager();
        $entityManager->add($a);
        $entityManager->add($b);

        $aoi = new GridAOI(10);

        // EventBus 用 stub：publishEnvelope 不入队，仅捕获信封参数供断言 the EventBus is stubbed: publishEnvelope does not enqueue, it only captures envelopes for assertions
        $published = [];
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('publishEnvelope')->willReturnCallback(
            static function (EventEnvelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        );

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            $aoi,
            $eventBus,
            $this->createStub(SchedulerInterface::class),
        );

        // 第一次 update 建立视野：A 登记（无邻居）无事件；B 登记时发现 A，产生一对双向初始 enter 信封；清空后开始正式断言
        // the first update establishes visibility: A registers with no neighbors (no events); when B registers it discovers A, producing one pair of bidirectional initial enter envelopes; clear them before the real assertions
        $world->update();
        self::assertCount(2, $published);
        self::assertSame('b', $published[0]->source); // 邻居视角：B 进入 A 的视野 neighbor view: B entered A's view
        self::assertSame('a', $published[0]->targetScope);
        self::assertSame('a', $published[1]->source); // 自身视角：A 进入 B 的视野 self view: A entered B's view
        self::assertSame('b', $published[1]->targetScope);
        $published = [];

        // 同格移动：(1,0) 仍在 0:0，走 AOI fast path，不发布任何 enter/leave 事件 same-cell move: (1,0) stays in 0:0, hitting the AOI fast path, so no enter/leave events are published
        $a->move(1, 0);
        $world->update();
        self::assertSame([], $published);

        // 跨格移动：(25,0) → 2:0，跨出 B（0:0）的九宫格（cx-1..cx+1），发布一对双向 leave 事件：
        // 邻居视角（source=a 通知 B「A 离开你的视野」）+ 自身视角（source=b 通知 A「B 离开你的视野」）
        // cross-cell move: (25,0) lands in 2:0, outside B's (0:0) 3x3 neighborhood (cx-1..cx+1), publishing one pair of bidirectional leave events:
        // neighbor view (source=a notifies B "A left your view") + self view (source=b notifies A "B left your view")
        $a->move(24, 0);
        $world->update();

        self::assertCount(2, $published);
        $leaveNeighbor = $published[0];
        self::assertInstanceOf(EventEnvelope::class, $leaveNeighbor);
        self::assertSame(EventEnvelope::TYPE_AOI_LEAVE, $leaveNeighbor->type);
        self::assertSame('a', $leaveNeighbor->source);
        self::assertSame('b', $leaveNeighbor->targetScope);
        self::assertFalse($leaveNeighbor->reliable);
        self::assertTrue($leaveNeighbor->droppable);
        self::assertSame(['x' => 25, 'y' => 0], $leaveNeighbor->payload['position']);

        $leaveSelf = $published[1];
        self::assertInstanceOf(EventEnvelope::class, $leaveSelf);
        self::assertSame(EventEnvelope::TYPE_AOI_LEAVE, $leaveSelf->type);
        self::assertSame('b', $leaveSelf->source);
        self::assertSame('a', $leaveSelf->targetScope);
        self::assertSame(['x' => 0, 'y' => 0], $leaveSelf->payload['position']);

        // 对称性：两个方向 targetScope 互补、source 互补（A 离开 B 视野 ⟺ B 离开 A 视野）
        // symmetry: the two envelopes have complementary targetScope and complementary source (A left B's view ⟺ B left A's view)
        self::assertSame([$leaveNeighbor->targetScope, $leaveSelf->targetScope], ['b', 'a']);
        self::assertSame([$leaveNeighbor->source, $leaveSelf->source], ['a', 'b']);
        $published = [];

        // 移回 (1,0) → 0:0，B 重新进入 A 的九宫格，发布一对双向 enter 事件（邻居视角 + 自身视角）
        // moving back to (1,0), cell 0:0, brings B back into A's neighborhood, publishing one pair of bidirectional enter events (neighbor view + self view)
        $a->move(-24, 0);
        $world->update();

        self::assertCount(2, $published);
        $enterNeighbor = $published[0];
        self::assertInstanceOf(EventEnvelope::class, $enterNeighbor);
        self::assertSame(EventEnvelope::TYPE_AOI_ENTER, $enterNeighbor->type);
        self::assertSame('a', $enterNeighbor->source);
        self::assertSame('b', $enterNeighbor->targetScope);
        self::assertFalse($enterNeighbor->reliable);
        self::assertTrue($enterNeighbor->droppable);
        self::assertSame(['x' => 1, 'y' => 0], $enterNeighbor->payload['position']);

        $enterSelf = $published[1];
        self::assertInstanceOf(EventEnvelope::class, $enterSelf);
        self::assertSame(EventEnvelope::TYPE_AOI_ENTER, $enterSelf->type);
        self::assertSame('b', $enterSelf->source);
        self::assertSame('a', $enterSelf->targetScope);
        self::assertSame(['x' => 0, 'y' => 0], $enterSelf->payload['position']);

        // 对称性：A 进入 B 视野 ⟺ B 进入 A 视野（targetScope 互补、source 互补）
        // symmetry: A entered B's view ⟺ B entered A's view (complementary targetScope and source)
        self::assertSame([$enterNeighbor->targetScope, $enterSelf->targetScope], ['b', 'a']);
        self::assertSame([$enterNeighbor->source, $enterSelf->source], ['a', 'b']);
    }

    public function testInjectedClockControlsEnvelopeTimestamp(): void
    {
        $a = new BaseEntity('a', new Position(0, 0)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(0, 0)); // 0:0 cell 0:0

        $entityManager = new SimpleEntityManager();
        $entityManager->add($a);
        $entityManager->add($b);

        $aoi = new GridAOI(10);

        $published = [];
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('publishEnvelope')->willReturnCallback(
            static function (EventEnvelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        );

        // 注入假时钟：envelope timestamp 必须取自注入源而非真实 microtime
        // Fake clock injected: envelope timestamps must come from the injected source, never the real microtime
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            $aoi,
            $eventBus,
            $this->createStub(SchedulerInterface::class),
            clock: $clock,
        );

        // 首次 update 产生一对双向初始 enter 信封，timestamp 均为注入时钟值
        // The first update produces one pair of bidirectional initial enter envelopes, both stamped with the injected clock value
        $world->update();
        self::assertCount(2, $published);
        self::assertSame(1000.0, $published[0]->timestamp);
        self::assertSame(1000.0, $published[1]->timestamp);

        // 推进注入时钟后跨格移动产生一对 leave 信封，timestamp 跟随注入源
        // After advancing the injected clock, a cross-cell move produces one pair of leave envelopes whose timestamps follow the injected source
        $now = 2000.5;
        $published = [];
        $a->move(24, 0);
        $world->update();

        self::assertCount(2, $published);
        self::assertSame(EventEnvelope::TYPE_AOI_LEAVE, $published[0]->type);
        self::assertSame(2000.5, $published[0]->timestamp);
        self::assertSame(2000.5, $published[1]->timestamp);
    }

    public function testClockIsCalledConstantTimesPerFrame(): void
    {
        // 时钟风暴修复：时间戳在帧首采样一次传入全部信封——无论本帧产生多少邻居对信封，
        // 时钟调用次数恒定（帧首采样 1 次 + 帧末探针 1 次 = 2 次），不再随 M×K 邻居对线性增长。
        // Clock-storm fix: the timestamp is sampled once at frame start and passed to every envelope — no matter
        // how many neighbor-pair envelopes a frame produces, clock calls stay constant (frame-start sample +
        // frame-end probe = 2), no longer scaling with M×K neighbor pairs.
        $a = new BaseEntity('a', new Position(0, 0));
        $b = new BaseEntity('b', new Position(0, 0));

        $entityManager = new SimpleEntityManager();
        $entityManager->add($a);
        $entityManager->add($b);

        $published = [];
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('publishEnvelope')->willReturnCallback(
            static function (EventEnvelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        );

        $calls = 0;
        $clock = static function () use (&$calls): float {
            $calls++;

            return 1000.0;
        };

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            new GridAOI(10),
            $eventBus,
            $this->createStub(SchedulerInterface::class),
            clock: $clock,
        );

        // 登记帧产生一对双向 enter 信封（2 个信封）：时钟仍只调 2 次
        // The registration frame produces one pair of bidirectional enter envelopes (2 envelopes): still exactly 2 clock calls
        $world->update();
        self::assertCount(2, $published);
        self::assertSame(2, $calls);
        self::assertSame(1000.0, $published[0]->timestamp);
        self::assertSame(1000.0, $published[1]->timestamp);

        // 跨格移动再产生一对 leave 信封：时钟次数只增加固定的 2 次
        // A cross-cell move produces one pair of leave envelopes: the count grows by a fixed 2 again
        $calls = 0;
        $published = [];
        $a->move(24, 0);
        $world->update();
        self::assertCount(2, $published);
        self::assertSame(2, $calls);
    }

    public function testGettersReturnInjectedInstances(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $aoi = $this->createStub(AOIProviderInterface::class);
        $eventBus = $this->createStub(EventBusInterface::class);
        $scheduler = $this->createStub(SchedulerInterface::class);

        $world = new World($entityManager, $actorSystem, $aoi, $eventBus, $scheduler);

        self::assertSame($entityManager, $world->getEntityManager());
        self::assertSame($actorSystem, $world->getActorSystem());
        self::assertSame($aoi, $world->getAOI());
        self::assertSame($eventBus, $world->getEventBus());
        self::assertSame($scheduler, $world->getScheduler());
    }

    public function testFullBroadcastWorldSkipsAOISweep(): void
    {
        // 全量广播型 World：AOI 恒非空（注入 UniversalAOI/任意提供者）；update() 必须跳过 AOI 差分（无空间索引，差分无意义）
        // A full-broadcast World: the AOI is always present (a UniversalAOI or any provider is injected);
        // update() must skip the AOI diff sweep (no spatial index, so the diff carries no meaning)
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $aoiCalled = false;
        $aoi = $this->createStub(AOIProviderInterface::class);
        $aoi->method('updateEntity')->willReturnCallback(static function () use (&$aoiCalled): array {
            $aoiCalled = true;

            return ['entered' => [], 'left' => []];
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('all')->willReturn([$this->createStub(EntityInterface::class)]);
        $entityManager->method('walk')->willReturn([$this->createStub(EntityInterface::class)]);
        $eventBus = $this->createStub(EventBusInterface::class);
        $scheduler = $this->createStub(SchedulerInterface::class);

        $world = new World($entityManager, $actorSystem, $aoi, $eventBus, $scheduler, WorldType::FULL_BROADCAST);

        self::assertSame(WorldType::FULL_BROADCAST, $world->getType());
        self::assertSame($aoi, $world->getAOI(), '全量广播型 World 的 getAOI() 必须返回注入的 AOI（而非 null）。');

        $world->update();

        self::assertFalse($aoiCalled, "全量型 World 的 update() 不得调用 AOI 差分。A full-broadcast World's update() must not call the AOI diff.");
    }
}
