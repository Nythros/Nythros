<?php

declare(strict_types=1);

namespace Nythros\Scheduler;

/**
 * 时间轮：把到期回调按 deadline 落入固定数量的槽位，advance 时从上次指针逐槽推进到当前时刻，yield 到期回调。
 * Timer wheel: places deadline callbacks into a fixed number of slots; advance sweeps slot by slot from the previous pointer to the current time and yields due callbacks.
 *
 * 单位约定：tickMs 为毫秒，deadline 与 advance 的 now 均为秒（Unix 风格时间戳）；内部计算统一换算为毫秒。
 * Unit convention: tickMs is milliseconds, while deadline and advance's now are seconds (Unix-style timestamps); internal math normalizes everything to milliseconds.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class TimerWheel
{
    /** @var array<int, list<array{id: int, deadline: float, callback: callable}>> 槽位：槽索引映射到条目列表（deadline 单位毫秒） Slots: slot index mapped to a list of entries (deadline in milliseconds). */
    private array $slots = [];

    /** @var int 自增任务 id 计数器，从 1 开始 Monotonic task id counter starting at 1. */
    private int $nextId = 1;

    /** @var array<int, true> 惰性取消集：被 cancel 的条目 id，advance 扫到时跳过执行 Lazy cancellation set: ids cancelled via cancel(), skipped when advance sweeps them. */
    private array $cancelled = [];

    /** @var float|null 上次推进到的时间指针（秒），null 表示尚未推进过 The time pointer advanced to last time (seconds); null means never advanced. */
    private ?float $lastNow = null;

    /** @var callable(): float 时钟闭包（备用：提供当前秒级时间；advance 由调用方显式传入 now） Clock closure (reserved: supplies the current time in seconds; advance receives now explicitly from the caller). */
    private $clock;

    /**
     * 构造时间轮。
     * Creates a timer wheel.
     *
     * @param int $tickMs 单槽时长（毫秒） Duration of one slot (milliseconds).
     * @param int $wheelSize 槽位数 Slot count.
     * @param callable|null $clock 时钟闭包，缺省返回微秒时间戳换算的秒 Clock closure, defaults to seconds converted from the microsecond timestamp.
     */
    public function __construct(
        private readonly int $tickMs,
        private readonly int $wheelSize,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * 排定一个到期回调，返回任务 id（用于 cancel）。
     * Schedules a callback and returns its task id (for cancel).
     *
     * clamp 语义：deadline 距当前指针超过一个轮转 span（tickMs × wheelSize）时，无法用时间轮精确表达，会被压到当前指针的前一槽，
     * 使任务在下一圈扫到该槽时尽快执行，而不是无限等待。
     * Clamp semantics: when the deadline is farther than one wheel span (tickMs × wheelSize) from the current pointer, it cannot be represented exactly on the wheel; it is clamped into the slot just before the current pointer, so the task fires as soon as the next revolution sweeps that slot instead of waiting forever.
     *
     * @param float $deadline 到期时刻（秒，Unix 风格时间戳） Deadline (seconds, Unix-style timestamp).
     * @param callable $callback 到期回调 The callback to fire when due.
     * @return int 任务 id Task id.
     */
    public function schedule(float $deadline, callable $callback): int
    {
        $id = $this->nextId++;

        // 统一换算为毫秒做槽位计算 convert to milliseconds for all slot math
        $tickMs = (float) $this->tickMs;
        $deadlineMs = $deadline * 1000.0;

        // clamp：超出当前指针一个轮转 span 的 deadline 压到指针前一槽，等待下一圈执行 clamp: a deadline beyond one wheel span from the pointer is pulled back to the slot before the pointer and fires on the next revolution
        if ($this->lastNow !== null && $deadlineMs >= $this->lastNow * 1000.0 + $tickMs * $this->wheelSize) {
            $pointerTick = (int) floor($this->lastNow * 1000.0 / $tickMs);
            $deadlineMs = (float) (($pointerTick - 1) * $tickMs);
        }

        $slot = $this->normalizeSlot((int) floor($deadlineMs / $tickMs));
        $this->slots[$slot][] = ['id' => $id, 'deadline' => $deadlineMs, 'callback' => $callback];

        return $id;
    }

    /**
     * 惰性取消任务：标记 cancelled，advance 扫到时跳过执行并从槽移除；取消不存在的 id 无副作用。
     * Lazy-cancels a task: marks it cancelled, so advance skips and drops it when swept; cancelling an unknown id has no effect.
     *
     * @param int $id 任务 id Task id returned by schedule().
     */
    public function cancel(int $id): void
    {
        $this->cancelled[$id] = true;
    }

    /**
     * 获取时钟当前时间（秒）。
     * Returns the clock's current time (seconds).
     */
    public function now(): float
    {
        return (float) ($this->clock)();
    }

    /**
     * 从上次指针逐槽推进到 $now 对应槽，yield 全部到期回调。
     * Advances slot by slot from the previous pointer to the slot containing $now, yielding every due callback.
     *
     * 语义细节：首次调用仅扫描 $now 所在槽并建立指针（避免首帧全量扫描历史槽）；未到期的条目留在槽内等下圈；已取消的条目跳过并移除；时间未前进（含回退）时不做任何事。
     * 每次推进从 lastNow 所在 tick 重扫起（schedule 于当前 tick 内的 deadline 在本次/下一次 advance 即到期，不再延迟一圈）；若本轮 yield 过回调，则对整段重扫一遍，使回调中 schedule 的立即到期任务在同一次 advance 到期。
     * Semantics: the first call sweeps only the slot containing $now and establishes the pointer (avoiding a full sweep of historical slots on the first frame); not-yet-due entries stay in their slot for a later revolution; cancelled entries are skipped and dropped; when time has not moved forward (including regression) nothing happens.
     * Every advance re-sweeps from the tick containing lastNow (deadlines scheduled inside the current tick fire on this or the next advance instead of waiting a whole revolution); when callbacks were yielded this round, the whole span is re-swept so immediately-due tasks scheduled inside callbacks fire within the same advance.
     *
     * @param float $now 当前时刻（秒） Current time (seconds).
     * @return iterable<callable> 到期回调序列（惰性 yield） Due callbacks, lazily yielded.
     */
    public function advance(float $now): iterable
    {
        $tickMs = (float) $this->tickMs;
        $nowMs = $now * 1000.0;

        // 首次推进：只扫 now 所在槽建立指针，不追溯历史 first advance: sweep only the slot containing now to establish the pointer, without walking through history
        if ($this->lastNow === null) {
            $startTick = (int) floor($nowMs / $tickMs);
            foreach ($this->sweepSlot($this->normalizeSlot($startTick), $startTick, $tickMs) as $callback) {
                yield $callback;
            }
            $this->lastNow = $now;

            return;
        }

        $lastMs = $this->lastNow * 1000.0;

        // 时间未前进（含回退）：忽略本次推进 time has not advanced (or regressed): ignore this call
        if ($nowMs <= $lastMs) {
            return;
        }

        $startTick = (int) floor($lastMs / $tickMs);
        $endTick = (int) floor($nowMs / $tickMs);

        // 第一轮：从 lastNow 所在 tick 重扫到 now 所在 tick（含首尾）——schedule 于当前 tick 内的 deadline（含 == lastNow）由此在下一次 advance 立即到期 first sweep: re-sweep from the tick containing lastNow through the tick containing now (inclusive) — deadlines scheduled inside the current tick (including == lastNow) thus fire on the very next advance
        $yielded = false;
        for ($tick = $startTick; $tick <= $endTick; $tick++) {
            foreach ($this->sweepSlot($this->normalizeSlot($tick), $tick, $tickMs) as $callback) {
                $yielded = true;
                yield $callback;
            }
        }

        // 第二轮重扫：回调执行期间新 schedule 的任务可能落入本轮已扫过的 tick，重扫使其在同一次 advance 到期（到期条目已被第一轮移除，不会重复执行；第二轮回调再入队的新任务留待下一轮 advance） second sweep: callbacks may schedule new tasks into ticks already swept this round; re-sweeping fires them within the same advance (due entries were removed in the first sweep, so nothing runs twice; tasks enqueued by second-sweep callbacks wait for the next advance)
        if ($yielded) {
            for ($tick = $startTick; $tick <= $endTick; $tick++) {
                foreach ($this->sweepSlot($this->normalizeSlot($tick), $tick, $tickMs) as $callback) {
                    yield $callback;
                }
            }
        }

        $this->lastNow = $now;
    }

    /**
     * 扫描单个槽：对槽内条目做快照，收集到期（deadline <= 本槽 tick 结束时刻）回调、未到期的留槽、已取消的移除，由 advance 逐个 yield。
     * Sweeps one slot: snapshots its entries, collects due callbacks (deadline <= this tick's end), keeps not-yet-due ones in place, and drops cancelled ones; advance yields the collected callbacks one by one.
     *
     * @param int $slot 槽索引 Slot index.
     * @param int $tick 当前 tick 序号（未归一化） Current tick number (un-normalized).
     * @param float $tickMs 单槽时长（毫秒） Slot duration in milliseconds.
     * @return list<callable> 到期回调列表（按槽内提交顺序） Due callbacks in slot submission order.
     */
    private function sweepSlot(int $slot, int $tick, float $tickMs): array
    {
        $entries = $this->slots[$slot] ?? [];
        if ($entries === []) {
            return [];
        }

        $tickEndMs = ($tick + 1) * $tickMs;
        $due = [];
        $remaining = [];

        foreach ($entries as $entry) {
            // 已取消：跳过执行并从槽移除，同时清掉惰性标记 cancelled: skip execution, drop from the slot, and clear the lazy marker
            if (isset($this->cancelled[$entry['id']])) {
                unset($this->cancelled[$entry['id']]);

                continue;
            }

            if ($entry['deadline'] <= $tickEndMs) {
                // 到期：收集后交给 advance yield due: collected here and yielded by advance
                $due[] = $entry['callback'];
            } else {
                // 未到期：留在槽内等下圈扫到 not yet due: keep it in the slot for a later revolution
                $remaining[] = $entry;
            }
        }

        if ($remaining === []) {
            unset($this->slots[$slot]);
        } else {
            $this->slots[$slot] = $remaining;
        }

        return $due;
    }

    /**
     * 把 tick 序号归一化为 [0, wheelSize) 内的槽索引（PHP 的 % 对负数结果非负，需手动归正）。
     * Normalizes a tick number into a slot index within [0, wheelSize) (PHP's % keeps the sign of the dividend, so negatives are shifted positive manually).
     */
    private function normalizeSlot(int $tick): int
    {
        $slot = $tick % $this->wheelSize;

        return $slot < 0 ? $slot + $this->wheelSize : $slot;
    }
}
