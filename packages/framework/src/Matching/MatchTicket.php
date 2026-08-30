<?php

declare(strict_types=1);

namespace Nythros\Framework\Matching;

/**
 * 排队票值对象：候选者在匹配队列中的登记记录。
 * Match ticket value object: a candidate's registration record inside a matching queue.
 */
final readonly class MatchTicket
{
    /**
     * @param string $uid 候选者账号 uid The candidate's account uid.
     * @param string $entityId 候选者实体 id（join 编排的定位键） The candidate's entity id (the join-orchestration locator).
     * @param int $level 准入等级（enqueue 时校验） The admission level (validated at enqueue).
     * @param string $queueId 所在队列 id The owning queue id.
     * @param float $enqueuedAt 入队时刻（microtime 秒） Enqueue instant (microtime seconds).
     */
    public function __construct(
        public string $uid,
        public string $entityId,
        public int $level,
        public string $queueId,
        public float $enqueuedAt,
    ) {
    }
}
