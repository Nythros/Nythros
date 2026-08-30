<?php

declare(strict_types=1);

namespace Nythros\Security\Tests;

use Nythros\Security\RedisTokenStore;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;
use PHPUnit\Framework\TestCase;

/**
 * RedisTokenStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisTokenStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 需 Redis 环境（本机 Redis 恢复后补跑对拍；开发期本机 Redis 不可用，本文件不参与日常运行）。
 * Requires a Redis environment (re-run for parity once local Redis recovers; while local Redis
 * is unavailable this file is excluded from routine runs).
 *
 * 五态矩阵 = ADR-013 8.2/8.4-8.6 + 18 章验收，与 InMemoryTokenStoreTest 对拍：
 * 三 scope 各 Valid 互不影响 / 同 scope 重放 Replayed / 未授权 Unauthorized（不写墓碑、主键保留）/
 * 过期 Expired（DEL 主键、跨 scope 一次性可见）/ remove 后未消费 scope Invalid / 畸形 scopes
 * （非 table 非 nil）Invalid + 主键 DEL / 旧格式无 scopes：map → Valid，其他 scope → Unauthorized
 * （不写墓碑、主键保留，先 consume 其他 scope 不破坏后续 map 消费）/ per-scope 墓碑键独立 TTL。
 * The five-state matrix mirrors ADR-013 8.2/8.4-8.6 and chapter 18 acceptance, paired against
 * InMemoryTokenStoreTest: three scopes each Valid independently / same-scope replay Replayed /
 * unauthorized scope Unauthorized (no tombstone, main key kept) / expiry Expired (DEL main key,
 * visible once across scopes) / remove then unconsumed scope Invalid / malformed scopes (non-table
 * non-nil) Invalid + main key DEL / legacy records without scopes: map → Valid, other scopes →
 * Unauthorized (no tombstone, main key kept; consuming another scope first does not break a later
 * map consume) / independent per-scope tombstone TTLs.
 */
final class RedisTokenStoreTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisTokenStore 集成测试');
        }

        // 读超时防御：Redis 服务僵死（连接可达但不响应）时 ping 至多挂 1s 后返回 false → skip，而非无限挂起
        // Read-timeout guard: if Redis is wedged (connectable but unresponsive), ping hangs at most 1s and returns false → skip, instead of hanging forever
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisTokenStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':token:';
    }

    protected function tearDown(): void
    {
        if ($this->redis === null) {
            return;
        }

        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
        $this->redis = null;
    }

    private function store(?callable $clock = null): RedisTokenStore
    {
        return new RedisTokenStore($this->redis, $this->prefix, 60, $clock);
    }

    /** per-scope 墓碑键 Per-scope tombstone key */
    private function tombstoneKey(string $scope): string
    {
        return $this->prefix . self::TOKEN . ':consume:' . $scope;
    }

    public function testSavedTokenConsumesValidThenReplayed(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
    }

    public function testThreeScopesConsumeIndependentlyValid(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat', 'team'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'team'));
    }

    public function testSameScopeReplayDoesNotAffectOtherScopes(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
    }

    public function testConsumeValidWritesPerScopeTombstoneAndKeepsMainKey(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));

        // per-scope 墓碑独立 TTL：map 墓碑 TTL ∈ (0, 30]（= 剩余有效期），chat 墓碑不存在
        // Independent per-scope tombstone TTL: the map tombstone TTL ∈ (0, 30] (= remaining lifetime); no chat tombstone
        $mapTombstoneTtl = $this->redis->ttl($this->tombstoneKey('map'));
        self::assertIsInt($mapTombstoneTtl);
        self::assertGreaterThan(0, $mapTombstoneTtl);
        self::assertLessThanOrEqual(30, $mapTombstoneTtl);
        self::assertFalse($this->redis->exists($this->tombstoneKey('chat')) > 0);

        // 主键保留（per-scope 模型：消费不删主键）
        // Main key survives (per-scope model: consumption does not delete the main key)
        self::assertTrue($this->redis->exists($this->prefix . self::TOKEN) > 0);
    }

    public function testUnauthorizedScopeDoesNotConsumeAnything(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        // 未授权：不写墓碑、主键保留 Unauthorized: no tombstone written, main key kept
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        self::assertFalse($this->redis->exists($this->tombstoneKey('chat')) > 0);
        self::assertTrue($this->redis->exists($this->prefix . self::TOKEN) > 0);
        // 无墓碑：再次 consume 同一未授权 scope 仍是 Unauthorized 而非 Replayed
        // No tombstone: consuming the same unauthorized scope again reads Unauthorized, not Replayed
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 授权 scope 不受影响 The authorized scope is unaffected
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testExpiredIsExpiredOnceThenInvalidAcrossScopes(): void
    {
        // ADR 8.6：Expired 一次性可见——首个撞上过期判定的 consume DEL 主键，其余 scope 再 consume → Invalid
        // ADR 8.6: Expired is visible once — the first consume hitting the expiry check DELs the main key; any further scope then reads Invalid
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $store = $this->store($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        $now += 31.0;

        self::assertSame(TokenStatus::Expired, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'chat'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testUnknownTokenIsInvalid(): void
    {
        self::assertSame(TokenStatus::Invalid, $this->store()->consume(self::TOKEN, 'map'));
    }

    public function testMalformedRecordIsInvalid(): void
    {
        $this->redis->setex($this->prefix . self::TOKEN, 90, 'not-json');

        self::assertSame(TokenStatus::Invalid, $this->store()->consume(self::TOKEN, 'map'));
        // 非法记录被消费脚本清理（防畸形键反复触发） The malformed record is cleaned by the consume script (preventing repeated hits)
        self::assertFalse($this->redis->exists($this->prefix . self::TOKEN) > 0);
    }

    public function testMalformedExpiryInvalidAndDeletesMainKey(): void
    {
        // expiresAt 非数字（字符串）→ tonumber nil → DEL + 3（与 InMemory 的 NAN expiresAt 用例对拍）
        // Non-numeric expiresAt (string) → tonumber nil → DEL + 3 (paired with InMemory's NAN expiresAt case)
        $this->redis->setex($this->prefix . self::TOKEN, 90, json_encode([
            'uid' => 'u1',
            'mapId' => 'map-1',
            'scopes' => ['map'],
            'issuedAt' => 100.0,
            'expiresAt' => 'not-a-number',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(TokenStatus::Invalid, $this->store()->consume(self::TOKEN, 'map'));
        self::assertFalse($this->redis->exists($this->prefix . self::TOKEN) > 0);
    }

    public function testMalformedScopesInvalidAndDeletesMainKey(): void
    {
        // scopes 存在但畸形类型（字符串而非 table）→ Invalid + DEL 主键（非 Unauthorized）
        //（注入固定时钟 100.0：expiresAt 130.0 未过期，畸形判定分支可达；
        // 若用真实时钟 epoch 秒 130.0 早已过期，会先判 Expired）
        // scopes present but of a malformed type (string, not a table) → Invalid + DEL main key
        // (not Unauthorized). A fixed clock of 100.0 is injected: expiresAt 130.0 is unexpired so
        // the malformation branch is reachable (with the real clock, epoch seconds 130.0 is long
        // past and the script would first judge Expired)
        $this->redis->setex($this->prefix . self::TOKEN, 90, json_encode([
            'uid' => 'u1',
            'mapId' => 'map-1',
            'scopes' => 'map',
            'issuedAt' => 100.0,
            'expiresAt' => 130.0,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(TokenStatus::Invalid, $this->store(static fn (): float => 100.0)->consume(self::TOKEN, 'map'));
        self::assertFalse($this->redis->exists($this->prefix . self::TOKEN) > 0);
    }

    public function testLegacyRecordWithoutScopesAuthorizesMapOnly(): void
    {
        // 旧格式（无 scopes 字段）向后兼容：scope='map' → 授权；其他 scope → Unauthorized
        // （不消费任何标记、不删主键，见 testLegacyRecordOtherScopeUnauthorizedKeepsMainKey）
        // Legacy records (no scopes field) are back-compatible: scope='map' → authorized; other
        // scopes → Unauthorized (nothing consumed, main key kept — see
        // testLegacyRecordOtherScopeUnauthorizedKeepsMainKey)
        $legacyPayload = json_encode([
            'uid' => 'u1',
            'mapId' => 'map-1',
            'issuedAt' => 100.0,
            'expiresAt' => 130.0,
        ], JSON_THROW_ON_ERROR);

        $this->redis->setex($this->prefix . self::TOKEN, 90, $legacyPayload);
        $store = $this->store(static fn (): float => 100.0);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
        self::assertTrue($this->redis->exists($this->tombstoneKey('map')) > 0);
    }

    public function testLegacyRecordOtherScopeUnauthorizedKeepsMainKey(): void
    {
        // 旧格式（无 scopes 字段）+ 非 map scope → Unauthorized：不写墓碑、不删主键
        //（旧格式 token 是「仅授权 map」的有效 token，先 consume chat 不应破坏后续 map 消费；
        // 与畸形 scopes 的 Invalid + DEL 分支严格分离）
        // Legacy record (no scopes field) + non-map scope → Unauthorized: no tombstone written,
        // main key kept (a legacy token is a valid map-only token — consuming chat first must not
        // break the later map consume; strictly separated from the malformed-scopes Invalid + DEL
        // branch)
        $this->redis->setex($this->prefix . self::TOKEN, 90, json_encode([
            'uid' => 'u1',
            'mapId' => 'map-1',
            'issuedAt' => 100.0,
            'expiresAt' => 130.0,
        ], JSON_THROW_ON_ERROR));

        $store = $this->store(static fn (): float => 100.0);

        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 不写墓碑：再次 consume 同一未授权 scope 仍是 Unauthorized 而非 Replayed
        // No tombstone written: consuming the same unauthorized scope again reads Unauthorized, not Replayed
        self::assertSame(TokenStatus::Unauthorized, $store->consume(self::TOKEN, 'chat'));
        // 不删主键：后续 map 消费不受影响 The main key survives: the later map consume is unaffected
        self::assertTrue($this->redis->exists($this->prefix . self::TOKEN) > 0);
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testRemoveUnconsumedScopeInvalidConsumedScopeReplayed(): void
    {
        $store = $this->store(static fn (): float => 100.0);

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
        $store = $this->store(static fn (): float => 100.0);

        $store->remove(self::TOKEN);

        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekReturnsRecordWithoutConsuming(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        $record = $store->peek(self::TOKEN);

        self::assertNotNull($record);
        self::assertSame('u1', $record->uid);
        self::assertSame('map-1', $record->mapId);
        self::assertSame(100.0, $record->issuedAt);
        self::assertSame(130.0, $record->expiresAt);
        self::assertSame(['map', 'chat'], $record->scopes);
        // peek 不消费：再次 peek 与 consume 均可见 peek does not consume: a second peek and consume both see it
        self::assertNotNull($store->peek(self::TOKEN));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekExpiredTokenReturnsNullWithoutConsuming(): void
    {
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $store = $this->store($clock);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);

        $now += 31.0;

        self::assertNull($store->peek(self::TOKEN));
        // peek 纯只读（不删除）：expired 判定保留给 consume 原子脚本（服务端 peek→consume 链路依赖此语义）
        // peek is purely read-only (never deletes): the expired verdict is left to consume's atomic script (the server peek→consume pipeline relies on this)
        self::assertSame(TokenStatus::Expired, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Invalid, $store->consume(self::TOKEN, 'map'));
    }

    public function testPeekConsumedTokenStillReturnsRecord(): void
    {
        // ADR 8.5 有意变更：per-scope 墓碑模型下主键保留，某 scope 消费后 peek 仍返回 record
        //（旧总墓碑模型下消费后 peek 为 null）
        // ADR 8.5 intentional change: under per-scope tombstones the main key survives, so peek
        // still returns the record after a scope is consumed (under the old overall tombstone model peek was null)
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));

        $record = $store->peek(self::TOKEN);
        self::assertNotNull($record);
        self::assertSame('u1', $record->uid);
        self::assertSame(['map', 'chat'], $record->scopes);
    }

    public function testPeekUnknownTokenReturnsNull(): void
    {
        self::assertNull($this->store()->peek(self::TOKEN));
    }

    public function testPerScopeTombstoneKeyExpiryIndependent(): void
    {
        // per-scope 墓碑键独立 TTL 的可观测性用例（Redis 键可外部 DEL 模拟 TTL 到期）：
        // 主键未过期时人为删除该 scope 墓碑 → 该 scope 可再次 Valid（总墓碑模型下为 Invalid）；
        // 另一 scope 的墓碑不受影响。自然到期（不 DEL、等 TTL）时墓碑到期 ⇔ expiresAt 已过 → Expired，
        // 与 InMemory 侧墓碑过期语义对拍一致。
        // Observability case for independent per-scope tombstone TTLs (Redis keys can be DELed
        // externally to simulate TTL expiry): while the main key is unexpired, removing this
        // scope's tombstone lets the scope consume Valid again (Invalid under the overall
        // tombstone model); the other scope's tombstone is unaffected. Natural expiry (no DEL,
        // wait for TTL) coincides with expiresAt being past → Expired, matching InMemory's
        // tombstone-expiry semantics.
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));

        // 模拟 map 墓碑 TTL 到期（主键仍未过期） Simulate the map tombstone's TTL expiring (main key still unexpired)
        $this->redis->del($this->tombstoneKey('map'));

        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));
        // chat 墓碑未受 map 墓碑删除影响（未消费 → 仍 Valid） The chat scope is unaffected by the map tombstone deletion (unconsumed → still Valid)
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
    }

    public function testRepeatedSaveOfConsumedTokenStaysReplayedForConsumedScope(): void
    {
        $store = $this->store(static fn (): float => 100.0);

        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], 100.0, 130.0), 30);
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'map'));

        // 墓碑期内重复 save 同一 token：已消费 scope 墓碑优先判定 → 仍 Replayed；新 record 新增的
        // scope 无墓碑 → Valid
        // Re-saving the same token within the tombstone window: the consumed scope's tombstone
        // takes priority → still Replayed; the newly added scope has no tombstone → Valid
        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map', 'chat'], 100.0, 130.0), 30);
        self::assertSame(TokenStatus::Replayed, $store->consume(self::TOKEN, 'map'));
        self::assertSame(TokenStatus::Valid, $store->consume(self::TOKEN, 'chat'));
    }

    /**
     * 并发竞态：5 个独立进程同时 consume 同一 token 的同一 scope，验证 EVAL 原子性——恰一个 Valid，其余 Replayed。
     * Concurrency race: 5 independent processes consume the same scope of the same token simultaneously,
     * verifying EVAL atomicity — exactly one Valid, the rest Replayed.
     */
    public function testConcurrentConsumeExactlyOneValid(): void
    {
        $store = $this->store(static fn (): float => microtime(true));
        $store->save(self::TOKEN, new TokenRecord('u1', 'map-1', ['map'], microtime(true), microtime(true) + 60.0), 60);

        $workerScript = tempnam(sys_get_temp_dir(), 'rtc_worker_');
        self::assertNotFalse($workerScript);
        file_put_contents($workerScript, <<<'PHP'
<?php
require $argv[1];
$redis = new \Redis();
try {
    $connected = @$redis->connect('127.0.0.1', 6379, 1.0);
} catch (\Throwable) {
    $connected = false;
}
if ($connected !== true) {
    fwrite(STDOUT, 'connect-fail');
    exit(1);
}
// ready 信号：就绪后等待 barrier 统一放行
$redis->set($argv[3] . ':ready:' . $argv[5], '1');
$deadline = microtime(true) + 10.0;
while ($redis->get($argv[3]) === false) {
    if (microtime(true) > $deadline) {
        fwrite(STDOUT, 'barrier-timeout');
        exit(1);
    }
    usleep(200);
}
try {
    $store = new \Nythros\Security\RedisTokenStore($redis, $argv[2]);
    fwrite(STDOUT, $store->consume($argv[4], 'map')->name);
} catch (\Throwable $e) {
    fwrite(STDOUT, 'error:' . $e->getMessage());
    exit(1);
}
PHP);

        $barrier = $this->prefix . 'barrier';
        $workerCount = 5;
        $processes = [];
        $pipes = [];
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

        for ($i = 0; $i < $workerCount; $i++) {
            $pipes[$i] = [];
            $processes[$i] = proc_open(
                [PHP_BINARY, $workerScript, $autoload, $this->prefix, $barrier, self::TOKEN, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
            self::assertIsResource($processes[$i]);
        }

        // 等待全部子进程就绪（ready 标记），再统一放行，最大化竞争窗口
        $readyDeadline = microtime(true) + 10.0;
        do {
            $readyCount = 0;
            for ($i = 0; $i < $workerCount; $i++) {
                if ($this->redis->get($barrier . ':ready:' . $i) !== false) {
                    $readyCount++;
                }
            }
            if ($readyCount === $workerCount) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $readyDeadline);
        self::assertSame($workerCount, $readyCount, '子进程未全部就绪');

        $this->redis->set($barrier, '1');

        $results = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $out = stream_get_contents($pipes[$i][1]);
            stream_get_contents($pipes[$i][2]);
            $code = proc_close($processes[$i]);
            self::assertSame(0, $code, 'consume 子进程异常退出');
            self::assertNotFalse($out);
            $results[] = trim((string) $out);
        }

        unlink($workerScript);

        sort($results);
        self::assertSame(
            ['Replayed', 'Replayed', 'Replayed', 'Replayed', 'Valid'],
            $results,
            '并发 consume 必须恰好一个 Valid，其余 Replayed',
        );
    }
}
