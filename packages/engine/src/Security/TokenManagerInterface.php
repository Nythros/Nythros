<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * Token 管理器接口：签发（多 scope）、一次性消费与只读查看。
 * Token manager interface: issue (multi-scope), one-shot consume, and read-only peek.
 */
interface TokenManagerInterface
{
    /**
     * 签发 token：短 TTL（默认 30s）；返回 64 字符 hex token。
     * Issue a token: short TTL (default 30s); returns a 64-char hex token.
     *
     * scopes 在管理器内白名单过滤（仅 'map'/'chat'/'team' 子集，保序去重；过滤后为空 → 缺省全量）。
     * scopes are whitelist-filtered inside the manager (an order-preserving, deduplicated subset
     * of 'map'/'chat'/'team'; an empty result defaults to the full set).
     *
     * @param string $uid 用户唯一标识 Unique user identifier
     * @param string $mapId 目标地图标识 Target map identifier
     * @param list<string> $scopes 授权 scope 列表，缺省 ['map'] Authorized scope list; default ['map']
     * @param int $ttlSeconds token 有效秒数，默认 30 Token TTL in seconds, default 30
     * @return string 64 字符 hex token 64-char hex token.
     */
    public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string;

    /**
     * 五态判定，一次性消费（带 scope）。
     * Five-state verdict with one-shot consumption (scoped).
     *
     * scope 白名单 /^[a-z][a-z0-9-]{0,31}$/：不匹配在管理器层短路为 Invalid，不进存储层。
     * Scope whitelist /^[a-z][a-z0-9-]{0,31}$/: mismatches short-circuit to Invalid at the manager layer and never reach the storage layer.
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return TokenStatus 五态判定结果 Five-state verdict.
     */
    public function consume(string $token, string $scope): TokenStatus;

    /**
     * 只读查看（不消费）：格式非法或不存在/已消费/已过期返回 null。
     * Read-only peek (no consumption): null when the format is illegal or the token is missing / consumed / expired.
     *
     * @param string $token token 字符串 Token string
     * @return ?TokenRecord token 记录，不可见时 null Token record, or null when not visible.
     */
    public function peek(string $token): ?TokenRecord;
}
