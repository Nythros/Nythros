<?php

declare(strict_types=1);

namespace Nythros\Framework\Persistence;

use Nythros\Contracts\TimerInterface;
use Nythros\Persistence\StorageInterface;

/**
 * 归档管线（组装层通用件）：业务状态异步归档——标脏 → 断连/登出立即 flush → 30s 定时兜底批量 saveBatch（ADR-013 10.5，裁决 4/6）。
 * Archive pipeline (an assembly-layer component): async archiving of business state — mark dirty → immediate flush on disconnect/logout → 30s periodic fallback batch saveBatch (ADR-013 10.5, rulings 4/6).
 *
 * 语义要点：
 * Semantic points:
 * - 标脏零 I/O：markDirty 仅登记内存脏记录（同 id 覆盖写并清零失败计数），不触碰存储——归档绝不阻塞帧预算（裁决 4）；
 * - Mark dirty without I/O: markDirty only registers an in-memory dirty record (same id overwrites, failure counter reset) and never touches storage — archiving never blocks the frame budget (ruling 4);
 * - 断连/登出立即 flush：flushId 是强制同步点，立即 save 该记录，不受 30s 门控影响；失败则留脏待兜底重试；
 *   生产断连路径推荐 scheduleFlushId——登记紧急队列，0.2s 合并窗内到齐的脏记录并成一次 saveBatch，
 *   断连处理器零往返（掉线风暴不放大），丢失窗口仍由 30s 兜底界定（裁决 4 不变）；
 * - Immediate flush on disconnect/logout: flushId is a forced sync point that saves the record at once, unaffected
 *   by the 30s gate; on failure the record stays dirty for fallback retry. The production disconnect path prefers
 *   scheduleFlushId — it enqueues into the urgent queue and a 0.2s window coalesces every arrived record into one
 *   saveBatch, giving the disconnect handler zero round-trips (a disconnect storm never amplifies), while the loss
 *   window stays bounded by the 30s fallback (ruling 4 unchanged);
 * - 30s 定时兜底：构造时注册持久定时器，回调 periodicFlush 经时钟门控「距上次兜底 ≥30s 才批量 saveBatch
 *   全部残留脏记录」——登出同步点之外有界丢失窗口的兜底（裁决 4）；门控使单测可用假时钟精确驱动；
 * - 30s periodic fallback: a persistent timer registered at construction; its periodicFlush callback is
 *   clock-gated ("batch saveBatch runs only when ≥30s elapsed since the last fallback") over every
 *   remaining dirty record — the backstop for the bounded loss window beyond the logout sync point
 *   (ruling 4); the gate lets tests drive the timing precisely with a fake clock;
 * - 失败重试超限记日志放弃：每次保存失败计一次尝试（flushId 的 save 失败、批量 saveBatch 的失败 id
 *   同口径），未达上限留脏等待下一次兜底重试；达 MAX_SAVE_ATTEMPTS 后 error_log 记日志并放弃该记录
 *   （裁决 6：尽力而为 + 丢失可解释，不引入重发队列）；markDirty 到来时计数清零（新数据重新计）；
 * - Retry-then-give-up with logging: each failed save counts one attempt (a false from flushId's save
 *   and a failed id from the batch saveBatch count alike); under the cap the record stays dirty for
 *   the next fallback retry; at MAX_SAVE_ATTEMPTS the record is dropped with an error_log entry
 *   (ruling 6: best effort with explainable loss, no resend queue); a fresh markDirty resets the
 *   counter (new data starts over);
 * - lastFallbackAt 只由 periodicFlush 路径推进：显式 flush() 是强制同步点，不推迟后续兜底——断连冲刷
 *   不会顺延其他残留记录的 30s 兜底窗口；
 * - lastFallbackAt is advanced only by the periodicFlush path: an explicit flush() is a forced sync
 *   point and never postpones the fallback — a disconnect flush does not extend the 30s fallback
 *   window of other pending records;
 * - 定时器进程上下文：应在 worker 进程内构造（fork 后，与 TeamServer 邀请清理 Timer 同一口径）；
 *   timer 为 null = 不启动兜底（单测/纯消息模式，由调用方按需显式 flush）。
 * - Timer process context: construct inside a worker (after fork, same convention as TeamServer's
 *   invite-cleanup timer); a null timer = no fallback (unit-test / message-only mode; callers flush explicitly as needed).
 *
 * @see PersistPipelineInterface 契约全量口径（本类为 MySQL 直写实现;Redis 权威口径见 RedisExportPipeline）
 */
final class ArchivePipeline implements PersistPipelineInterface
{
    /** 兜底冲刷间隔（秒）：定时器回调间隔与时钟门控共用（ADR 10.5 的 30s 兜底）。Fallback flush interval in seconds: shared by the timer callback interval and the clock gate (the ADR 10.5 30s fallback). */
    public const FLUSH_INTERVAL_SECONDS = 30.0;

    /**
     * 紧急冲刷合并窗（秒）：断连/登出的 scheduleFlushId 不再逐条同步 save，而是登记进紧急队列，
     * 窗口内到齐的脏记录合并为一次 saveBatch——掉线风暴（网络抖动批量断连）时把 N 条串行 MySQL
     * 往返压成 1 条，每断连处理路径零往返（主循环 IO 剥离）。无定时器（单测/纯消息模式）时
     * scheduleFlushId 回落 flushId 同步点，语义与旧口径一致。
     * The urgent-flush coalescing window in seconds: scheduleFlushId (disconnect/logout) no longer saves one record
     * synchronously; it enqueues the id, and every dirty record that arrives within the window is merged into a
     * single saveBatch — collapsing an N-round-trip serial MySQL storm (mass disconnects from a network blip) into
     * one batch and keeping the per-disconnect handler at zero round-trips (the hot-path IO strip). Without a timer
     * (unit-test / message-only mode) scheduleFlushId falls back to the synchronous flushId, preserving the legacy
     * semantics.
     */
    public const URGENT_COALESCE_SECONDS = 0.2;

    /** 单条记录最大保存尝试次数：达到后记日志放弃（裁决 6 丢失可解释）。Maximum save attempts per record: at the cap the record is logged and dropped (ruling 6, explainable loss). */
    public const MAX_SAVE_ATTEMPTS = 3;

    /** @var array<string, true> 紧急队列：待合并冲刷的 id（flushUrgent 消费） Urgent queue: ids waiting to be coalesced (consumed by flushUrgent). */
    private array $urgentQueue = [];

    /** 紧急定时器是否已挂（一次性，flushUrgent 复位） Whether the one-shot urgent timer is armed (reset by flushUrgent). */
    private bool $urgentArmed = false;

    /** @var array<string, array<string, mixed>> 脏记录表：id => 最新数据（markDirty 覆盖写） Dirty record table: id => latest data (markDirty overwrites). */
    private array $dirty = [];

    /** @var array<string, int> 失败计数表：id => 已失败次数（markDirty 清零；达 MAX_SAVE_ATTEMPTS 放弃） Failure counter table: id => failed attempts so far (reset by markDirty; dropped at MAX_SAVE_ATTEMPTS). */
    private array $attempts = [];

    /** 上次兜底冲刷时间（秒） Last fallback flush time in seconds. */
    private float $lastFallbackAt;

    /** @var callable(): float 时间源（单测注入假时钟驱动 30s 门控） Time source (tests inject a fake clock to drive the 30s gate). */
    private $clock;

    /**
     * 组装归档管线。
     * Wires the archive pipeline.
     *
     * @param StorageInterface $storage 目标存储（InMemoryStorage 或可裁剪的 MySqlStorage） Target storage (InMemoryStorage or the trimmable MySqlStorage)
     * @param string $collection 归档集合名（如玩家状态集合） Archive collection name (e.g. the player-state collection)
     * @param ?TimerInterface $timer 定时器；缺省 null = 不启动 30s 兜底（单测/纯消息模式） Timer; default null = no 30s fallback (unit-test/message-only mode)
     * @param ?callable(): float $clock 时间源；缺省 null = microtime(true)（单测注入假时钟驱动 30s 门控） Time source; default null = microtime(true) (tests inject a fake clock to drive the 30s gate)
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $collection,
        private ?TimerInterface $timer = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->lastFallbackAt = ($this->clock)();

        if ($this->timer !== null) {
            $this->timer->add(self::FLUSH_INTERVAL_SECONDS, $this->periodicFlush(...), true);
        }
    }

    /**
     * fork 后绑定定时器（组装层 onWorkerStart 内调用）：把构造期缺省的 null timer 换成进程内真实定时器,
     * 同时注册 30s 周期兜底——本方法存在的前提：Workerman 定时器不能 fork 前挂（子进程重复继承注册、
     * 主循环误触发）,而 scheduleFlushId 的紧急合并窗又必须有 timer 才能 arm。
     * 幂等：已绑定时再次调用直接返回；timer 为 null 时不 arm（纯消息/单测模式保持 flushId 同步点）。
     * Binds the post-fork timer (called by the assembly inside onWorkerStart): swaps the constructor-null timer for
     * the real in-process timer and registers the 30s fallback. This exists because Workerman timers cannot be armed
     * pre-fork (children re-inherit the registration and the master loop misfires), yet scheduleFlushId's urgent
     * window needs a timer to arm. Idempotent: repeat calls after a bind are no-ops; a null timer leaves the
     * flushId sync-point behavior (message-only / unit-test mode).
     */
    public function bindTimer(?TimerInterface $timer): void
    {
        if ($timer === null || $this->timer !== null) {
            return;
        }

        $this->timer = $timer;
        $this->timer->add(self::FLUSH_INTERVAL_SECONDS, $this->periodicFlush(...), true);
    }

    /**
     * 标脏：登记最新状态（同 id 覆盖写）并清零失败计数；零 I/O，不阻塞帧预算（裁决 4）。
     * Marks dirty: registers the latest state (same id overwrites) and resets the failure counter; zero I/O, never blocks the frame budget (ruling 4).
     *
     * @param string $id 记录标识（如玩家 uid） Record identifier (e.g. player uid).
     * @param array<string, mixed> $data 最新状态 Latest state.
     */
    public function markDirty(string $id, array $data): void
    {
        $this->dirty[$id] = $data;
        $this->attempts[$id] = 0;
    }

    /**
     * 断连/登出立即冲刷：立即 save 该记录（强制同步点，不受 30s 门控影响）；save 失败时计一次
     * 尝试并留脏等待下一次兜底重试（达上限记日志放弃）；未标脏时为空操作。
     * Immediate flush on disconnect/logout: saves the record at once (a forced sync point unaffected
     * by the 30s gate); a save failure counts one attempt and keeps the record dirty for the next
     * fallback retry (dropped with a log entry at the cap); a no-op for records that were never dirty.
     *
     * @param string $id 记录标识 Record identifier.
     */
    public function flushId(string $id): void
    {
        if (!isset($this->dirty[$id])) {
            return;
        }

        if ($this->storage->save($this->collection, $id, $this->dirty[$id])) {
            unset($this->dirty[$id], $this->attempts[$id]);

            return;
        }

        $this->registerFailure($id);
    }

    /**
     * 断连/登出的合并冲刷入口（主循环 IO 剥离）：把 id 登记进紧急队列并挂一次性合并窗定时器，
     * 到窗时 flushUrgent 把窗口内全部脏记录并成一次 saveBatch——断连处理器自身零往返。
     * 未标脏空操作；无定时器装配（单测/纯消息模式）回落 flushId 同步点（旧语义保持）。
     * The coalesced disconnect/logout flush entry (the hot-path IO strip): enqueues the id and arms a one-shot
     * coalescing timer; at the window flushUrgent merges every queued dirty record into one saveBatch, so the
     * disconnect handler itself does zero round-trips. A no-op for records that were never dirty; without a timer
     * (unit-test / message-only mode) it falls back to the synchronous flushId (legacy semantics preserved).
     *
     * @param string $id 记录标识 Record identifier.
     */
    public function scheduleFlushId(string $id): void
    {
        if (!isset($this->dirty[$id])) {
            return;
        }

        if ($this->timer === null) {
            // 无定时器 = 无合并窗可依赖，保持强制同步点语义（单测/纯消息模式，裁决 4 的断连同步点）
            // Without a timer there is no coalescing window to rely on, so the forced sync point stays (unit-test /
            // message-only mode, the disconnect sync point of ruling 4)
            $this->flushId($id);

            return;
        }

        $this->urgentQueue[$id] = true;
        if (!$this->urgentArmed) {
            $this->urgentArmed = true;
            $this->timer->add(self::URGENT_COALESCE_SECONDS, $this->flushUrgent(...), false);
        }
    }

    /**
     * 合并窗到期回调：清空队列并把其中仍脏的记录一次 saveBatch（强制同步点口径：不推进 lastFallbackAt，
     * 与显式 flush 同规则）；失败 id 计一次尝试留脏交兜底（达上限记日志放弃，裁决 6）。
     * 冲刷期间新的 scheduleFlushId 会重新挂窗（urgentArmed 先复位后入队），不丢登记。
     * The coalescing-window callback: drains the queue and batch-saves the still-dirty records in one saveBatch
     * (the forced-sync-point rule: it does not advance lastFallbackAt, same as an explicit flush); each failed id
     * counts one attempt and stays dirty for the fallback (logged and dropped at the cap, ruling 6). A
     * scheduleFlushId arriving mid-flush re-arms the window (urgentArmed resets before the batch runs), never
     * dropping a registration.
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
            if (isset($this->dirty[$id])) {
                $records[(string) $id] = $this->dirty[$id];
            }
        }
        if ($records === []) {
            return;
        }

        $failed = $this->storage->saveBatch($this->collection, $records);
        $failedKeys = [];
        foreach ($failed as $failedId) {
            $failedKeys[(string) $failedId] = true;
        }
        foreach (array_keys($records) as $id) {
            if (isset($failedKeys[(string) $id])) {
                $this->registerFailure((string) $id);

                continue;
            }

            unset($this->dirty[$id], $this->attempts[$id]);
        }
    }

    /**
     * 读路径（P18 工程债收尾：关闭「归档只写」的半闭环）：按 id 读取最近一次归档的记录——
     * 消费方为登录/迁移 attach 的状态恢复（转移票据缺席时的兜底读，blueprint/30）。
     * 读失败（存储异常/无记录）返回 null——恢复语义是「尽力而为」，失败回落全新入场。
     * The read path (the P18 engineering-debt close-out, closing the write-only archive's half-open loop):
     * loads the most recently archived record by id — consumed by the login/migration attach's state restore
     * (the fallback read when a transfer ticket is absent, blueprint/30). Read failures (storage exceptions /
     * missing record) return null — the restore semantics are best-effort, and a failure degrades to a fresh entry.
     *
     * @param string $id 记录标识（如玩家 uid） Record identifier (e.g. player uid).
     * @return array<string, mixed>|null 归档记录，无记录/读失败 null The archived record, or null on miss/read failure.
     */
    public function load(string $id): ?array
    {
        try {
            return $this->storage->load($this->collection, $id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 批量冲刷全部脏记录（saveBatch）：成功记录出脏；失败 id 计一次尝试，未达上限留待重试，
     * 达上限记日志放弃。显式调用 = 强制同步点，不推进 lastFallbackAt（不推迟后续 30s 兜底）。
     * Batch-flushes every dirty record (saveBatch): successful records leave the dirty table; each
     * failed id counts one attempt — under the cap it stays dirty for retry, at the cap it is logged
     * and dropped. An explicit call is a forced sync point and does not advance lastFallbackAt (the next 30s fallback is never postponed).
     */
    public function flush(): void
    {
        if ($this->dirty === []) {
            return;
        }

        // 数字 uid（如 '1001'）会被 PHP 数组键整数化为 int 键：批量交存储前把键名 (string) 规整，
        // 遍历消费时同样 (string) 转换——否则 strict_types 下 StorageInterface::save(string $id) 与
        // registerFailure(string $id) 收到 int 直接 TypeError（真实启用 30s 兜底 + 数字 uid 时落库必失败）
        // Numeric uids (e.g. '1001') become int keys under PHP array semantics: normalize the keys back
        // to (string) before handing them to the storage and again while consuming them — otherwise
        // StorageInterface::save(string $id) and registerFailure(string $id) receive an int under
        // strict_types and throw TypeError (a guaranteed failure of the real 30s fallback with numeric uids)
        $records = [];
        foreach ($this->dirty as $id => $data) {
            $records[(string) $id] = $data;
        }

        $failed = $this->storage->saveBatch($this->collection, $records);
        $failedSet = array_flip($failed);

        // 遍历冲刷时的规整键快照：失败 id 计一次尝试（未达上限留待重试），成功出脏；
        // 存储返回的不在脏表中的失败 id 天然不会命中（只消费脏表自身的键）
        // Iterate over the normalized key snapshot taken at flush time: each failed id counts one
        // attempt (stays dirty for retry under the cap), successful ids leave the dirty table; failed
        // ids the storage returns that are absent from the dirty table never match (only the dirty
        // table's own keys are consumed)
        foreach (array_keys($records) as $id) {
            if (isset($failedSet[$id])) {
                $this->registerFailure((string) $id);

                continue;
            }

            unset($this->dirty[$id], $this->attempts[$id]);
        }
    }

    /**
     * 定时兜底回调（30s 持久定时器）：时钟门控——距上次兜底冲刷不足 30s 直接返回；否则推进
     * lastFallbackAt 并批量冲刷全部残留脏记录。
     * Periodic fallback callback (30s persistent timer): clock-gated — returns early when less than
     * 30s elapsed since the last fallback flush; otherwise advances lastFallbackAt and batch-flushes every remaining dirty record.
     */
    public function periodicFlush(): void
    {
        $now = ($this->clock)();
        if ($now - $this->lastFallbackAt < self::FLUSH_INTERVAL_SECONDS) {
            return;
        }

        $this->lastFallbackAt = $now;
        $this->flush();
    }

    /**
     * 记录一次保存失败：失败计数 +1；达 MAX_SAVE_ATTEMPTS 记日志并放弃该记录（裁决 6：丢失可解释）。
     * Records one save failure: increments the counter; at MAX_SAVE_ATTEMPTS logs and drops the record (ruling 6: explainable loss).
     */
    private function registerFailure(string $id): void
    {
        $attempts = ($this->attempts[$id] ?? 0) + 1;
        if ($attempts >= self::MAX_SAVE_ATTEMPTS) {
            unset($this->dirty[$id], $this->attempts[$id]);
            error_log(sprintf(
                '[ArchivePipeline] 归档放弃: collection=%s id=%s attempts=%d（超上限，丢失已记录，裁决 6）',
                $this->collection,
                $id,
                $attempts,
            ));

            return;
        }

        $this->attempts[$id] = $attempts;
    }
}
