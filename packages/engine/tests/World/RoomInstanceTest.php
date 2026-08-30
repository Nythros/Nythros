<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomState;
use Nythros\Contracts\WorldType;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Scheduler\TickScheduler;
use Nythros\World\RoomInstance;
use PHPUnit\Framework\TestCase;

/**
 * RoomInstanceTest - 覆盖房间聚合的生命周期状态机（Created/Running/Settled/Closed）、
 * join/leave 双向通知、独立子系统装配、共享宿主 EventBus、固定帧序与边界矩阵条款。
 * RoomInstanceTest - covers the room aggregate's lifecycle state machine (Created/Running/Settled/Closed),
 * join/leave bidirectional notifications, independent subsystem assembly, the shared host EventBus,
 * the fixed frame order, and the boundary-matrix clauses.
 */
final class RoomInstanceTest extends TestCase
{
    public function testInitialStateIsCreatedWithConfiguredId(): void
    {
        $room = $this->createRoom();

        self::assertSame('room-1', $room->getRoomId());
        self::assertSame(RoomState::Created, $room->getState());
    }

    public function testJoinActivatesRoomAndRegistersEntityAndActor(): void
    {
        $room = $this->createRoom();
        $entity = new BaseEntity('p1', new Position(1, 1));
        $actor = new CountingActor();

        self::assertTrue($room->join($entity, $actor));
        self::assertSame(RoomState::Running, $room->getState());
        self::assertSame($entity, $room->getEntityManager()->get('p1'));

        // EM.add 即 markMoved：首帧必进 AOI 索引 EM.add marks moved: the first frame always enters the AOI index
        self::assertTrue($entity->consumeMoved());

        // Actor 已注册：一帧更新驱动一次 the actor is registered: one update drives it once
        $room->update();
        self::assertSame(1, $actor->updates);
    }

    public function testJoinExistingMemberReturnsFalse(): void
    {
        $room = $this->createRoom();
        $entity = new BaseEntity('p1', new Position(1, 1));

        self::assertTrue($room->join($entity));
        self::assertFalse($room->join($entity));
        self::assertSame(RoomState::Running, $room->getState());
    }

    public function testJoinNotifiesExistingMembersAndJoinerBidirectionally(): void
    {
        $bus = new SimpleEventBus();
        $enterBox = new EnvelopeBox();
        $snapshotBox = new EnvelopeBox();
        $bus->subscribe(RoomInstanceInterface::EVENT_MEMBER_ENTER, static fn (EventEnvelope $e): bool => $enterBox->push($e));
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_SNAPSHOT, static fn (EventEnvelope $e): bool => $snapshotBox->push($e));

        $room = $this->createRoom(eventBus: $bus);
        $first = new BaseEntity('m1', new Position(1, 1));
        $second = new BaseEntity('p2', new Position(5, 5));

        $room->join($first);
        $room->join($second);

        // 信封只入队，flush 由上层帧末触发 envelopes are only enqueued; the upper layer flushes at frame end
        $bus->flush();

        // 既有成员 m1 收到 p2 的 member_enter the existing member m1 receives p2's member_enter
        self::assertCount(1, $enterBox->envelopes);
        self::assertSame('p2', $enterBox->envelopes[0]->source);
        self::assertSame('m1', $enterBox->envelopes[0]->targetScope);
        self::assertSame(['x' => 5, 'y' => 5], $enterBox->envelopes[0]->payload['position']);

        // 进入者 p2 收到房间快照（含 m1）；首个成员 m1 也收到空房间快照（每次加入均发快照）
        // the joiner p2 receives the room snapshot (containing m1); the first member m1 also received an empty-room snapshot (every join gets one)
        self::assertCount(2, $snapshotBox->envelopes);
        $m1Snapshot = $snapshotBox->envelopes[0];
        self::assertSame('m1', $m1Snapshot->targetScope);
        self::assertSame([], $m1Snapshot->payload['members']);

        $p2Snapshot = $snapshotBox->envelopes[1];
        self::assertSame('room-1', $p2Snapshot->source);
        self::assertSame('p2', $p2Snapshot->targetScope);
        self::assertSame([['id' => 'm1', 'position' => ['x' => 1, 'y' => 1]]], $p2Snapshot->payload['members']);
    }

    public function testJoinRejectsWhenFull(): void
    {
        $room = $this->createRoom(maxMembers: 1);

        self::assertTrue($room->join(new BaseEntity('p1', new Position(1, 1))));
        self::assertFalse($room->join(new BaseEntity('p2', new Position(2, 2))), '满员后加入应返回 false joining a full room must return false');
        self::assertNull($room->getEntityManager()->get('p2'));
    }

    public function testJoinAfterSettleThrows(): void
    {
        $room = $this->createRoom();
        $room->join(new BaseEntity('p1', new Position(1, 1)));
        $room->settle();

        $this->expectException(\InvalidArgumentException::class);

        $room->join(new BaseEntity('p2', new Position(2, 2)));
    }

    public function testJoinAfterCloseThrows(): void
    {
        $room = $this->createRoom();
        $room->settle();
        $room->close();

        $this->expectException(\InvalidArgumentException::class);

        $room->join(new BaseEntity('p2', new Position(2, 2)));
    }

    public function testLeaveRemovesFromEmAoiAndActorSystem(): void
    {
        $room = $this->createRoom();
        $entity = new BaseEntity('p1', new Position(1, 1));
        $actor = new CountingActor();
        $room->join($entity, $actor);

        self::assertTrue($room->leave('p1'));
        self::assertNull($room->getEntityManager()->get('p1'));
        self::assertSame([], $room->getAOI()->queryShape(new CircleShape(1, 1, 50)), '离开后不得再被形状查询命中 the departed entity must no longer match shape queries');

        $actor->updates = 0;
        $room->update();
        self::assertSame(0, $actor->updates, '离开后 Actor 不再被驱动 the departed actor is no longer driven');

        self::assertFalse($room->leave('p1'), '非成员离开返回 false leaving a non-member returns false');
    }

    public function testLeaveNotifiesRemainingAndDeparted(): void
    {
        $bus = new SimpleEventBus();
        $leaveBox = new EnvelopeBox();
        $leftBox = new EnvelopeBox();
        $bus->subscribe(RoomInstanceInterface::EVENT_MEMBER_LEAVE, static fn (EventEnvelope $e): bool => $leaveBox->push($e));
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_LEFT, static fn (EventEnvelope $e): bool => $leftBox->push($e));

        $room = $this->createRoom(eventBus: $bus);
        $room->join(new BaseEntity('m1', new Position(1, 1)));
        $room->join(new BaseEntity('p2', new Position(5, 5)));

        $room->leave('p2');

        $bus->flush();

        // 留守方收到 member_leave the remaining member receives member_leave
        self::assertCount(1, $leaveBox->envelopes);
        self::assertSame('p2', $leaveBox->envelopes[0]->source);
        self::assertSame('m1', $leaveBox->envelopes[0]->targetScope);

        // 当事方收到 room.left 回执 the departing party receives the room.left receipt
        self::assertCount(1, $leftBox->envelopes);
        self::assertSame('room-1', $leftBox->envelopes[0]->source);
        self::assertSame('p2', $leftBox->envelopes[0]->targetScope);
    }

    /**
     * 固定帧序：actor updateAll → drainMoved + AOI 差分（双向信封入共享宿主总线）→ scheduler runFrame。
     * Fixed frame order: actor updateAll → drainMoved + AOI diff (bidirectional envelopes into the shared host bus) → scheduler runFrame.
     */
    public function testUpdateRunsFixedFrameOrderAndPublishesVisionDiffsToSharedBus(): void
    {
        $bus = new SimpleEventBus();
        $enterBox = new EnvelopeBox();
        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static fn (EventEnvelope $e): bool => $enterBox->push($e));

        $room = $this->createRoom(eventBus: $bus);
        $taskRan = false;
        $room->getScheduler()->addTask(static function () use (&$taskRan): void {
            $taskRan = true;
        });

        $a = new BaseEntity('a', new Position(1, 1));
        $b = new BaseEntity('b', new Position(2, 2));
        $room->join($a);
        $room->join($b);

        $room->update();

        // 视野差分信封发布到共享宿主总线（先刷后发，flush 由上层触发） vision diff envelopes land on the shared host bus (publish-only; the upper layer flushes)
        $bus->flush();
        $pairs = array_map(
            static fn (EventEnvelope $e): string => $e->source . '>' . $e->targetScope,
            $enterBox->envelopes,
        );
        self::assertContains('a>b', $pairs);
        self::assertContains('b>a', $pairs);
        self::assertTrue($taskRan, '帧内调度任务应被执行 the frame scheduler task must have run');

        // 同帧实体已互见且 moved 已消费：再更新不产生重复进入事件 both entities already see each other and moved flags were consumed: no duplicate enters on the next update
        $count = count($enterBox->envelopes);
        $room->update();
        self::assertCount($count, $enterBox->envelopes);
    }

    public function testUpdateSkipsAoiDiffSweepForFullBroadcastRooms(): void
    {
        $bus = new SimpleEventBus();
        $enterBox = new EnvelopeBox();
        $bus->subscribe(EventEnvelope::TYPE_AOI_ENTER, static fn (EventEnvelope $e): bool => $enterBox->push($e));

        $room = $this->createRoom(eventBus: $bus, aoiFactory: static fn (EntityManagerInterface $em): UniversalAOI => new UniversalAOI($em));
        $room->join(new BaseEntity('a', new Position(1, 1)));
        $room->join(new BaseEntity('b', new Position(2, 2)));

        $room->update();
        $bus->flush();

        // 全量广播型房间无视野差分 full-broadcast rooms have no vision deltas
        self::assertSame([], $enterBox->envelopes);
        self::assertSame(WorldType::FULL_BROADCAST, $room->getType());
    }

    public function testGetTypeDerivedFromAoiImplementation(): void
    {
        self::assertSame(WorldType::AOI, $this->createRoom()->getType());
    }

    public function testFacadeAccessorsAreRoomOwnedAndBusShared(): void
    {
        $bus = new SimpleEventBus();
        $roomA = $this->createRoom(eventBus: $bus);
        $roomB = $this->createRoom(eventBus: $bus);

        // 子系统每房独立（不共享实例） subsystems are per-room (instances never shared)
        self::assertNotSame($roomA->getEntityManager(), $roomB->getEntityManager());
        self::assertNotSame($roomA->getAOI(), $roomB->getAOI());
        self::assertNotSame($roomA->getActorSystem(), $roomB->getActorSystem());
        self::assertInstanceOf(TickScheduler::class, $roomA->getScheduler());

        // EventBus 共享宿主注入 the EventBus is the injected shared host bus
        self::assertSame($bus, $roomA->getEventBus());
        self::assertSame($bus, $roomB->getEventBus());
    }

    public function testSettleTransitionsAndNotifiesSurvivors(): void
    {
        $bus = new SimpleEventBus();
        $closedBox = new EnvelopeBox();
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_CLOSED, static fn (EventEnvelope $e): bool => $closedBox->push($e));

        $room = $this->createRoom(eventBus: $bus);
        $room->join(new BaseEntity('m1', new Position(1, 1)));
        $room->join(new BaseEntity('m2', new Position(2, 2)));

        $room->settle();

        self::assertSame(RoomState::Settled, $room->getState());

        // 信封只入队，flush 后断言 envelopes are only enqueued; flush then assert
        $bus->flush();

        // 存活成员各收一封 room.closed 信封 every surviving member receives one room.closed envelope
        $targets = array_map(static fn (EventEnvelope $e): string => (string) $e->targetScope, $closedBox->envelopes);
        sort($targets);
        self::assertSame(['m1', 'm2'], $targets);
        self::assertSame('room-1', $closedBox->envelopes[0]->source);
    }

    public function testSettleTwiceThrows(): void
    {
        $room = $this->createRoom();
        $room->settle();

        $this->expectException(\LogicException::class);

        $room->settle();
    }

    public function testSettleEmptyCreatedRoomIsSilent(): void
    {
        $bus = new SimpleEventBus();
        $closedBox = new EnvelopeBox();
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_CLOSED, static fn (EventEnvelope $e): bool => $closedBox->push($e));

        $room = $this->createRoom(eventBus: $bus);
        $room->settle();

        // 从未开房的空房间静默结算：无存活成员、无信封 an never-opened empty room settles silently: no survivors, no envelopes
        self::assertSame(RoomState::Settled, $room->getState());
        self::assertSame([], $closedBox->envelopes);
    }

    public function testCloseClearsMembersAndIndexes(): void
    {
        $room = $this->createRoom();
        $entity = new BaseEntity('p1', new Position(1, 1));
        $actor = new CountingActor();
        $room->join($entity, $actor);

        $room->settle();
        $room->close();

        self::assertSame(RoomState::Closed, $room->getState());
        self::assertNull($room->getEntityManager()->get('p1'), 'close 清空成员 close clears members');
        self::assertSame([], $room->getEntityManager()->all());
        // r=300：queryShape 覆盖格阈值（4096）内的合法大形状，足以验证 close 清空索引
        // r=300: a legitimate large shape within queryShape's covered-cell cap (4096), sufficient to verify index clearing on close
        self::assertSame([], $room->getAOI()->queryShape(new CircleShape(0, 0, 300)), 'close 清空索引 close clears indexes');

        $actor->updates = 0;
        $room->update();
        self::assertSame(0, $actor->updates, 'close 后 Actor 不再被驱动 actors are no longer driven after close');
    }

    public function testCloseRequiresSettledState(): void
    {
        $room = $this->createRoom();
        $room->join(new BaseEntity('p1', new Position(1, 1)));

        try {
            $room->close();
            self::fail('Running 态直接 close 应抛异常 closing straight from Running must throw');
        } catch (\LogicException) {
            // 预期路径 expected path
        }

        $room->settle();
        $room->close();

        $this->expectException(\LogicException::class);
        $room->close();
    }

    public function testUpdateIsNoOpAfterTerminalStates(): void
    {
        $room = $this->createRoom();
        $taskRan = false;
        $room->getScheduler()->addTask(static function () use (&$taskRan): void {
            $taskRan = true;
        });
        $room->join(new BaseEntity('p1', new Position(1, 1)));

        $room->settle();
        $room->update();

        self::assertFalse($taskRan, '结算后 update 不再推进帧轮次 update no longer advances frames after settle');
    }

    /**
     * V2（ADR-024 §9）：getConfig 单一配置访问器——返回构造注入的同一 RoomConfig 实例。
     * V2 (ADR-024 §9): the single getConfig accessor — returns the very RoomConfig instance injected at construction.
     */
    public function testGetConfigReturnsSameInjectedInstance(): void
    {
        $config = new RoomConfig(
            roomId: 'room-cfg',
            periodMs: 25,
            maxMembers: 6,
            aoiFactory: static fn (): GridAOI => new GridAOI(10),
            maxCatchUpTicks: 2,
        );
        $room = new RoomInstance($config, new SimpleEventBus(), clock: static fn (): float => 1000.0);

        self::assertSame($config, $room->getConfig());
        self::assertSame('room-cfg', $room->getConfig()->roomId);
        self::assertSame(25, $room->getConfig()->periodMs);
        self::assertSame(6, $room->getConfig()->maxMembers);
        self::assertSame(2, $room->getConfig()->maxCatchUpTicks);
    }

    /**
     * 构造一个房间（缺省 GridAOI 工厂，可注入自定义工厂）。
     * Creates a room (GridAOI factory by default, custom factory injectable).
     */
    private function createRoom(?int $maxMembers = null, ?SimpleEventBus $eventBus = null, ?callable $aoiFactory = null): RoomInstance
    {
        $config = new RoomConfig(
            roomId: 'room-1',
            periodMs: 50,
            maxMembers: $maxMembers ?? 8,
            aoiFactory: $aoiFactory ?? static fn (): GridAOI => new GridAOI(10),
        );

        return new RoomInstance($config, $eventBus ?? new SimpleEventBus(), clock: static fn (): float => 1000.0);
    }
}

/**
 * 计数 Actor 测试桩。
 * Counting actor test stub.
 */
final class CountingActor implements ActorInterface
{
    public int $updates = 0;

    public function update(): void
    {
        $this->updates++;
    }
}

/**
 * 信封收集器测试桩。
 * Envelope-collector test stub.
 */
final class EnvelopeBox
{
    /** @var list<EventEnvelope> */
    public array $envelopes = [];

    public function push(EventEnvelope $envelope): bool
    {
        $this->envelopes[] = $envelope;

        return true;
    }
}
