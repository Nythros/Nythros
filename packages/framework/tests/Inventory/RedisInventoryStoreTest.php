<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Inventory;

use Nythros\Framework\Inventory;
use Nythros\Framework\Inventory\RedisInventoryStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisInventoryStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * 契约：快照覆盖写（save/load）、原子加扣（add/remove）、水化工具（hydrate）、白名单校验。
 * Integration tests for RedisInventoryStore: requires Redis on 127.0.0.1:6379, skipped entirely when
 * unavailable. Contract: snapshot overwrite (save/load), atomic add/remove, the hydrate helper, whitelists.
 *
 * 键隔离：随机基前缀 + tearDown 清理，不与生产 nythros:bag: 键混用（RedisQuestStoreTest 同款）。
 * Key isolation: a random base prefix cleaned in tearDown, never colliding with production nythros:bag: keys.
 */
final class RedisInventoryStoreTest extends TestCase
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
        if ($connected !== true || @$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisInventoryStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':bag:';
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

    private function store(): RedisInventoryStore
    {
        return new RedisInventoryStore($this->redis, $this->prefix);
    }

    public function testSnapshotSaveLoadRoundTrip(): void
    {
        $store = $this->store();

        self::assertSame([], $store->load('1001'), '无键读 = 空表');

        $store->save('1001', ['gold' => 3, 'potion' => 1]);
        self::assertSame(['gold' => 3, 'potion' => 1], $store->load('1001'));

        // 覆盖写：旧物品不残留
        $store->save('1001', ['bone' => 2]);
        self::assertSame(['bone' => 2], $store->load('1001'), 'save 是整表覆盖,不合并');

        // 空快照清键
        $store->save('1001', []);
        self::assertSame([], $store->load('1001'));
    }

    public function testAtomicAddRemove(): void
    {
        $store = $this->store();
        $store->add('1001', 'gold', 5);
        $store->add('1001', 'gold', 2);
        self::assertSame(7, $store->count('1001', 'gold'));

        self::assertTrue($store->remove('1001', 'gold', 7));
        self::assertSame(0, $store->count('1001', 'gold'), '扣到 0 即 HDEL');
        self::assertFalse($store->remove('1001', 'gold', 1), '不足拒绝');
    }

    public function testHydrateBuildsInventory(): void
    {
        $inventory = RedisInventoryStore::hydrate(['gold' => 3, 'zero' => 0, 'neg' => -1]);

        self::assertInstanceOf(Inventory::class, $inventory);
        self::assertSame(['gold' => 3], $inventory->all(), '零/负数条目水化时丢弃');
    }

    public function testIllegalsAreRejected(): void
    {
        $store = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $store->load('bad uid with space');
    }

    public function testIllegalItemIsRejected(): void
    {
        $store = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $store->add('1001', 'bad item', 1);
    }
}
