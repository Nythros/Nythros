<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Cluster;

use Nythros\Framework\Auction\CurrencyLedger;
use Nythros\Framework\Cluster\ReplicaBarrier;
use Nythros\Framework\Inventory\RedisInventoryStore;
use PHPUnit\Framework\TestCase;

/**
 * ReplicaBarrier 集成测试：依赖 127.0.0.1:6379 可用（单实例 = 无从库环境），不可用时整体跳过。
 * Integration tests for ReplicaBarrier: requires Redis on 127.0.0.1:6379 (a single instance = a replica-less
 * environment), skips entirely when unavailable.
 *
 * 无从库环境下 WAIT 1 会阻塞满 timeout——测试用短 timeout（20ms）保持快速；真实副本链路的耐久语义由
 * deploy/redis-ha/failover-drill.sh 端到端演练覆盖（写标记 → WAIT 1 → 切换 → 新主上标记仍在）。
 * Against a replica-less Redis, WAIT 1 blocks for the full timeout — tests use a short 20ms bound to stay fast;
 * the real durability semantics on a replica chain are covered end-to-end by deploy/redis-ha/failover-drill.sh
 * (write marker → WAIT 1 → failover → the marker must exist on the new master).
 */
final class ReplicaBarrierTest extends TestCase
{
    private const TIMEOUT_MS = 20;

    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        ReplicaBarrier::reset();

        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 ReplicaBarrier 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 ReplicaBarrier 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':';
    }

    protected function tearDown(): void
    {
        ReplicaBarrier::reset();

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

    /** 缺省关闭：await 是纯空转（不产生任何网络等待）。 */
    public function testDisabledByDefaultIsANoOp(): void
    {
        self::assertFalse(ReplicaBarrier::enabled());

        $started = microtime(true);
        ReplicaBarrier::await($this->redis);
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertLessThan(15.0, $elapsedMs, '未启用时 await 不应阻塞');
        self::assertTrue($this->redis->set($this->prefix . 'k', 'v'));
    }

    /** 启用后确认等待发生（无从库 → 阻塞到 timeout 上限后返回），且不抛异常、连接仍可用。 */
    public function testEnabledWaitsForReplicaAndNeverThrows(): void
    {
        ReplicaBarrier::configure(true, self::TIMEOUT_MS);

        $started = microtime(true);
        ReplicaBarrier::await($this->redis);
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertGreaterThanOrEqual((float) self::TIMEOUT_MS, $elapsedMs, '启用后应等待到 timeout 上限');
        self::assertTrue($this->redis->set($this->prefix . 'k', 'v2'));
        self::assertSame('v2', $this->redis->get($this->prefix . 'k'));
    }

    /** 屏障失败不得改变写结果：启用状态下 store 的写读语义与关闭时一致。 */
    public function testStoreWritesRemainCorrectWithBarrierEnabled(): void
    {
        ReplicaBarrier::configure(true, self::TIMEOUT_MS);

        $ledger = new CurrencyLedger($this->redis, $this->prefix . 'ec:');
        $ledger->deposit('1001', 10);
        self::assertTrue($ledger->withdraw('1001', 4));
        self::assertFalse($ledger->withdraw('1001', 99));
        self::assertSame(6, $ledger->balance('1001'));

        $bag = new RedisInventoryStore($this->redis, $this->prefix . 'bag:');
        $bag->add('1001', 'potion', 3);
        self::assertTrue($bag->remove('1001', 'potion', 1));
        self::assertFalse($bag->remove('1001', 'potion', 99));
        self::assertSame(2, $bag->count('1001', 'potion'));
    }

    /** 环境开关：NYTHROS_REDIS_AWAIT_REPLICAS=1 才启用。 */
    public function testEnabledFromEnvReadsTheFlag(): void
    {
        $previous = getenv(ReplicaBarrier::ENV_FLAG);

        try {
            putenv(ReplicaBarrier::ENV_FLAG . '=1');
            self::assertTrue(ReplicaBarrier::enabledFromEnv());

            putenv(ReplicaBarrier::ENV_FLAG . '=0');
            self::assertFalse(ReplicaBarrier::enabledFromEnv());

            putenv(ReplicaBarrier::ENV_FLAG);
            self::assertFalse(ReplicaBarrier::enabledFromEnv());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv(ReplicaBarrier::ENV_FLAG . '=' . $previous);
            } else {
                putenv(ReplicaBarrier::ENV_FLAG);
            }
        }
    }
}
