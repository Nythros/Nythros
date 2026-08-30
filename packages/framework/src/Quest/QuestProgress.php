<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务进度值对象：某 uid 对某任务的累计进度与状态标记（存储单元）。
 * Quest progress value object: one uid's accumulated progress and status flags for one quest (the storage unit).
 */
final readonly class QuestProgress
{
    /**
     * @param string $uid 玩家 uid The player uid.
     * @param string $questId 任务 id The quest id.
     * @param int $count 已累计进度 Current accumulated count.
     * @param bool $completed 是否已完成（count ≥ requiredCount） Whether completed (count >= requiredCount).
     * @param bool $rewarded 是否已领奖（领奖幂等标记） Whether rewarded (the claim-idempotency flag).
     */
    public function __construct(
        public string $uid,
        public string $questId,
        public int $count = 0,
        public bool $completed = false,
        public bool $rewarded = false,
    ) {
    }
}
