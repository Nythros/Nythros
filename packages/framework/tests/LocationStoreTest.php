<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Social\LocationStore;
use PHPUnit\Framework\TestCase;

/**
 * LocationStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for LocationStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:gw: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:gw: keys.
 */
final class LocationStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 LocationStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 LocationStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':gw:';
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

    private function store(): LocationStore
    {
        return new LocationStore($this->redis, $this->prefix);
    }

    public function testMarkOfflineThenIsOfflineTrueThenClearOfflineFalse(): void
    {
        $store = $this->store();

        self::assertFalse($store->isOffline('u1'));

        $store->markOffline('u1');
        self::assertTrue($store->isOffline('u1'));

        $store->clearOffline('u1');
        self::assertFalse($store->isOffline('u1'));
    }

    public function testSaveLocationThenGetLocationReturnsSnapshot(): void
    {
        $store = $this->store();

        $store->saveLocation('u1', 'map-1', 'ch-1', 10.5, -3.0);

        $location = $store->getLocation('u1');
        self::assertNotNull($location);
        self::assertSame('map-1', $location['mapId']);
        self::assertSame('ch-1', $location['channelId']);
        self::assertSame(10.5, $location['x']);
        self::assertSame(-3.0, $location['y']);
        self::assertGreaterThan(0.0, $location['updatedAt']);
    }

    public function testSaveLocationWithNullXYRoundTrips(): void
    {
        $store = $this->store();

        $store->saveLocation('u1', 'map-1', 'ch-1');

        $location = $store->getLocation('u1');
        self::assertNotNull($location);
        self::assertNull($location['x']);
        self::assertNull($location['y']);
    }

    public function testGetLocationMissingReturnsNull(): void
    {
        self::assertNull($this->store()->getLocation('missing'));
    }
}
