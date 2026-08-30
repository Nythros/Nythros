<?php

declare(strict_types=1);

namespace Nythros\Framework\Leaderboard;

/**
 * 排行榜存储 Redis ZSet 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单）。
 * Leaderboard store, Redis ZSet-backed (following the GuildStore precedent: a \Redis|\Closure constructor,
 * key prefixes and format whitelists).
 *
 * 键设计（nythros:lb: 前缀）：
 * - nythros:lb:board:{boardId}   zset {uid => score}（无 TTL，持久；分数降序即榜单）
 * Key design (nythros:lb: prefix):
 * - nythros:lb:board:{boardId}   zset {uid => score} (no TTL, persistent; the descending score order is the board)
 *
 * 写入口径两式：report（业务上报，ZADD 覆盖）与 aggregate（定时聚合，批量 ZADD upsert）；
 * 查询帧走 ZREVRANGE（含 WITHSCORES）/ZREVRANK+ZSCORE。同分成员按 Redis 字典序确定性排列。
 * Two write modes: report (business reporting, ZADD overwrite) and aggregate (scheduled aggregation, bulk ZADD
 * upsert); query frames ride ZREVRANGE (WITHSCORES) / ZREVRANK+ZSCORE. Equal-score members follow Redis's
 * deterministic lexicographic order.
 */
final class RedisLeaderboardStore implements LeaderboardStoreInterface
{
    /** 榜单 zset 键子前缀（相对基前缀） Board-zset key sub-prefix (relative to the base prefix). */
    private const BOARD_SUB_PREFIX = 'board:';

    /** 批量聚合单次写入的条目上限（防超长命令） Per-write entry cap for bulk aggregation (guards against oversized commands). */
    private const AGGREGATE_CHUNK = 1000;

    /** uid 格式白名单（uid 进入 zset member，收敛注入面） uid format whitelist (uid enters as a zset member). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** boardId 格式白名单（SERVICE_ID 风格，进入键构造） boardId format whitelist (SERVICE_ID style, enters key construction). */
    private const BOARD_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._#:-]{0,63}$/';

    /** 键基前缀（默认 nythros:lb:，测试可注入隔离前缀） Base key prefix (defaults to nythros:lb:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造排行榜存储。
     * Create the leaderboard store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:lb:） Base key prefix (defaults to nythros:lb:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:lb:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function report(string $board, string $uid, float $score): void
    {
        $this->assertBoardId($board);
        $this->assertUid($uid);

        if ($this->redis()->zAdd($this->boardKey($board), $score, $uid) === false) {
            throw new \RuntimeException(sprintf('RedisLeaderboardStore report 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    public function aggregate(string $board, array $scores): void
    {
        $this->assertBoardId($board);

        /** @var list<float|string> $entries 交替 [score, uid] 的扁平参数表 The flat [score, uid] alternating argument list. */
        $entries = [];
        foreach ($scores as $uid => $score) {
            $this->assertUid((string) $uid);
            $entries[] = (float) $score;
            $entries[] = (string) $uid;

            if (count($entries) >= self::AGGREGATE_CHUNK * 2) {
                $this->zAddEntries($board, $entries);
                $entries = [];
            }
        }

        if ($entries !== []) {
            $this->zAddEntries($board, $entries);
        }
    }

    /**
     * 批量写入 zset 条目（交替 [score, uid...] 变参；首元素显式 float 化满足 phpredis 签名）。
     * Bulk-write zset entries (alternating [score, uid...] variadic; the leading element is explicitly floated to
     * satisfy the phpredis signature).
     *
     * @param list<float|string> $entries
     */
    private function zAddEntries(string $board, array $entries): void
    {
        $leadingScore = array_shift($entries);
        if ($this->redis()->zAdd($this->boardKey($board), (float) $leadingScore, ...$entries) === false) {
            throw new \RuntimeException(sprintf('RedisLeaderboardStore aggregate 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    public function remove(string $board, string $uid): bool
    {
        $this->assertBoardId($board);
        $this->assertUid($uid);

        return $this->redis()->zRem($this->boardKey($board), $uid) > 0;
    }

    public function top(string $board, int $n, int $offset = 0): array
    {
        $this->assertBoardId($board);
        if ($n < 1 || $offset < 0) {
            throw new \InvalidArgumentException('RedisLeaderboardStore: n 必须为正整数且 offset 非负');
        }

        $raw = $this->redis()->zRevRange($this->boardKey($board), $offset, $offset + $n - 1, true);
        if ($raw === false || $raw === []) {
            return [];
        }

        $rows = [];
        $index = 0;
        foreach ($raw as $uid => $score) {
            $rows[] = ['rank' => $offset + $index + 1, 'uid' => (string) $uid, 'score' => (float) $score];
            $index++;
        }

        return $rows;
    }

    public function rankOf(string $board, string $uid): ?array
    {
        $this->assertBoardId($board);
        $this->assertUid($uid);

        $rank = $this->redis()->zRevRank($this->boardKey($board), $uid);
        if ($rank === false) {
            return null;
        }

        /** @var float|false $score phpredis 序列化器关闭时返回 float，异常态 false The phpredis float with the serializer off; false in the abnormal state. */
        $score = $this->redis()->zScore($this->boardKey($board), $uid);

        return ['rank' => $rank + 1, 'score' => $score === false ? 0.0 : (float) $score];
    }

    public function size(string $board): int
    {
        $this->assertBoardId($board);

        $size = $this->redis()->zCard($this->boardKey($board));

        return $size === false ? 0 : $size;
    }

    /**
     * 获取当前进程使用的 phpredis 连接（工厂模式：每个 fork 出的进程各自建连一次）。
     * Get the phpredis connection used by the current process (factory mode: each forked process connects once on its own).
     *
     * @return \Redis 当前进程的 phpredis 连接 The phpredis connection of the current process.
     */
    private function redis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $factory = $this->redis;
        $client = $factory();

        // 缓存工厂产物：本进程后续调用复用同一连接 Cache the factory result: subsequent calls in this process reuse the same connection
        $this->redis = $client;

        return $client;
    }

    /**
     * uid 格式白名单校验（进入 zset member 的字段收敛注入面）。
     * Validate the uid against its format whitelist (narrowing the injection surface of zset members).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisLeaderboardStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * boardId 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the boardId against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertBoardId(string $board): void
    {
        if (preg_match(self::BOARD_ID_PATTERN, $board) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisLeaderboardStore: 非法 boardId 格式: %s', $board));
        }
    }

    /**
     * 榜单 zset 键：基前缀 + board: + boardId。
     * Board zset key: base prefix + board: + boardId.
     */
    private function boardKey(string $board): string
    {
        return $this->prefix . self::BOARD_SUB_PREFIX . $board;
    }
}
