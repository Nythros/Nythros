<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Cluster;

use Nythros\Framework\Auction\CurrencyLedger;
use Nythros\Framework\Cluster\RedisConnector;
use PHPUnit\Framework\TestCase;

/**
 * RedisConnector 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过。
 * Integration tests for RedisConnector: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 哨兵解析经测试缝（masterResolver 注入）驱动，不依赖真实哨兵进程；真实哨兵链路由
 * deploy/redis-ha/failover-drill.sh 端到端演练覆盖。
 * Sentinel resolution is driven through the test seam (an injected masterResolver) — no real sentinel
 * process required; the real Sentinel chain is covered end-to-end by deploy/redis-ha/failover-drill.sh.
 */
final class RedisConnectorTest extends TestCase
{
    private const REDIS_HOST = '127.0.0.1';

    private const REDIS_PORT = 6379;

    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect(self::REDIS_HOST, self::REDIS_PORT, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisConnector 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisConnector 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':';
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

    /** 直连模式（未配置哨兵）：行为与接入前一致——client 可用、refresh 空转、地址即配置地址。 */
    public function testDirectModeIsUnchanged(): void
    {
        $connector = new RedisConnector(self::REDIS_HOST, self::REDIS_PORT);

        self::assertFalse($connector->isSentinelMode());

        $client = $connector->client();
        self::assertTrue($client->set($this->prefix . 'direct', 'ok'));
        self::assertSame('ok', $client->get($this->prefix . 'direct'));
        self::assertSame(1, $connector->trackedClients());
        self::assertSame([self::REDIS_HOST, self::REDIS_PORT], $connector->masterAddress());

        // 直连模式 refresh 恒为「完成」且不产生任何副作用
        self::assertTrue($connector->refresh());
        self::assertTrue($connector->refresh(force: true));
        self::assertSame('ok', $client->get($this->prefix . 'direct'));
    }

    /** 工厂闭包形态：每次调用产出独立连接并全部纳入追踪（store 的消费形态）。 */
    public function testFactoryProducesTrackedIndependentClients(): void
    {
        $connector = new RedisConnector(self::REDIS_HOST, self::REDIS_PORT);
        $factory = $connector->factory();

        $first = $factory();
        $second = $factory();

        self::assertNotSame($first, $second);
        self::assertSame(2, $connector->trackedClients());
        self::assertTrue($first->set($this->prefix . 'a', '1'));
        self::assertTrue($second->set($this->prefix . 'b', '2'));
        self::assertSame('1', $second->get($this->prefix . 'a'));
    }

    /** 哨兵模式：解析结果（数字索引数组，phpredis 权威形态）归一为主库地址并建连。 */
    public function testSentinelModeResolvesMasterAddress(): void
    {
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static fn (): array => [self::REDIS_HOST, (string) self::REDIS_PORT],
        );

        self::assertTrue($connector->isSentinelMode());
        $client = $connector->client();

        self::assertTrue($client->ping());
        self::assertSame([self::REDIS_HOST, self::REDIS_PORT], $connector->masterAddress());
    }

    /** 哨兵模式且全部哨兵不可达（解析为 null）→ 建连抛 RuntimeException（走 500 兜底，worker 存活）。 */
    public function testClientThrowsWhenSentinelsUnreachable(): void
    {
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static fn (): ?array => null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('哨兵全部不可达');
        $connector->client();
    }

    /** 刷新时哨兵不可达：保留现有连接（数据面不因哨兵故障中断），返回 false 等下一轮。 */
    public function testRefreshKeepsConnectionsWhenResolutionFails(): void
    {
        $addresses = [[self::REDIS_HOST, (string) self::REDIS_PORT], null];
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static function () use (&$addresses): ?array {
                return array_shift($addresses);
            },
        );

        $client = $connector->client();
        self::assertTrue($client->set($this->prefix . 'alive', '1'));

        self::assertFalse($connector->refresh(force: true));
        self::assertSame('1', $client->get($this->prefix . 'alive'));
        self::assertSame([self::REDIS_HOST, self::REDIS_PORT], $connector->masterAddress());
    }

    /** 主库地址变化：探测新主通过后，全部追踪连接原地重指向（同一 \Redis 对象的 set 落到新地址）。 */
    public function testRefreshRetargetsClientsOnMasterChange(): void
    {
        // 'localhost' 与 '127.0.0.1' 指向同一实例但地址字面量不同 → 触发切换分支（探测 + 原地重指向）。
        $addresses = [[self::REDIS_HOST, (string) self::REDIS_PORT], ['localhost', (string) self::REDIS_PORT]];
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static function () use (&$addresses): ?array {
                return array_shift($addresses);
            },
        );

        $client = $connector->client();
        self::assertTrue($client->set($this->prefix . 'before', 'v1'));

        self::assertTrue($connector->refresh(force: true));
        self::assertSame(['localhost', self::REDIS_PORT], $connector->masterAddress());
        self::assertTrue($client->set($this->prefix . 'after', 'v2'));
        self::assertSame('v2', $client->get($this->prefix . 'after'));
    }

    /** 新主探活失败：不重指向现有连接（避免把整队连接指向不可达地址），返回 false 等下轮。 */
    public function testRefreshDoesNotRetargetWhenNewMasterUnreachable(): void
    {
        $addresses = [[self::REDIS_HOST, (string) self::REDIS_PORT], [self::REDIS_HOST, '1']];
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static function () use (&$addresses): ?array {
                return array_shift($addresses);
            },
        );

        $client = $connector->client();
        self::assertTrue($client->set($this->prefix . 'before', 'v1'));

        self::assertFalse($connector->refresh(force: true));
        self::assertSame([self::REDIS_HOST, self::REDIS_PORT], $connector->masterAddress());
        // 现有连接仍指向原主：读写照常
        self::assertSame('v1', $client->get($this->prefix . 'before'));
        self::assertTrue($client->set($this->prefix . 'after', 'v2'));
    }

    /**
     * 连接失活（Redis 重启/网络闪断的等价形态）：refresh 原地重连，store 缓存的连接自愈。
     * Dead connection (the equivalent of a Redis restart / network blip): refresh reconnects it in place, so a
     * store-cached connection heals — this is the pre-existing "worker never recovers" defect's fix.
     */
    public function testRefreshReconnectsDeadConnectionInPlace(): void
    {
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static fn (): array => [self::REDIS_HOST, (string) self::REDIS_PORT],
        );

        $client = $connector->client();
        self::assertTrue($client->set($this->prefix . 'k', 'v1'));

        $client->close();
        self::assertFalse($client->isConnected());

        self::assertTrue($connector->refresh(force: true));
        self::assertTrue($client->isConnected());
        self::assertSame('v1', $client->get($this->prefix . 'k'));
    }

    /** 已缓存连接的 store 经 connector 自愈：断连 → refresh → 读写恢复（端到端形态）。 */
    public function testStoreCachedConnectionSelfHealsThroughRefresh(): void
    {
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static fn (): array => [self::REDIS_HOST, (string) self::REDIS_PORT],
        );

        $ledger = new CurrencyLedger($connector->client(), $this->prefix . 'ec:');
        $ledger->deposit('1001', 7);
        self::assertSame(7, $ledger->balance('1001'));

        // 模拟 Redis 重启：store 缓存的连接失活，refresh 原地重连后 store 无需重建即可继续工作。
        $client = $connector->client();
        $client->close();
        $connector->refresh(force: true);

        self::assertTrue($ledger->withdraw('1001', 3));
        self::assertSame(4, $ledger->balance('1001'));
    }

    /** 刷新节流：非 force 的相邻调用不重复解析（worker 定时器 5s 一轮的语义）。 */
    public function testRefreshThrottlesUnlessForced(): void
    {
        $connector = new RedisConnector(
            self::REDIS_HOST,
            self::REDIS_PORT,
            ['127.0.0.1:26379'],
            'nythros',
            masterResolver: static fn (): array => [self::REDIS_HOST, (string) self::REDIS_PORT],
        );

        self::assertTrue($connector->refresh(force: true));
        self::assertFalse($connector->refresh());
        self::assertTrue($connector->refresh(force: true));
    }

    /** 环境变量构造：哨兵三变量齐全 → 哨兵模式 + 缺省监控组名；未设置 → 直连（开发缺省）。 */
    public function testFromEnvBuildsSentinelModeFromEnvironment(): void
    {
        $previous = [
            RedisConnector::ENV_SENTINELS => getenv(RedisConnector::ENV_SENTINELS),
            RedisConnector::ENV_MASTER => getenv(RedisConnector::ENV_MASTER),
        ];

        try {
            putenv(RedisConnector::ENV_SENTINELS . '=10.0.0.11:26379, 10.0.0.12:26379');
            putenv(RedisConnector::ENV_MASTER);
            $connector = RedisConnector::fromEnv(self::REDIS_HOST, self::REDIS_PORT);
            self::assertTrue($connector->isSentinelMode());

            putenv(RedisConnector::ENV_SENTINELS);
            $direct = RedisConnector::fromEnv(self::REDIS_HOST, self::REDIS_PORT);
            self::assertFalse($direct->isSentinelMode());
        } finally {
            foreach ($previous as $name => $value) {
                if (is_string($value) && $value !== '') {
                    putenv($name . '=' . $value);
                } else {
                    putenv($name);
                }
            }
        }
    }
}
