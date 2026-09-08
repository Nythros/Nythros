<?php

declare(strict_types=1);

namespace Nythros\Framework\Persistence;

use Nythros\Contracts\TimerInterface;

/**
 * 玩家状态持久化管线的公开契约（MapServer 侧的写回/恢复门面）。
 * The public contract of the player-state persistence pipeline (the write-back / restore facade MapServer uses).
 *
 * 契约语义（实现方必须遵守）：
 * Contract semantics (implementations must uphold):
 * - markDirty 只登记内存最新快照，**不做任何 I/O**，不阻塞帧预算（裁决 4）；
 *   markDirty only registers the in-memory latest snapshot with no I/O and never blocks the frame budget (ruling 4);
 * - scheduleFlushId 为断连/登出推荐入口：登记合并窗，到窗一次批量冲刷；无定时器装配时回落 flushId 同步点；
 *   scheduleFlushId is the disconnect/logout entry: it arms a coalescing window and batch-flushes once; without a
 *   timer it falls back to the synchronous flushId;
 * - flushId / flush / periodicFlush 是强制同步点（实现方自行决定是否受兜底门控影响）；
 * - flushId / flush / periodicFlush are forced sync points (the implementation decides their gating);
 * - load 为 attach 恢复入口，读失败/无记录返回 null，语义是「尽力而为」（P18 口径）；
 * - load is the attach-restore entry; read failures / missing records return null ("best effort", the P18 stance);
 * - 任何路径不得静默丢数据：失败重试达上限必须记日志放弃（裁决 6）。
 * - No path may silently lose data: a capped-retry failure must be logged and dropped (ruling 6).
 *
 * fork 语义（bindTimer 存在的原因）：Workerman 定时器不能在 fork 前挂（子进程重复继承/主循环误触发），
 * 组装层惯例是「fork 前构造（timer=null）、onWorkerStart 内 bindTimer」——bindTimer 同时启用
 * scheduleFlushId 的紧急合并窗并注册周期兜底,幂等可重复调用。
 * Fork semantics (why bindTimer exists): Workerman timers must not be armed before fork (children inherit the
 * registration / the master loop misfires), so the assembly convention is "construct pre-fork with timer=null,
 * bindTimer inside onWorkerStart". bindTimer arms both the scheduleFlushId coalescing window and the periodic
 * fallback, and is idempotent.
 *
 * 实现方（可插拔）：
 * Implementations (pluggable):
 * - ArchivePipeline：MySQL 直写（旧口径，保留兼容）；
 *   ArchivePipeline: direct MySQL writes (the legacy path, kept compatible);
 * - RedisExportPipeline：会话权威写 Redis + Stream 导出，worker 内零 PDO（新目标口径）。
 *   RedisExportPipeline: session-authoritative Redis writes plus a Stream export, zero PDO inside the worker
 *   (the new target path).
 */
interface PersistPipelineInterface
{
    /**
     * 绑定 fork 后定时器（组装层 onWorkerStart 内调用）：启用紧急合并窗 + 注册周期兜底；幂等。
     * Binds the post-fork timer (called by the assembly inside onWorkerStart): arms the urgent coalescing window
     * and registers the periodic fallback; idempotent.
     */
    public function bindTimer(?TimerInterface $timer): void;

    /**
     * 标脏：登记最新状态（同 id 覆盖写）。零 I/O，不阻塞帧预算（裁决 4）。
     * Marks dirty: registers the latest state (same id overwrites). Zero I/O, never blocks the frame budget (ruling 4).
     *
     * @param string $id 记录标识（如玩家 uid） Record identifier (e.g. the player uid).
     * @param array<string, mixed> $data 最新状态 Latest state.
     */
    public function markDirty(string $id, array $data): void;

    /**
     * 强制同步冲刷单条记录；未标脏空操作。
     * Synchronously flushes one record; a no-op for records that were never dirty.
     *
     * @param string $id 记录标识 Record identifier.
     */
    public function flushId(string $id): void;

    /**
     * 合并冲刷单条记录（生产断连/登出入口）。
     * Coalesced single-record flush (the production disconnect/logout entry).
     *
     * @param string $id 记录标识 Record identifier.
     */
    public function scheduleFlushId(string $id): void;

    /**
     * attach 恢复读路径：按 id 读取最近持久化记录；失败/无记录 null。
     * The attach-restore read path: loads the most recently persisted record by id; null on miss/failure.
     *
     * @param string $id 记录标识（如玩家 uid） Record identifier (e.g. the player uid).
     * @return array<string, mixed>|null 持久化记录，无记录/读失败 null The record, or null on miss/failure.
     */
    public function load(string $id): ?array;

    /**
     * 立即批量冲刷全部脏记录（强制同步点，如 onStop 收尾）。
     * Batch-flushes every dirty record at once (a forced sync point, e.g. the onStop close-out).
     */
    public function flush(): void;

    /**
     * 定时兜底回调（时钟门控批冲刷，30s）。
     * The periodic fallback callback (the clock-gated 30s batch flush).
     */
    public function periodicFlush(): void;
}
