<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务进度存储契约：进度状态机的持久化边界（实现方负责序列化，Redis/MySQL 等后端按部署裁决）。
 * The quest-progress storage contract: the persistence boundary of the progress state machine (implementations own
 * serialization; Redis/MySQL backends are ruled per deployment).
 */
interface QuestStoreInterface
{
    /**
     * 保存（整体覆盖语义：以传入进度为准）。
     * Saves (whole-record semantics: the passed-in progress is authoritative).
     */
    public function save(QuestProgress $progress): void;

    /**
     * 查询某 uid 某任务的进度；无记录返回 null。
     * Looks up one uid's progress on one quest; null when unrecorded.
     */
    public function get(string $uid, string $questId): ?QuestProgress;

    /**
     * 某 uid 的全部任务进度。
     * All of one uid's quest progress.
     *
     * @return list<QuestProgress>
     */
    public function all(string $uid): array;

    /**
     * 删除某 uid 某任务的进度记录；不存在静默。
     * Deletes one uid's progress record of one quest; silently ignored when absent.
     */
    public function delete(string $uid, string $questId): void;
}
