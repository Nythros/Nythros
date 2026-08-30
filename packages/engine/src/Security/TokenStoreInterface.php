<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * Token 存储接口：定义 token 的持久化与五态判定契约。
 * Token store interface: defines the persistence and five-state verdict contract of tokens.
 */
interface TokenStoreInterface
{
    /**
     * 保存 Token 记录。
     * Save a token record.
     *
     * @param string $token token 字符串 Token string
     * @param TokenRecord $record token 记录 Token record
     * @param int $ttlSeconds token 有效秒数 Token TTL in seconds
     */
    public function save(string $token, TokenRecord $record, int $ttlSeconds): void;

    /**
     * 原子消费五态：Valid（该 scope 首次消费成功）/ Expired（存在但超时）/ Replayed（该 scope 已消费）/ Invalid（不存在或格式非法）/ Unauthorized（scope 未授权，不消费）。
     * Atomically consume and return the five-state verdict: Valid (this scope's first successful consumption) / Expired (exists but timed out) / Replayed (this scope already consumed) / Invalid (missing or malformed) / Unauthorized (scope not authorized, nothing consumed).
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return TokenStatus 五态判定结果 Five-state verdict.
     */
    public function consume(string $token, string $scope): TokenStatus;

    /**
     * 只读查看（不消费）：主键存在且未过期 → 返回含 scopes 的记录；主键缺失/畸形/已过期 → null。
     * 不感知 per-scope 消费墓碑（某 scope 已消费后 peek 仍返回 record）。
     * Read-only peek (no consumption): main key present and unexpired → returns the record including scopes; missing / malformed / expired → null.
     * Per-scope consumption tombstones are invisible (peek still returns the record after a scope has been consumed).
     *
     * @param string $token token 字符串 Token string
     * @return ?TokenRecord token 记录，不可见时 null Token record, or null when not visible.
     */
    public function peek(string $token): ?TokenRecord;

    /**
     * 移除 token：仅删除主记录/主键（不写总墓碑）。per-scope 墓碑各自 TTL 自然消亡——
     * 已消费 scope 墓碑期内仍 Replayed，未消费 scope → Invalid，撤销语义正确。
     * Remove a token: delete only the main record / main key (no overall tombstone is written).
     * Per-scope tombstones expire on their own TTL — a consumed scope still reads Replayed
     * within its tombstone window; unconsumed scopes read Invalid, so revocation semantics
     * are correct.
     *
     * @param string $token token 字符串 Token string
     */
    public function remove(string $token): void;
}
