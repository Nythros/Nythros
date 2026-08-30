<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 位置快照与掉线标记存储（Redis 持久，跨进程）。
 * Location snapshot and offline-marker store (Redis-backed, cross-process).
 *
 * 键设计（nythros:gw: 前缀，与 token:/svc: 严格三前缀隔离，ADR-015 §2）：
 * - nythros:gw:location:{uid}  SETEX 300s JSON {mapId, channelId, x, y, updatedAt}（x/y 允许 null）
 * - nythros:gw:offline:{uid}   SETEX 300s '1'（onClose 写，auth 恢复判定读）
 * Key design (nythros:gw: prefix, strictly separated from token:/svc:; ADR-015 §2):
 * - nythros:gw:location:{uid}  SETEX 300s JSON {mapId, channelId, x, y, updatedAt} (x/y may be null)
 * - nythros:gw:offline:{uid}   SETEX 300s '1' (written onClose, read by auth recovery)
 *
 * 连接管理：与 RedisTokenStore/RedisServiceRegistry 相同的工厂模式——构造可传已连接的 \Redis 实例
 * （单进程/测试场景），或传连接工厂闭包（Workerman 多 Worker 场景：fork 后各进程首次使用时各自建连）。
 * Connection management: the same factory pattern as RedisTokenStore/RedisServiceRegistry — pass a connected
 * \Redis instance (single-process / test scenarios) or a connection-factory closure (Workerman multi-Worker
 * scenarios: each process connects on first use after fork).
 */
final class LocationStore implements LocationStoreInterface
{
    /** 位置快照 TTL（秒） Location snapshot TTL in seconds. */
    private const LOCATION_TTL = 300;

    /** 掉线标记 TTL（秒） Offline marker TTL in seconds. */
    private const OFFLINE_TTL = 300;

    /** 位置快照键子前缀（相对基前缀） Location snapshot key sub-prefix (relative to the base prefix). */
    private const LOCATION_SUB_PREFIX = 'location:';

    /** 掉线标记键子前缀（相对基前缀） Offline marker key sub-prefix (relative to the base prefix). */
    private const OFFLINE_SUB_PREFIX = 'offline:';

    /** uid 格式白名单（uid 进入 location/offline 键构造，收敛键注入面，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 键基前缀（默认 nythros:gw:，测试可注入隔离前缀） Base key prefix (defaults to nythros:gw:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造位置存储。
     * Create the location store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:gw:） Base key prefix (defaults to nythros:gw:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:gw:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * 写掉线标记（SETEX 300s '1'）。
     * Write the offline marker (SETEX 300s '1').
     *
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    public function markOffline(string $uid): void
    {
        $this->assertUid($uid);

        if ($this->redis()->setex($this->offlineKey($uid), self::OFFLINE_TTL, '1') === false) {
            throw new \RuntimeException(sprintf('LocationStore markOffline 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    /**
     * 掉线判定（EXISTS offline:{uid}）。
     * Offline verdict (EXISTS offline:{uid}).
     *
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     */
    public function isOffline(string $uid): bool
    {
        $this->assertUid($uid);

        return $this->redis()->exists($this->offlineKey($uid)) > 0;
    }

    /**
     * 写位置快照（SETEX 300s JSON，覆盖写）。
     * Write the location snapshot (SETEX 300s JSON, overwrite).
     *
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    public function saveLocation(string $uid, string $mapId, string $channelId, ?float $x = null, ?float $y = null): void
    {
        $this->assertUid($uid);

        $payload = json_encode([
            'mapId' => $mapId,
            'channelId' => $channelId,
            'x' => $x,
            'y' => $y,
            'updatedAt' => microtime(true),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->redis()->setex($this->locationKey($uid), self::LOCATION_TTL, $payload) === false) {
            throw new \RuntimeException(sprintf('LocationStore saveLocation 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    /**
     * 读位置快照（GET location:{uid} → 解码 → 逐字段校验）。
     * Read the location snapshot (GET location:{uid} → decode → per-field validation).
     *
     * @return ?array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float} 快照数据；不可见时 null Snapshot; null when unavailable.
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     */
    public function getLocation(string $uid): ?array
    {
        $this->assertUid($uid);

        $raw = $this->redis()->get($this->locationKey($uid));
        if (!is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)
            || !is_string($data['mapId'] ?? null)
            || !is_string($data['channelId'] ?? null)
        ) {
            return null;
        }

        $x = $data['x'] ?? null;
        $x = is_int($x) || is_float($x) ? (float) $x : null;
        $y = $data['y'] ?? null;
        $y = is_int($y) || is_float($y) ? (float) $y : null;
        $updatedAt = is_numeric($data['updatedAt'] ?? null) ? (float) $data['updatedAt'] : 0.0;

        return [
            'mapId' => $data['mapId'],
            'channelId' => $data['channelId'],
            'x' => $x,
            'y' => $y,
            'updatedAt' => $updatedAt,
        ];
    }

    /**
     * 清除掉线标记（DEL offline:{uid}）。
     * Clear the offline marker (DEL offline:{uid}).
     *
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     */
    public function clearOffline(string $uid): void
    {
        $this->assertUid($uid);

        $this->redis()->del($this->offlineKey($uid));
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
     * uid 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the uid against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('LocationStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * 位置快照键：基前缀 + location: + uid。
     * Location snapshot key: base prefix + location: + uid.
     */
    private function locationKey(string $uid): string
    {
        return $this->prefix . self::LOCATION_SUB_PREFIX . $uid;
    }

    /**
     * 掉线标记键：基前缀 + offline: + uid。
     * Offline marker key: base prefix + offline: + uid.
     */
    private function offlineKey(string $uid): string
    {
        return $this->prefix . self::OFFLINE_SUB_PREFIX . $uid;
    }
}
