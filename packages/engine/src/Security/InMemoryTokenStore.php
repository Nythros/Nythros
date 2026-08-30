<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * 内存 Token 存储：进程内数组实现，仅适用于单进程场景（五态 + per-scope 墓碑，与 RedisTokenStore 语义对拍）。
 * In-memory token store: implemented with in-process arrays; only suitable for single-process
 * scenarios (five-state + per-scope tombstones, semantics mirrored against RedisTokenStore).
 *
 * 与 Redis 实现的语义对齐点：
 * Semantic alignment points with the Redis implementation:
 * - 主键保留：consume 授权后仅写 per-scope 墓碑，主记录保留（Redis 侧主键同样保留至 TTL 兜底）；
 * - Main key survives: an authorized consume only writes the per-scope tombstone; the main record
 *   is kept (on the Redis side the main key survives until the TTL fallback);
 * - per-scope 墓碑键 "{token}:{scope}"，TTL = 原 record 的 expiresAt（Redis 侧 SETEX 至同一时刻）；
 * - Per-scope tombstone key "{token}:{scope}" with TTL = the original record's expiresAt (the
 *   Redis side SETEXes to the same instant);
 * - Expired 一次性可见：首个撞上过期判定的 consume 删主键返回 Expired，其余 scope 再 consume → Invalid；
 * - Expired is visible once: the first consume that hits the expiry check deletes the main key and
 *   returns Expired; any further scope then reads Invalid;
 * - 旧格式（scopes 为 null，对应 Redis 侧无 scopes 字段）仅授权 'map'：map → Valid；其他
 *   scope → Unauthorized（不写墓碑、不删主键，先 consume 其他 scope 不破坏后续 map 消费）；
 * - Legacy records (null scopes, mirroring the Redis side's missing scopes field) authorize 'map'
 *   only: map → Valid; other scopes → Unauthorized (no tombstone written, main key kept;
 *   consuming another scope first does not break a later map consume);
 * - 过期主键不被 peek/sweep 删除（Redis 侧由 TTL 兜底），保证「peek 过期 → null 后 consume 仍判
 *   Expired」对拍；过期主键靠 consume 的 Expired 分支 / remove / save 覆盖清理。
 * - Expired main keys are not deleted by peek/sweep (on the Redis side the TTL is the fallback),
 *   keeping "peek after expiry → null, then consume → Expired" in parity; expired main keys are
 *   cleaned by consume's Expired branch / remove / save overwrites.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class InMemoryTokenStore implements TokenStoreInterface
{
    /** @var array<string, TokenRecord> 有效 token 映射 Valid token map */
    private array $tokens = [];

    /** @var array<string, float> "{token}:{scope}" => 墓碑过期时间（原 record 的 expiresAt） "{token}:{scope}" => tombstone expiry time (the original record's expiresAt) */
    private array $tombstones = [];

    /** @var callable(): float 时间源 Time source */
    private $clock;

    /**
     * 构造内存 Token 存储。
     * Create an in-memory token store.
     *
     * @param ?callable(): float $clock 时间源，缺省 microtime(true) Time source; defaults to microtime(true)
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 保存 Token 记录。
     * Save a token record.
     *
     * @param string $token token 字符串 Token string
     * @param TokenRecord $record token 记录 Token record
     * @param int $ttlSeconds token 有效秒数 Token TTL in seconds
     */
    public function save(string $token, TokenRecord $record, int $ttlSeconds): void
    {
        $this->sweep();
        $this->tokens[$token] = $record;
    }

    /**
     * 原子取走五态：Valid / Expired / Replayed / Invalid / Unauthorized，判定顺序与 Redis Lua 脚本逐条对齐。
     * Atomically consume and return the five-state verdict: Valid / Expired / Replayed / Invalid /
     * Unauthorized; the check order mirrors the Redis Lua script line by line.
     *
     * @param string $token token 字符串 Token string
     * @param string $scope 消费的授权域 Scope being consumed.
     * @return TokenStatus 五态判定结果 Five-state verdict.
     */
    public function consume(string $token, string $scope): TokenStatus
    {
        $now = ($this->clock)();
        $tombstoneKey = $token . ':' . $scope;

        // ① 该 scope 墓碑命中（未过期）→ Replayed，与 Lua 的 EXISTS 优先一致
        // ① This scope's tombstone hit (unexpired) → Replayed, consistent with the Lua script's EXISTS-first check
        if (isset($this->tombstones[$tombstoneKey]) && $this->tombstones[$tombstoneKey] > $now) {
            $this->sweep();

            return TokenStatus::Replayed;
        }

        // ② 主键不存在 → Invalid
        // ② Main key absent → Invalid
        if (!isset($this->tokens[$token])) {
            $this->sweep();

            return TokenStatus::Invalid;
        }

        $record = $this->tokens[$token];

        // ③ 主键畸形（expiresAt 非有限数，如 NAN/INF）→ Invalid + 删主键，与 Lua 的
        //    expiresAt 非数字 → DEL 逐条对齐（PHP 类型系统保证不了 float 有限性，这是
        //    InMemory 唯一可达的畸形；Lua 侧 JSON 解码失败 / scopes 非 table 在 InMemory
        //    无序列化路径，不可达）。scopes 元素类型不做畸形检查——Lua 的 ipairs 对非
        //    string 元素只是不匹配，同样宽容。
        // ③ Malformed main key (expiresAt not finite, e.g. NAN/INF) → Invalid + drop the main
        //    key, mirroring the Lua script's non-numeric expiresAt → DEL line by line (the PHP
        //    type system cannot guarantee float finiteness — this is the only malformation
        //    reachable in InMemory; the Lua-side JSON decode failure / non-table scopes have no
        //    serialization path here and are unreachable). Scope element types are not checked
        //    for malformation — the Lua ipairs loop treats non-string elements as merely
        //    non-matching, and we are equally lenient.
        if (!is_finite($record->expiresAt)) {
            unset($this->tokens[$token]);
            $this->sweep();

            return TokenStatus::Invalid;
        }

        // ④ 过期 → Expired + 删主键（与 Lua 的 expiresAt < now → DEL 一致；跨 scope 一次性可见）
        // ④ Expired → Expired + drop the main key (consistent with the Lua script's expiresAt < now → DEL; visible once across scopes)
        if ($record->expiresAt < $now) {
            unset($this->tokens[$token]);
            $this->sweep();

            return TokenStatus::Expired;
        }

        // ⑤ scope 授权判定（与 Lua 三分支对齐）：scopes 为 list → 成员匹配；scopes 为 null
        //    （旧格式，无 scopes 字段）→ 仅授权 'map'，其他 scope → Unauthorized（不写墓碑、
        //    不删主键，先 consume 其他 scope 不破坏后续 map 消费）；畸形（非 list 非 null）
        //    PHP 侧不可达（TokenRecord->scopes 类型为 ?list<string>）。未授权 → Unauthorized
        //    （不写墓碑、主键保留，与 Lua 的 return 4 一致）
        // ⑤ Scope authorization check (mirroring the Lua three branches): scopes is a list →
        //    membership match; scopes is null (legacy record without the scopes field) →
        //    authorizes 'map' only, any other scope → Unauthorized (no tombstone written, main
        //    key kept; consuming another scope first does not break a later map consume);
        //    malformed (neither list nor null) is unreachable on the PHP side (TokenRecord->scopes
        //    is typed ?list<string>). Unauthorized → no tombstone written, main key kept
        //    (consistent with the Lua script's return 4)
        $authorized = $record->scopes !== null
            ? in_array($scope, $record->scopes, true)
            : $scope === 'map';
        if (!$authorized) {
            $this->sweep();

            return TokenStatus::Unauthorized;
        }

        // ⑥ 授权：写 per-scope 墓碑（TTL = 原 expiresAt），主键保留（与 Lua 的 SETEX 一致）
        // ⑥ Authorized: write the per-scope tombstone (TTL = original expiresAt), main key kept (consistent with the Lua script's SETEX)
        $this->tombstones[$tombstoneKey] = $record->expiresAt;
        $this->sweep();

        return TokenStatus::Valid;
    }

    /**
     * 只读查看（不消费）：主键存在且未过期 → 返回含 scopes 的记录；主键缺失/畸形/已过期 → null。
     * 不感知 per-scope 消费墓碑（某 scope 已消费后 peek 仍返回 record，ADR 8.5 有意变更）。
     * Read-only peek (no consumption): main key present and unexpired → returns the record
     * including scopes; missing / malformed / expired → null. Per-scope consumption tombstones
     * are invisible (peek still returns the record after a scope has been consumed — an
     * intentional change of ADR 8.5).
     *
     * @param string $token token 字符串 Token string
     * @return ?TokenRecord token 记录，不可见时 null Token record, or null when not visible.
     */
    public function peek(string $token): ?TokenRecord
    {
        $this->sweep();

        $record = $this->tokens[$token] ?? null;
        if ($record === null) {
            return null;
        }

        // 纯只读：畸形只返回 null，不删除（expiresAt 非有限数，与 consume ③ 同口径）
        // Purely read-only: malformation only yields null, never deletion (non-finite expiresAt, same criterion as consume ③)
        if (!is_finite($record->expiresAt)) {
            return null;
        }

        // 纯只读：过期只返回 null，不删除（与 Redis peek 一致——Expired 判定保留给 consume）
        // Purely read-only: expiry only yields null, never deletion (consistent with Redis peek — the Expired verdict is left to consume)
        if ($record->expiresAt < ($this->clock)()) {
            return null;
        }

        return $record;
    }

    /**
     * 移除 token：仅删除主记录。per-scope 墓碑各自 TTL 自然消亡（已消费 scope 墓碑期内仍
     * Replayed；未消费 scope → Invalid，撤销语义正确）。
     * Remove a token: only delete the main record. Per-scope tombstones expire on their own
     * (a consumed scope still reads Replayed within its tombstone window; unconsumed scopes
     * read Invalid — revocation semantics are correct).
     *
     * @param string $token token 字符串 Token string
     */
    public function remove(string $token): void
    {
        $this->sweep();

        unset($this->tokens[$token]);
    }

    /**
     * 惰性清理：删除已过期的 per-scope 墓碑。
     * 不删除过期主键——Redis 侧过期主键由 TTL 兜底、peek 亦不删除；这里保留过期主键才能让
     * 「peek 过期 → null 后 consume 仍判 Expired」与 Redis 对拍，过期主键靠 consume 的
     * Expired 分支 / remove / save 覆盖清理。
     * Lazy sweep: drop expired per-scope tombstones.
     * Expired main keys are not dropped here — on the Redis side they are handled by the TTL
     * fallback and peek never deletes either; keeping them preserves the "peek after expiry →
     * null, then consume → Expired" parity, and expired main keys are cleaned by consume's
     * Expired branch / remove / save overwrites.
     */
    private function sweep(): void
    {
        $now = ($this->clock)();

        foreach ($this->tombstones as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->tombstones[$key]);
            }
        }
    }
}
