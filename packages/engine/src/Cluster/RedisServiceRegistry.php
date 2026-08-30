<?php

declare(strict_types=1);

namespace Nythros\Cluster;

/**
 * 基于 Redis 的跨进程服务注册表（ADR-013 10.2/10.3，MAJOR-3 分组键）。
 * Redis-backed cross-process service registry (ADR-013 10.2/10.3, MAJOR-3 grouped keys).
 *
 * 键设计（键前缀默认 nythros:svc:，与 RedisTokenStore 的 nythros:token: 严格分离）：
 * Key design (default key prefix nythros:svc:, strictly separated from RedisTokenStore's nythros:token:):
 * - 服务 hash {prefix}{type} = hash {serviceId => JSON meta}
 *   Service hash {prefix}{type} = hash {serviceId => JSON meta}
 * - 心跳键 {prefix}hb:{type}:{serviceId} = SETEX 15s（值 = PHP 侧时钟时间戳，诊断用途）
 *   Heartbeat key {prefix}hb:{type}:{serviceId} = SETEX 15s (value = PHP-side clock timestamp, diagnostic use)
 * - uid 映射 {prefix}uid:{type}:{serviceId} = hash {uid => '1'}，EXPIRE 21600s（每次 bind 续期）
 *   uid mapping {prefix}uid:{type}:{serviceId} = hash {uid => '1'}, EXPIRE 21600s (renewed on every bind)
 *
 * 语义要点：
 * Semantics:
 * - register：HSET 整体覆盖 meta + 立即心跳；重复注册 = 覆盖 + 续心跳（自愈路径）。
 * - register: HSET overwrites meta wholesale + immediate heartbeat; re-registering overwrites and renews the heartbeat (self-healing path).
 * - heartbeat：meta 与既有值原子合并（Lua 单脚本读改写；未提及字段保留，playerCount 上报不丢 mapId/wsAddress 等注册字段）。
 * - heartbeat: meta is atomically merged with the existing values (a single Lua script does read-modify-write; untouched fields survive, so a playerCount report never loses the mapId/wsAddress etc. registered fields).
 * - discover：弱一致快照——HGETALL 服务 hash 后逐个检查心跳键，心跳键缺失（实例已死）即不可见，
 *   并惰性 HDEL 服务 hash 条目 + DEL 该实例的 uid hash（空间回收，MAJOR-3 自愈）。
 * - discover: weakly-consistent snapshot — HGETALL the service hash then check each heartbeat key; missing heartbeat keys (dead instances) are invisible and lazily HDELed from the service hash together with a DEL of that instance's uid hash (space reclamation, MAJOR-3 self-healing).
 * - unregister：HDEL 服务 hash + DEL 心跳键；uid hash 有意保留至其 TTL（6h 兜底）——不主动清理，
 *   因为死实例的 uid hash 已在 discover 惰性回收，剩余情形靠 TTL 兜底（与 ADR 10.3 一致）。
 * - unregister: HDEL the service hash + DEL the heartbeat key; the uid hash is intentionally kept until its TTL (6h fallback) — dead instances' uid hashes are already lazily reclaimed by discover, and the TTL covers the rest (per ADR 10.3).
 * - bind：HSET uid hash 的 {uid} 字段 = '1' + EXPIRE 续期（覆盖写 = 同 uid 后登录者覆盖先登录者；
 *   会话期 TTL 与 token TTL 解耦）。键按实例分组后，「仅当当前映射值等于 serviceId 才删」的
 *   条件删除语义天然成立（跨实例误删不可能）。
 * - bind: HSET the {uid} field of the uid hash to '1' + EXPIRE renewal (overwrite semantics: a later login overrides the earlier one; the session TTL is decoupled from the token TTL). With per-instance grouped keys, the "delete only when the current mapping equals serviceId" conditional-delete semantics hold naturally (cross-instance deletion is impossible).
 * - resolve：discover 得存活实例列表 → 按 serviceId 字典序（ksort + SORT_STRING）逐实例 HGET uid hash
 *   的 {uid} 字段 → 首个命中返回该 serviceId → 全 miss 返回 null。多实例命中时字典序取首个，
 *   语义确定且稳定（MINOR-R4），不保证与「最后登录/最近活动」频道一致。
 * - resolve: discover the live instances → iterate them in lexicographic serviceId order (ksort with SORT_STRING) HGETting the {uid} field of each instance's uid hash → the first hit returns that serviceId → all miss returns null. With multiple hits the lexicographically first instance wins — deterministic and stable (MINOR-R4), with no guarantee of matching the "last login / most recent activity" channel.
 *
 * 连接管理：与 RedisTokenStore 相同的工厂模式——构造可传已连接的 \Redis 实例（单进程/测试场景），
 * 或传连接工厂闭包（Workerman 多 Worker 场景：fork 复制 socket fd，多 worker 共享同一连接会破坏
 * Redis 协议；工厂在 fork 后各 worker 首次使用时调用，每个进程各自建连）。
 * Connection management: same factory pattern as RedisTokenStore — pass a connected \Redis instance (single-process / test scenarios) or a connection-factory closure (Workerman multi-Worker scenarios: fork duplicates socket fds and sharing one connection across workers would corrupt the Redis protocol; the factory is invoked on first use in each worker after fork, so every process opens its own connection).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class RedisServiceRegistry implements ServiceRegistryInterface
{
    /**
     * 心跳原子合并脚本：读旧 meta（缺失/畸形按空表处理），与传入 meta 逐字段合并后 HSET 回写。
     * 单脚本原子执行，并发心跳不会丢字段（register 的整体覆盖与 heartbeat 的合并语义严格区分）。
     * Atomic heartbeat-merge script: read the old meta (missing/malformed treated as empty), merge the incoming meta field by field, then HSET back. The single-script execution is atomic, so concurrent heartbeats never lose fields (register's wholesale overwrite is strictly separated from heartbeat's merge semantics).
     * KEYS[1] = 服务 hash；ARGV[1] = serviceId；ARGV[2] = 合并 meta JSON。
     * KEYS[1] = service hash; ARGV[1] = serviceId; ARGV[2] = merged-in meta JSON.
     */
    private const HEARTBEAT_MERGE_SCRIPT = <<<'LUA'
-- KEYS[1]=服务 hash；ARGV[1]=serviceId；ARGV[2]=合并 meta JSON
local raw = redis.call('HGET', KEYS[1], ARGV[1])
local merged = {}
if raw then
    local ok, old = pcall(cjson.decode, raw)
    if ok and type(old) == 'table' then
        merged = old
    end
end
local okNew, newMeta = pcall(cjson.decode, ARGV[2])
if okNew and type(newMeta) == 'table' then
    for k, v in pairs(newMeta) do
        merged[k] = v
    end
end
redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(merged))
return 1
LUA;

    /**
     * serviceType 格式白名单：小写字母开头，仅小写字母/数字/连字符，长度 1~32
     * （与 scope 同规则；serviceType 进入服务 hash/心跳键/uid hash 键构造，白名单收敛键注入面）。
     * serviceType format whitelist: starts with a lowercase letter, lowercase letters/digits/hyphens only, length 1-32 (same rule as scopes; serviceType enters service-hash/heartbeat-key/uid-hash key construction, so the whitelist narrows the key-injection surface).
     */
    private const SERVICE_TYPE_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /**
     * serviceId 格式白名单：字母数字开头，仅字母数字/'.'/'_'/'#'/':'/'-'，长度 1~64
     * （覆盖 'chat-1'/'team-1'/'map-1#ch-1' 等编码；serviceId 进入心跳键/uid hash 键构造）。
     * serviceId format whitelist: starts alphanumeric, only alphanumerics/'.'/'_'/'#'/':'/'-', length 1-64 (covers 'chat-1'/'team-1'/'map-1#ch-1' encodings; serviceId enters heartbeat-key/uid-hash key construction).
     */
    private const SERVICE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._#:-]{0,63}$/';

    /** 心跳 TTL 秒数（ADR 10.3：15s） Heartbeat TTL in seconds (ADR 10.3: 15s) */
    private const HEARTBEAT_TTL = 15;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /** 键前缀 Key prefix */
    private readonly string $prefix;

    /** @var \Closure(): float 时间源 Time source */
    private \Closure $clock;

    /**
     * 构造 Redis 服务注册表。
     * Create a Redis service registry.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂
     *                                          （多进程场景必须用工厂，fork 后各进程首次使用时各自建连）
     *                                          Connected phpredis client, or a factory returning a connected
     *                                          client (multi-process scenarios must use the factory: each
     *                                          process connects on first use after fork)
     * @param string $prefix     键前缀（默认 nythros:svc:，与 token 前缀 nythros:token: 严格分离）
     *                           Key prefix (defaults to nythros:svc:, strictly separated from the token prefix nythros:token:)
     * @param ?callable(): float $clock 时间源，缺省 microtime(true)（心跳键值的时间戳来源）
     *                                  Time source; defaults to microtime(true) (source of the heartbeat key's timestamp value)
     */
    public function __construct(
        \Redis|\Closure $redis,
        string $prefix = 'nythros:svc:',
        ?callable $clock = null,
    ) {
        // 运行时守卫（对齐 MySqlStorage 的 pdo_mysql 守卫）：phpredis 缺失时给出明确的替代实现指引，
        // 而不是等到首次 Redis 调用才报未定义类/方法。
        // Runtime guard (aligned with MySqlStorage's pdo_mysql guard): when phpredis is missing, fail fast with a
        // clear alternative-implementation hint instead of an undefined class/method on the first Redis call.
        if (!extension_loaded('redis')) {
            throw new \InvalidArgumentException(
                'RedisServiceRegistry 需要 ext-redis：缺失该扩展时请改用进程内注册表等替代实现',
            );
        }

        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->clock = \Closure::fromCallable($clock ?? static fn (): float => microtime(true));
    }

    /**
     * 注册服务实例：HSET 整体覆盖 meta（JSON）+ 立即心跳；重复注册 = 覆盖 + 续心跳（自愈路径）。
     * Register a service instance: HSET overwrites meta wholesale (JSON) + immediate heartbeat; re-registering overwrites and renews the heartbeat (self-healing path).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param array<string, mixed> $meta 实例元数据 Instance metadata.
     * @throws \InvalidArgumentException serviceType/serviceId 格式非法 Illegal serviceType/serviceId format.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    public function register(string $serviceType, string $serviceId, array $meta = []): void
    {
        $this->assertServiceType($serviceType);
        $this->assertServiceId($serviceId);
        $redis = $this->redis();

        if ($redis->hSet($this->svcKey($serviceType), $serviceId, $this->encodeMeta($meta)) === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry register 失败: %s', (string) $redis->getLastError()));
        }
        $this->setHeartbeat($redis, $serviceType, $serviceId);
    }

    /**
     * 心跳续期：meta 原子合并（Lua 读改写，未提及字段保留）+ SETEX 心跳键 15s。
     * Renew the heartbeat: meta is atomically merged (Lua read-modify-write, untouched fields survive) + SETEX the heartbeat key for 15s.
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param array<string, mixed> $meta 心跳携带的元数据（与既有 meta 合并） Metadata carried by the heartbeat (merged with the existing meta).
     * @throws \InvalidArgumentException serviceType/serviceId 格式非法 Illegal serviceType/serviceId format.
     * @throws \RuntimeException Redis 执行失败 Redis execution failed.
     */
    public function heartbeat(string $serviceType, string $serviceId, array $meta = []): void
    {
        $this->assertServiceType($serviceType);
        $this->assertServiceId($serviceId);
        $redis = $this->redis();

        if ($meta !== []) {
            $result = $redis->eval(
                self::HEARTBEAT_MERGE_SCRIPT,
                [
                    $this->svcKey($serviceType),
                    $serviceId,
                    $this->encodeMeta($meta),
                ],
                1,
            );
            if ($result === false) {
                throw new \RuntimeException(sprintf('RedisServiceRegistry heartbeat 失败: %s', (string) $redis->getLastError()));
            }
        }
        $this->setHeartbeat($redis, $serviceType, $serviceId);
    }

    /**
     * 发现存活实例：HGETALL 服务 hash → 逐实例检查心跳键，心跳键缺失即不可见，
     * 并惰性 HDEL 服务 hash 条目 + DEL 该实例 uid hash（空间回收）。
     * Discover live instances: HGETALL the service hash → check each heartbeat key; instances whose heartbeat key is gone are invisible and lazily HDELed from the service hash with a DEL of their uid hash (space reclamation).
     *
     * @param string $serviceType 服务类型 Service type.
     * @return array<string, ServiceInstance> map<serviceId, ServiceInstance> 存活实例映射 Live instance map.
     * @throws \InvalidArgumentException serviceType 格式非法 Illegal serviceType format.
     * @throws \RuntimeException Redis 读取失败 Redis read failed.
     */
    public function discover(string $serviceType): array
    {
        $this->assertServiceType($serviceType);
        $redis = $this->redis();

        $entries = $redis->hGetAll($this->svcKey($serviceType));
        if ($entries === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry discover 失败: %s', (string) $redis->getLastError()));
        }

        $instances = [];
        foreach ($entries as $serviceId => $rawMeta) {
            $serviceId = (string) $serviceId;

            // 心跳键缺失 = 实例已死（kill -9 / 心跳停摆）：不可见 + 惰性回收
            // Missing heartbeat key = dead instance (kill -9 / heartbeat stall): invisible + lazy reclamation
            if (!$this->isAlive($redis, $serviceType, $serviceId)) {
                $redis->hDel($this->svcKey($serviceType), $serviceId);
                $redis->del($this->uidKey($serviceType, $serviceId));
                continue;
            }

            // meta 畸形（非 JSON 对象）：防御性忽略并回收（视为死数据，防畸形条目反复出现）
            // Malformed meta (not a JSON object): defensively ignored and reclaimed (treated as dead data, preventing repeated hits)
            $meta = $this->decodeMeta($rawMeta);
            if ($meta === null) {
                $redis->hDel($this->svcKey($serviceType), $serviceId);
                continue;
            }

            $instances[$serviceId] = new ServiceInstance($serviceId, $meta);
        }

        return $instances;
    }

    /**
     * 注销服务实例：HDEL 服务 hash + DEL 心跳键；uid hash 有意保留至其 TTL（6h 兜底，
     * 死实例的 uid hash 由 discover 惰性回收，此处不主动清理）。
     * Unregister a service instance: HDEL the service hash + DEL the heartbeat key; the uid hash is intentionally kept until its TTL (6h fallback — dead instances' uid hashes are lazily reclaimed by discover, so nothing is actively cleaned here).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @throws \InvalidArgumentException serviceType/serviceId 格式非法 Illegal serviceType/serviceId format.
     * @throws \RuntimeException Redis 执行失败 Redis execution failed.
     */
    public function unregister(string $serviceType, string $serviceId): void
    {
        $this->assertServiceType($serviceType);
        $this->assertServiceId($serviceId);
        $redis = $this->redis();

        if ($redis->hDel($this->svcKey($serviceType), $serviceId) === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry unregister 失败: %s', (string) $redis->getLastError()));
        }
        $redis->del($this->hbKey($serviceType, $serviceId));
    }

    /**
     * uid 寻址：discover 得存活实例列表 → 按 serviceId 字典序（SORT_STRING）逐实例 HGET uid hash 的
     * {uid} 字段 → 首个命中返回该 serviceId → 全 miss 返回 null（MINOR-R4 确定性语义）。
     * uid addressing: discover the live instances → iterate them in lexicographic serviceId order (SORT_STRING) HGETting the {uid} field of each instance's uid hash → the first hit returns that serviceId → all miss returns null (MINOR-R4 deterministic semantics).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @return ?string 存活实例 id，无映射 null Live instance id, or null when unbound.
     * @throws \InvalidArgumentException serviceType 格式非法或 uid 为空 Illegal serviceType format or empty uid.
     * @throws \RuntimeException Redis 读取失败 Redis read failed.
     */
    public function resolve(string $serviceType, string $uid): ?string
    {
        $this->assertServiceType($serviceType);
        $this->assertUid($uid);

        $instances = $this->discover($serviceType);
        if ($instances === []) {
            return null;
        }

        // 字典序排序（SORT_STRING：serviceId 如 'map-10#ch-1' 与 'map-2#ch-1' 必须按字符串比较，
        // SORT_REGULAR 会把数值前缀转数字比较导致 'map-2#ch-1' 排在 'map-10#ch-1' 之后且比较不稳定）
        // Lexicographic sort (SORT_STRING: serviceIds like 'map-10#ch-1' vs 'map-2#ch-1' must compare as strings; SORT_REGULAR would coerce numeric prefixes and both compare unstable and misorder them)
        ksort($instances, SORT_STRING);

        $redis = $this->redis();
        foreach ($instances as $serviceId => $instance) {
            if ($redis->hGet($this->uidKey($serviceType, $serviceId), $uid) !== false) {
                return $serviceId;
            }
        }

        return null;
    }

    /**
     * 绑定 uid → 实例：HSET uid hash 的 {uid} 字段 = '1' + EXPIRE 续期（覆盖写 = 同 uid 后登录者
     * 覆盖先登录者；键按实例分组后条件删除语义天然成立）。
     * Bind a uid to an instance: HSET the {uid} field of the uid hash to '1' + EXPIRE renewal (overwrite semantics: a later login overrides the earlier one; with per-instance grouped keys the conditional-delete semantics hold naturally).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param int $ttlSeconds 绑定 TTL 秒数（每次 bind 续期） Binding TTL in seconds (renewed on every bind).
     * @throws \InvalidArgumentException 参数格式非法 Illegal parameter format.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    public function bind(string $serviceType, string $uid, string $serviceId, int $ttlSeconds = 21600): void
    {
        $this->assertServiceType($serviceType);
        $this->assertUid($uid);
        $this->assertServiceId($serviceId);
        $redis = $this->redis();

        if ($redis->hSet($this->uidKey($serviceType, $serviceId), $uid, '1') === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry bind 失败: %s', (string) $redis->getLastError()));
        }
        $redis->expire($this->uidKey($serviceType, $serviceId), max(1, $ttlSeconds));
    }

    /**
     * 解除 uid → 实例绑定：HDEL 该实例 uid hash 的 {uid} 字段——键按实例分组后，
     * 「仅当当前映射值等于 serviceId 才删」的条件删除语义天然成立（跨实例误删不可能）。
     * Unbind a uid from an instance: HDEL the {uid} field of that instance's uid hash — with per-instance grouped keys, the "delete only when the current mapping equals serviceId" conditional-delete semantics hold naturally (cross-instance deletion is impossible).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @param string $serviceId 实例标识 Instance identifier.
     * @throws \InvalidArgumentException 参数格式非法 Illegal parameter format.
     * @throws \RuntimeException Redis 执行失败 Redis execution failed.
     */
    public function unbind(string $serviceType, string $uid, string $serviceId): void
    {
        $this->assertServiceType($serviceType);
        $this->assertUid($uid);
        $this->assertServiceId($serviceId);
        $redis = $this->redis();

        if ($redis->hDel($this->uidKey($serviceType, $serviceId), $uid) === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry unbind 失败: %s', (string) $redis->getLastError()));
        }
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
     * 写心跳键：SETEX 15s，值 = PHP 侧时钟时间戳（诊断用途；TTL 由 Redis 管理，不依赖 PHP 时钟）。
     * Write the heartbeat key: SETEX 15s, value = PHP-side clock timestamp (diagnostic use; the TTL is managed by Redis, not the PHP clock).
     *
     * @param \Redis $redis 当前进程的 phpredis 连接 The phpredis connection of the current process.
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    private function setHeartbeat(\Redis $redis, string $serviceType, string $serviceId): void
    {
        if ($redis->setex($this->hbKey($serviceType, $serviceId), self::HEARTBEAT_TTL, (string) ($this->clock)()) === false) {
            throw new \RuntimeException(sprintf('RedisServiceRegistry 心跳写入失败: %s', (string) $redis->getLastError()));
        }
    }

    /**
     * 判断实例心跳键是否存在（存在即存活）。
     * Whether the instance's heartbeat key exists (existing means alive).
     *
     * @param \Redis $redis 当前进程的 phpredis 连接 The phpredis connection of the current process.
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @return bool true 存活 true when alive.
     */
    private function isAlive(\Redis $redis, string $serviceType, string $serviceId): bool
    {
        return $redis->exists($this->hbKey($serviceType, $serviceId)) > 0;
    }

    /**
     * 编码 meta 为 JSON（过滤 null 值：Redis Lua cjson 无法编码 null 值，null 字段被忽略）。
     * Encode meta as JSON (null values are filtered: the Redis Lua cjson cannot encode nulls, so null fields are ignored).
     *
     * @param array<string, mixed> $meta 实例元数据 Instance metadata.
     * @return string JSON 字符串 JSON string.
     * @throws \JsonException meta 含不可 JSON 编码的值 Meta contains values that cannot be JSON-encoded.
     */
    private function encodeMeta(array $meta): string
    {
        $meta = array_filter(
            $meta,
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 解码 meta JSON；非 JSON 对象返回 null（防御性忽略）。
     * Decode meta JSON; returns null when it is not a JSON object (defensive ignore).
     *
     * @param string $rawMeta 原始 meta JSON Raw meta JSON.
     * @return ?array<string, mixed> meta 数组，畸形时 null Meta array, or null when malformed.
     */
    private function decodeMeta(string $rawMeta): ?array
    {
        try {
            $meta = json_decode($rawMeta, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($meta) ? $meta : null;
    }

    /**
     * serviceType 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the serviceType against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @param string $serviceType 服务类型 Service type.
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertServiceType(string $serviceType): void
    {
        if (preg_match(self::SERVICE_TYPE_PATTERN, $serviceType) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisServiceRegistry: 非法 serviceType 格式: %s', $serviceType));
        }
    }

    /**
     * serviceId 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the serviceId against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @param string $serviceId 实例标识 Instance identifier.
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertServiceId(string $serviceId): void
    {
        if (preg_match(self::SERVICE_ID_PATTERN, $serviceId) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisServiceRegistry: 非法 serviceId 格式: %s', $serviceId));
        }
    }

    /**
     * uid 最小校验：仅拒绝空字符串（uid 是 hash field 而非键组成部分，无键注入面，不收紧格式）。
     * Minimal uid validation: only rejects the empty string (uid is a hash field, not part of any key, so there is no key-injection surface to narrow with a format rule).
     *
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @throws \InvalidArgumentException uid 为空字符串 Empty uid.
     */
    private function assertUid(string $uid): void
    {
        if ($uid === '') {
            throw new \InvalidArgumentException('RedisServiceRegistry: uid 不能为空字符串');
        }
    }

    /**
     * 服务 hash 键：前缀 + 服务类型。
     * Service hash key: prefix + service type.
     *
     * @param string $serviceType 服务类型 Service type.
     * @return string Redis 服务 hash 键 Redis service hash key.
     */
    private function svcKey(string $serviceType): string
    {
        return $this->prefix . $serviceType;
    }

    /**
     * 心跳键：前缀 + hb: + 服务类型 + : + 实例标识。
     * Heartbeat key: prefix + hb: + service type + : + instance identifier.
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @return string Redis 心跳键 Redis heartbeat key.
     */
    private function hbKey(string $serviceType, string $serviceId): string
    {
        return $this->prefix . 'hb:' . $serviceType . ':' . $serviceId;
    }

    /**
     * uid hash 键：前缀 + uid: + 服务类型 + : + 实例标识。
     * uid hash key: prefix + uid: + service type + : + instance identifier.
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @return string Redis uid hash 键 Redis uid hash key.
     */
    private function uidKey(string $serviceType, string $serviceId): string
    {
        return $this->prefix . 'uid:' . $serviceType . ':' . $serviceId;
    }
}
