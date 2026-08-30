<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Leaderboard;

use Nythros\Framework\Leaderboard\RedisLeaderboardStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisLeaderboardStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisLeaderboardStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:lb: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:lb: keys.
 */
final class RedisLeaderboardStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisLeaderboardStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisLeaderboardStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':lb:';
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

    private function store(): RedisLeaderboardStore
    {
        return new RedisLeaderboardStore($this->redis, $this->prefix);
    }

    public function testReportOverwritesAndTopOrdersByScoreDesc(): void
    {
        $store = $this->store();

        $store->report('level', 'u1', 100);
        $store->report('level', 'u2', 300);
        $store->report('level', 'u3', 200);

        // 同 uid 重复上报覆盖为最新分
        // Repeated reports overwrite with the latest score
        $store->report('level', 'u1', 400);

        self::assertSame([
            ['rank' => 1, 'uid' => 'u1', 'score' => 400.0],
            ['rank' => 2, 'uid' => 'u2', 'score' => 300.0],
            ['rank' => 3, 'uid' => 'u3', 'score' => 200.0],
        ], $store->top('level', 10));
    }

    public function testAggregateBulkUpserts(): void
    {
        $store = $this->store();

        $store->aggregate('level', ['u1' => 10, 'u2' => 30, 'u3' => 20]);
        // 二次聚合 upsert 覆盖旧分（定时聚合口径）
        // A second aggregation upserts over the old scores (the aggregation-job mode)
        $store->aggregate('level', ['u1' => 50]);

        self::assertSame([
            ['rank' => 1, 'uid' => 'u1', 'score' => 50.0],
            ['rank' => 2, 'uid' => 'u2', 'score' => 30.0],
            ['rank' => 3, 'uid' => 'u3', 'score' => 20.0],
        ], $store->top('level', 10));
        self::assertSame(3, $store->size('level'));
    }

    public function testRemoveDeletesEntry(): void
    {
        $store = $this->store();

        $store->report('level', 'u1', 100);
        $store->report('level', 'u2', 90);

        self::assertTrue($store->remove('level', 'u1'));
        self::assertFalse($store->remove('level', 'u1'));
        self::assertSame([['rank' => 1, 'uid' => 'u2', 'score' => 90.0]], $store->top('level', 10));
        self::assertNull($store->rankOf('level', 'u1'));
    }

    public function testRankOfReturnsOneBasedRankAndScore(): void
    {
        $store = $this->store();

        foreach (['u1' => 100, 'u2' => 200, 'u3' => 150] as $uid => $score) {
            $store->report('level', (string) $uid, (float) $score);
        }

        self::assertSame(['rank' => 1, 'score' => 200.0], $store->rankOf('level', 'u2'));
        self::assertSame(['rank' => 2, 'score' => 150.0], $store->rankOf('level', 'u3'));
        self::assertSame(['rank' => 3, 'score' => 100.0], $store->rankOf('level', 'u1'));
        self::assertNull($store->rankOf('level', 'nobody'));
    }

    public function testTopPaginationWithOffset(): void
    {
        $store = $this->store();

        for ($i = 1; $i <= 5; $i++) {
            $store->report('level', sprintf('u%d', $i), $i * 10.0);
        }

        self::assertSame([
            ['rank' => 2, 'uid' => 'u4', 'score' => 40.0],
            ['rank' => 3, 'uid' => 'u3', 'score' => 30.0],
        ], $store->top('level', 2, 1));

        // 越界分页返回空表
        // Out-of-range pagination returns an empty list
        self::assertSame([], $store->top('level', 5, 10));
        self::assertSame([], $store->top('empty-board', 5));
    }

    public function testEqualScoresFollowDeterministicLexicographicOrder(): void
    {
        $store = $this->store();

        // 同分成员按 Redis ZSet 字典序确定性排列（ZREVRANGE 同分为字典序降序）
        // Equal-score members follow the Redis ZSet's deterministic lexicographic order (ZREVRANGE reads reverse-lexicographic among equals)
        $store->aggregate('level', ['ua' => 50, 'uc' => 50, 'ub' => 50]);

        self::assertSame([
            ['rank' => 1, 'uid' => 'uc', 'score' => 50.0],
            ['rank' => 2, 'uid' => 'ub', 'score' => 50.0],
            ['rank' => 3, 'uid' => 'ua', 'score' => 50.0],
        ], $store->top('level', 10));
    }

    public function testIllegalUidAndBoardIdAreRejected(): void
    {
        $store = $this->store();

        try {
            $store->report('level', "bad;uid\x80", 1.0);
            self::fail('非法 uid 应抛 InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $store->report('; drop keys *', 'u1', 1.0);
    }
}
