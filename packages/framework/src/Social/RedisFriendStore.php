<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 好友关系存储 Redis 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单，无 TTL 持久）。
 * Friend store, Redis-backed (following the GuildStore precedent: a \Redis|\Closure constructor, key prefixes and
 * format whitelists; persistent with no TTL).
 *
 * 键设计（nythros:gw: 前缀，ADR-015 §2 社交状态键族）：
 * - nythros:gw:friend:{uid}      set of 好友 uid（双向各写一份：A↔B 同时出现在 friend:A 与 friend:B）
 * - nythros:gw:friend-req:{uid}  hash {applicantUid => appliedAt}（指向 uid 的待处理申请）
 * Key design (nythros:gw: prefix, the ADR-015 §2 social-state key family):
 * - nythros:gw:friend:{uid}      set of friend uids (written on both sides: A↔B appear in both friend:A and friend:B)
 * - nythros:gw:friend-req:{uid}  hash {applicantUid => appliedAt} (pending requests targeting uid)
 *
 * demo 规模单机 Redis 下采用非原子读写（好友无队伍级别的跨进程不变量约束，与 GuildStore 同口径；
 * 见 ADR-015 §1.6 BLOCKER 说明仅约束 Team）。
 * Non-atomic read-modify-write at demo scale on a single Redis (friendship carries no cross-process invariant
 * comparable to the team's, same as GuildStore; see ADR-015 §1.6 BLOCKER notes constraining Team only).
 */
final class RedisFriendStore implements FriendStoreInterface
{
    /** 好友 set 键子前缀（相对基前缀） Friend-set key sub-prefix (relative to the base prefix). */
    private const FRIEND_SUB_PREFIX = 'friend:';

    /** 待处理申请 hash 键子前缀（相对基前缀） Pending-request hash key sub-prefix (relative to the base prefix). */
    private const REQUEST_SUB_PREFIX = 'friend-req:';

    /** uid 格式白名单（uid 进入键构造，收敛键注入面，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 键基前缀（默认 nythros:gw:，测试可注入隔离前缀） Base key prefix (defaults to nythros:gw:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造好友存储。
     * Create the friend store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:gw:） Base key prefix (defaults to nythros:gw:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:gw:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function apply(string $fromUid, string $toUid): array
    {
        $this->assertUid($fromUid);
        $this->assertUid($toUid);

        if ($fromUid === $toUid) {
            return ['code' => self::CODE_SELF];
        }

        $redis = $this->redis();

        // 已是好友 → 409 already_friends
        if ($redis->sIsMember($this->friendKey($fromUid), $toUid)) {
            return ['code' => self::CODE_ALREADY_FRIENDS];
        }

        // 重复申请 → 409 request_exists
        if ($redis->hExists($this->requestKey($toUid), $fromUid)) {
            return ['code' => self::CODE_REQUEST_EXISTS];
        }

        if ($redis->hSet($this->requestKey($toUid), $fromUid, (string) microtime(true)) === false) {
            throw new \RuntimeException(sprintf('RedisFriendStore apply 失败: %s', (string) $redis->getLastError()));
        }

        return ['code' => self::CODE_OK];
    }

    public function accept(string $applicantUid, string $acceptorUid): array
    {
        $this->assertUid($applicantUid);
        $this->assertUid($acceptorUid);

        if ($applicantUid === $acceptorUid) {
            return ['code' => self::CODE_SELF];
        }

        $redis = $this->redis();

        // 申请不存在 → 404 request_not_found
        if (!$redis->hExists($this->requestKey($acceptorUid), $applicantUid)) {
            return ['code' => self::CODE_REQUEST_NOT_FOUND];
        }
        $redis->hDel($this->requestKey($acceptorUid), $applicantUid);
        // 反向残留申请一并清除（互加场景下后同意方不再留下孤儿申请）
        // A reverse leftover request is cleared too (no orphan request survives in mutual-apply scenarios)
        $redis->hDel($this->requestKey($applicantUid), $acceptorUid);

        // 双向写好友关系（A→B 与 B→A 一致）
        // Write the friendship on both sides (A→B and B→A stay consistent)
        $redis->sAdd($this->friendKey($applicantUid), $acceptorUid);
        $redis->sAdd($this->friendKey($acceptorUid), $applicantUid);

        return ['code' => self::CODE_OK];
    }

    public function reject(string $applicantUid, string $rejectorUid): array
    {
        $this->assertUid($applicantUid);
        $this->assertUid($rejectorUid);

        $deleted = $this->redis()->hDel($this->requestKey($rejectorUid), $applicantUid);

        return ['code' => $deleted > 0 ? self::CODE_OK : self::CODE_REQUEST_NOT_FOUND];
    }

    public function remove(string $uid, string $targetUid): array
    {
        $this->assertUid($uid);
        $this->assertUid($targetUid);

        if ($uid === $targetUid) {
            return ['code' => self::CODE_SELF];
        }

        $redis = $this->redis();
        $removed = $redis->sRem($this->friendKey($uid), $targetUid);
        $redis->sRem($this->friendKey($targetUid), $uid);

        return ['code' => $removed > 0 ? self::CODE_OK : self::CODE_NOT_FRIENDS];
    }

    public function list(string $uid): array
    {
        $this->assertUid($uid);

        $members = $this->redis()->sMembers($this->friendKey($uid));
        if (!is_array($members) || $members === []) {
            return [];
        }

        $friends = array_values(array_filter(
            array_map('strval', $members),
            static fn (string $member): bool => $member !== '',
        ));
        sort($friends, SORT_STRING);

        return $friends;
    }

    /**
     * 获取当前进程使用的 phpredis 连接（工厂模式：每个 fork 出的进程各自建连一次）。
     * Get the phpredis connection used by the current process (factory mode: each forked process connects once on its own).
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
     * uid 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the uid against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisFriendStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * 好友 set 键：基前缀 + friend: + uid。
     * Friend-set key: base prefix + friend: + uid.
     */
    private function friendKey(string $uid): string
    {
        return $this->prefix . self::FRIEND_SUB_PREFIX . $uid;
    }

    /**
     * 待处理申请 hash 键：基前缀 + friend-req: + uid。
     * Pending-request hash key: base prefix + friend-req: + uid.
     */
    private function requestKey(string $uid): string
    {
        return $this->prefix . self::REQUEST_SUB_PREFIX . $uid;
    }
}
