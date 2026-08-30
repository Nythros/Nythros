<?php

declare(strict_types=1);

namespace Nythros\Cluster\Tests;

use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Cluster\ServiceInstance;
use PHPUnit\Framework\TestCase;

/**
 * RedisServiceRegistry 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisServiceRegistry: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 覆盖矩阵 = ADR-013 10.2/10.3（MAJOR-3）+ 组 4 任务 4.4：
 * 注册/覆盖（重复注册覆盖 meta + 续心跳）/心跳 meta 原子合并（未提及字段保留）/
 * discover 心跳过滤（手动 DEL 心跳键模拟 kill -9，死实例不可见 + 服务 hash 条目与 uid hash 惰性回收）/
 * unregister（服务 hash 与心跳键删除，uid hash 保留至 TTL）/bind 覆盖写 + TTL 续期 /
 * unbind 条件删除（跨实例误删不可能）/resolve 字典序确定性（SORT_STRING，多实例命中取首个；
 * 死实例不参与 resolve）/全 miss null / 格式白名单（serviceType/serviceId/uid 空串）。
 * Coverage matrix = ADR-013 10.2/10.3 (MAJOR-3) + group-4 task 4.4: register/overwrite (re-registering
 * overwrites meta and renews the heartbeat) / atomic heartbeat meta merge (untouched fields survive) /
 * discover heartbeat filtering (manually DEL the heartbeat key to simulate kill -9; dead instances are
 * invisible while their service-hash entries and uid hashes are lazily reclaimed) / unregister (service
 * hash and heartbeat key removed, uid hash kept until TTL) / bind overwrite + TTL renewal / unbind
 * conditional deletion (cross-instance deletion impossible) / resolve lexicographic determinism
 * (SORT_STRING; first of multiple hits wins; dead instances never participate) / all-miss null /
 * format whitelists (serviceType/serviceId/empty uid).
 */
final class RedisServiceRegistryTest extends TestCase
{
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisServiceRegistry 集成测试');
        }

        // 读超时防御：Redis 服务僵死（连接可达但不响应）时 ping 至多挂 1s 后返回 false → skip，而非无限挂起
        // Read-timeout guard: if Redis is wedged (connectable but unresponsive), ping hangs at most 1s and returns false → skip, instead of hanging forever
        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisServiceRegistry 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':svc:';
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

    private function registry(): RedisServiceRegistry
    {
        return new RedisServiceRegistry($this->redis, $this->prefix);
    }

    /** 服务 hash 键 Service hash key */
    private function svcKey(string $type): string
    {
        return $this->prefix . $type;
    }

    /** 心跳键 Heartbeat key */
    private function hbKey(string $type, string $serviceId): string
    {
        return $this->prefix . 'hb:' . $type . ':' . $serviceId;
    }

    /** uid hash 键 uid hash key */
    private function uidKey(string $type, string $serviceId): string
    {
        return $this->prefix . 'uid:' . $type . ':' . $serviceId;
    }

    public function testRegisterThenDiscoverReturnsInstanceWithMeta(): void
    {
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', [
            'mapId' => 'map-1',
            'channelId' => 'ch-1',
            'playerCount' => 0,
            'wsAddress' => 'ws://127.0.0.1:9001',
        ]);

        $instances = $registry->discover('map');

        self::assertCount(1, $instances);
        self::assertInstanceOf(ServiceInstance::class, $instances['map-1#ch-1']);
        self::assertSame('map-1#ch-1', $instances['map-1#ch-1']->id);
        self::assertSame([
            'mapId' => 'map-1',
            'channelId' => 'ch-1',
            'playerCount' => 0,
            'wsAddress' => 'ws://127.0.0.1:9001',
        ], $instances['map-1#ch-1']->meta);

        // 注册即心跳：心跳键存在且 TTL ∈ (0, 15]
        // Register implies a heartbeat: the heartbeat key exists with TTL ∈ (0, 15]
        $ttl = $this->redis->ttl($this->hbKey('map', 'map-1#ch-1'));
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(15, $ttl);
    }

    public function testRegisterOverwritesMetaAndRenewsHeartbeat(): void
    {
        $registry = $this->registry();

        $registry->register('chat', 'chat-1', ['wsAddress' => 'ws://127.0.0.1:8081', 'playerCount' => 3]);
        $registry->register('chat', 'chat-1', ['wsAddress' => 'ws://127.0.0.1:8181']);

        // 重复注册 = 覆盖 meta（旧 playerCount 不再存在）+ 续心跳（自愈路径）
        // Re-registering overwrites meta (the old playerCount is gone) and renews the heartbeat (self-healing path)
        $instances = $registry->discover('chat');
        self::assertSame(['chat-1'], array_keys($instances));
        self::assertSame(['wsAddress' => 'ws://127.0.0.1:8181'], $instances['chat-1']->meta);
        self::assertTrue($this->redis->exists($this->hbKey('chat', 'chat-1')) > 0);
    }

    public function testHeartbeatMergesMetaAtomically(): void
    {
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1', 'playerCount' => 10]);
        $registry->heartbeat('map', 'map-1#ch-1', ['playerCount' => 20]);

        // 原子合并：playerCount 更新，未提及的 mapId 保留（register 覆盖与 heartbeat 合并语义分离）
        // Atomic merge: playerCount updated, untouched mapId survives (register's overwrite is separated from heartbeat's merge)
        $instances = $registry->discover('map');
        self::assertSame(['mapId' => 'map-1', 'playerCount' => 20], $instances['map-1#ch-1']->meta);

        // 心跳续期至 15s 上限
        // The heartbeat renews the key up to the 15s cap
        $ttl = $this->redis->ttl($this->hbKey('map', 'map-1#ch-1'));
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(15, $ttl);
    }

    public function testHeartbeatWithEmptyMetaOnlyRenewsKey(): void
    {
        $registry = $this->registry();

        $registry->register('team', 'team-1', ['wsAddress' => 'ws://127.0.0.1:8082']);
        $registry->heartbeat('team', 'team-1');

        // 空 meta：不做合并（Lua 脚本不执行），仅续心跳；meta 保持不变
        // Empty meta: no merge (the Lua script is skipped), heartbeat only; meta stays untouched
        $instances = $registry->discover('team');
        self::assertSame(['wsAddress' => 'ws://127.0.0.1:8082'], $instances['team-1']->meta);
    }

    public function testDiscoverFiltersInstanceWhoseHeartbeatKeyIsDeleted(): void
    {
        // 手动 DEL 心跳键模拟 kill -9（心跳停摆后 15s TTL 到期）：死实例不可见 + 服务 hash 条目惰性回收
        // Manually DEL the heartbeat key to simulate kill -9 (the 15s TTL elapses after the heartbeat stalls): the dead instance is invisible and its service-hash entry is lazily reclaimed
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->register('map', 'map-2#ch-1', ['mapId' => 'map-2']);

        $this->redis->del($this->hbKey('map', 'map-1#ch-1'));

        $instances = $registry->discover('map');

        self::assertSame(['map-2#ch-1'], array_keys($instances));
        // 惰性回收：服务 hash 条目已 HDEL（不再返回死实例的原始 JSON 条目）
        // Lazy reclamation: the service-hash entry was HDELed (the dead instance's raw JSON entry is gone)
        self::assertFalse($this->redis->hExists($this->svcKey('map'), 'map-1#ch-1'));
    }

    public function testDeadInstanceUidHashIsReclaimedLazilyOnDiscover(): void
    {
        // MAJOR-3 自愈：实例心跳过期 → discover 不可见 → 该实例 uid hash 整体失效并被惰性 DEL（空间回收）
        // MAJOR-3 self-healing: heartbeat expiry → invisible to discover → the instance's uid hash lapses wholesale and is lazily DELed (space reclamation)
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->bind('map', 'u1', 'map-1#ch-1');
        self::assertTrue($this->redis->exists($this->uidKey('map', 'map-1#ch-1')) > 0);

        $this->redis->del($this->hbKey('map', 'map-1#ch-1'));

        self::assertSame([], $registry->discover('map'));
        self::assertFalse($this->redis->exists($this->uidKey('map', 'map-1#ch-1')) > 0);
    }

    public function testUnregisterRemovesInstanceAndHeartbeatButKeepsUidHash(): void
    {
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->bind('map', 'u1', 'map-1#ch-1');
        $registry->unregister('map', 'map-1#ch-1');

        self::assertSame([], $registry->discover('map'));
        self::assertFalse($this->redis->exists($this->hbKey('map', 'map-1#ch-1')) > 0);
        // uid hash 保留至 TTL（文档化语义：不主动清理，靠 6h TTL 兜底 / discover 惰性回收）
        // The uid hash is kept until its TTL (documented semantics: no active cleanup; the 6h TTL and discover's lazy reclamation cover it)
        self::assertTrue($this->redis->exists($this->uidKey('map', 'map-1#ch-1')) > 0);
    }

    public function testBindOverwritesFieldAndRenewsTtl(): void
    {
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->bind('map', 'u1', 'map-1#ch-1', 21600);

        $longTtl = $this->redis->ttl($this->uidKey('map', 'map-1#ch-1'));
        self::assertIsInt($longTtl);
        self::assertGreaterThan(20000, $longTtl);

        // 覆盖写 + 续期：同 uid 同实例再次 bind（更短 TTL）→ 值覆盖为 '1'，TTL 收敛到新值
        // Overwrite + renewal: binding the same uid to the same instance again (shorter TTL) overwrites the value to '1' and the TTL converges to the new value
        $registry->bind('map', 'u1', 'map-1#ch-1', 60);

        self::assertSame('1', $this->redis->hGet($this->uidKey('map', 'map-1#ch-1'), 'u1'));
        $shortTtl = $this->redis->ttl($this->uidKey('map', 'map-1#ch-1'));
        self::assertIsInt($shortTtl);
        self::assertLessThanOrEqual(60, $shortTtl);
        self::assertGreaterThan(0, $shortTtl);
    }

    public function testUnbindRemovesOnlyMatchingInstanceField(): void
    {
        // unbind 条件删除：键按实例分组后，unbind(uid, A) 只删 A 实例 hash 的字段，
        // 其它实例（B）同 uid 的字段不受影响——跨实例误删不可能
        // Conditional unbind: with per-instance grouped keys, unbind(uid, A) removes only instance A's hash field; the same uid's field on another instance (B) is untouched — cross-instance deletion is impossible
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->register('map', 'map-2#ch-1', ['mapId' => 'map-2']);
        $registry->bind('map', 'u1', 'map-1#ch-1');
        $registry->bind('map', 'u1', 'map-2#ch-1');

        $registry->unbind('map', 'u1', 'map-1#ch-1');

        self::assertFalse($this->redis->hExists($this->uidKey('map', 'map-1#ch-1'), 'u1'));
        self::assertTrue($this->redis->hExists($this->uidKey('map', 'map-2#ch-1'), 'u1'));

        // 未 bind 过的实例 unbind 为 no-op（条件删除不会碰别的实例）
        // Unbinding from an instance the uid was never bound to is a no-op (conditional deletion never touches other instances)
        $registry->unbind('map', 'u1', 'map-3#ch-1');
        self::assertTrue($this->redis->hExists($this->uidKey('map', 'map-2#ch-1'), 'u1'));
    }

    public function testResolveReturnsLexicographicallyFirstInstance(): void
    {
        // MINOR-R4 确定性：同 uid 多实例命中时按 serviceId 字典序（SORT_STRING）取首个——
        // 结果确定且稳定，不随 hash 遍历顺序漂移
        // MINOR-R4 determinism: multiple hits for one uid take the lexicographically first serviceId (SORT_STRING) — deterministic and stable, never drifting with hash iteration order
        $registry = $this->registry();

        $registry->register('map', 'map-2#ch-1', ['mapId' => 'map-2']);
        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->bind('map', 'u1', 'map-2#ch-1');
        $registry->bind('map', 'u1', 'map-1#ch-1');

        self::assertSame('map-1#ch-1', $registry->resolve('map', 'u1'));

        // 解除字典序小者的绑定 → 落到字典序大者（顺序翻转亦稳定：数字字符串不按数值比较）
        // Unbinding the lexicographically smaller one falls through to the larger one (order-flip stability: numeric strings never compare numerically)
        $registry->unbind('map', 'u1', 'map-1#ch-1');
        self::assertSame('map-2#ch-1', $registry->resolve('map', 'u1'));
    }

    public function testResolveIgnoresDeadInstance(): void
    {
        // 自愈链路验收（ADR 10.3）：实例心跳过期 → 退出 resolve 查询范围，即使 uid hash 残留字段
        //（kill -9 场景：≤15s 后 resolve 不再指向该实例）
        // Self-healing acceptance (ADR 10.3): heartbeat expiry removes the instance from the resolve range even if its uid hash still holds the field (kill -9: within 15s resolve no longer points at it)
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);
        $registry->register('map', 'map-2#ch-1', ['mapId' => 'map-2']);
        $registry->bind('map', 'u1', 'map-1#ch-1');
        $registry->bind('map', 'u1', 'map-2#ch-1');

        $this->redis->del($this->hbKey('map', 'map-1#ch-1'));

        self::assertSame('map-2#ch-1', $registry->resolve('map', 'u1'));
    }

    public function testResolveReturnsNullWhenUnbound(): void
    {
        $registry = $this->registry();

        $registry->register('map', 'map-1#ch-1', ['mapId' => 'map-1']);

        // 未绑定 / 绑定后解绑 → null
        // Unbound / bound-then-unbound → null
        self::assertNull($registry->resolve('map', 'u1'));
        $registry->bind('map', 'u1', 'map-1#ch-1');
        self::assertSame('map-1#ch-1', $registry->resolve('map', 'u1'));
        $registry->unbind('map', 'u1', 'map-1#ch-1');
        self::assertNull($registry->resolve('map', 'u1'));
    }

    public function testDiscoverUnknownTypeReturnsEmptyMap(): void
    {
        self::assertSame([], $this->registry()->discover('gateway'));
    }

    public function testInvalidServiceTypeIsRejected(): void
    {
        // 白名单：serviceType 进入键构造，非法格式 fail fast（收敛键注入面）
        // Whitelist: serviceType enters key construction, so illegal formats fail fast (narrowing the key-injection surface)
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('BAD TYPE!', 'x-1');
    }

    public function testInvalidServiceIdIsRejected(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('chat', 'bad id with spaces');
    }

    public function testEmptyUidIsRejected(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->bind('map', '', 'map-1#ch-1');
    }

    public function testClockTimestampIsWrittenIntoHeartbeatKey(): void
    {
        // 心跳键值 = 注入时钟的时间戳（诊断用途；固定时钟可确定性断言）
        // The heartbeat key's value is the injected clock's timestamp (diagnostic use; a fixed clock asserts deterministically)
        $registry = new RedisServiceRegistry($this->redis, $this->prefix, static fn (): float => 12345.678);

        $registry->register('chat', 'chat-1', []);

        self::assertSame('12345.678', $this->redis->get($this->hbKey('chat', 'chat-1')));
    }
}
