<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Social\FriendStoreInterface;
use Nythros\Framework\Social\RedisFriendStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisFriendStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisFriendStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:gw: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:gw: keys.
 */
final class FriendStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisFriendStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisFriendStore 集成测试');
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

    private function store(): RedisFriendStore
    {
        return new RedisFriendStore($this->redis, $this->prefix);
    }

    public function testApplyAcceptMakesBidirectionalFriendship(): void
    {
        $store = $this->store();

        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->apply('u1', 'u2'));
        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->accept('u1', 'u2'));

        // 双向一致：A→B 与 B→A 列表相同
        // Bidirectional consistency: A→B and B→A lists match
        self::assertSame(['u2'], $store->list('u1'));
        self::assertSame(['u1'], $store->list('u2'));
    }

    public function testApplySelfIsRejected(): void
    {
        self::assertSame(['code' => FriendStoreInterface::CODE_SELF], $this->store()->apply('u1', 'u1'));
    }

    public function testDuplicateApplyIsRejected(): void
    {
        $store = $this->store();

        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->apply('u1', 'u2'));
        self::assertSame(['code' => FriendStoreInterface::CODE_REQUEST_EXISTS], $store->apply('u1', 'u2'));
    }

    public function testApplyWhenAlreadyFriendsIsRejected(): void
    {
        $store = $this->store();

        $store->apply('u1', 'u2');
        $store->accept('u1', 'u2');

        self::assertSame(['code' => FriendStoreInterface::CODE_ALREADY_FRIENDS], $store->apply('u1', 'u2'));
    }

    public function testAcceptWithoutRequestIsRejected(): void
    {
        self::assertSame(['code' => FriendStoreInterface::CODE_REQUEST_NOT_FOUND], $this->store()->accept('u1', 'u2'));
    }

    public function testRejectRemovesPendingRequest(): void
    {
        $store = $this->store();

        $store->apply('u1', 'u2');
        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->reject('u1', 'u2'));

        // 拒绝后申请消失：再次同意 → request_not_found；可重新申请
        // After rejection the request is gone: accepting again reads request_not_found; re-applying is allowed
        self::assertSame(['code' => FriendStoreInterface::CODE_REQUEST_NOT_FOUND], $store->accept('u1', 'u2'));
        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->apply('u1', 'u2'));
    }

    public function testRejectWithoutRequestIsRejected(): void
    {
        self::assertSame(['code' => FriendStoreInterface::CODE_REQUEST_NOT_FOUND], $this->store()->reject('u1', 'u2'));
    }

    public function testRemoveDeletesFriendshipOnBothSides(): void
    {
        $store = $this->store();

        $store->apply('u1', 'u2');
        $store->accept('u1', 'u2');
        $store->apply('u3', 'u1');
        $store->accept('u3', 'u1');

        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->remove('u1', 'u2'));

        // 双向清空，其余好友不受影响
        // Both sides cleared; other friends stay unaffected
        self::assertSame(['u3'], $store->list('u1'));
        self::assertSame([], $store->list('u2'));

        self::assertSame(['code' => FriendStoreInterface::CODE_NOT_FRIENDS], $store->remove('u1', 'u2'));
    }

    public function testListEmptyAndSorted(): void
    {
        $store = $this->store();

        self::assertSame([], $store->list('u1'));

        foreach (['u3', 'u2'] as $friend) {
            $store->apply($friend, 'u1');
            $store->accept($friend, 'u1');
        }

        self::assertSame(['u2', 'u3'], $store->list('u1'));
    }

    public function testMutualApplyThenSingleAcceptClearsBothRequests(): void
    {
        $store = $this->store();

        $store->apply('u1', 'u2');
        $store->apply('u2', 'u1');

        self::assertSame(['code' => FriendStoreInterface::CODE_OK], $store->accept('u1', 'u2'));
        self::assertSame(['u2'], $store->list('u1'));
        self::assertSame(['u1'], $store->list('u2'));

        // 反向残留申请已清除：u2 对 u1 的重复申请不再 request_exists（已是好友）
        // The reverse leftover request is cleared: u2 re-applying to u1 reads already-friends, not request_exists
        self::assertSame(['code' => FriendStoreInterface::CODE_ALREADY_FRIENDS], $store->apply('u2', 'u1'));
    }

    public function testIllegalUidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->apply("bad;uid\x80", 'u2');
    }
}
