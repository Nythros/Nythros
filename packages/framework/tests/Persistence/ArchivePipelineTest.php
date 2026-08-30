<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Persistence;

use Nythros\Contracts\TimerInterface;
use Nythros\Framework\Persistence\ArchivePipeline;
use Nythros\Persistence\StorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * ArchivePipeline 测试：标脏零 I/O → 断连立即 flush → 30s 定时兜底（假时钟驱动门控）→ saveBatch 部分失败重试/超限放弃（ADR-013 10.5，裁决 4/6）。
 * Tests for ArchivePipeline: zero-I/O mark-dirty → immediate disconnect flush → 30s periodic fallback (fake-clock-driven gate) → partial saveBatch failure retry / give-up past the cap (ADR-013 10.5, rulings 4/6).
 */
final class ArchivePipelineTest extends TestCase
{
    public function testMarkDirtyPerformsNoIo(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        $pipeline->markDirty('u1', ['hp' => 100]);

        // 标脏只登记内存，不做任何 I/O（裁决 4：不阻塞帧预算）
        // Mark-dirty only registers in memory, no I/O at all (ruling 4: never blocks the frame budget)
        self::assertSame([], $storage->saveCalls);
        self::assertSame([], $storage->batchCalls);
    }

    public function testFlushIdPersistsImmediatelyRegardlessOfClock(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        $pipeline->markDirty('u1', ['hp' => 100]);
        $pipeline->flushId('u1');

        // 断连/登出是强制同步点：时钟为 0 也立即 save（不受 30s 门控影响）
        // Disconnect/logout is a forced sync point: saves immediately even at clock 0 (unaffected by the 30s gate)
        self::assertCount(1, $storage->saveCalls);
        self::assertSame('u1', $storage->saveCalls[0]['id']);
        self::assertSame(['hp' => 100], $storage->saveCalls[0]['data']);
        self::assertSame('players', $storage->saveCalls[0]['collection']);
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));

        // 已冲刷记录再次 flushId 为空操作
        // A flushed record is a no-op on a second flushId
        $pipeline->flushId('u1');
        self::assertCount(1, $storage->saveCalls);
    }

    public function testFlushIdOnNonDirtyIsNoOp(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        $pipeline->flushId('never-dirty');

        self::assertSame([], $storage->saveCalls);
    }

    public function testPeriodicFallbackFlushesAfterThirtySeconds(): void
    {
        $storage = new RecordingStorage();
        $clock = new ArchiveClock();
        $timer = new ArchiveFakeTimer();
        $pipeline = $this->pipeline($storage, $timer, $clock);

        $pipeline->markDirty('u1', ['hp' => 100]);
        $pipeline->markDirty('u2', ['hp' => 80]);

        // 时钟未推进 30s：门控拦截，不冲刷
        // Before 30s elapse: the gate blocks, no flush
        $timer->trigger();
        self::assertSame([], $storage->batchCalls);

        $clock->now = ArchivePipeline::FLUSH_INTERVAL_SECONDS - 0.01;
        $timer->trigger();
        self::assertSame([], $storage->batchCalls);

        // 30s 到点：批量 saveBatch 全部残留脏记录
        // At the 30s mark: batch saveBatch over every remaining dirty record
        $clock->now = ArchivePipeline::FLUSH_INTERVAL_SECONDS;
        $timer->trigger();

        self::assertCount(1, $storage->batchCalls);
        self::assertSame('players', $storage->batchCalls[0]['collection']);
        self::assertSame(
            ['u1' => ['hp' => 100], 'u2' => ['hp' => 80]],
            $storage->batchCalls[0]['records'],
        );
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
        self::assertSame(['hp' => 80], $storage->load('players', 'u2'));

        // 冲刷后脏表清空：再触发兜底不再发起 saveBatch
        // Dirty table cleared after the flush: further triggers issue no saveBatch
        $clock->now += ArchivePipeline::FLUSH_INTERVAL_SECONDS;
        $timer->trigger();
        self::assertCount(1, $storage->batchCalls);
    }

    public function testExplicitFlushDoesNotPostponeFallback(): void
    {
        $storage = new RecordingStorage();
        $clock = new ArchiveClock();
        $timer = new ArchiveFakeTimer();
        $pipeline = $this->pipeline($storage, $timer, $clock);

        // 显式 flush 是强制同步点：立即冲刷
        // An explicit flush is a forced sync point: flushes now
        $pipeline->markDirty('u1', ['hp' => 100]);
        $clock->now = 20.0;
        $pipeline->flush();
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));

        // 显式 flush 不推进 lastFallbackAt：构造时刻（t=0）起算的 30s 兜底窗口不被 t=20 的显式冲刷顺延——
        // 若被顺延，t=30 触发时门控距上次仅 10s 会被拦截、u2 不会落库
        // An explicit flush does not advance lastFallbackAt: the 30s fallback window measured from
        // construction (t=0) is not postponed by the t=20 explicit flush — if it were, the t=30 trigger
        // would see only 10s elapsed, be gated off, and u2 would never persist
        $pipeline->markDirty('u2', ['hp' => 80]);
        $clock->now = ArchivePipeline::FLUSH_INTERVAL_SECONDS;
        $timer->trigger();

        self::assertCount(2, $storage->batchCalls);
        self::assertSame(['u2' => ['hp' => 80]], $storage->batchCalls[1]['records']);
    }

    public function testBatchPartialFailureRetriesThenGivesUp(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // a 成功、x 连续失败：前 MAX_SAVE_ATTEMPTS 次批量冲刷 x 留脏重试，超限后记日志放弃
        // a succeeds while x keeps failing: x stays dirty for retry through the first MAX_SAVE_ATTEMPTS batch flushes, then is logged and dropped
        $storage->batchFailurePlan = [
            ['x'], ['x'], ['x'],
        ];
        $pipeline->markDirty('a', ['hp' => 1]);
        $pipeline->markDirty('x', ['hp' => 0]);

        for ($i = 1; $i <= ArchivePipeline::MAX_SAVE_ATTEMPTS; $i++) {
            $pipeline->flush();
        }

        self::assertSame(['hp' => 1], $storage->load('players', 'a'));
        self::assertNull($storage->load('players', 'x'));
        self::assertCount(ArchivePipeline::MAX_SAVE_ATTEMPTS, $storage->batchCalls);

        // 放弃后脏表清空：再显式 flush 不再发起 saveBatch
        // Dirty table is empty after the give-up: further explicit flushes issue no saveBatch
        $pipeline->flush();
        self::assertCount(ArchivePipeline::MAX_SAVE_ATTEMPTS, $storage->batchCalls);
    }

    public function testMarkDirtyResetsAttempts(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // x 失败两次后新数据到来：计数清零，第三次批量冲刷成功持久化新数据
        // After x fails twice, fresh data arrives: the counter resets and the third batch flush persists the new data
        $storage->batchFailurePlan = [['x'], ['x']];
        $pipeline->markDirty('x', ['hp' => 0]);

        $pipeline->flush();
        $pipeline->flush();
        self::assertNull($storage->load('players', 'x'));

        $pipeline->markDirty('x', ['hp' => 55]);
        $pipeline->flush();

        self::assertSame(['hp' => 55], $storage->load('players', 'x'));
    }

    public function testFlushIdFailureKeepsRecordForFallbackRetry(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // 断连立即冲刷失败：留脏等待 30s 兜底批量重试
        // The immediate disconnect flush fails: the record stays dirty for the 30s fallback batch retry
        $storage->saveFailures = ['u1' => true];
        $pipeline->markDirty('u1', ['hp' => 100]);
        $pipeline->flushId('u1');
        self::assertNull($storage->load('players', 'u1'));

        $storage->saveFailures = [];
        $pipeline->flush();
        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
    }

    public function testGiveUpDropsRecordFromFutureFlushes(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // x 放弃后新标脏的 y 批量冲刷时 x 不再参与
        // Once x is given up, a later batch flush of freshly dirtied y no longer includes x
        $storage->batchFailurePlan = [['x'], ['x'], ['x'], ['x']];
        $pipeline->markDirty('x', ['hp' => 0]);

        for ($i = 1; $i <= ArchivePipeline::MAX_SAVE_ATTEMPTS; $i++) {
            $pipeline->flush();
        }

        $pipeline->markDirty('y', ['hp' => 9]);
        $pipeline->flush();

        self::assertNull($storage->load('players', 'x'));
        self::assertSame(['hp' => 9], $storage->load('players', 'y'));
        self::assertCount(ArchivePipeline::MAX_SAVE_ATTEMPTS + 1, $storage->batchCalls);
        self::assertSame(['y' => ['hp' => 9]], $storage->batchCalls[ArchivePipeline::MAX_SAVE_ATTEMPTS]['records']);
    }

    public function testNumericUidFlushPersistsWithStringId(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // 数字 uid（如 1001）被 PHP 数组键整数化为 int 键：flush 的批量路径必须 (string) 规整后
        // 再交存储，否则 strict_types 下 StorageInterface::save(string $id) 收到 int 直接 TypeError
        // Numeric uids (e.g. 1001) become int keys under PHP array semantics: the batch flush path must
        // normalize ids to (string) before handing them to the storage, otherwise
        // StorageInterface::save(string $id) receives an int and throws TypeError under strict_types
        $pipeline->markDirty('1001', ['hp' => 100]);
        $pipeline->flush();

        // 批量路径不抛 TypeError 且数据落库（saveBatch 的 records 键命中数字 uid）
        // The batch path never throws and the data persists (the saveBatch records key matches the numeric uid)
        self::assertSame(['hp' => 100], $storage->load('players', '1001'));
        self::assertArrayHasKey('1001', $storage->batchCalls[0]['records']);

        // flushId 单条路径：save 收到 string id
        // Single flushId path: save receives a string id
        $pipeline->markDirty('1002', ['hp' => 80]);
        $pipeline->flushId('1002');
        self::assertSame('1002', $storage->saveCalls[0]['id']);
    }

    public function testNumericUidBatchFailureTracksAttemptsAsString(): void
    {
        $storage = new RecordingStorage();
        $pipeline = $this->pipeline($storage, new ArchiveFakeTimer(), new ArchiveClock());

        // 数字 uid 批量失败：失败计数路径须 (string) 规整，否则 registerFailure(string $id) 收到 int TypeError
        // A failed numeric-uid batch: the failure-counter path must normalize to (string), otherwise
        // registerFailure(string $id) receives an int and throws TypeError
        $storage->batchFailurePlan = [['1001']];
        $pipeline->markDirty('1001', ['hp' => 100]);
        $pipeline->flush();

        self::assertNull($storage->load('players', '1001'));

        // 留脏等待兜底重试：下一轮（存储恢复）flush 成功落库
        // Stays dirty for the fallback retry: the next flush (storage recovered) persists
        $pipeline->flush();
        self::assertSame(['hp' => 100], $storage->load('players', '1001'));
    }

    public function testLoadReadsBackTheArchivedRecordAndMissYieldsNull(): void
    {
        $storage = new RecordingStorage();
        $pipeline = new ArchivePipeline($storage, 'players', null, static fn (): float => 0.0);

        // P18 读路径：markDirty+flush 落库后 load 读回；未落库 id 与缺行返回 null
        // The P18 read path: markDirty+flush persists, load reads back; a never-flushed id and a missing row yield null.
        $pipeline->markDirty('1001', ['inventory' => ['potion' => 2]]);
        $pipeline->flush();

        self::assertSame(['inventory' => ['potion' => 2]], $pipeline->load('1001'));
        self::assertNull($pipeline->load('1002'), '无归档记录返回 null / null when no archived record');
    }

    public function testTimerNullSkipsFallbackRegistration(): void
    {
        $storage = new RecordingStorage();
        // timer 为 null = 不启动 30s 兜底（单测/纯消息模式）：仍可显式 flush
        // A null timer = no 30s fallback (unit-test/message-only mode): explicit flush still works
        $pipeline = new ArchivePipeline($storage, 'players', null, static fn (): float => 0.0);

        $pipeline->markDirty('u1', ['hp' => 100]);
        $pipeline->flush();

        self::assertSame(['hp' => 100], $storage->load('players', 'u1'));
    }

    /**
     * 组装被测管线：统一 collection=players。
     * Builds the pipeline under test: fixed collection=players.
     */
    private function pipeline(RecordingStorage $storage, ArchiveFakeTimer $timer, ArchiveClock $clock): ArchivePipeline
    {
        return new ArchivePipeline($storage, 'players', $timer, $clock);
    }
}

/**
 * RecordingStorage - 记录 save/saveBatch 调用的假存储：可配置 save 失败 id 与按序消耗的批量失败计划。
 * RecordingStorage - a fake storage recording save/saveBatch calls: configurable save failures and a per-call batch failure plan.
 */
final class RecordingStorage implements StorageInterface
{
    /** @var array<string, array<string, array<string, mixed>>> 已持久化数据：collection => id => data Persisted data: collection => id => data. */
    public array $persisted = [];

    /** @var list<array{collection: string, id: string, data: array<string, mixed>}> save 调用记录 save call records. */
    public array $saveCalls = [];

    /** @var list<array{collection: string, records: array<string, array<string, mixed>>}> saveBatch 调用记录 saveBatch call records. */
    public array $batchCalls = [];

    /** @var array<string, bool> save 失败配置：id => save 是否返回 false save failure config: id => whether save returns false. */
    public array $saveFailures = [];

    /** @var list<list<string>> 按调用序消耗的批量失败 id 计划：第 n 次 saveBatch 返回该列表（缺省空） Per-call batch failure plan: the n-th saveBatch returns this list (empty by default). */
    public array $batchFailurePlan = [];

    public function save(string $collection, string $id, array $data): bool
    {
        $this->saveCalls[] = ['collection' => $collection, 'id' => $id, 'data' => $data];

        if (($this->saveFailures[$id] ?? false) === true) {
            return false;
        }

        $this->persisted[$collection][$id] = $data;

        return true;
    }

    public function load(string $collection, string $id): ?array
    {
        return $this->persisted[$collection][$id] ?? null;
    }

    public function delete(string $collection, string $id): bool
    {
        unset($this->persisted[$collection][$id]);

        return true;
    }

    public function saveBatch(string $collection, array $records): array
    {
        $this->batchCalls[] = ['collection' => $collection, 'records' => $records];

        $failed = array_shift($this->batchFailurePlan) ?? [];
        // 失败 id 判定 (string) 归一化：数字 uid 在 PHP 数组键下 int/string 等价，两种形态都算命中
        // The failed-id check normalizes to (string): numeric uids are int/string equivalent as PHP array keys, both forms match
        $failedKeys = [];
        foreach ($failed as $failedId) {
            $failedKeys[(string) $failedId] = true;
        }
        foreach ($records as $id => $data) {
            if (!isset($failedKeys[(string) $id])) {
                $this->persisted[$collection][$id] = $data;
            }
        }

        return $failed;
    }
}

/**
 * ArchiveFakeTimer - 测试定时器：只记录回调不真正定时，由测试经 trigger 手动驱动（与 MapServerTest 的 FakeTimer 同名异类，避免类冲突）。
 * ArchiveFakeTimer - test timer: records callbacks without real timing, driven manually by tests via trigger (a distinct class from MapServerTest's FakeTimer to avoid class collision).
 */
final class ArchiveFakeTimer implements TimerInterface
{
    /** @var list<callable> 已登记的回调 Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
        // 测试不需要取消语义，空操作 No cancellation semantics needed in tests; no-op
    }

    /**
     * 手动触发全部已登记回调（模拟定时器逐次到期）。
     * Manually fires every registered callback (simulating timer expirations).
     */
    public function trigger(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }
}

/**
 * ArchiveClock - 测试时钟：可变的秒级时间源（可调用对象注入 ArchivePipeline），供 30s 门控精确推进。
 * ArchiveClock - test clock: a mutable second-based time source (a callable injected into ArchivePipeline) for precise 30s-gate advancement.
 */
final class ArchiveClock
{
    /** @var float 当前时间（秒） Current time in seconds. */
    public float $now = 0.0;

    public function __invoke(): float
    {
        return $this->now;
    }
}
