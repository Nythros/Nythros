<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Quest;

use Nythros\Framework\Quest\CachedQuestStore;
use Nythros\Framework\Quest\InMemoryQuestStore;
use Nythros\Framework\Quest\QuestBatchStoreInterface;
use Nythros\Framework\Quest\QuestProgress;
use Nythros\Framework\Quest\QuestStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * 计数后端桩：包 InMemoryQuestStore 并记录 all/save/delete 往返次数，可按 questId 抛错模拟写回失败——
 * 用于证明 CachedQuestStore「热路径零往返、冲刷点才回写」的语义（真 Redis 契约由 RedisQuestStoreTest 覆盖）。
 * A counting backend stub wrapping InMemoryQuestStore, recording all/save/delete round-trips and able to throw
 * per questId to simulate write-back failure — it lets CachedQuestStoreTest prove the "zero hot-path round-trips,
 * backend writes only at flush points" semantics (the real Redis contract stays under RedisQuestStoreTest).
 */
final class CountingQuestBackend implements QuestStoreInterface, QuestBatchStoreInterface
{
    public int $getAllCalls = 0;

    public int $saveCalls = 0;

    public int $deleteCalls = 0;

    /** @var int saveMany 调用次数（批量回写往返计数）saveMany call count (the batch round-trip counter). */
    public int $saveManyCalls = 0;

    /** @var array<string, true> questId => save/saveMany 时抛错 throw-on-write set. */
    public array $failOn = [];

    private InMemoryQuestStore $store;

    public function __construct()
    {
        $this->store = new InMemoryQuestStore();
    }

    public function save(QuestProgress $progress): void
    {
        $this->saveCalls++;
        if (isset($this->failOn[$progress->questId])) {
            throw new \RuntimeException('simulated write-back failure');
        }
        $this->store->save($progress);
    }

    public function saveMany(array $progresses): void
    {
        $this->saveManyCalls++;
        if ($progresses === []) {
            return;
        }
        // 任一失败记录在批内 → 整批抛出（批量语义 = 全有或全留）
        // Any failing record inside the batch throws the whole batch (batch semantics = all-or-keep)
        foreach ($progresses as $progress) {
            if (isset($this->failOn[$progress->questId])) {
                throw new \RuntimeException('simulated batch write-back failure');
            }
        }
        foreach ($progresses as $progress) {
            $this->store->save($progress);
        }
    }

    public function get(string $uid, string $questId): ?QuestProgress
    {
        return $this->store->get($uid, $questId);
    }

    public function all(string $uid): array
    {
        $this->getAllCalls++;

        return $this->store->all($uid);
    }

    public function delete(string $uid, string $questId): void
    {
        $this->deleteCalls++;
        $this->store->delete($uid, $questId);
    }
}

/**
 * CachedQuestStore 单测：写回缓冲语义（无外部依赖，CI 常驻可跑）。
 * Unit tests for CachedQuestStore: the write-back buffer semantics (no external dependency, always runs in CI).
 *
 * 覆盖：读穿透首访载入一次、save 只标脏不回写、preload 幂等预热、evict 回写+淘汰、flushAll 部分失败留脏、
 * delete 记删除标记、readThrough=false 严格模式未预热读返回空。
 * Covers: read-through first-touch single load, save marking dirty without writing, idempotent preload,
 * evict write-back + drop, flushAll partial-failure keeping dirty, delete flagging, and the readThrough=false
 * strict mode returning empty for an un-preloaded uid.
 */
final class CachedQuestStoreTest extends TestCase
{
    public function testReadThroughLoadsOnceThenServesFromMemory(): void
    {
        $backend = new CountingQuestBackend();
        $backend->save(new QuestProgress('u1', 'q1', 1, false, false));
        $backend->save(new QuestProgress('u1', 'q2', 5, true, false));
        $backend->getAllCalls = 0;
        $backend->saveCalls = 0;

        $store = new CachedQuestStore($backend);

        self::assertSame(1, $store->get('u1', 'q1')?->count);
        self::assertSame(5, $store->get('u1', 'q2')?->count);
        // 后续读全部命中缓存：后端整批读只发生一次（首访）
        self::assertSame(1, $store->get('u1', 'q1')?->count);
        self::assertCount(2, $store->all('u1'));
        self::assertSame(1, $backend->getAllCalls, 'read-through must load the uid exactly once');
        self::assertSame(0, $backend->saveCalls, 'reads must never write back');
    }

    public function testSaveIsInMemoryUntilFlushed(): void
    {
        $backend = new CountingQuestBackend();
        $store = new CachedQuestStore($backend);

        $store->save(new QuestProgress('u1', 'q1', 1, false, false));
        $store->save(new QuestProgress('u1', 'q1', 2, false, false));

        // 内存视图已是最新值，但后端零写回
        self::assertSame(2, $store->get('u1', 'q1')?->count);
        self::assertSame(0, $backend->saveManyCalls, 'save must not touch the backend until flush');
        self::assertTrue($store->hasDirty('u1'));

        self::assertTrue($store->flushAll());
        self::assertSame(1, $backend->saveManyCalls, 'the whole session collapses into one batched write');
        self::assertFalse($store->hasDirty('u1'));
        self::assertSame(2, $backend->get('u1', 'q1')?->count);
    }

    public function testFlushCollapsesManyRecordsAcrossUidsIntoOneBatch(): void
    {
        $backend = new CountingQuestBackend();
        $store = new CachedQuestStore($backend);

        // 三个 uid、每 uid 若干脏任务：一次 flushAll 只付一次批量往返
        // Three uids, a few dirty quests each: one flushAll pays exactly one batched round-trip
        $store->save(new QuestProgress('u1', 'a', 1, false, false));
        $store->save(new QuestProgress('u1', 'b', 2, false, false));
        $store->save(new QuestProgress('u2', 'a', 3, false, false));
        $store->save(new QuestProgress('u3', 'c', 4, true, false));

        self::assertTrue($store->flushAll());
        self::assertSame(1, $backend->saveManyCalls, 'cross-uid records merge into one pipeline round-trip');
        self::assertSame(0, $backend->saveCalls, 'the batch path must not use per-record save');
        self::assertFalse($store->hasDirty('u1'));
        self::assertFalse($store->hasDirty('u2'));
        self::assertFalse($store->hasDirty('u3'));
        self::assertSame(2, $backend->get('u1', 'b')?->count);
        self::assertSame(4, $backend->get('u3', 'c')?->count);
    }

    public function testPreloadIsIdempotent(): void
    {
        $backend = new CountingQuestBackend();
        $backend->save(new QuestProgress('u1', 'q1', 3, true, false));
        $backend->getAllCalls = 0;

        $store = new CachedQuestStore($backend);
        $store->preload('u1');
        $store->preload('u1');

        self::assertSame(1, $backend->getAllCalls, 'preload must be idempotent');
        self::assertSame(3, $store->get('u1', 'q1')?->count);
    }

    public function testEvictWritesBackThenDropsFromCache(): void
    {
        $backend = new CountingQuestBackend();
        $store = new CachedQuestStore($backend);

        $store->save(new QuestProgress('u1', 'q1', 2, false, false));
        $store->evict('u1');

        self::assertSame(1, $backend->saveManyCalls);
        self::assertFalse($store->hasDirty('u1'));
        self::assertSame(2, $backend->get('u1', 'q1')?->count);

        // 淘汰后再预热：回读到的正是冲刷值（跨会话不丢）
        $store->preload('u1');
        self::assertSame(2, $store->get('u1', 'q1')?->count);
    }

    public function testFlushAllKeepsFailedRecordsDirty(): void
    {
        $backend = new CountingQuestBackend();
        $backend->failOn = ['bad' => true];
        $store = new CachedQuestStore($backend);

        $store->save(new QuestProgress('u1', 'ok', 1, false, false));
        $store->save(new QuestProgress('u1', 'bad', 1, false, false));

        self::assertFalse($store->flushAll());
        // 批量语义 = 全有或全留：一条失败整批留脏（下次兜底重试，绝不静默丢）
        // Batch semantics = all-clear-or-all-keep: one failure keeps the whole batch dirty for the next fallback
        self::assertTrue($store->hasDirty('u1'), 'the failed batch must stay dirty for retry');

        // 解除故障 → 下次冲刷成功
        $backend->failOn = [];
        self::assertTrue($store->flushAll());
        self::assertFalse($store->hasDirty('u1'));
        self::assertSame(1, $backend->get('u1', 'bad')?->count);
    }

    public function testDeleteFlagWritesBackDelete(): void
    {
        $backend = new CountingQuestBackend();
        $backend->save(new QuestProgress('u1', 'q1', 9, true, false));
        $backend->deleteCalls = 0;

        $store = new CachedQuestStore($backend);
        $store->delete('u1', 'q1');

        self::assertNull($store->get('u1', 'q1'));
        self::assertSame(0, $backend->deleteCalls, 'delete must not touch the backend until flush');

        self::assertTrue($store->flushAll());
        self::assertSame(1, $backend->deleteCalls);
        self::assertNull($backend->get('u1', 'q1'));
    }

    public function testNonBatchBackendFallsBackToPerRecordSave(): void
    {
        // 只实现 QuestStoreInterface 的后端（无批量能力）→ CachedQuestStore 必须自动回落逐条回写
        // A backend implementing only QuestStoreInterface (no batching) → CachedQuestStore must auto-fall-back
        // to per-record write-back
        $backend = new class (new InMemoryQuestStore()) implements QuestStoreInterface {
            public int $saveCalls = 0;

            public function __construct(private readonly QuestStoreInterface $inner)
            {
            }

            public function save(QuestProgress $progress): void
            {
                $this->saveCalls++;
                $this->inner->save($progress);
            }

            public function get(string $uid, string $questId): ?QuestProgress
            {
                return $this->inner->get($uid, $questId);
            }

            public function all(string $uid): array
            {
                return $this->inner->all($uid);
            }

            public function delete(string $uid, string $questId): void
            {
                $this->inner->delete($uid, $questId);
            }
        };

        $store = new CachedQuestStore($backend);
        $store->save(new QuestProgress('u1', 'a', 1, false, false));
        $store->save(new QuestProgress('u1', 'b', 2, false, false));

        self::assertTrue($store->flushAll());
        self::assertSame(2, $backend->saveCalls, 'without batching the fallback writes per record');
        self::assertSame(1, $backend->get('u1', 'a')?->count);
        self::assertSame(2, $backend->get('u1', 'b')?->count);
    }

    public function testNoReadThroughRequiresPreload(): void
    {
        $backend = new CountingQuestBackend();
        $backend->save(new QuestProgress('u1', 'q1', 1, false, false));
        $backend->getAllCalls = 0;

        $store = new CachedQuestStore($backend, readThrough: false);

        // 未预热：读返回空且零往返（装配保证 attach 必先 preload 的严格模式）
        self::assertNull($store->get('u1', 'q1'));
        self::assertSame([], $store->all('u1'));
        self::assertSame(0, $backend->getAllCalls);

        $store->preload('u1');
        self::assertSame(1, $store->get('u1', 'q1')?->count);
    }
}
