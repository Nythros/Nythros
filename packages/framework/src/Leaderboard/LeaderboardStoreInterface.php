<?php

declare(strict_types=1);

namespace Nythros\Framework\Leaderboard;

/**
 * 排行榜存储契约（Redis ZSet 承载）：写入口径两式——业务上报（report，单 uid 实时覆盖）
 * 与定时聚合（aggregate，批量 upsert，供离线统计任务合并写入）；查询帧——top N 分页与单 uid 排名。
 * Leaderboard store contract (carried by a Redis ZSet): two write modes — business reporting (report, per-uid
 * real-time overwrite) and scheduled aggregation (aggregate, bulk upsert for offline statistics jobs); query
 * frames — paginated top N and a single uid's ranking.
 *
 * 排序口径：分数降序；同分成员按 Redis ZSet 字典序确定性排列。
 * Ordering: scores descend; equal-score members follow the Redis ZSet's deterministic lexicographic order.
 */
interface LeaderboardStoreInterface
{
    /**
     * 业务上报：单 uid 分数写入（同 uid 重复上报覆盖为最新分）。
     * Business reporting: writes one uid's score (repeated reports overwrite with the latest).
     */
    public function report(string $board, string $uid, float $score): void;

    /**
     * 定时聚合：批量 upsert（uid => score 映射一次合并写入，聚合任务口径）。
     * Scheduled aggregation: bulk upsert (a uid => score map merged in one pass — the aggregation-job mode).
     *
     * @param array<string, float|int> $scores uid => 分数映射 The uid => score map.
     */
    public function aggregate(string $board, array $scores): void;

    /**
     * 移除条目（uid 退出榜单）。
     * Remove an entry (the uid leaves the board).
     *
     * @return bool true = 已移除；false = 未上榜 true when removed; false when not on the board.
     */
    public function remove(string $board, string $uid): bool;

    /**
     * top N 查询（分数降序，rank 从 1 起，offset 分页）。
     * Top-N query (scores descend, ranks start at 1, paged via offset).
     *
     * @return list<array{rank: int, uid: string, score: float}> 榜单行 Board rows.
     */
    public function top(string $board, int $n, int $offset = 0): array;

    /**
     * 单 uid 排名（rank 从 1 起）；未上榜 null。
     * A single uid's ranking (rank starts at 1); null when not on the board.
     *
     * @return ?array{rank: int, score: float} 排名与分数；未上榜 null The rank and score; null when not on the board.
     */
    public function rankOf(string $board, string $uid): ?array;

    /**
     * 榜单规模（聚合任务与运维观测用）。
     * The board's size (for aggregation jobs and ops observation).
     */
    public function size(string $board): int;
}
