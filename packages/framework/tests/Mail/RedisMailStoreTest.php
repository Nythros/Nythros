<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Mail;

use Nythros\Framework\Mail\RedisMailStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisMailStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisMailStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:ml: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:ml: keys.
 */
final class RedisMailStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisMailStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisMailStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':ml:';
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

    private function store(): RedisMailStore
    {
        return new RedisMailStore($this->redis, $this->prefix);
    }

    public function testInsertThenGetAndList(): void
    {
        $store = $this->store();

        $store->insert('u1', 'mail-a', 'system', '标题 A', '正文 A', [['itemId' => 'gold', 'count' => 10]]);
        usleep(1000); // 保证 sentAt 单调递增（同毫秒插入的排序确定性） Keep sentAt monotonic (deterministic ordering for same-millisecond inserts).
        $store->insert('u1', 'mail-b', 'system', '标题 B', '正文 B', []);

        $mail = $store->get('u1', 'mail-a');
        self::assertNotNull($mail);
        self::assertSame('mail-a', $mail['mailId']);
        self::assertSame('system', $mail['from']);
        self::assertSame('标题 A', $mail['title']);
        self::assertSame('正文 A', $mail['body']);
        self::assertSame([['itemId' => 'gold', 'count' => 10]], $mail['attachments']);

        $list = $store->listByUid('u1');
        self::assertCount(2, $list);
        self::assertSame('mail-a', $list[0]['mailId']);
        self::assertSame('mail-b', $list[1]['mailId']);
    }

    public function testGetMissingMailReturnsNull(): void
    {
        self::assertNull($this->store()->get('u1', 'mail-none'));
    }

    public function testListEmptyMailboxReturnsEmptyList(): void
    {
        self::assertSame([], $this->store()->listByUid('u-empty'));
    }

    public function testClaimGateIsAtomicIdempotent(): void
    {
        $store = $this->store();

        // 首次领取抢到闸门；重复领取幂等命中
        // The first claim acquires the gate; repeated claims hit idempotently
        self::assertTrue($store->claimGate('u1', 'mail-a'));
        self::assertFalse($store->claimGate('u1', 'mail-a'));

        // 其他邮件互不影响
        // Other mails stay unaffected
        self::assertTrue($store->claimGate('u1', 'mail-b'));
    }

    public function testReleaseClaimGateAllowsReclaim(): void
    {
        $store = $this->store();

        self::assertTrue($store->claimGate('u1', 'mail-a'));
        $store->releaseClaimGate('u1', 'mail-a');
        self::assertTrue($store->claimGate('u1', 'mail-a'));
    }

    public function testDeleteRemovesMailAndClearsGate(): void
    {
        $store = $this->store();

        $store->insert('u1', 'mail-a', 'system', 't', 'b', []);
        $store->claimGate('u1', 'mail-a');

        self::assertTrue($store->delete('u1', 'mail-a'));
        self::assertNull($store->get('u1', 'mail-a'));
        // 删除清理闸门残留：重插同名邮件可重新领取
        // Deletion clears the gate: re-inserting the same mail id is claimable again
        self::assertTrue($store->claimGate('u1', 'mail-a'));
        self::assertFalse($store->delete('u1', 'mail-a'));
    }

    public function testIllegalUidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->get("bad;uid\x80", 'mail-a');
    }

    public function testIllegalMailIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->get('u1', '; drop keys *');
    }
}
