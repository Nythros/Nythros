<?php

declare(strict_types=1);

namespace Nythros\World;

use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\SchedulerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Contracts\WorldType;
use Nythros\Kernel\PerfProbe;

/**
 * 世界容器：聚合 EntityManager、ActorSystem、AOI、EventBus、Scheduler，按固定顺序驱动每帧更新。
 * World container: aggregates the EntityManager, ActorSystem, AOI, EventBus and Scheduler, and drives each frame's update in a fixed order.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class World implements WorldInterface
{
    /** @var callable(): float 时钟函数（可注入，便于测试；用于信封 timestamp） Clock function (injectable for testing; supplies envelope timestamps). */
    private $clock;

    /** @var WorldType 世界类型（AOI 局域 / 全量广播） World type (AOI-local / full broadcast). */
    private readonly WorldType $type;

    /** @var AOIProviderInterface 视野提供者：GridAOI（九宫格）或 UniversalAOI（全量广播 = 全世界即视野），恒非空 View provider: GridAOI (3x3) or UniversalAOI (full broadcast = the whole world is the view); never null. */
    private readonly AOIProviderInterface $aoi;

    /**
     * 构造世界容器并注入各子系统。
     * Creates the world container and injects its subsystems.
     *
     * @param EntityManagerInterface $entityManager 实体管理器 Entity manager.
     * @param ActorSystemInterface $actorSystem Actor 系统 Actor system.
     * @param AOIProviderInterface $aoi 视野提供者：GridAOI（AOI 局域）或 UniversalAOI（全量广播）——恒非空，全量型 World 也注入 UniversalAOI View provider: GridAOI (AOI-local) or UniversalAOI (full broadcast) — always present, full-broadcast Worlds inject a UniversalAOI.
     * @param EventBusInterface $eventBus 事件总线 Event bus.
     * @param SchedulerInterface $scheduler 帧调度器 Frame scheduler.
     * @param WorldType $type 世界类型：AOI（默认）或 FULL_BROADCAST World type: AOI (default) or FULL_BROADCAST.
     * @param null|callable(): float $clock 可选时钟注入（缺省 microtime(true)） Optional clock injection (defaults to microtime(true)).
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActorSystemInterface $actorSystem,
        AOIProviderInterface $aoi,
        private readonly EventBusInterface $eventBus,
        private readonly SchedulerInterface $scheduler,
        WorldType $type = WorldType::AOI,
        ?callable $clock = null,
    ) {
        $this->type = $type;
        $this->aoi = $aoi;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 驱动世界一帧：先更新 Actor，再同步 AOI 索引并发布视野进入/离开事件，最后执行调度器任务队列。
     * 本方法不调用 eventBus->flush()：信封由上层（demo 层）在帧末统一触发 flush。
     * Drives one world frame: updates actors first, then syncs the AOI index and publishes visibility enter/leave events, and finally runs the scheduler's task queue.
     * This method does not call eventBus->flush(): envelopes are flushed by the upper layer (demo layer) at frame end.
     */
    public function update(): void
    {
        // 运行期探针：帧耗时（毫秒）采样（成本一个 clock 调用 + 桶累加，可忽略；不启用探针采样时仅静态计数）。
        // 帧首采样一次，同时作为本帧全部视野信封的统一时间戳源——避免每个邻居对各调一次时钟（M×K 邻居 = 2MK 次/帧）。
        // Runtime probe: per-frame cost (ms) sampling (one clock call + bucket increment, negligible; without probe
        // sampling it is just static counting). Sampled once at frame start and reused as the single timestamp source
        // for every vision envelope this frame — instead of one clock call per neighbor pair (M×K neighbors = 2MK calls/frame).
        $frameClock = ($this->clock)();
        $frameStartMs = $frameClock * 1000.0;

        // 第 1 步：Actor 先行，实体移动等状态变化在本步骤产生 step 1: actors run first, producing state changes such as entity movement
        $this->actorSystem->updateAll();

        // 第 2 步：Actor 可能移动了实体，重建/刷新 AOI 索引，保证后续查询与最新位置一致；视野差分先统一收集、遍历结束后再发布（先刷后发），保证同帧事件顺序稳定 step 2: actors may have moved entities, so refresh the AOI index to keep subsequent queries consistent with the latest positions; visibility deltas are collected first and published after the sweep (flush-then-publish) so same-frame event ordering stays stable
        /** @var list<EventEnvelope> $enterEnvelopes 本帧全部视野进入信封 all visibility-enter envelopes of this frame */
        $enterEnvelopes = [];
        /** @var list<EventEnvelope> $leaveEnvelopes 本帧全部视野离开信封 all visibility-leave envelopes of this frame */
        $leaveEnvelopes = [];

        // 全量广播型 World 注入 UniversalAOI：其 updateEntity 恒返回空差分，无差分可扫——跳过 AOI 差分步骤
        // （实体间无视野关系，全量可见由上层广播路径保证）。
        // A full-broadcast World injects a UniversalAOI whose updateEntity always returns an empty delta, so the
        // diff step has nothing to do — it is skipped (no vision relationships; full visibility is guaranteed by
        // the upper-layer broadcast path).
        if ($this->type === WorldType::AOI) {
            $diffs = $this->sweepAOIDiffs($frameClock);
            $enterEnvelopes = $diffs['entered'];
            $leaveEnvelopes = $diffs['left'];
        }

        // 遍历结束后统一发布：先全部 entered 后全部 left，事件只入队不 flush（由上层帧末触发） publish in one batch after the sweep: all entered envelopes first, then all left ones; envelopes are only enqueued, never flushed here (the upper layer flushes at frame end)
        foreach ($enterEnvelopes as $envelope) {
            $this->eventBus->publishEnvelope($envelope);
        }
        foreach ($leaveEnvelopes as $envelope) {
            $this->eventBus->publishEnvelope($envelope);
        }

        // 第 3 步：最后执行本帧调度任务，任务可观察到前两步的最终状态 step 3: run this frame's scheduled tasks last so they observe the final state produced by the previous steps
        $this->scheduler->runFrame();

        // 帧末探针：本帧总耗时 + 信封吞吐（帧耗时直方图 + 事件计数，供运行期性能检测）
        // Frame-end probe: total frame cost + envelope throughput (frame-cost histogram + event counter for runtime performance monitoring)
        PerfProbe::recordDuration('world.frame_ms', ($this->clock)() * 1000.0 - $frameStartMs);
        PerfProbe::increment('world.envelope_published', count($enterEnvelopes) + count($leaveEnvelopes));
    }

    /**
     * AOI 差分扫描：仅对本帧已移动（moved-dirty）的实体刷新空间索引，收集视野进入/离开信封（仅 AOI 型 World 调用）。
     * 静止实体零 AOI 成本过帧；实体首次登记即视为 moved，保证新实体必进索引。
     * 装配逻辑抽至 AoiDiffEnvelopes（与 RoomInstance 共用）。
     * AOI diff sweep: refreshes the spatial index only for entities that moved this frame (moved-dirty), collecting
     * vision enter/leave envelopes (only called by AOI-type Worlds). Stationary entities cross the frame at zero AOI
     * cost; a newly registered entity counts as moved, so it always enters the index. The assembly logic lives in
     * AoiDiffEnvelopes (shared with RoomInstance).
     *
     * @param float $frameClock 帧首采样的统一时间戳（全部信封共用，时钟每帧只调一次） The frame-start sampled timestamp shared by all envelopes (one clock call per frame).
     * @return array{entered: list<EventEnvelope>, left: list<EventEnvelope>} 先全部 entered 后全部 left 的信封集 Envelope sets, all entered first then all left.
     */
    private function sweepAOIDiffs(float $frameClock): array
    {
        return AoiDiffEnvelopes::collect($this->entityManager, $this->aoi, $frameClock);
    }

    /**
     * 获取实体管理器。
     * Gets the entity manager.
     *
     * @return EntityManagerInterface 实体管理器 The entity manager.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * 获取 Actor 系统。
     * Gets the actor system.
     *
     * @return ActorSystemInterface Actor 系统 The actor system.
     */
    public function getActorSystem(): ActorSystemInterface
    {
        return $this->actorSystem;
    }

    /**
     * 获取视野提供者：GridAOI（九宫格）或 UniversalAOI（全量广播 = 全世界即视野），恒非空。
     * Gets the view provider: GridAOI (3x3) or UniversalAOI (full broadcast = the whole world is the view); never null.
     *
     * @return AOIProviderInterface 视野提供者，恒非空 The view provider; never null.
     */
    public function getAOI(): AOIProviderInterface
    {
        return $this->aoi;
    }

    /**
     * 获取本世界类型（AOI 局域 / 全量广播）。
     * Gets the World's type (AOI-local / full broadcast).
     */
    public function getType(): WorldType
    {
        return $this->type;
    }

    /**
     * 获取事件总线。
     * Gets the event bus.
     *
     * @return EventBusInterface 事件总线 The event bus.
     */
    public function getEventBus(): EventBusInterface
    {
        return $this->eventBus;
    }

    /**
     * 获取帧调度器。
     * Gets the frame scheduler.
     *
     * @return SchedulerInterface 帧调度器 The frame scheduler.
     */
    public function getScheduler(): SchedulerInterface
    {
        return $this->scheduler;
    }
}
