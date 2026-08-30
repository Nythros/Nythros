<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * 基于 Redis 的跨进程 TokenStore。
 * Redis-backed cross-process TokenStore.
 *
 * 背景：Workerman 在 Linux 下为每个 Worker fork 独立进程，InMemoryTokenStore 的
 * 进程内数组不跨进程共享——gateway 进程签发的 token 在 map 进程 consume 永远是
 * Invalid。因此 token 状态必须落地 Redis（127.0.0.1:6379）。
 * Background: under Linux, Workerman forks an independent process per Worker, so the
 * in-process arrays of InMemoryTokenStore are not shared across processes — tokens issued
 * by the gateway process would always consume as Invalid in the map process. Token state
 * must therefore be persisted in Redis (127.0.0.1:6379).
 *
 * 键设计：
 * Key design:
 * - 主键 {prefix}{token}                   JSON（uid/mapId/scopes/issuedAt/expiresAt），
 *                                          Redis TTL = ttlSeconds + graceSeconds（仅兜底清理）
 * - Main key {prefix}{token}               JSON (uid/mapId/scopes/issuedAt/expiresAt),
 *                                          Redis TTL = ttlSeconds + graceSeconds (fallback cleanup only)
 * - per-scope 墓碑 {prefix}{token}:consume:{scope}   SETEX TTL = 剩余存活时间（= 原 expiresAt - now），
 *                                         防该 scope 重放；消费后主键保留
 * - Per-scope tombstone {prefix}{token}:consume:{scope} SETEX TTL = remaining lifetime
 *                                          (= original expiresAt - now), preventing replay of that
 *                                          scope; the main key survives consumption
 *
 * 五态语义与 InMemoryTokenStore 完全对齐：
 * Five-state semantics are fully aligned with InMemoryTokenStore:
 * - consume 原子（EVAL 单脚本，per-scope）：该 scope 墓碑存在 → Replayed；主键不存在 → Invalid；
 *   主键 JSON 畸形 / expiresAt 缺失或非数字 / scopes 非 table 非 nil → Invalid（DEL 主键，
 *   防畸形键反复触发）；expiresAt < now → Expired（DEL 主键，跨 scope 一次性可见）；
 *   scopes 为 table 且含 scope → 授权，写该 scope 墓碑 → Valid；scopes 为 table 但不含
 *   scope → Unauthorized（不消费任何标记，主键保留）；旧格式（无 scopes 字段）向后兼容
 *   视为仅授权 'map'——map → Valid，其他 scope → Unauthorized（不消费任何标记、不删主键，
 *   先 consume 其他 scope 不破坏后续 map 消费）。一个 token 一个 scope 至多一个进程
 *   consume 成功（脚本内原子执行）。
 * - consume is atomic (single EVAL script, per-scope): the scope's tombstone present → Replayed;
 *   main key absent → Invalid; malformed JSON / missing or non-numeric expiresAt / non-table
 *   non-nil scopes → Invalid (DEL main key, preventing repeated hits on a malformed key);
 *   expiresAt < now → Expired (DEL main key, visible once across scopes); scopes is a table
 *   containing the scope → authorized, tombstone written → Valid; scopes is a table without
 *   the scope → Unauthorized (nothing consumed, main key kept); legacy records (no scopes
 *   field) are back-compatibly treated as authorizing 'map' only — map → Valid, any other
 *   scope → Unauthorized (nothing consumed, main key kept; consuming another scope first does
 *   not break a subsequent map consume). At most one process can consume one scope of a token
 *   successfully (atomic inside the script).
 * - peek 只读不消费：主键存在且未过期 → 返回含 scopes 的记录；主键缺失/畸形/已过期 → null。
 *   不感知 per-scope 消费墓碑（某 scope 已消费 peek 仍返回 record）。
 * - peek is read-only: main key present and unexpired → returns the record including scopes;
 *   missing / malformed / expired → null. Per-scope consumption tombstones are invisible
 *   (peek still returns the record after a scope is consumed).
 * - remove：仅 DEL 主键；per-scope 墓碑各自 TTL 自然消亡（已消费 scope 墓碑期内仍 Replayed，
 *   未消费 scope → Invalid，撤销语义正确）。
 * - remove: only DEL the main key; per-scope tombstones expire on their own TTL (a consumed
 *   scope still reads Replayed within its tombstone window; unconsumed scopes read Invalid —
 *   revocation semantics are correct).
 *
 * 过期判定以 record 内 expiresAt 与 PHP 侧时钟（可注入）为准，不依赖 Redis 时钟，
 * 避免跨机器（Windows 侧 Redis 经 localhost 转发）时钟偏差影响语义。
 * Expiry is decided by the record's expiresAt against the PHP-side clock (injectable),
 * not the Redis clock, so cross-machine clock skew (Redis forwarded via localhost on
 * Windows) cannot affect semantics.
 *
 * 连接管理：构造可传已连接的 \Redis 实例（单进程/测试场景），或传连接工厂闭包
 * （Workerman 多 Worker 场景）——fork 会复制 socket fd，多个 worker 共享同一连接
 * 会破坏 Redis 协议；工厂在 fork 后各 worker 首次使用时调用，每个进程各自建连。
 * Connection management: pass a connected \Redis instance (single-process / test scenarios)
 * or a connection-factory closure (Workerman multi-Worker scenarios) — fork duplicates
 * socket fds, and sharing one connection across workers would corrupt the Redis protocol;
 * the factory is invoked on first use in each worker after fork, so every process opens
 * its own connection.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class RedisTokenStore implements TokenStoreInterface
{
    /**
     * 原子消费脚本（五态，per-scope 墓碑，逐行对照 ADR-013 8.4）。
     * Atomic consume script (five-state, per-scope tombstone; mirrors ADR-013 8.4 line by line).
     * KEYS[1] = 主键；KEYS[2] = 该 scope 的墓碑键；ARGV[1] = 当前时间（float 字符串）；ARGV[2] = scope。
     * KEYS[1] = main key; KEYS[2] = this scope's tombstone key; ARGV[1] = current time (float string); ARGV[2] = scope.
     * 返回码：0=valid，1=expired，2=replayed，3=invalid，4=unauthorized。
     * Return codes: 0=valid, 1=expired, 2=replayed, 3=invalid, 4=unauthorized.
     */
    private const CONSUME_SCRIPT = <<<'LUA'
-- KEYS[1]=主键 KEYS[2]=scope 墓碑；ARGV[1]=now ARGV[2]=scope
-- 返回：0=valid 1=expired 2=replayed 3=invalid 4=unauthorized
local now = tonumber(ARGV[1])
local scope = ARGV[2]
if redis.call('EXISTS', KEYS[2]) == 1 then
    return 2                                    -- 该 scope 已消费：Replayed
end
local raw = redis.call('GET', KEYS[1])
if not raw then
    return 3                                    -- 主键不存在：Invalid
end
local ok, rec = pcall(cjson.decode, raw)
if not ok or type(rec) ~= 'table' or not rec.expiresAt then
    redis.call('DEL', KEYS[1])
    return 3                                    -- 主键畸形：Invalid + DEL（防畸形键反复触发）
end
local expiresAt = tonumber(rec.expiresAt)
if not expiresAt then
    redis.call('DEL', KEYS[1])
    return 3
end
if expiresAt < now then
    redis.call('DEL', KEYS[1])
    return 1                                    -- 过期：Expired（跨 scope 一次性可见，见 8.6）
end
local authorized = false
local malformed = false
if type(rec.scopes) == 'table' then
    for _, s in ipairs(rec.scopes) do
        if s == scope then authorized = true break end
    end
elseif rec.scopes == nil then
    if scope == 'map' then authorized = true end -- 向后兼容：旧格式（无 scopes）视为仅授权 'map'
else
    malformed = true                             -- scopes 存在但畸形类型（string/number 等）
end
if malformed then
    redis.call('DEL', KEYS[1])
    return 3                                     -- 畸形 scopes → Invalid + DEL（与 expiresAt 畸形同策略）
end
if not authorized then
    return 4                                     -- scope 未授权：Unauthorized（不消费任何标记，主键保留）
end
local remain = math.ceil(expiresAt - now)
if remain < 1 then remain = 1 end
redis.call('SETEX', KEYS[2], remain, '1')       -- 写 per-scope 墓碑，主键保留
return 0
LUA;

    /**
     * 原子移除脚本：仅删除主键。per-scope 墓碑各自 TTL 自然消亡（继续防「已消费 scope 重放」，
     * 不再写总墓碑；主键删除后未消费 scope → Invalid，撤销语义正确）。
     * Atomic remove script: only deletes the main key. Per-scope tombstones expire on their own
     * TTL (still preventing replays of consumed scopes; no overall tombstone is written — after
     * the main key is gone, unconsumed scopes read Invalid, so revocation semantics are correct).
     * KEYS[1] = 主键。
     * KEYS[1] = main key.
     */
    private const REMOVE_SCRIPT = <<<'LUA'
redis.call('DEL', KEYS[1])
return 1
LUA;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * token 格式白名单：64 位十六进制（大小写不敏感，与 TokenManager::consume 一致；
     * TokenManager::issue 恒输出小写）。非法格式在方法入口直接短路，
     * 不进入 Redis 键构造（收敛键注入面与无效查询）。
     * Token format whitelist: 64 hex chars (case-insensitive, consistent with
     * TokenManager::consume; TokenManager::issue always outputs lowercase). Illegal formats
     * short-circuit at method entry and never reach Redis key construction (narrowing the
     * key-injection surface and useless queries).
     */
    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/i';

    /**
     * scope 格式白名单：小写字母开头，仅小写字母/数字/连字符，长度 1~32（与 TokenManager 同规则；
     * scope 会进入 per-scope 墓碑键构造，白名单收敛键注入面）。
     * Scope format whitelist: starts with a lowercase letter, lowercase letters/digits/hyphens
     * only, length 1-32 (same rule as TokenManager; scopes enter per-scope tombstone key
     * construction, so the whitelist narrows the key-injection surface).
     */
    private const SCOPE_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /** 键前缀 Key prefix */
    private readonly string $prefix;

    /** 主键 Redis TTL 相对 token TTL 的冗余秒数（兜底清理） Grace seconds added to the token TTL for the main key's Redis TTL (fallback cleanup) */
    private readonly int $graceSeconds;

    /** @var callable(): float 时间源 Time source */
    private $clock;

    /**
     * 构造 Redis Token 存储。
     * Create a Redis token store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂
     *                                          （多进程场景必须用工厂，fork 后各进程首次使用时各自建连）
     *                                          Connected phpredis client, or a factory returning a connected
     *                                          client (multi-process scenarios must use the factory: each
     *                                          process connects on first use after fork)
     * @param string $prefix      键前缀 Key prefix
     * @param int $graceSeconds   主键 Redis TTL 相对 token TTL 的冗余秒数（兜底清理，语义判定不受影响）
     *                            Grace seconds added to the token TTL for the main key's Redis TTL
     *                            (fallback cleanup; semantics are unaffected)
     * @param ?callable(): float $clock 时间源，缺省 microtime(true) Time source; defaults to microtime(true)
     */
    public function __construct(
        \Redis|\Closure $redis,
        string $prefix = 'nythros:token:',
        int $graceSeconds = 60,
        ?callable $clock = null,
    ) {
        // 运行时守卫（对齐 MySqlStorage 的 pdo_mysql 守卫）：phpredis 缺失时给出明确的替代实现指引，
        // 而不是等到首次 Redis 调用才报未定义类/方法。
        // Runtime guard (aligned with MySqlStorage's pdo_mysql guard): when phpredis is missing, fail fast with a
        // clear alternative-implementation hint instead of an undefined class/method on the first Redis call.
        if (!extension_loaded('redis')) {
            throw new \InvalidArgumentException(
                'RedisTokenStore 需要 ext-redis：缺失该扩展时请改用 InMemoryTokenStore 等替代实现',
            );
        }

        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->graceSeconds = max(0, $graceSeconds);
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 保存 Token 记录：格式预校验后写入主键并设置 Redis TTL。
     * Save a token record: pre-validate the format, then write the main key and set its Redis TTL.
     *
     * @param string $token token 字符串 Token string
     * @param TokenRecord $record token 记录 Token record
     * @param int $ttlSeconds token 有效秒数 Token TTL in seconds
     * @throws \InvalidArgumentException token 格式非法 Token format is illegal
     * @throws \RuntimeException Redis 写入失败 Redis write failed
     */
    public function save(string $token, TokenRecord $record, int $ttlSeconds): void
    {
        // 格式白名单预校验：非法格式直接拒绝，不构造 Redis 键 Pre-validate against the format whitelist: reject illegal formats without constructing Redis keys
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('RedisTokenStore save: 非法 token 格式（要求 64 位十六进制）');
        }

        $payload = [
            'uid' => $record->uid,
            'mapId' => $record->mapId,
            'issuedAt' => $record->issuedAt,
            'expiresAt' => $record->expiresAt,
        ];
        // 旧格式（scopes 为 null）省略 scopes 字段——Lua 侧以字段缺失识别旧格式；
        // 写 "scopes": null 会被 cjson 解为 userdata，误判畸形 → Invalid + DEL
        // Legacy records (null scopes) omit the scopes field — the Lua side recognizes the
        // legacy format by field absence; writing "scopes": null would decode to userdata and
        // be misjudged malformed → Invalid + DEL
        if ($record->scopes !== null) {
            $payload['scopes'] = $record->scopes;
        }
        $payload = json_encode($payload, JSON_THROW_ON_ERROR);

        // Redis TTL 仅作过期数据兜底清理（grace 冗余），五态判定以 record.expiresAt 为准 Redis TTL is only fallback cleanup for expired data (grace margin); the five-state verdict relies on record.expiresAt
        $redisTtl = max(1, $ttlSeconds) + $this->graceSeconds;
        if ($this->redis()->setex($this->tokenKey($token), $redisTtl, $payload) === false) {
            throw new \RuntimeException(sprintf('RedisTokenStore save 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    /**
     * 原子消费：执行 Lua 脚本一次性判定五态并落该 scope 墓碑（主键保留；墓碑 SETEX 至原 expiresAt）。
     * Atomic consume: run the Lua script to produce the one-shot five-state verdict and write this
     * scope's tombstone (the main key survives; the tombstone is SETEX'ed to the original expiresAt).
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return TokenStatus 五态判定结果 Five-state verdict.
     * @throws \RuntimeException Redis 执行失败 Redis execution failed
     */
    public function consume(string $token, string $scope): TokenStatus
    {
        // 格式白名单预校验：token 或 scope 任一非法直接判定 Invalid，不进 Redis Pre-validate against the format whitelists: an illegal token or scope is judged Invalid without touching Redis
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1
            || preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            return TokenStatus::Invalid;
        }

        $now = ($this->clock)();
        $result = $this->redis()->eval(
            self::CONSUME_SCRIPT,
            [
                $this->tokenKey($token),
                $this->tombstoneKey($token, $scope),
                (string) $now,
                $scope,
            ],
            2,
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf('RedisTokenStore consume 失败: %s', (string) $this->redis()->getLastError()));
        }

        // Lua 返回码 → 五态枚举映射 Map the Lua return codes to the five-state enum
        return match ((int) $result) {
            0 => TokenStatus::Valid,
            1 => TokenStatus::Expired,
            2 => TokenStatus::Replayed,
            4 => TokenStatus::Unauthorized,
            default => TokenStatus::Invalid,
        };
    }

    /**
     * 只读查看（不消费）：主键存在且未过期 → 返回含 scopes 的记录；主键缺失/畸形/已过期 → null。
     * 不感知 per-scope 消费墓碑（组 2 落地后某 scope 已消费 peek 仍返回 record）。
     * Read-only peek (no consumption): main key present and unexpired → returns the record including scopes; missing / malformed / expired → null.
     * Per-scope consumption tombstones are invisible (after group 2 lands, peek still returns the record once a scope is consumed).
     *
     * @param string $token token 字符串 Token string
     * @return ?TokenRecord token 记录，不可见时 null Token record, or null when not visible.
     */
    public function peek(string $token): ?TokenRecord
    {
        // 格式白名单预校验：非法格式直接返回 null，不进 Redis Pre-validate against the format whitelist: return null directly for illegal formats without touching Redis
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $raw = $this->redis()->get($this->tokenKey($token));
        if (!is_string($raw)) {
            return null;
        }

        try {
            // 关联数组解码，出错抛 JsonException Decode as associative array; throw JsonException on error
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // 逐字段类型校验：任一字段不合法视为不可见 Validate each field's type: any illegal field means not visible
        if (!is_array($data)
            || !is_string($data['uid'] ?? null)
            || !is_string($data['mapId'] ?? null)
            || !is_numeric($data['issuedAt'] ?? null)
            || !is_numeric($data['expiresAt'] ?? null)
        ) {
            return null;
        }

        // scopes 解析回填：缺失（旧格式）按向后兼容视为 ['map']；存在但非 string 列表视为畸形不可见
        // scopes parsing and back-fill: missing (legacy format) is back-compatibly treated as ['map']; present but not a string list means malformed and invisible
        $scopes = $data['scopes'] ?? null;
        if ($scopes === null) {
            $scopes = ['map'];
        } elseif (!is_array($scopes) || array_filter($scopes, static fn (mixed $s): bool => !is_string($s)) !== []) {
            return null;
        } else {
            $scopes = array_values($scopes);
        }

        $record = new TokenRecord(
            $data['uid'],
            $data['mapId'],
            $scopes,
            (float) $data['issuedAt'],
            (float) $data['expiresAt'],
        );

        // 纯只读：过期只返回 null，不删除。删除/Expired 判定统一交给 consume 的
        // Lua 原子脚本（MapServer 是 peek 取 uid 后立刻 consume 判五态；若 peek
        // 惰性删除过期记录，consume 会误判为 Invalid，expired 态在服务端链路不可见）。
        // 过期主键由 Redis 侧 TTL（ttlSeconds + graceSeconds）兜底清理。
        // Purely read-only: expiry only yields null, never deletion. Deletion / Expired
        // verdicts are left entirely to consume's atomic Lua script (MapServer peeks the
        // uid and immediately consumes for the five-state verdict; if peek lazily deleted
        // expired records, consume would misjudge them as Invalid and the expired state
        // would be invisible in the server pipeline). Expired main keys are eventually
        // cleaned up by the Redis-side TTL (ttlSeconds + graceSeconds).
        $now = ($this->clock)();
        if ($record->expiresAt < $now) {
            return null;
        }

        return $record;
    }

    /**
     * 移除 token：执行 Lua 脚本仅删除主键（per-scope 墓碑各自 TTL 自然消亡，不再写总墓碑）。
     * Remove a token: run the Lua script to delete only the main key (per-scope tombstones expire
     * on their own TTL; no overall tombstone is written).
     *
     * @param string $token token 字符串 Token string
     * @throws \RuntimeException Redis 执行失败 Redis execution failed
     */
    public function remove(string $token): void
    {
        // 格式白名单预校验：非法格式静默忽略，不进 Redis Pre-validate against the format whitelist: silently ignore illegal formats without touching Redis
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }

        $result = $this->redis()->eval(
            self::REMOVE_SCRIPT,
            [$this->tokenKey($token)],
            1,
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf('RedisTokenStore remove 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    /**
     * 获取当前进程使用的 phpredis 连接（工厂模式：每个 fork 出的进程各自建连一次）。
     * Get the phpredis connection used by the current process (factory mode: each forked
     * process connects once on its own).
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
     * 生成主键：前缀 + token。
     * Build the main key: prefix + token.
     *
     * @param string $token token 字符串 Token string
     * @return string Redis 主键 Redis main key.
     */
    private function tokenKey(string $token): string
    {
        return $this->prefix . $token;
    }

    /**
     * 生成 per-scope 墓碑键：前缀 + token + :consume: + scope。
     * Build the per-scope tombstone key: prefix + token + :consume: + scope.
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return string Redis per-scope 墓碑键 Redis per-scope tombstone key.
     */
    private function tombstoneKey(string $token, string $scope): string
    {
        return $this->prefix . $token . ':consume:' . $scope;
    }
}
