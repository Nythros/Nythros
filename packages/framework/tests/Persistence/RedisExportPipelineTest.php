<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Persistence;

use Nythros\Contracts\TimerInterface;
use Nythros\Framework\Inventory\RedisInventoryStore;
use Nythros\Framework\Persistence\RedisExportPipeline;
use PHPUnit\Framework\TestCase;

/**
 * 导出管线测试定时器：记录回调供手动驱动（与 ArchivePipelineTest 的 ArchiveFakeTimer 同名异类口径,
 * 独立类名避免 autoload 冲突）。
 * Test timer for the export pipeline: records callbacks for manual firing (the ArchiveFakeTimer convention,
 * under a distinct class name to avoid autoload collisions).
 */
final class ExportFakeTimer implements TimerInterface
{
    /** @var list<callable> Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
        // 测试不需要取消语义 No cancellation semantics needed in tests
    }

    public function count(): int
    {
        return count($this->callbacks);
    }

    public function triggerLast(): void
    {
        $callback = end($this->callbacks);
        if ($callback !== false) {
            $callback();
        }
    }
}

/**
 * 时钟假源（驱动 30s 兜底门控与紧急合并窗,不依赖真实 sleep）。
 * Fake clock (drives the 30s gate and the urgent window without real sleeps).
 */
final class ExportClock
{
    public float $now = 0.0;

    public function __invoke(): float
    {
        return $this->now;
    }
}

/**
 * RedisExportPipeline 集成测试：依赖 127.0.0.1:6379,不可用时跳过（RedisQuestStoreTest 同款门控）。
 * 核心契约:markDirty 零 I/O → flush 一次 pipeline 同时写背包 hash + XADD Stream → load 回读快照;
 * scheduleFlushId 合并窗;失败留脏、达上限放弃（裁决 6）;bindTimer 幂等。
 * Integration tests for RedisExportPipeline (skip when Redis is unavailable). Contract under test:
 * zero-I/O markDirty → one flush = one pipeline writing the bag hash AND XADDing the Stream → load reads the
 * snapshot back; the scheduleFlushId coalescing window; keep-dirty-then-give-up (ruling 6); idempotent bindTimer.
 */
final class RedisExportPipelineTest extends TestCase
{
    private ?\Redis $redis = null;

    private string $prefix = '';

    private string $streamKey = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true || @$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisExportPipeline 集成测试');
        }

        $tag = bin2hex(random_bytes(6));
        $this->prefix = "nythros:test:{$tag}:bag:";
        $this->streamKey = "nythros:test:{$tag}:export";
    }

    protected function tearDown(): void
    {
        if ($this->redis === null) {
            return;
        }
        foreach ($this->redis->keys($this->prefix . '*') ?: [] as $k) {
            $this->redis->del((string) $k);
        }
        $this->redis->del($this->streamKey);
        $this->redis->close();
        $this->redis = null;
    }

    private function pipeline(?TimerInterface $timer = null, ?callable $clock = null): RedisExportPipeline
    {
        $redis = $this->redis;
        assert($redis instanceof \Redis);

        return new RedisExportPipeline(
            static fn (): \Redis => $redis,
            new RedisInventoryStore($redis, $this->prefix),
            $this->streamKey,
            $timer,
            $clock,
        );
    }

    public function testFlushWritesBagAndPublishesStreamInOneRoundTrip(): void
    {
        $p = $this->pipeline();
        $p->markDirty('1001', ['inventory' => ['gold' => 2, 'potion' => 1]]);

        self::assertSame(1, $p->pendingCount());
        self::assertSame(0, $this->redis->hExists($this->prefix . '1001', 'gold') ? 1 : 0, 'markDirty 零 I/O');
        self::assertSame(0, (int) $this->redis->xLen($this->streamKey));

        $p->flush();

        self::assertSame(0, $p->pendingCount());
        self::assertSame('2', (string) $this->redis->hGet($this->prefix . '1001', 'gold'), '背包快照落 Redis 权威');
        self::assertSame(1, (int) $this->redis->xLen($this->streamKey), '脏快照同时进导出 Stream');

        // phpredis xRange 形态:[entryId => fieldsMap]（与 xReadGroup 同层,实测校准）
        $entry = array_values($this->redis->xRange($this->streamKey, '-', '+'))[0] ?? [];
        self::assertSame('1001', (string) ($entry['id'] ?? ''));
        self::assertSame(['inventory' => ['gold' => 2, 'potion' => 1]], json_decode((string) ($entry['data'] ?? 'null'), true));
        self::assertGreaterThan(0, (int) ($entry['version'] ?? 0), '版本随发布单调携带');
    }

    public function testLoadReadsBackSnapshotFromRedisAuthority(): void
    {
        $p = $this->pipeline();
        self::assertNull($p->load('nope'), '无键 = null（新入场语义）');

        $p->markDirty('2002', ['inventory' => ['bone' => 7]]);
        $p->flush();

        self::assertSame(['inventory' => ['bone' => 7]], $p->load('2002'), 'load 与 markDirty 数据形状对称');

        // 覆盖写：旧物品不残留（整表快照）
        $p->markDirty('2002', ['inventory' => ['gold' => 1]]);
        $p->flush();
        self::assertSame(['inventory' => ['gold' => 1]], $p->load('2002'));
    }

    public function testScheduleFlushIdWithoutTimerIsSyncFallback(): void
    {
        $p = $this->pipeline();
        $p->markDirty('3003', ['inventory' => ['gold' => 5]]);
        $p->scheduleFlushId('3003');

        self::assertSame(0, $p->pendingCount(), '无定时器 → 立即冲刷（flushId 回落）');
    }

    public function testUrgentWindowCoalescesAcrossUids(): void
    {
        $timer = new ExportFakeTimer();
        $clock = new ExportClock();
        $p = $this->pipeline($timer, $clock);
        $timerCountAfterBind = $timer->count();

        $p->markDirty('4001', ['inventory' => ['gold' => 1]]);
        $p->markDirty('4002', ['inventory' => ['gold' => 2]]);
        $p->scheduleFlushId('4001');
        $p->scheduleFlushId('4002');

        self::assertSame($timerCountAfterBind + 1, $timer->count(), '两笔登记共享一个合并窗');
        self::assertSame(2, $p->pendingCount(), '登记本身零往返');

        $timer->triggerLast();

        self::assertSame(0, $p->pendingCount());
        self::assertSame(2, (int) $this->redis->xLen($this->streamKey), '一窗一批:两条快照一次发布');
    }

    public function testBindTimerIsIdempotentAndArmsFallback(): void
    {
        $timer = new ExportFakeTimer();
        $p = $this->pipeline(); // 构造 timer=null

        $p->bindTimer($timer);
        $p->bindTimer($timer);
        self::assertSame(1, $timer->count(), 'bindTimer 幂等:周期兜底只挂一次');

        // 合并窗可用:schedule 在 bindTimer 之后登记第二条回调（=1 兜底 +1 窗）
        $p->markDirty('5005', ['inventory' => ['gold' => 1]]);
        $p->scheduleFlushId('5005');
        self::assertSame(2, $timer->count());
    }

    public function testPeriodicFallbackHonorsClockGate(): void
    {
        $clock = new ExportClock();
        $p = $this->pipeline(null, $clock);
        $p->markDirty('6006', ['inventory' => ['gold' => 1]]);

        $clock->now = 10.0;
        $p->periodicFlush();
        self::assertSame(1, $p->pendingCount(), '<30s 门控跳过');

        $clock->now = 31.0;
        $p->periodicFlush();
        self::assertSame(0, $p->pendingCount());
    }

    public function testPublishFailureKeepsDirtyAndGivesUpAtCap(): void
    {
        $broken = static function (): \Redis {
            throw new \RuntimeException('redis gone');
        };
        $p = new RedisExportPipeline(
            $broken,
            new RedisInventoryStore($broken, $this->prefix),
            $this->streamKey,
        );
        $p->markDirty('7007', ['inventory' => ['gold' => 1]]);

        $p->flush();
        self::assertSame(1, $p->pendingCount(), '失败留脏');
        $p->flush();
        self::assertSame(1, $p->pendingCount());
        // 第三次达上限:放弃（裁决 6 丢失可解释——bag 权威仍在,仅导出老化）
        @$p->flush();
        self::assertSame(0, $p->pendingCount(), 'MAX_PUBLISH_ATTEMPTS 后记日志放弃');
    }

    public function testEmptyFlushIsNoOp(): void
    {
        $p = $this->pipeline();
        $p->flush();
        $p->periodicFlush();
        self::assertSame(0, (int) $this->redis->exists($this->streamKey), '空脏表不碰 Redis 键');
    }
}
