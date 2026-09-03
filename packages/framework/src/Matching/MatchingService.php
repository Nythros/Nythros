<?php

declare(strict_types=1);

namespace Nythros\Framework\Matching;

use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomManagerInterface;

/**
 * 匹配服务（R3 玩法批）：队列管理、撮合与开房编排。
 * Matching service (the R3 gameplay batch): queue management, matching and room-building orchestration.
 *
 * 分层铁律：本服务只依赖 RoomManagerInterface 契约（创建/查询房间）与 MatchJoinHandlerInterface 委托
 * （入房编排），不 import demo RoomHub、不感知引擎 AOI 实现——房间 AOI 工厂由组装层以闭包注入
 * （GridAOI 属 engine @internal，framework 不可见）。
 * Layering iron rule: this service depends only on the RoomManagerInterface contract (room creation/lookup) and the
 * MatchJoinHandlerInterface delegation (join orchestration); it never imports the demo RoomHub nor knows engine AOI
 * implementations — the room AOI factory is injected as a closure by the assembly layer (GridAOI is engine @internal,
 * invisible to the framework).
 *
 * 撮合语义（tick 定时任务路径，组装层周期调用）：
 * - 每队列按入队序（FIFO）取前 teamSize 个候选者凑满即开房；人未满保持等待（条件不满足等待口径）；
 * - 开房 = rooms->create(RoomConfig) 后逐候选者 joinHandler->joinRoom；编排失败者重新入队（保留原入队时刻，
 *   排回队首），其余成员留在新房——部分成功语义，避免一票失败拖垮整房；
 * - 毒票防护：单票连续编排失败达上限（MAX_CONSECUTIVE_JOIN_FAILURES）即移出队列并告警——部分成功语义下
 *   永久失败的票若每拍回队首再次配对，会持续消耗无辜票进部分空房直至队列耗尽；
 * - 房间 id 由服务内序号生成（match-{queueId}-{seq}），同进程唯一。
 * Matching semantics (the tick periodic-task path, invoked by the assembly layer's timer):
 * - per queue, the first teamSize candidates in FIFO order fill a room; an unfilled queue keeps waiting (the
 *   conditions-unmet waiting convention);
 * - building = rooms->create(RoomConfig) followed by joinHandler->joinRoom per candidate; a failed orchestration
 *   re-queues that candidate (original enqueue instant kept, back at the head) while the rest stay in the new room —
 *   partial-success semantics, so one bad ticket never sinks the whole room;
 * - poison-ticket guard: a ticket failing orchestration MAX_CONSECUTIVE_JOIN_FAILURES times in a row is evicted from
 *   its queue with an alert — under partial success a permanently failing ticket re-paired at the head every tick
 *   would keep burning innocent tickets into half-empty rooms until the queue drains;
 * - room ids come from an in-service sequence (match-{queueId}-{seq}), unique within the process.
 */
final class MatchingService
{
    /** 毒票移出阈值：单票连续编排失败次数上限（达到即认定实体失效，移出并告警）。 Poison eviction threshold: consecutive per-ticket orchestration failures before eviction (the entity is deemed dead). */
    private const MAX_CONSECUTIVE_JOIN_FAILURES = 3;

    /** @var array<string, MatchCriteria> queueId => 撮合条件 queueId => match criteria. */
    private array $criteria = [];

    /** @var array<string, list<MatchTicket>> queueId => FIFO 候选列表 queueId => FIFO candidate list. */
    private array $queues = [];

    /** @var array<string, MatchTicket> uid => 在队票（一人一票的唯一索引） uid => live ticket (the unique one-ticket-per-candidate index). */
    private array $ticketsByUid = [];

    /** @var array<string, int> uid => 连续编排失败次数（票离队时同步清除，防无界增长） uid => consecutive orchestration-failure count (cleared in sync as tickets leave, so it never grows unbounded). */
    private array $failureCounts = [];

    /** @var int 开房序号（保证 roomId 进程内唯一） Built-room sequence (keeps roomIds unique within the process). */
    private int $roomSequence = 0;

    /**
     * @param RoomManagerInterface $rooms 房间管理器契约（开房唯一入口） The room-manager contract (the sole room-building entry).
     * @param MatchJoinHandlerInterface $joinHandler 入房编排委托（组装层实现） The join-orchestration delegate (implemented by the assembly layer).
     * @param \Closure(): mixed $roomAoiFactory 房间 AOI 工厂闭包（组装层注入，透传 RoomConfig） The room-AOI factory closure (injected by the assembly layer, passed through to RoomConfig).
     */
    public function __construct(
        private readonly RoomManagerInterface $rooms,
        private readonly MatchJoinHandlerInterface $joinHandler,
        private readonly \Closure $roomAoiFactory,
    ) {
    }

    /**
     * 注册队列撮合条件；同 id 后注册覆盖先注册。
     * Registers a queue's criteria; a later registration with the same id overrides the earlier one.
     */
    public function registerCriteria(MatchCriteria $criteria): void
    {
        $this->criteria[$criteria->queueId] = $criteria;
    }

    /**
     * 入队：条件未注册 / 等级越界 / 已在任意队列 → false；成功登记排队票。
     * Enqueues: false on unregistered criteria / out-of-range level / already queued anywhere; on success a ticket is registered.
     */
    public function enqueue(string $queueId, string $uid, string $entityId, int $level, float $now): bool
    {
        $criteria = $this->criteria[$queueId] ?? null;
        if ($criteria === null || isset($this->ticketsByUid[$uid]) || !$criteria->admits($level)) {
            return false;
        }

        $ticket = new MatchTicket($uid, $entityId, $level, $queueId, $now);
        $this->queues[$queueId][] = $ticket;
        $this->ticketsByUid[$uid] = $ticket;

        return true;
    }

    /**
     * 取消排队：在队即摘票返回 true；不在队静默 false。
     * Cancels: removes and returns true when queued; silently false otherwise.
     */
    public function cancel(string $uid): bool
    {
        return $this->dequeue($this->ticketsByUid[$uid] ?? null) !== null;
    }

    /**
     * 撮合扫描（定时任务路径）：逐队列凑满即开房并编排入房。
     * Matching sweep (the periodic-task path): fills each queue into rooms and orchestrates the joins.
     *
     * @param float $now 当前时刻（microtime 秒） The current instant (microtime seconds).
     * @return list<array{roomId: string, queueId: string, uids: list<string>, entityIds: list<string>}> 本拍开房记录
     *   （uids 与 entityIds 并行对齐，供调用方定向投递 matched 帧） This tick's built rooms (uids and entityIds in
     *   parallel alignment, for the caller's directed matched-frame delivery).
     */
    public function tick(float $now): array
    {
        $built = [];
        foreach ($this->criteria as $queueId => $criteria) {
            while (count($this->queues[$queueId] ?? []) >= $criteria->teamSize) {
                $batch = array_splice($this->queues[$queueId], 0, $criteria->teamSize);
                foreach ($batch as $ticket) {
                    unset($this->ticketsByUid[$ticket->uid]);
                }

                $roomId = sprintf('match-%s-%d', $queueId, ++$this->roomSequence);
                try {
                    $this->rooms->create(new RoomConfig(
                        $roomId,
                        $criteria->roomPeriodMs,
                        $criteria->roomMaxMembers,
                        $this->roomAoiFactory,
                    ));
                } catch (\InvalidArgumentException|\OverflowException) {
                    // roomId 冲突等配置异常 / 准入上限（P9c 房间数触顶）：整批重新入队并终止本队列本拍撮合
                    // （与整批编排失败同口径——异常不消除凑满条件，continue 会构成开房死循环）；
                    // 准入触顶的长期出路是匹配路由他处（registry 指标），本进程侧先保撮合不空转。
                    // Config exceptions such as a roomId collision, or the admission cap (the P9c full rooms):
                    // re-queue the whole batch and stop matching this queue this tick (same convention as a
                    // whole-batch orchestration failure — the exception never clears the fill condition, so
                    // continue would spin the build loop forever); the long-term relief for a full process is
                    // routing matches elsewhere (registry metrics), while this process keeps the loop honest.
                    $this->requeueBatch($queueId, $batch);

                    break;
                }

                $joinedUids = [];
                $joinedEntityIds = [];
                $failed = [];
                foreach ($batch as $ticket) {
                    if ($this->joinHandler->joinRoom($roomId, $ticket->entityId)) {
                        unset($this->failureCounts[$ticket->uid]);
                        $joinedUids[] = $ticket->uid;
                        $joinedEntityIds[] = $ticket->entityId;

                        continue;
                    }
                    $failed[] = $ticket;
                }
                $requeued = $this->requeueFailures($queueId, $failed);
                $this->requeueBatch($queueId, $requeued);

                if ($requeued !== [] && $joinedUids === []) {
                    // 整批编排失败（毒票移出后无票回队首则不终止）：整批已回队首，终止本队列本拍撮合
                    // （防 joinRoom 恒失败时的开房死循环）
                    // The whole batch failed orchestration (a tick where eviction emptied the re-queue does not stop
                    // the loop): it is back at the head — stop matching this queue this tick (guards the room-building
                    // loop against a perpetually failing joinRoom)
                    break;
                }

                if ($joinedUids !== []) {
                    $built[] = ['roomId' => $roomId, 'queueId' => $queueId, 'uids' => $joinedUids, 'entityIds' => $joinedEntityIds];
                }
            }
        }

        return $built;
    }

    /**
     * 离线清理：按 entityId 摘除全部匹配票，返回摘除数量（断连清理路径调用）。
     * Offline cleanup: drops every ticket behind the given entityIds, returning how many were removed (the disconnect-cleanup path).
     *
     * @param list<string> $entityIds 离线实体 id 列表 The offline entity ids.
     */
    public function purgeOffline(array $entityIds): int
    {
        $offline = array_fill_keys($entityIds, true);
        $purged = 0;
        foreach (array_keys($this->queues) as $queueId) {
            $kept = [];
            foreach ($this->queues[$queueId] as $ticket) {
                if (isset($offline[$ticket->entityId])) {
                    unset($this->ticketsByUid[$ticket->uid]);
                    unset($this->failureCounts[$ticket->uid]);
                    $purged++;

                    continue;
                }
                $kept[] = $ticket;
            }
            if ($kept === []) {
                unset($this->queues[$queueId]);
            } else {
                $this->queues[$queueId] = $kept;
            }
        }

        return $purged;
    }

    /**
     * 查询某 uid 的在队票；不在队返回 null。
     * Looks up a uid's live ticket; null when not queued.
     */
    public function ticketOf(string $uid): ?MatchTicket
    {
        return $this->ticketsByUid[$uid] ?? null;
    }

    /**
     * 队列当前深度（未注册队列返回 0）。
     * A queue's current depth (0 for unregistered queues).
     */
    public function queueDepth(string $queueId): int
    {
        return count($this->queues[$queueId] ?? []);
    }

    /**
     * 编排失败票的毒票过滤：连续失败计数 +1，达上限者移出队列（从索引摘除并告警——永久失败的票
     * 若每拍回队首再次配对，会持续消耗无辜票进部分空房），其余保留待重排。
     * Poison-ticket filtering for failed orchestrations: bumps the consecutive-failure count; tickets at the cap are
     * evicted (dropped from the index with an alert — a permanently failing ticket re-paired at the head every tick
     * would keep burning innocent tickets into half-empty rooms), the rest stay for re-queueing.
     *
     * @param list<MatchTicket> $failed 本拍编排失败的票 Tickets failing orchestration this tick.
     * @return list<MatchTicket> 未达上限、需要重排回队首的失败票 Failed tickets short of the cap, to re-queue at the head.
     */
    private function requeueFailures(string $queueId, array $failed): array
    {
        $requeued = [];
        foreach ($failed as $ticket) {
            $count = ($this->failureCounts[$ticket->uid] ?? 0) + 1;
            if ($count >= self::MAX_CONSECUTIVE_JOIN_FAILURES) {
                unset($this->ticketsByUid[$ticket->uid]);
                unset($this->failureCounts[$ticket->uid]);
                error_log(sprintf(
                    'MatchingService: 票连续 %d 次编排失败，移出队列（queue=%s uid=%s entityId=%s）',
                    $count,
                    $queueId,
                    $ticket->uid,
                    $ticket->entityId,
                ));

                continue;
            }
            $this->failureCounts[$ticket->uid] = $count;
            $requeued[] = $ticket;
        }

        return $requeued;
    }

    /**
     * 整批重新入队（保留原票对象与入队时刻，排回队首）。
     * Re-queues a whole batch (original ticket objects and instants kept, back at the head).
     *
     * @param list<MatchTicket> $batch
     */
    private function requeueBatch(string $queueId, array $batch): void
    {
        if ($batch === []) {
            return;
        }
        foreach ($batch as $ticket) {
            $this->ticketsByUid[$ticket->uid] = $ticket;
        }
        $this->queues[$queueId] = [...$batch, ...$this->queues[$queueId] ?? []];
    }

    /**
     * 从队列与索引中摘除一张票；命中返回该票，未命中返回 null。
     * Removes one ticket from its queue and the index; returns it on a hit, null otherwise.
     */
    private function dequeue(?MatchTicket $ticket): ?MatchTicket
    {
        if ($ticket === null) {
            return null;
        }
        $queueId = $ticket->queueId;
        $kept = [];
        foreach ($this->queues[$queueId] ?? [] as $candidate) {
            if ($candidate->uid === $ticket->uid) {
                continue;
            }
            $kept[] = $candidate;
        }
        if ($kept === []) {
            unset($this->queues[$queueId]);
        } else {
            $this->queues[$queueId] = $kept;
        }
        unset($this->ticketsByUid[$ticket->uid]);
        unset($this->failureCounts[$ticket->uid]);

        return $ticket;
    }
}
