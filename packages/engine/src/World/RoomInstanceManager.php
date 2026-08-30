<?php

declare(strict_types=1);

namespace Nythros\World;

use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomManagerInterface;
use Nythros\Contracts\RoomState;
use Nythros\Event\SimpleEventBus;

/**
 * 房间实例管理器：创建、归属表校验与到期驱动的唯一编排入口（ADR-024 §D-B）。
 * 到期驱动：线性扫描房间表 nextDueAt，到期者依次 update() 并 nextDueAt += periodMs；
 * 落后超过 maxCatchUpTicks 周期则跳帧对齐当前时刻（防死亡螺旋）；本周期累计耗时达预算即止，
 * 剩余到期房间顺延计 deferred。nextDueAt 惰性初始化：首次观察即到期执行一帧。
 * 引擎侧纯 PHP 无事件循环依赖，时钟可注入（假时钟可测）。
 *
 * Room instance manager: the single orchestration entry for creation, ownership-table validation and due-driven
 * ticking (ADR-024 §D-B). Due-driven: linearly scans nextDueAt, updates each due room and advances nextDueAt by
 * periodMs; falling behind more than maxCatchUpTicks periods triggers skip-frame alignment to now (death-spiral
 * prevention); once this cycle's measured cost reaches the budget, remaining due rooms are deferred. nextDueAt is
 * lazily initialized: a room's first observation is immediately due for one frame. Pure PHP with no event-loop
 * dependency; the clock is injectable (fake-clock testable).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class RoomInstanceManager implements RoomManagerInterface
{
    /** @var array<string, array{room: RoomInstanceInterface, config: RoomConfig, nextDueAt: float|null, periodMs: int, deferStreak: int}> 房间表：roomId → 房间 + 配置 + 下次到期时刻（null=尚未观察）+ 动态周期（P9c）+ 连续顺延计数（P9c） Room table: roomId → room + config + next due time (null = never observed) + dynamic period (the P9c) + consecutive-deferral streak (the P9c). */
    private array $rooms = [];

    /** @var array<string, string> 归属表：实体 id → 所在房间 id；经 transfer 进出的实体强制单容器归属 Ownership table: entity id → room id; transfer-managed entities are forced single-container. */
    private array $ownership = [];

    /** @var callable(): float 时钟函数（预算实测用，可注入假时钟） Clock function (for budget measurement; fake-clock injectable). */
    private $clock;

    /** @var float 单次 tick 的周期预算（秒）；缺省 30ms = 宿主 50ms 周期的 60%（ADR-024 §D-B） Per-tick budget in seconds; default 30ms = 60% of the host's 50ms cycle (ADR-024 §D-B). */
    private readonly float $budgetSecs;

    /** @var EventBusInterface 共享宿主事件总线：全部房间统一信封队列 The shared host event bus: one unified envelope queue for all rooms. */
    private readonly EventBusInterface $eventBus;

    /** 动态周期上限（毫秒，P9c 预算压力下 periodMs 的膨胀地板；房间回落不超过配置周期）。 The dynamic-period ceiling (ms, the P9c inflation floor under budget pressure; rooms never recover beyond their configured period). */
    private readonly int $maxDynamicPeriodMs;

    /** 房间准入上限（P9c；0 = 不限）。create 触顶即抛（装配层据此标记 busy/路由他处）。 The room-admission cap (the P9c; 0 = unlimited). create() throws when full (the assembly layer marks busy / routes elsewhere). */
    private readonly int $maxRooms;

    /** @var int 上一次 tick 的顺延房间数（指标暴露 + 回升判定） The last tick's deferred-room count (metrics + recovery signal). */
    private int $lastDeferred = 0;

    /**
     * 构造房间管理器。
     * Creates the room manager.
     *
     * @param null|callable(): float $clock 可选时钟注入（缺省 microtime(true)），用于预算耗时实测 Optional clock injection (defaults to microtime(true)), used to measure budget consumption.
     * @param float $budgetMs 单次 tick 预算（毫秒），缺省 30ms Per-tick budget in milliseconds, default 30ms.
     * @param null|EventBusInterface $eventBus 共享宿主总线（缺省自建 SimpleEventBus；starter-kit 接线时注入宿主总线） Shared host bus (defaults to a fresh SimpleEventBus; the starter-kit wiring injects the host bus).
     */
    public function __construct(
        ?callable $clock = null,
        float $budgetMs = 30.0,
        ?EventBusInterface $eventBus = null,
        int $maxDynamicPeriodMs = 50,
        int $maxRooms = 0,
    ) {
        if ($maxDynamicPeriodMs <= 0 || $maxRooms < 0) {
            throw new \InvalidArgumentException('管理器 maxDynamicPeriodMs 必须为正、maxRooms 必须非负（0 = 不限） / manager requires a positive maxDynamicPeriodMs and a non-negative maxRooms (0 = unlimited)');
        }
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->budgetSecs = $budgetMs / 1000.0;
        $this->eventBus = $eventBus ?? new SimpleEventBus();
        $this->maxDynamicPeriodMs = $maxDynamicPeriodMs;
        $this->maxRooms = $maxRooms;
    }

    public function create(RoomConfig $config): RoomInstanceInterface
    {
        if (isset($this->rooms[$config->roomId])) {
            throw new \InvalidArgumentException(sprintf('房间 %s 已存在 / room %s already exists', $config->roomId, $config->roomId));
        }
        // 准入控制（P9c）：进程内房间数触顶即抛——装配层捕获后标记 busy / 匹配路由他处，
        // 而非继续塞入然后全员降频惩罚。
        // Admission control (the P9c): throw when the in-process room count is full — the assembly layer
        // catches it to mark busy / route matching elsewhere, instead of cramming in and degrading everyone.
        if ($this->maxRooms > 0 && count($this->rooms) >= $this->maxRooms) {
            throw new \OverflowException(sprintf('房间数已达进程准入上限 %d / room count reached the process admission cap %d', $this->maxRooms, $this->maxRooms));
        }

        // 房间构造即装配独立子系统，EventBus 注入共享宿主总线（ADR-024 §D-A）；
        // 房间帧时钟独立于管理器预算时钟（后者仅实测 tick 耗时），避免时钟调用序列耦合。
        // Room construction assembles its independent subsystems; the EventBus is the injected shared host bus
        // (ADR-024 §D-A). A room's frame clock stays independent of the manager's budget clock (which only
        // measures tick cost), avoiding clock-call-sequence coupling.
        $room = new RoomInstance($config, $this->eventBus);
        $this->rooms[$config->roomId] = ['room' => $room, 'config' => $config, 'nextDueAt' => null, 'periodMs' => $config->periodMs, 'deferStreak' => 0];

        return $room;
    }

    public function get(string $roomId): ?RoomInstanceInterface
    {
        return $this->rooms[$roomId]['room'] ?? null;
    }

    public function all(): array
    {
        return array_map(static fn (array $entry): RoomInstanceInterface => $entry['room'], array_values($this->rooms));
    }

    public function tick(float $now): array
    {
        $updated = 0;
        $deferred = 0;
        $deadline = ($this->clock)() + $this->budgetSecs;

        foreach ($this->rooms as &$entry) {
            $state = $entry['room']->getState();
            // 终态房间不再驱动 terminal rooms are no longer driven
            if ($state !== RoomState::Created && $state !== RoomState::Running) {
                continue;
            }

            // 惰性初始化：首次观察即到期 lazy init: first observation is immediately due
            $entry['nextDueAt'] ??= $now;
            if ($entry['nextDueAt'] > $now) {
                continue; // 未到期不计 deferred not yet due — not counted as deferred
            }

            // 周期预算截断：实测超预算则剩余到期房间顺延 budget truncation: past the measured budget, remaining due rooms are deferred
            if (($this->clock)() >= $deadline) {
                $deferred++;
                // 预算压力自调（P9c）：连续被顺延的房间抬高自身周期（降档），把预算让给如期房间——
                // 被顺延是「本房间跑不完一帧」的直接信号，膨胀上限 maxDynamicPeriodMs 兜底。
                // Budget-pressure self-tuning (the P9c): a room deferred repeatedly raises its own period
                // (downgrades), yielding budget to on-schedule rooms — deferral is the direct signal that
                // the room cannot finish a frame in budget; maxDynamicPeriodMs backstops the inflation.
                $entry['deferStreak']++;
                if ($entry['deferStreak'] >= 2) {
                    $entry['periodMs'] = (int) min($this->maxDynamicPeriodMs, (int) ceil($entry['periodMs'] * 1.5));
                    $entry['deferStreak'] = 0;
                }
                $entry['nextDueAt'] = max($entry['nextDueAt'], $now);
                continue;
            }

            // 如期执行：清零顺延计数（预算压力已解除的直接反证） A due execution clears the deferral streak.
            $entry['deferStreak'] = 0;
            $updated += $this->driveRoom($entry, $now);
        }
        unset($entry);

        // 预算回升（P9c）：本轮零顺延 = 进程有余量——全部动态膨胀过的房间按比例回落 toward 配置周期。
        // Budget recovery (the P9c): zero deferrals this round = process headroom — every dynamically
        // inflated room steps back down toward its configured period proportionally.
        if ($deferred === 0) {
            foreach ($this->rooms as &$entry) {
                if ($entry['periodMs'] > $entry['config']->periodMs) {
                    $entry['periodMs'] = (int) max($entry['config']->periodMs, (int) ceil($entry['periodMs'] / 1.25));
                }
            }
            unset($entry);
        }
        $this->lastDeferred = $deferred;

        return ['updated' => $updated, 'deferred' => $deferred];
    }

    /**
     * 上一次 tick 的顺延房间数（P9c 指标暴露：registry 心跳元数据 / busy 判定消费）。
     * The last tick's deferred-room count (the P9c metrics for registry heartbeat metadata / busy adjudication).
     */
    public function lastDeferred(): int
    {
        return $this->lastDeferred;
    }

    /**
     * 当前动态周期（毫秒）映射：roomId → periodMs（P9c 指标暴露/观测用）。
     * The current dynamic-period map: roomId → periodMs (the P9c metrics/observability).
     *
     * @return array<string, int>
     */
    public function periodMap(): array
    {
        return array_map(static fn (array $entry): int => $entry['periodMs'], $this->rooms);
    }

    public function transfer(?string $fromRoomId, string $toRoomId, EntityInterface $entity, ?ActorInterface $actor = null): bool
    {
        $target = $this->rooms[$toRoomId]['room'] ?? null;
        if ($target === null) {
            return false; // 目标房不存在 target room unknown
        }
        if ($fromRoomId === $toRoomId) {
            return false; // 同房转移无意义 same-room transfer is meaningless
        }

        $id = $entity->getId();

        // 归属表前置校验（先校验后变更，杜绝半迁移状态） ownership pre-validation (validate before mutating, no half-migrated states)
        if ($fromRoomId === null) {
            // 从大世界进入：实体不得已归属任何房间 entering from the world: the entity must belong to no room
            if (isset($this->ownership[$id])) {
                return false;
            }
        } else {
            // 跨房转移：实体必须确实归属源房 cross-room: the entity must genuinely belong to the source room
            if (($this->ownership[$id] ?? null) !== $fromRoomId) {
                return false;
            }
            if (!isset($this->rooms[$fromRoomId])) {
                return false; // 源房不存在 source room unknown
            }
        }

        // 目标房可入性预检：终态拒绝（满员/重复由 join 返回 false 触发回滚） target admissibility pre-check: terminal states rejected here (full/duplicate surface via join's false and trigger rollback)
        $targetState = $target->getState();
        if ($targetState !== RoomState::Created && $targetState !== RoomState::Running) {
            return false;
        }

        // 原子迁移：leave 源房 → join 目标房；join 失败（满员等）回滚源房 atomic transfer: leave source → join target; a failed join (full etc.) rolls back the source
        if ($fromRoomId !== null) {
            $this->rooms[$fromRoomId]['room']->leave($id);
        }
        if (!$target->join($entity, $actor)) {
            if ($fromRoomId !== null) {
                $this->rooms[$fromRoomId]['room']->join($entity, $actor);
            }

            return false;
        }

        $this->ownership[$id] = $toRoomId;

        return true;
    }

    public function destroy(string $roomId): void
    {
        $entry = $this->rooms[$roomId] ?? null;
        if ($entry === null) {
            return;
        }

        // 强制 settle→close：按当前状态跳过已完成的步骤 force settle→close: skip steps already done per current state
        $room = $entry['room'];
        $state = $room->getState();
        if ($state === RoomState::Created || $state === RoomState::Running) {
            $room->settle();
            $state = $room->getState();
        }
        if ($state === RoomState::Settled) {
            $room->close();
        }

        unset($this->rooms[$roomId]);

        // 归属表同步清除：指向被销毁房间的记录全部移除 purge ownership entries pointing at the destroyed room
        foreach ($this->ownership as $entityId => $ownedRoom) {
            if ($ownedRoom === $roomId) {
                unset($this->ownership[$entityId]);
            }
        }
    }

    public function evictFromAny(string $entityId): bool
    {
        $roomId = $this->ownership[$entityId] ?? null;
        if ($roomId === null) {
            return false; // 不在任何受管房间（大世界成员或未知实体）：幂等安全 not in any managed room (a world member or unknown): idempotently safe
        }

        $room = $this->rooms[$roomId]['room'] ?? null;
        unset($this->ownership[$entityId]);

        // 房间已销毁或房内已无此实体（close 清空/重复清理）：静默幂等 room destroyed or entity already gone (close-cleared / repeated cleanup): silently idempotent
        if ($room === null || !$room->leave($entityId)) {
            return false;
        }

        return true;
    }

    /**
     * 驱动单个到期房间执行其应跑的帧数：落后未超上限逐帧追满，超过上限跳帧对齐。
     * Drives one due room through its owed frames: frame-by-frame catch-up within the cap, skip-frame alignment beyond it.
     *
     * @param array{room: RoomInstanceInterface, config: RoomConfig, nextDueAt: float|null, periodMs: int, deferStreak: int} $entry 房间表条目 Room table entry.
     * @return int 本房间实际执行的帧数 Frames actually executed for this room.
     */
    private function driveRoom(array &$entry, float $now): int
    {
        // 动态周期（P9c）：预算压力下膨胀、余量时回落，配置 periodMs 为下限
        // The dynamic period (the P9c): inflates under budget pressure, recovers with headroom, and the
        // configured periodMs is the floor.
        $periodSecs = $entry['periodMs'] / 1000.0;
        $maxCatchUpTicks = $entry['config']->maxCatchUpTicks;
        $nextDueAt = $entry['nextDueAt'];

        // 落后超过上限：跳帧对齐当前时刻——只执行一帧，nextDueAt 对齐 now+period（防死亡螺旋）
        // Behind beyond the cap: skip-frame aligns to the current moment — exactly one frame runs, nextDueAt aligns to now+period (death-spiral prevention)
        if (($now - $nextDueAt) > $maxCatchUpTicks * $periodSecs) {
            $entry['room']->update();
            $entry['nextDueAt'] = $now + $periodSecs;

            return 1;
        }

        // 逐帧追帧：追满全部欠帧（含本次到期帧） frame-by-frame catch-up: run every owed frame (the currently due one included)
        $frames = 0;
        do {
            $entry['room']->update();
            $frames++;
            $entry['nextDueAt'] += $periodSecs;
        } while ($entry['nextDueAt'] <= $now);

        return $frames;
    }
}
