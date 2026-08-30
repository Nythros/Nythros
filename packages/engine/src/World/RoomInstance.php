<?php

declare(strict_types=1);

namespace Nythros\World;

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomState;
use Nythros\Contracts\SchedulerInterface;
use Nythros\Contracts\WorldType;
use Nythros\Kernel\PerfProbe;
use Nythros\Scheduler\TickScheduler;

/**
 * 房间实例聚合：短生命周期小世界，独立 EntityManager/AOI/ActorSystem/TickScheduler 完整注册，
 * EventBus 共享宿主注入；update() 复刻 World 固定帧序（actor updateAll → drainMoved + AOI 差分
 * 双向信封入共享总线 → scheduler runFrame），信封只入队、flush 仍由上层帧末统一触发。
 * tick 驱动不在本类——由 RoomInstanceManager 到期驱动调用 update()。
 *
 * Room instance aggregate: a short-lived small world with fully registered independent
 * EntityManager/AOI/ActorSystem/TickScheduler and an injected shared host EventBus; update() replicates the
 * World's fixed frame order (actor updateAll → drainMoved + AOI diff bidirectional envelopes onto the shared bus →
 * scheduler runFrame), envelopes are only enqueued and flushing stays with the upper layer at frame end.
 * Tick driving is not this class's job — the RoomInstanceManager invokes update() when a room is due.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class RoomInstance implements RoomInstanceInterface
{
    /** @var RoomState 当前生命周期状态 Current lifecycle state. */
    private RoomState $state = RoomState::Created;

    /** @var SimpleEntityManager 房间自有实体管理器 The room's own entity manager. */
    private readonly SimpleEntityManager $entityManager;

    /** @var SimpleActorSystem 房间自有 Actor 系统 The room's own actor system. */
    private readonly SimpleActorSystem $actorSystem;

    /** @var AOIProviderInterface 房间自有 AOI 提供者（由 RoomConfig 工厂产出） The room's own AOI provider (produced by the RoomConfig factory). */
    private readonly AOIProviderInterface $aoi;

    /** @var TickScheduler 房间自有帧调度器（tickMs = periodMs） The room's own frame scheduler (tickMs = periodMs). */
    private readonly TickScheduler $scheduler;

    /** @var array<string, ActorInterface> 成员 id → Actor 映射，leave/close 时定向注销 Member id → actor map for targeted unregistration on leave/close. */
    private array $actorsById = [];

    /** @var int 当前成员数 Current member count. */
    private int $memberCount = 0;

    /** @var callable(): float 时钟函数（可注入，便于测试；用于信封 timestamp） Clock function (injectable for testing; supplies envelope timestamps). */
    private $clock;

    /**
     * 构造房间实例并装配独立子系统。
     * Creates the room instance and assembles its independent subsystems.
     *
     * @param RoomConfig $config 房间配置（roomId/periodMs/maxMembers/aoiFactory/maxCatchUpTicks） Room configuration.
     * @param EventBusInterface $eventBus 共享宿主事件总线（信封统一队列、上层帧末 flush） The shared host event bus (unified envelope queue, upper-layer frame-end flush).
     * @param null|callable(): float $clock 可选时钟注入（缺省 microtime(true)） Optional clock injection (defaults to microtime(true)).
     */
    public function __construct(
        private readonly RoomConfig $config,
        private readonly EventBusInterface $eventBus,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);

        // 先建房间自有 EM，再调工厂并传入：UniversalAOI 必须包裹房间自有 EM 才能对齐 query/queryShape 全表口径；
        // 零参工厂（GridAOI 等）忽略多余实参，两种写法兼容。
        // Build the room's own EM first, then invoke the factory with it: UniversalAOI must wrap the room's own EM
        // to keep the full-table query/queryShape semantics aligned; zero-arg factories (GridAOI etc.) ignore the
        // extra argument, so both styles work.
        $this->entityManager = new SimpleEntityManager();

        $factory = $config->aoiFactory;
        if (!is_callable($factory)) {
            throw new \InvalidArgumentException('RoomConfig::aoiFactory 必须可调用 / aoiFactory must be callable');
        }
        $aoi = $factory($this->entityManager);
        if (!$aoi instanceof AOIProviderInterface) {
            throw new \InvalidArgumentException('RoomConfig::aoiFactory 必须产出 AOIProviderInterface / aoiFactory must yield an AOIProviderInterface');
        }

        $this->actorSystem = new SimpleActorSystem();
        $this->aoi = $aoi;
        $this->scheduler = new TickScheduler($this->clock, $config->periodMs);
    }

    /**
     * 被驱动的内部更新路径：复刻 World 固定帧序。Settled/Closed 终态为空操作（驱动方只调度 Running 房间，
     * 此处兜底防御直接调用）。空房间照常推进轮次（跳过 actor/AOI 的实际工作量为零成本）。
     * The internally driven update path: replicates the World's fixed frame order. Terminal states are no-ops
     * (the driver only schedules Running rooms; this guards direct callers too). Empty rooms still advance their
     * rounds (skipped actor/AOI work costs nothing).
     */
    public function update(): void
    {
        if ($this->state === RoomState::Settled || $this->state === RoomState::Closed) {
            return;
        }

        $frameClock = ($this->clock)();
        $frameStartMs = $frameClock * 1000.0;

        // 第 1 步：房间自有 Actor 先行 step 1: the room's own actors run first
        $this->actorSystem->updateAll();

        // 第 2 步：AOI 差分（全量广播型房间无视野关系，跳过） step 2: AOI diff (full-broadcast rooms have no vision relationships, skipped)
        $enterEnvelopes = [];
        $leaveEnvelopes = [];
        if ($this->getType() === WorldType::AOI) {
            $diffs = AoiDiffEnvelopes::collect($this->entityManager, $this->aoi, $frameClock);
            $enterEnvelopes = $diffs['entered'];
            $leaveEnvelopes = $diffs['left'];
        }
        foreach ($enterEnvelopes as $envelope) {
            $this->eventBus->publishEnvelope($envelope);
        }
        foreach ($leaveEnvelopes as $envelope) {
            $this->eventBus->publishEnvelope($envelope);
        }

        // 第 3 步：房间自有调度器执行本帧任务 step 3: the room's own scheduler runs this frame's tasks
        $this->scheduler->runFrame();

        PerfProbe::recordDuration('room.frame_ms', ($this->clock)() * 1000.0 - $frameStartMs);
        PerfProbe::increment('room.envelope_published', count($enterEnvelopes) + count($leaveEnvelopes));
    }

    public function getRoomId(): string
    {
        return $this->config->roomId;
    }

    public function getState(): RoomState
    {
        return $this->state;
    }

    public function getConfig(): RoomConfig
    {
        return $this->config;
    }

    public function join(EntityInterface $entity, ?ActorInterface $actor = null): bool
    {
        if ($this->state === RoomState::Settled || $this->state === RoomState::Closed) {
            throw new \InvalidArgumentException(sprintf('房间 %s 已 %s，停止收成员 / room %s is %s, admissions stopped', $this->config->roomId, $this->state->name, $this->config->roomId, $this->state->name));
        }

        $id = $entity->getId();
        // 已是成员：返回 false（重复加入语义） already a member: false (duplicate-join semantics)
        if ($this->entityManager->get($id) !== null) {
            return false;
        }
        // 满员：返回 false at capacity: false
        if ($this->memberCount >= $this->config->maxMembers) {
            return false;
        }

        // 快照既有成员用于双向通知（不含进入者自身） snapshot existing members for bidirectional notification (joiner excluded)
        $existing = $this->entityManager->all();
        $now = ($this->clock)();

        // EM 登记即 markMoved：首帧必进 AOI 索引 EM registration marks moved: the first frame always enters the AOI index
        $this->entityManager->add($entity);
        if ($actor !== null) {
            $this->actorSystem->add($actor);
            $this->actorsById[$id] = $actor;
        }
        $this->memberCount++;
        // Created 首次加入即激活为 Running the first join activates a Created room into Running
        $this->state = RoomState::Running;

        // 双向通知：既有成员收 member_enter；进入者收房间快照 bidirectional notification: existing members get member_enter; the joiner gets the room snapshot
        $snapshot = [];
        foreach ($existing as $member) {
            $snapshot[] = ['id' => $member->getId(), 'position' => $member->getPosition()];
            $this->eventBus->publishEnvelope(new EventEnvelope(
                source: $id,
                type: self::EVENT_MEMBER_ENTER,
                timestamp: $now,
                targetScope: $member->getId(),
                reliable: true,
                droppable: false,
                payload: ['roomId' => $this->config->roomId, 'position' => $entity->getPosition()],
            ));
        }
        $this->eventBus->publishEnvelope(new EventEnvelope(
            source: $this->config->roomId,
            type: self::EVENT_ROOM_SNAPSHOT,
            timestamp: $now,
            targetScope: $id,
            reliable: true,
            droppable: false,
            payload: ['roomId' => $this->config->roomId, 'members' => $snapshot],
        ));

        return true;
    }

    public function leave(string $entityId): bool
    {
        $entity = $this->entityManager->get($entityId);
        // 非成员：返回 false non-member: false
        if ($entity === null) {
            return false;
        }

        $now = ($this->clock)();

        // 摘除顺序：AOI 索引 → EM → ActorSystem teardown order: AOI index → EM → ActorSystem
        $this->aoi->remove($entity);
        $this->entityManager->remove($entityId);
        if (isset($this->actorsById[$entityId])) {
            $this->actorSystem->remove($this->actorsById[$entityId]);
            unset($this->actorsById[$entityId]);
        }
        $this->memberCount--;

        // 双向通知：留守成员收 member_leave；离开者收 room.left 回执 bidirectional notification: remaining members get member_leave; the departer gets the room.left receipt
        foreach ($this->entityManager->all() as $remaining) {
            $this->eventBus->publishEnvelope(new EventEnvelope(
                source: $entityId,
                type: self::EVENT_MEMBER_LEAVE,
                timestamp: $now,
                targetScope: $remaining->getId(),
                reliable: true,
                droppable: false,
                payload: ['roomId' => $this->config->roomId],
            ));
        }
        $this->eventBus->publishEnvelope(new EventEnvelope(
            source: $this->config->roomId,
            type: self::EVENT_ROOM_LEFT,
            timestamp: $now,
            targetScope: $entityId,
            reliable: true,
            droppable: false,
            payload: ['roomId' => $this->config->roomId],
        ));

        return true;
    }

    public function settle(): void
    {
        if ($this->state === RoomState::Settled || $this->state === RoomState::Closed) {
            throw new \LogicException(sprintf('非法状态迁移 settle：%s / illegal transition settle from %s', $this->state->name, $this->state->name));
        }

        $this->state = RoomState::Settled;
        $now = ($this->clock)();

        // 向存活成员发 room_closed 信封（Created 空房无存活成员，静默） surviving members receive room.closed envelopes (an empty Created room has none, silent)
        foreach ($this->entityManager->all() as $member) {
            $this->eventBus->publishEnvelope(new EventEnvelope(
                source: $this->config->roomId,
                type: self::EVENT_ROOM_CLOSED,
                timestamp: $now,
                targetScope: $member->getId(),
                reliable: true,
                droppable: false,
                payload: ['roomId' => $this->config->roomId],
            ));
        }
    }

    public function close(): void
    {
        if ($this->state !== RoomState::Settled) {
            throw new \LogicException(sprintf('非法状态迁移 close：%s（须经 settle）/ illegal transition close from %s (settle required)', $this->state->name, $this->state->name));
        }

        // 强制清空：close 时仍有成员属预期（settle 已通知），逐个摘除索引与 Actor force-clear: remaining members at close are expected (settle already notified); tear down indexes and actors one by one
        foreach ($this->entityManager->all() as $entity) {
            $id = $entity->getId();
            $this->aoi->remove($entity);
            if (isset($this->actorsById[$id])) {
                $this->actorSystem->remove($this->actorsById[$id]);
                unset($this->actorsById[$id]);
            }
            $this->entityManager->remove($id);
        }
        $this->memberCount = 0;
        $this->state = RoomState::Closed;
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function getActorSystem(): ActorSystemInterface
    {
        return $this->actorSystem;
    }

    public function getAOI(): AOIProviderInterface
    {
        return $this->aoi;
    }

    public function getEventBus(): EventBusInterface
    {
        return $this->eventBus;
    }

    /**
     * 世界类型由产出的 AOI 实现推导：UniversalAOI 即全量广播，其余（GridAOI 等）为 AOI 局域。
     * 与装配层「AOI 选择即类型选择」的配对知识一致。
     * The world type derives from the produced AOI implementation: UniversalAOI means full broadcast, anything
     * else (GridAOI etc.) is AOI-local — matching the assembly layer's "AOI choice is type choice" pairing.
     */
    public function getType(): WorldType
    {
        return $this->aoi instanceof UniversalAOI ? WorldType::FULL_BROADCAST : WorldType::AOI;
    }

    public function getScheduler(): SchedulerInterface
    {
        return $this->scheduler;
    }
}
