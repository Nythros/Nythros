<?php

declare(strict_types=1);

namespace Nythros\Framework\Persistence;

use Nythros\Contracts\TimerInterface;
use Nythros\Framework\Inventory\RedisInventoryStore;

/**
 * Redis 权威 + Stream 导出管线（PersistPipelineInterface 的第二实现，worker 零 PDO）。
 * Redis-authoritative + Stream-export pipeline (the second PersistPipelineInterface implementation; zero PDO in the worker).
 *
 * 架构定位（「Redis 管热数据、MySQL 只落盘」模型）：
 * Architectural position (the "Redis holds hot data, MySQL is only the durable sink" model):
 * - 游戏 worker 的会话热状态（背包等）**权威落 Redis**（本管线冲刷时写 `nythros:bag:{uid}` hash），
 *   worker 进程内不再出现 \PDO——MySQL 抖动从「帧延迟问题」降级为「落盘延迟问题」；
 *   session-hot state (the inventory, etc.) is authoritative in Redis (this pipeline writes the
 *   `nythros:bag:{uid}` hash at flush), so the worker process holds no \PDO at all — MySQL latency demotes from a
 *   frame-latency problem to an export-latency problem;
 * - 同时每条脏记录 XADD 进 Stream（默认 `nythros:export:players`），由 **storage-exporter** 独立进程消费组
 *   落 MySQL（报表/回档/Redis 灾难兜底），游戏进程与落盘彻底解耦；
 *   every dirty record is also XADD'd onto a Stream (`nythros:export:players` by default), consumed by the
 *   independent storage-exporter worker (consumer group → MySQL) for reports/rollback/Redis-disaster recovery —
 *   persistence is fully decoupled from game processes;
 * - load 读 Redis 背包 hash（attach 恢复主路径）；Redis 无键返回 null（新入场语义,与裁决 4/6 一致）。
 *   load reads the Redis bag hash (the attach-restore main path); a missing key yields null (fresh entry, in
 *   line with rulings 4/6).
 *
 * 语义与 ArchivePipeline 逐点对齐（drop-in）：markDirty 零 I/O、scheduleFlushId 0.2s 合并窗、
 * 30s 定时兜底、失败留脏重试、达上限记日志放弃、强制同步点不推进兜底门控。
 * Semantics align with ArchivePipeline point by point (drop-in): zero-I/O markDirty, the 0.2s coalescing window
 * of scheduleFlushId, the 30s periodic fallback, keep-dirty-then-retry on failure, logged give-up at the cap,
 * and forced sync points never postponing the fallback gate.
 *
 * 一致性边界：同一 uid 的脏快照在**本进程内**覆盖写（最新值胜出），单 map worker 权威（uid@connectionId
 * 与转移票据语义保证）；跨进程先后序由 Stream 追加序承载,exporter 以单消费者顺序消费即无乱序。
 * Consistency boundary: a uid's dirty snapshot is overwritten in-process (latest wins) under single-map-worker
 * authority (uid@connectionId + transfer-ticket semantics); cross-process ordering rides the Stream append order,
 * which a single sequential exporter consumer preserves.
 */
final class RedisExportPipeline implements PersistPipelineInterface
{
    /** 兜底冲刷间隔（秒）：与 ArchivePipeline 同节奏（裁决 4 的 30s 兜底）。 Fallback flush interval, the same cadence as ArchivePipeline (the 30s backstop of ruling 4). */
    public const FLUSH_INTERVAL_SECONDS = 30.0;

    /** 紧急冲刷合并窗（秒）：与 ArchivePipeline 同值（掉线风暴并批）。 Urgent coalescing window, the same value as ArchivePipeline (storm batching). */
    public const URGENT_COALESCE_SECONDS = 0.2;

    /** 单条记录最大发布尝试次数：达到后记日志放弃（裁决 6 丢失可解释）。 Max publish attempts per record before the logged give-up (ruling 6). */
    public const MAX_PUBLISH_ATTEMPTS = 3;

    /** 默认导出 Stream 键（storage-exporter 消费组消费） Default export Stream key (consumed by the storage-exporter group). */
    public const DEFAULT_STREAM_KEY = 'nythros:export:players';

    /** @var array<string, array<string, mixed>> 脏快照表：uid => 最新数据（覆盖写） Dirty snapshot table: uid => latest data (overwrite). */
    private array $pending = [];

    /** @var array<string, int> 发布失败计数：uid => 已失败次数 Publish failure counter: uid => failed attempts. */
    private array $attempts = [];

    /** @var array<string, true> 紧急队列：待合并冲刷的 uid Urgent queue: uids waiting for the coalesced flush. */
    private array $urgentQueue = [];

    /** 紧急定时器是否已挂（一次性,flushUrgent 复位） Whether the one-shot urgent timer is armed (reset by flushUrgent). */
    private bool $urgentArmed = false;

    /** 上次兜底冲刷时间（秒） Last fallback flush time in seconds. */
    private float $lastFallbackAt;

    /** 进程内单调版本源（毫秒时钟与自增保底,供 exporter 审计与乱序防线） In-process monotonic version source (ms clock with an increment floor, for exporter audit and the out-of-order guard). */
    private int $versionSeq = 0;

    /** @var callable(): float 时间源 Time source. */
    private $clock;

    /**
     * 组装管线（应在 worker 进程内构造,fork 后 lazy 建连,与 ArchivePipeline 同口径）。
     * Wires the pipeline (construct inside the worker; connections come up lazily post-fork, the ArchivePipeline convention).
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接 phpredis 客户端或工厂（Stream 发布用） Connected phpredis client or factory (for Stream publishing)
     * @param RedisInventoryStore $bags 背包 Redis 权威存储（冲刷快照落此） Redis-authoritative bag store (flushed snapshots land here)
     * @param string $streamKey 导出 Stream 键 Export Stream key
     * @param ?TimerInterface $timer 定时器;null = 不挂兜底/合并窗（单测/纯消息模式） Timer; null = no fallback/coalescing timers
     * @param ?callable(): float $clock 时间源(假时钟注入) Time source (fake-clock injection)
     * @param int $streamMaxLen Stream 近似封顶（XADD MAXLEN ~N;exporter 落后时的内存保险丝,正常由 exporter XTRIM 收敛）
     *   Approximate Stream MAXLEN cap on XADD (the memory fuse when the exporter lags; normally kept tight by the
     *   exporter's XTRIM)
     */
    public function __construct(
        private readonly \Redis|\Closure $redis,
        private readonly RedisInventoryStore $bags,
        private readonly string $streamKey = self::DEFAULT_STREAM_KEY,
        private ?TimerInterface $timer = null,
        ?callable $clock = null,
        private readonly int $streamMaxLen = 100000,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->lastFallbackAt = ($this->clock)();

        if ($this->timer !== null) {
            $this->timer->add(self::FLUSH_INTERVAL_SECONDS, $this->periodicFlush(...), true);
        }
    }

    /**
     * fork 后绑定定时器（与 ArchivePipeline::bindTimer 同规则,幂等）：组装层在 onWorkerStart 调用,
     * 同时启用 scheduleFlushId 的 0.2s 合并窗并注册 30s 周期兜底。
     * Binds the post-fork timer (the ArchivePipeline::bindTimer rule, idempotent): called by the assembly inside
     * onWorkerStart, it arms both the scheduleFlushId 0.2s coalescing window and the 30s periodic fallback.
     */
    public function bindTimer(?TimerInterface $timer): void
    {
        if ($timer === null || $this->timer !== null) {
            return;
        }

        $this->timer = $timer;
        $this->timer->add(self::FLUSH_INTERVAL_SECONDS, $this->periodicFlush(...), true);
    }

    /** {@inheritDoc} 标脏零 I/O:覆盖内存快照并清零失败计数。 Zero-I/O mark-dirty: overwrites the in-memory snapshot and resets the failure counter. */
    public function markDirty(string $id, array $data): void
    {
        $this->pending[$id] = $data;
        $this->attempts[$id] = 0;
    }

    /** {@inheritDoc} 强制同步点:立即冲刷单条（未标脏空操作;失败留脏计数）。 Forced sync point: flushes one record at once (no-op when never dirty; failures stay dirty and counted). */
    public function flushId(string $id): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }

        $this->doFlush([(string) $id => $this->pending[$id]]);
    }

    /** {@inheritDoc} 合并冲刷:登记紧急队列,0.2s 窗并批;无定时器回落 flushId。 Coalesced flush: enqueues and the 0.2s window batches; without a timer it falls back to flushId. */
    public function scheduleFlushId(string $id): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }

        if ($this->timer === null) {
            $this->flushId($id);

            return;
        }

        $this->urgentQueue[$id] = true;
        if (!$this->urgentArmed) {
            $this->urgentArmed = true;
            $this->timer->add(self::URGENT_COALESCE_SECONDS, $this->flushUrgent(...), false);
        }
    }

    /** {@inheritDoc} attach 恢复读:读 Redis 背包 hash,组装 archive 同形记录（['inventory'=>items]）;无键 null。 The attach-restore read: loads the Redis bag hash into the archive-shaped record (['inventory'=>items]); null when keyless. */
    public function load(string $id): ?array
    {
        // uid 形态校验交由 bags（assertUid）,异常按「读失败」口径返回 null
        // uid validation rides on bags (assertUid); exceptions surface as the read-failure stance: null
        try {
            $items = $this->bags->load($id);
        } catch (\Throwable) {
            return null;
        }

        if ($items === []) {
            return null;
        }

        return ['inventory' => $items];
    }

    /** {@inheritDoc} 立即批量冲刷全部脏记录（onStop 收尾级强制点;不推进 lastFallbackAt）。 Batch-flushes every dirty record at once (an onStop-grade sync point; does not advance lastFallbackAt). */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $this->doFlush($this->snapshotPending());
    }

    /** {@inheritDoc} 30s 时钟门控兜底。 The 30s clock-gated fallback. */
    public function periodicFlush(): void
    {
        $now = ($this->clock)();
        if ($now - $this->lastFallbackAt < self::FLUSH_INTERVAL_SECONDS) {
            return;
        }

        $this->lastFallbackAt = $now;
        $this->doFlush($this->snapshotPending());
    }

    /**
     * 合并窗到期回调（与 ArchivePipeline::flushUrgent 同规则:强制同步点不推进兜底门控）。
     * The coalescing-window callback (the ArchivePipeline::flushUrgent rule: the forced sync point never advances
     * the fallback gate).
     */
    public function flushUrgent(): void
    {
        $this->urgentArmed = false;
        $queue = $this->urgentQueue;
        $this->urgentQueue = [];
        if ($queue === []) {
            return;
        }

        $records = [];
        foreach (array_keys($queue) as $id) {
            if (isset($this->pending[$id])) {
                $records[(string) $id] = $this->pending[$id];
            }
        }
        if ($records !== []) {
            $this->doFlush($records);
        }
    }

    /** 待冲刷记录数（观测/测试用）。 Count of pending records (an observation/test seam). */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * 实际冲刷:一次 pipeline 完成全部 uid 的 背包快照写 + Stream 发布（1 往返）。
     * 背包键先 DEL 再 HMSET（清残留物品;整表覆盖语义）。发布成功出脏;
     * 整批异常全留脏计一次尝试,达 MAX_PUBLISH_ATTEMPTS 记日志放弃（裁决 6:尽力而为、丢失可解释）。
     * The real flush: ONE pipeline performs every uid's bag-snapshot write + Stream publish (1 round-trip).
     * Each bag key is DEL'd then HMSET (whole-table overwrite — stale items never survive). Successes clear
     * dirty; a batch-wide failure keeps everything dirty for one counted attempt, and at MAX_PUBLISH_ATTEMPTS a
     * record is logged and dropped (ruling 6: best effort with explainable loss).
     *
     * @param array<string, array<string, mixed>> $records uid => 快照（键已 (string) 规整）
     */
    private function doFlush(array $records): void
    {
        if ($records === []) {
            return;
        }

        try {
            $redis = ($this->redis instanceof \Redis) ? $this->redis : ($this->redis)();
            $pipeline = $redis->multi(\Redis::PIPELINE);
            $ids = [];
            foreach ($records as $uid => $data) {
                $uid = (string) $uid;
                $ids[] = $uid;

                // 背包快照回写:整表覆盖（DEL + HMSET 同窗原子;空快照即清键）
                // The bag snapshot write-back: whole-table overwrite (DEL + HMSET in the same window; an empty
                // snapshot clears the key)
                $bagKey = $this->bags->keyFor($uid);
                $pipeline->del($bagKey);
                $items = $data['inventory'] ?? null;
                if (is_array($items) && $items !== []) {
                    $fields = [];
                    foreach ($items as $itemId => $count) {
                        $c = (int) $count;
                        if ($c > 0) {
                            $fields[(string) $itemId] = (string) $c;
                        }
                    }
                    if ($fields !== []) {
                        $pipeline->hMSet($bagKey, $fields);
                    }
                }

                // 导出发布:Stream 条目携带完整快照与版本（exporter 消费落 MySQL）
                // The export publish: the Stream entry carries the full snapshot plus the version (the exporter
                // consumes and persists to MySQL)
                $pipeline->xAdd($this->streamKey, '*', [
                    'id' => $uid,
                    'data' => (string) json_encode($data, JSON_THROW_ON_ERROR),
                    'version' => (string) $this->nextVersion(),
                ]);
            }
            // 近似封顶（内存保险丝）:exporter 落后时 Stream 不会无界增长;正常水位由 exporter XTRIM 收敛
            // Approximate cap (the memory fuse): the Stream cannot grow unbounded while the exporter lags; the
            // normal watermark is kept tight by the exporter's own XTRIM
            if ($this->streamMaxLen > 0) {
                $pipeline->xTrim($this->streamKey, (string) $this->streamMaxLen, true);
            }
            $pipeline->exec();

            foreach ($ids as $uid) {
                unset($this->pending[$uid], $this->attempts[$uid]);
            }
        } catch (\Throwable $e) {
            // 整批失败:全部留脏计一次尝试(与 ArchivePipeline「失败 id 逐个计数」不同粒度——本实现批量发布
            // 原子同窗,失败即全体,不做部分裁决)
            // Whole-batch failure: every record stays dirty with one counted attempt (coarser than
            // ArchivePipeline's per-id tallying — this batch publishes in one atomic window, so a failure means
            // all of them; no partial adjudication)
            error_log(sprintf('[RedisExportPipeline] flush failed (%d records): %s', count($records), $e->getMessage()));
            foreach ($records as $uid => $data) {
                $this->registerFailure((string) $uid);
            }
        }
    }

    /** 记录一次发布失败:计数 +1,达上限记日志放弃该记录(裁决 6)。 Records one publish failure: +1 attempt, logged give-up at the cap (ruling 6). */
    private function registerFailure(string $id): void
    {
        $attempts = ($this->attempts[$id] ?? 0) + 1;
        if ($attempts >= self::MAX_PUBLISH_ATTEMPTS) {
            unset($this->pending[$id], $this->attempts[$id]);
            error_log(sprintf(
                '[RedisExportPipeline] 导出放弃: id=%s attempts=%d(超上限,丢失已记录,裁决 6;Redis 权威仍在,bag 快照可能滞后)',
                $id,
                $attempts,
            ));

            return;
        }

        $this->attempts[$id] = $attempts;
    }

    /**
     * 脏表快照:数字 uid 的 int 数组键 (string) 规整(与 ArchivePipeline::flush 同一 PHP 数组键转型坑位,
     * strict_types 下 Stream/背包消费要求 string)。
     * The dirty-table snapshot: numeric-uid int keys are normalized back to (string) (the same PHP array-key cast
     * trap ArchivePipeline::flush handles — Stream/bag consumers require strings under strict_types).
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshotPending(): array
    {
        $records = [];
        foreach ($this->pending as $id => $data) {
            $records[(string) $id] = $data;
        }

        return $records;
    }

    /** 单调版本:毫秒时钟与「上一值 +1」取大(同毫秒内保序)。 Monotonic version: max(ms clock, previous + 1) so same-millisecond records keep order. */
    private function nextVersion(): int
    {
        $ms = (int) ((($this->clock)()) * 1000);
        $this->versionSeq = max($ms, $this->versionSeq + 1);

        return $this->versionSeq;
    }
}
