<?php

declare(strict_types=1);

namespace Nythros\Security\Tests;

use Nythros\Security\InMemoryTokenStore;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;
use PHPUnit\Framework\TestCase;

/**
 * InMemoryTokenStoreTest - 覆盖 InMemoryTokenStore 的五态消费（per-scope 墓碑）、只读 peek 与墓碑 TTL 语义。
 * Tests covering InMemoryTokenStore's five-state consume (per-scope tombstones), read-only peek, and tombstone TTL semantics.
 *
 * 语义基准 = ADR-013 8.2/8.4-8.6 五态矩阵（与 RedisTokenStoreTest 对拍）：
 * 本实现不依赖 Redis，可随时运行；Redis 侧恢复后跑同套矩阵对拍。
 * 两侧语义差异说明：①「旧格式无 scopes 字段」在 InMemory 侧 = TokenRecord->scopes 为 null
 * （对应 Redis 序列化路径无 scopes 字段），两侧同语义：仅授权 'map'，其他 scope →
 * Unauthorized（不写墓碑、不删主键）；②畸形落点不同：Redis 侧可达畸形 = JSON 解码失败 /
 * expiresAt 非数字 / scopes 非 table（Lua DEL + 3）；InMemory 唯一可达畸形 = expiresAt 非
 * 有限数（NAN/INF，PHP float 保证不了有限性），对应 Lua 的 expiresAt 非数字分支（scopes
 * 非 list 非 null 的畸形在 PHP 类型系统下不可达）；③ Redis 墓碑键可外部 DEL 观察（独立
 * TTL 用例），InMemory 墓碑过期 ⇔ expiresAt 已过 → 自然走 Expired。
 * The semantic baseline is the ADR-013 8.2/8.4-8.6 five-state matrix (mirrored against
 * RedisTokenStoreTest): this implementation needs no Redis and runs anytime; the Redis side
 * re-runs the same matrix for parity once Redis recovers. Documented side differences:
 * ① "legacy record without scopes" is represented on the InMemory side by TokenRecord->scopes
 * being null (mirroring the Redis serialization path's missing scopes field); both sides share
 * the semantics: authorizes 'map' only, other scopes → Unauthorized (no tombstone, main key kept);
 * ② the malformation entry points differ: on the Redis side they are JSON decode failure /
 * non-numeric expiresAt / non-table scopes (Lua DEL + 3); the only malformation reachable in
 * InMemory is a non-finite expiresAt (NAN/INF — PHP floats cannot guarantee finiteness),
 * mirroring the Lua non-numeric-expiresAt branch (non-list non-null scopes are unreachable under
 * PHP's type system); ③ Redis tombstone keys are externally DEL-observable (independent TTL
 * case), while here a tombstone's expiry coincides with expiresAt → naturally reads Expired.
 */
final class InMemoryTokenStoreTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testSavedTokenConsumesValidThenReplayed(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $store = new InMemoryTokenStore($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
    }

    public function testThreeScopesConsumeIndependentlyValid(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat', 'team'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'team'));
    }

    public function testSameScopeReplayDoesNotAffectOtherScopes(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
    }

    public function testUnauthorizedScopeDoesNotConsumeAnything(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        // 未授权：不写墓碑、主键保留 Unauthorized: no tombstone written, main key kept
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 主键保留：peek 仍可见 The main key survives: peek still sees the record
        self::assertNotNull($store->peek(self::TOKEN));
        // 无墓碑：再次 consume 同一未授权 scope 仍是 Unauthorized 而非 Replayed
        // No tombstone: consuming the same unauthorized scope again reads Unauthorized, not Replayed
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 授权 scope 不受影响 The authorized scope is unaffected
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testExpiredIsExpiredOnceThenInvalidAcrossScopes(): void
    {
        // ADR 8.6：Expired 一次性可见——首个撞上过期判定的 consume 删主键，其余 scope 再 consume → Invalid
        // ADR 8.6: Expired is visible once — the first consume hitting the expiry check drops the main key; any further scope then reads Invalid
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $store = new InMemoryTokenStore($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        $now += 31.0;

        self::assertSame(TokenStatus::Expired, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'chat'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testMalformedExpiryInvalidAndDeletesMainKey(): void
    {
        // InMemory 唯一可达畸形：expiresAt 非有限数（NAN）。对应 Lua 的 expiresAt 非数字 →
        // DEL + 3；PHP float 保证不了有限性，运行时真实可达。畸形判定优先于过期判定（与 Lua 一致）。
        // The only malformation reachable in InMemory: a non-finite expiresAt (NAN). Mirrors the
        // Lua non-numeric expiresAt → DEL + 3; PHP floats cannot guarantee finiteness, so this is
        // genuinely reachable at runtime. The malformation check precedes the expiry check (as in Lua).
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, NAN), 30);

        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
        // 主键已删除：peek 不可见 The main key was dropped: peek no longer sees it
        self::assertNull($store->peek(self::TOKEN));
        // 主键已删除：后续 consume 亦 Invalid The main key was dropped: further consumes read Invalid
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testNonStringScopeElementToleratedLikeLua(): void
    {
        // scopes 元素类型宽容（与 Lua 的 ipairs 逐条对齐）：非 string 元素只是不匹配，
        // 不判畸形、不影响其他元素命中。scopes ['map', 123]：map → Valid；chat → Unauthorized。
        // Lenient scope element types (mirroring the Lua ipairs loop line by line): non-string
        // elements merely fail to match — no malformation verdict, no impact on other elements.
        // scopes ['map', 123]: map → Valid; chat → Unauthorized.
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 123], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 主键保留（未判畸形） The main key survives (no malformation verdict)
        self::assertNotNull($store->peek(self::TOKEN));
    }

    public function testLegacyRecordNullScopesAuthorizesMapOnly(): void
    {
        // 旧格式（scopes 为 null，对应 Redis 侧无 scopes 字段）向后兼容：仅授权 'map'
        // Legacy record (null scopes, mirroring the Redis side's missing scopes field) is
        // back-compatibly treated as authorizing 'map' only
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', null, 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
    }

    public function testLegacyRecordNullScopesOtherScopeUnauthorizedKeepsMainKey(): void
    {
        // 旧格式（scopes 为 null）+ 非 map scope → Unauthorized：不写墓碑、不删主键
        //（旧格式 token 是「仅授权 map」的有效 token，先 consume chat 不应破坏后续 map 消费）
        // Legacy record (null scopes) + non-map scope → Unauthorized: no tombstone written, main
        // key kept (a legacy token is a valid map-only token — consuming chat first must not
        // break the later map consume)
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', null, 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 不写墓碑：再次 consume 同一未授权 scope 仍是 Unauthorized 而非 Replayed
        // No tombstone written: consuming the same unauthorized scope again reads Unauthorized, not Replayed
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 主键未删：peek 仍可见 The main key survives: peek still sees the record
        self::assertNotNull($store->peek(self::TOKEN));
        // 后续 map 消费不受影响 The later map consume is unaffected
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testRemoveUnconsumedScopeInvalidConsumedScopeReplayed(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));

        $store->remove(self::TOKEN);

        // 未消费 scope → Invalid；已消费 scope 墓碑独立 TTL 存活 → 仍 Replayed
        // Unconsumed scope → Invalid; the consumed scope's tombstone lives on its own TTL → still Replayed
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'chat'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
    }

    public function testRemoveUnknownTokenIsNoop(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->remove(self::TOKEN);

        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekReturnsRecordWithoutConsuming(): void
    {
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        $record = $store->peek(self::TOKEN);

        self::assertNotNull($record);
        self::assertSame('u1', $record->uid);
        self::assertSame('map-1', $record->mapId);
        self::assertSame(['map', 'chat'], $record->scopes);
        // peek 不消费：再次 peek 与 consume 均可见 peek does not consume: a second peek and consume both see it
        self::assertNotNull($store->peek(self::TOKEN));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekConsumedTokenStillReturnsRecord(): void
    {
        // ADR 8.5 有意变更：per-scope 墓碑模型下主键保留，某 scope 消费后 peek 仍返回 record
        //（旧总墓碑模型下消费后 peek 为 null）
        // ADR 8.5 intentional change: under per-scope tombstones the main key survives, so peek
        // still returns the record after a scope is consumed (under the old overall tombstone model peek was null)
        $store = new InMemoryTokenStore(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));

        $record = $store->peek(self::TOKEN);
        self::assertNotNull($record);
        self::assertSame('u1', $record->uid);
        self::assertSame(['map', 'chat'], $record->scopes);
    }

    public function testPeekExpiredTokenReturnsNullAndConsumeStillExpired(): void
    {
        // peek 纯只读不删除：过期 peek null 后 consume 仍判 Expired（与 Redis 对拍；
        // 若 peek 惰性删主键会误判 Invalid，expired 态在服务端链路不可见）
        // peek is purely read-only: after an expired peek returns null, consume still reads
        // Expired (parity with Redis; if peek lazily dropped the main key, consume would
        // misjudge Invalid and the expired state would be invisible in the server pipeline)
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $store = new InMemoryTokenStore($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        $now += 31.0;

        self::assertNull($store->peek(self::TOKEN));
        self::assertSame(TokenStatus::Expired, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekUnknownTokenReturnsNull(): void
    {
        $store = new InMemoryTokenStore();

        self::assertNull($store->peek(self::TOKEN));
    }

    public function testTombstoneExpiryThenExpired(): void
    {
        // per-scope 墓碑 TTL = 原 expiresAt：墓碑到期 ⇔ token 已过期 → Expired（而非
        // Replayed/Invalid），与 Redis 的 SETEX 剩余 TTL 语义对拍
        // The per-scope tombstone TTL equals the original expiresAt: tombstone expiry coincides
        // with token expiry → Expired (not Replayed/Invalid), mirroring Redis's SETEX remaining-TTL semantics
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $store = new InMemoryTokenStore($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));

        $now += 31.0;

        self::assertSame(TokenStatus::Expired, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testUnknownTokenIsInvalid(): void
    {
        $store = new InMemoryTokenStore();

        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }
}
