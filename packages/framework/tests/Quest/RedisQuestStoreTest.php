<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Quest;

use Nythros\Framework\Quest\QuestProgress;
use Nythros\Framework\Quest\RedisQuestStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisQuestStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * P4c 试点收口：任务进度持久化契约（保存/查询/枚举/删除 + 坏数据静默 + uid 白名单）。
 * Integration tests for RedisQuestStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 * The P4c pilot close-out: the quest-progress persistence contract (save/get/all/delete + corrupt-data silence + the uid whitelist).
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:gw:quest: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:gw:quest: keys.
 */
final class RedisQuestStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisQuestStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisQuestStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':quest:';
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

    private function store(): RedisQuestStore
    {
        return new RedisQuestStore($this->redis, $this->prefix);
    }

    public function testSaveGetAllRoundTrip(): void
    {
        $store = $this->store();

        // 未记录 → null
        self::assertNull($store->get('u1', 'kill_wolves'));
        self::assertSame([], $store->all('u1'));

        $store->save(new QuestProgress('u1', 'kill_wolves', 2, true, false));
        $store->save(new QuestProgress('u1', 'collect_bones', 1, true, true));

        $killed = $store->get('u1', 'kill_wolves');
        self::assertNotNull($killed);
        self::assertSame(2, $killed->count);
        self::assertTrue($killed->completed);
        self::assertFalse($killed->rewarded);

        $all = $store->all('u1');
        self::assertCount(2, $all);
        $byId = [];
        foreach ($all as $progress) {
            $byId[$progress->questId] = $progress;
        }
        self::assertSame(1, $byId['collect_bones']->count);
        self::assertTrue($byId['collect_bones']->rewarded);

        // uid 隔离：另一 uid 查不到
        self::assertNull($store->get('u2', 'kill_wolves'));
        self::assertSame([], $store->all('u2'));
    }

    public function testSaveOverwritesWholeRecord(): void
    {
        $store = $this->store();

        $store->save(new QuestProgress('u1', 'kill_wolves', 1, false, false));
        $store->save(new QuestProgress('u1', 'kill_wolves', 2, true, true));

        $progress = $store->get('u1', 'kill_wolves');
        self::assertNotNull($progress);
        self::assertSame(2, $progress->count);
        self::assertTrue($progress->completed);
        self::assertTrue($progress->rewarded);
    }

    public function testDeleteRemovesRecord(): void
    {
        $store = $this->store();

        $store->save(new QuestProgress('u1', 'kill_wolves', 2, true, false));
        $store->delete('u1', 'kill_wolves');
        self::assertNull($store->get('u1', 'kill_wolves'));

        // 删除不存在记录静默
        $store->delete('u1', 'missing_quest');
    }

    public function testCorruptRecordIsSilentlyIgnored(): void
    {
        $store = $this->store();

        // 直接写坏 JSON（绕过 store）→ 读取返回 null，不抛异常
        $this->redis->hSet($this->prefix . 'quest:u1', 'kill_wolves', 'not-json{{');
        self::assertNull($store->get('u1', 'kill_wolves'));
        self::assertSame([], $store->all('u1'));
    }

    public function testIllegalUidIsRejected(): void
    {
        $store = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $store->get('u1 with spaces', 'kill_wolves');
    }
}
