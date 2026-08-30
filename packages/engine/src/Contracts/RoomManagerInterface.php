<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 房间管理器契约：房间创建、归属校验与到期驱动的唯一编排入口。
 * 宿主心跳 now → 各房间按 periodMs 判到期 → 到期者执行一帧；追帧跳帧上限与周期预算截断由实现裁决（ADR-024 §D-B）。
 * Room manager contract: the single orchestration entry for room creation, ownership validation and due-driven
 * ticking. Host heartbeat now → per-room due judgment by periodMs → one frame per due room; the catch-up/skip-frame
 * cap and budget truncation are ruled by the implementation (ADR-024 §D-B).
 */
interface RoomManagerInterface
{
    /**
     * 创建并登记一个房间实例；roomId 重复抛 InvalidArgumentException。
     * Creates and registers a room instance; a duplicate roomId throws.
     */
    public function create(RoomConfig $config): RoomInstanceInterface;

    /**
     * 按 id 查询房间；不存在返回 null。
     * Looks up a room by id; null when it does not exist.
     */
    public function get(string $roomId): ?RoomInstanceInterface;

    /**
     * 返回全部房间（登记顺序）。
     * Returns all rooms in registration order.
     *
     * @return list<RoomInstanceInterface>
     */
    public function all(): array;

    /**
     * 宿主心跳驱动：线性扫描房间表 nextDueAt，到期房间依次 update()，本周期预算耗尽即止。
     * 首次观察的房间立即到期执行一帧；落后超过 maxCatchUpTicks 周期则跳帧对齐当前时刻。
     *
     * Host-heartbeat driver: linearly scans the room table's nextDueAt, updates each due room in turn, and stops
     * once this cycle's budget is exhausted. A room observed for the first time is immediately due for one frame;
     * falling behind by more than maxCatchUpTicks periods triggers skip-frame alignment to the current moment.
     *
     * @param float $now 当前时刻（秒，来自宿主时钟） Current time in seconds from the host clock.
     * @return array{updated: int, deferred: int} updated=本周期实际执行的帧数（含追帧多帧）；deferred=预算耗尽被顺延的房间数（未到期不计） updated = frames actually executed this cycle (catch-up frames included); deferred = rooms postponed by budget exhaustion (not-due rooms not counted)
     */
    public function tick(float $now): array;

    /**
     * 跨容器成员迁移编排（含归属表校验，杜绝双房）：leave 源房 + join 目标房原子语义，
     * 目标房不可入（满员/终态）时回滚源房并返回 false；$fromRoomId=null 表示从大世界进入
     * （宿主 World 的 EM/AOI 摘除由调用方编排，见 ADR-024 §4）。
     *
     * Cross-container member transfer orchestration (with ownership-table validation, double-housing impossible):
     * leave source + join target with atomic semantics — when the target cannot admit (full/terminal), the source
     * is rolled back and false returned; $fromRoomId=null means entering from the host world (removal from the host
     * World's EM/AOI is orchestrated by the caller, see ADR-024 §4).
     */
    public function transfer(?string $fromRoomId, string $toRoomId, EntityInterface $entity, ?ActorInterface $actor = null): bool;

    /**
     * 异常路径销毁：内部强制 settle→close→移除并清除归属表；未知 roomId 静默。
     * Exceptional-path destroy: internally forces settle→close→removal and purges the ownership table;
     * an unknown roomId is silently ignored.
     */
    public function destroy(string $roomId): void;

    /**
     * 跨容器断连清理（ADR-024 §9 V3）：按归属表定位实体所在房间并复用 leave 全链
     * （摘 EM/AOI/ActorSystem + member_leave/room.left 双向信封），随后清除归属记录。
     * 幂等与安全语义：实体不在任何受管房间返回 false（大世界成员由调用方既有模板清理，
     * 不在本方法职责内）；归属不匹配不可能发生（唯一事实来源即归属表）；已 settle 房仍可摘除
     * （settle 只停收新成员，存量成员的断连清理照常）；close/destroy 后成员已被清空或归属表已清除，
     * 返回 false 静默幂等。
     *
     * Cross-container disconnect cleanup (ADR-024 §9 V3): locates the entity's room via the ownership table and
     * reuses the full leave chain (EM/AOI/ActorSystem removal plus the bidirectional member_leave/room.left
     * envelopes), then purges the ownership record. Idempotency and safety: returns false when the entity belongs
     * to no managed room (world members are cleaned by the caller's existing template, not this method's duty);
     * ownership mismatch cannot happen (the ownership table is the single source of truth); a settled room still
     * evicts (settle only stops admissions, disconnect cleanup of existing members proceeds); after close/destroy
     * the members are already cleared or the ownership purged — false is returned silently and idempotently.
     */
    public function evictFromAny(string $entityId): bool;
}
