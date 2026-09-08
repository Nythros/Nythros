<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 批量回写能力（可选扩展，能力探测式）：任务进度存储实现方若把多条整记录合并为一次后端往返的能力
 * 暴露出来，即可实现本接口——写回缓冲（CachedQuestStore）在冲刷点探测并优先走批量路径，把
 * 「每条脏进度一次往返」压成「按 uid 归组一次往返（多 uid 再 pipeline 合并）」。
 * An optional batch write-back capability (capability-probed): a QuestStoreInterface implementation that can merge
 * many whole records into a single backend round-trip implements this interface — the write-back buffer
 * (CachedQuestStore) probes for it at flush time and prefers the batch path, collapsing "one round-trip per dirty
 * record" into "one round-trip per uid (multiple uids further merged by a pipeline)".
 *
 * 单列接口而非并入 QuestStoreInterface：主契约保持最小（save/get/all/delete），批量是后端的可选优化，
 * 未实现者（InMemory/第三方）由 CachedQuestStore 自动回落逐条回写——零破坏、零强制。
 * A separate interface rather than folding it into QuestStoreInterface: the primary contract stays minimal
 * (save/get/all/delete) and batching is an optional backend optimization — non-implementers (InMemory/third-party)
 * are auto-fallen-back to per-record write-back by CachedQuestStore, with zero breakage and zero obligation.
 */
interface QuestBatchStoreInterface
{
    /**
     * 批量整记录回写（whole-record 语义同 save）：空列表零操作；实现方应尽量合并往返。
     * 失败直接抛出（由调用方决定留脏/重试策略）——批量路径不做静默吞异常。
     * Batch whole-record write-back (same whole-record semantics as save): an empty list is a no-op; implementations
     * should merge round-trips as much as possible. Failures throw (the caller decides the keep-dirty/retry policy)
     * — the batch path never silently swallows exceptions.
     *
     * @param list<QuestProgress> $progresses 待回写进度 Records to write back.
     */
    public function saveMany(array $progresses): void;
}
