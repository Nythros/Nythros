<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * Token 管理器：签发 token（多 scope）、格式预校验与一次性消费。
 * Token manager: token issuance (multi-scope), format pre-validation, and one-shot consumption.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class TokenManager implements TokenManagerInterface
{
    /** scope 白名单：小写字母开头，仅小写字母/数字/连字符，长度 1~32（收敛 Redis 键构造注入面）。Scope whitelist: starts with a lowercase letter, lowercase letters/digits/hyphens only, length 1-32 (narrowing the Redis key-injection surface). */
    private const SCOPE_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /** @var callable(): float 时间源 Time source */
    private $clock;

    /**
     * 构造 Token 管理器。
     * Create a token manager.
     *
     * @param TokenStoreInterface $store token 存储后端 Token storage backend
     * @param ?callable(): float $clock 时间源，缺省 microtime(true) Time source; defaults to microtime(true)
     */
    public function __construct(
        private readonly TokenStoreInterface $store,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 签发 token：生成 64 字符 hex 随机串并写入存储。
     * Issue a token: generate a 64-char hex random string and persist it.
     *
     * scopes 在管理器内白名单过滤：仅保留 'map'/'chat'/'team' 子集（保序去重）；
     * 过滤结果为空 → 缺省全量 ['map','chat','team']（ADR 9.3 缺省全量）。
     * scopes are whitelist-filtered inside the manager: only the 'map'/'chat'/'team' subset is
     * kept (order-preserving, deduplicated); an empty result defaults to the full set
     * ['map','chat','team'] (ADR 9.3 default = full set).
     *
     * @param string $uid 用户唯一标识 Unique user identifier
     * @param string $mapId 目标地图标识 Target map identifier
     * @param list<string> $scopes 授权 scope 列表，缺省 ['map'] Authorized scope list; default ['map']
     * @param int $ttlSeconds token 有效秒数，默认 30 Token TTL in seconds, default 30
     * @return string 64 字符 hex token 64-char hex token.
     */
    public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string
    {
        // 白名单过滤：非 'map'/'chat'/'team' 剔除、保序去重、空集缺省全量
        // Whitelist filtering: drop non-'map'/'chat'/'team' scopes, deduplicate in order, default an empty result to the full set
        $scopes = self::filterScopes($scopes);

        // 32 字节 CSPRNG 随机源，hex 化后为 64 字符 32 bytes from CSPRNG, hex-encoded into 64 chars
        $token = bin2hex(random_bytes(32));
        $now = ($this->clock)();
        $record = new TokenRecord($uid, $mapId, $scopes, $now, $now + $ttlSeconds);

        $this->store->save($token, $record, $ttlSeconds);

        return $token;
    }

    /**
     * 五态判定，一次性消费；token 格式非法或 scope 白名单不匹配直接短路为 Invalid（不进存储层）。
     * Five-state verdict with one-shot consumption; an illegal token format or a scope failing the whitelist short-circuits to Invalid (never reaching the storage layer).
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return TokenStatus 五态判定结果 Five-state verdict.
     */
    public function consume(string $token, string $scope): TokenStatus
    {
        // 格式白名单：64 字符十六进制；不匹配不进入存储层 Format whitelist: 64 hex chars; mismatches never reach the storage layer
        if (preg_match('/^[0-9a-f]{64}$/i', $token) !== 1) {
            return TokenStatus::Invalid;
        }

        // scope 白名单短路：不匹配直接 Invalid（scope 会进入存储层键构造，白名单收敛注入面） Scope whitelist short-circuit: mismatch is Invalid outright (scopes enter storage-layer key construction; the whitelist narrows the injection surface)
        if (preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
            return TokenStatus::Invalid;
        }

        return $this->store->consume($token, $scope);
    }

    /**
     * 只读查看（不消费）：格式非法或不存在/已消费/已过期返回 null。
     * Read-only peek (no consumption): null when the format is illegal or the token is missing / consumed / expired.
     *
     * @param string $token token 字符串 Token string
     * @return ?TokenRecord token 记录，不可见时 null Token record, or null when not visible.
     */
    public function peek(string $token): ?TokenRecord
    {
        // 格式白名单：64 字符十六进制；不匹配直接返回 null Format whitelist: 64 hex chars; return null directly on mismatch
        if (preg_match('/^[0-9a-f]{64}$/i', $token) !== 1) {
            return null;
        }

        return $this->store->peek($token);
    }

    /**
     * scope 白名单过滤：仅保留 'map'/'chat'/'team' 子集（保序去重）；过滤结果为空 → 缺省全量。
     * in_array 严格比较天然剔除非字符串元素（scopes 可能来自客户端 payload 的 mixed 数据）。
     * Scope whitelist filtering: keep only the 'map'/'chat'/'team' subset (order-preserving,
     * deduplicated); an empty result defaults to the full set. in_array's strict comparison
     * naturally drops non-string elements (scopes may come from mixed client payload data).
     *
     * @param list<string> $scopes 请求的 scope 列表 Requested scope list.
     * @return list<string> 过滤后的 scope 列表 Filtered scope list.
     */
    private static function filterScopes(array $scopes): array
    {
        $filtered = array_values(array_unique(array_filter(
            $scopes,
            static fn (mixed $scope): bool => in_array($scope, ['map', 'chat', 'team'], true),
        )));

        return $filtered === [] ? ['map', 'chat', 'team'] : $filtered;
    }
}
