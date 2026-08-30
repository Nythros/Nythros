<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务进度存储 Redis 实现（照 GuildStore/FriendStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单，
 * 无 TTL 持久）。P4c 试点收口：任务进度从进程内 InMemoryQuestStore 换到跨进程持久后端——
 * 服务器重启后进度不丢（击杀/收集/对话/领奖标记全量落盘）。
 * Quest-progress store, Redis-backed (following the GuildStore/FriendStore precedent: a \Redis|\Closure constructor,
 * key prefixes and format whitelists; persistent with no TTL). The P4c pilot close-out: quest progress moves from
 * the in-process InMemoryQuestStore to a cross-process persistent backend — progress survives server restarts
 * (kill/collect/talk progress and the claim flag all persist).
 *
 * 键设计（nythros:gw: 前缀，可注入，ADR-015 §2 社交状态键族同族）：
 * - nythros:gw:quest:{uid}  hash {questId => JSON(QuestProgress 全字段)}（每任务一条，整体覆盖语义）
 * Key design (nythros:gw: prefix, injectable; same family as the ADR-015 §2 social-state keys):
 * - nythros:gw:quest:{uid}  hash {questId => JSON(all QuestProgress fields)} (one entry per quest, whole-record overwrite).
 *
 * demo 规模单机 Redis 下采用非原子读写（进度单键单字段，无队伍级别的跨进程不变量；与 FriendStore 同口径）。
 * Non-atomic read-modify-write at demo scale on a single Redis (progress is a single key/field per record, carrying
 * no team-level cross-process invariant; same stance as FriendStore).
 */
final class RedisQuestStore implements QuestStoreInterface
{
    /** 任务进度 hash 键子前缀（相对基前缀） Quest-progress hash key sub-prefix (relative to the base prefix). */
    private const QUEST_SUB_PREFIX = 'quest:';

    /** uid 格式白名单（uid 进入键构造，收敛键注入面，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 键基前缀（默认 nythros:gw:，测试可注入隔离前缀） Base key prefix (defaults to nythros:gw:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造任务进度存储。
     * Create the quest-progress store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:gw:） Base key prefix (defaults to nythros:gw:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:gw:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function save(QuestProgress $progress): void
    {
        $this->assertUid($progress->uid);

        $this->redis()->hSet(
            $this->questKey($progress->uid),
            $progress->questId,
            (string) json_encode([
                'uid' => $progress->uid,
                'questId' => $progress->questId,
                'count' => $progress->count,
                'completed' => $progress->completed,
                'rewarded' => $progress->rewarded,
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function get(string $uid, string $questId): ?QuestProgress
    {
        $this->assertUid($uid);

        $raw = $this->redis()->hGet($this->questKey($uid), $questId);
        if (!is_string($raw)) {
            return null;
        }

        return $this->decode($uid, $questId, $raw);
    }

    public function all(string $uid): array
    {
        $this->assertUid($uid);

        $records = $this->redis()->hGetAll($this->questKey($uid));
        if (!is_array($records)) {
            return [];
        }

        $progress = [];
        foreach ($records as $questId => $raw) {
            $decoded = $this->decode($uid, (string) $questId, (string) $raw);
            if ($decoded !== null) {
                $progress[] = $decoded;
            }
        }

        return $progress;
    }

    public function delete(string $uid, string $questId): void
    {
        $this->assertUid($uid);

        $this->redis()->hDel($this->questKey($uid), $questId);
    }

    /** 反序列化进度记录（字段损坏静默丢弃，返回 null——不把坏数据抛进状态机）。 Deserializes a progress record (a corrupt field is silently dropped as null — bad data never enters the state machine). */
    private function decode(string $uid, string $questId, string $raw): ?QuestProgress
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return new QuestProgress(
            $uid,
            $questId,
            (int) ($data['count'] ?? 0),
            (bool) ($data['completed'] ?? false),
            (bool) ($data['rewarded'] ?? false),
        );
    }

    private function questKey(string $uid): string
    {
        return $this->prefix . self::QUEST_SUB_PREFIX . $uid;
    }

    private function redis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $factory = $this->redis;
        $client = $factory();

        // 缓存工厂产物：本进程后续调用复用同一连接（照 RedisFriendStore 先例，局部变量收窄返回类型）
        // Cache the factory result: subsequent calls in this process reuse the same connection (following the
        // RedisFriendStore precedent; the local variable narrows the return type).
        $this->redis = $client;

        return $client;
    }

    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('任务进度存储 uid 格式非法: %s / quest store rejects an illegal uid: %s', $uid, $uid));
        }
    }
}
