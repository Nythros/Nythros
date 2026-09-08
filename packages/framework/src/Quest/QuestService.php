<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Inventory;
use Nythros\Framework\Persistence\SessionParticipantInterface;

/**
 * 任务服务（R3 玩法批）：三类进度源（击杀/收集/对话）的进度状态机与奖励发放。
 * Quest service (the R3 gameplay batch): the progress state machine over the three progress sources
 * (kill/collect/talk) plus reward granting.
 *
 * 进度状态机：count 累计 → count ≥ requiredCount 即 completed（单向，不回退）→ 领奖置 rewarded；
 * completed/rewarded 后进度上报幂等短路（不再累计、不重复触发完成）。
 * The progress state machine: count accumulates → count >= requiredCount completes the quest (one-way, never
 * regresses) → claiming sets rewarded; progress reports short-circuit idempotently once completed/rewarded
 * (no further accumulation, no repeated completion).
 *
 * 任务链（R4 mmorpg 试点 → Quest 子系统）：构造期注入 list<QuestChain>（缺省 [] = 全部任务恒解锁，
 * 行为与链前一致）；链上任务按序解锁——前序未完成的任务忽略进度上报（QuestChainRules 纯规则判定），
 * 完成当前任务即解锁下一环（解锁是完成集的派生状态，无独立解锁动作）。
 * Quest chains (the R4 mmorpg pilot → the Quest subsystem): a list<QuestChain> injected at construction
 * (default [] = every quest always unlocked, matching the pre-chain behavior); chain quests unlock in order —
 * reports targeting a quest whose predecessors are incomplete are ignored (judged by the QuestChainRules pure
 * functions); completing the current quest unlocks the next ring (unlocking is a derived state of the completion
 * set, not a standalone action).
 *
 * 事件埋点消费（D4 缺口闭环）：attachDispatcher 把本服务挂到 CombatService 的 combat.kill / combat.pickup
 * 埋点上——击杀/拾取路径的业务事件直接驱动前两类进度源；对话进度源无自然事件，由路由显式调 reportTalk。
 * Instrumentation consumption (closing the D4 gap): attachDispatcher hooks this service onto CombatService's
 * combat.kill / combat.pickup instrumentation — business events from the kill/pickup paths drive the first two
 * progress sources directly; the talk source has no natural event and is fed by an explicit reportTalk call from routes.
 *
 * 会话参与者（SessionParticipantInterface）：onSessionOpen/onSessionClose 委托 preload/evict——
 * 任务进度缓存随玩家会话开关走统一生命周期（MapServer attach/detach 驱动,无需装配层逐能力插调用）。
 * Session participant: onSessionOpen/onSessionClose delegate to preload/evict — the quest-progress cache joins
 * the unified session lifecycle driven by MapServer attach/detach (no per-capability splices in the assembly).
 */
final class QuestService implements SessionParticipantInterface
{
    /** 击杀埋点事件名（CombatService 派发）。 The kill-instrumentation event name (dispatched by CombatService). */
    public const EVENT_KILL = 'combat.kill';

    /** 拾取埋点事件名（CombatService 派发）。 The pickup-instrumentation event name (dispatched by CombatService). */
    public const EVENT_PICKUP = 'combat.pickup';

    /**
     * @param QuestStoreInterface $store 进度存储 Progress storage.
     * @param QuestRepository $quests 任务定义注册表 The quest-definition repository.
     * @param list<QuestChain> $chains 任务链配置（缺省 [] = 无链，全部任务恒解锁） Quest-chain configs (default
     *   [] = chainless, every quest always unlocked).
     */
    public function __construct(
        private readonly QuestStoreInterface $store,
        private readonly QuestRepository $quests,
        private readonly array $chains = [],
    ) {
    }

    /**
     * 事件埋点接线：监听 combat.kill / combat.pickup 并驱动对应进度源（组装层在装配后调用一次）。
     * Instrumentation wiring: listens to combat.kill / combat.pickup and drives the matching progress sources
     * (invoked once by the assembly layer after construction).
     */
    public function attachDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->listen(self::EVENT_KILL, function (array $payload): void {
            $uid = $payload['killerUid'] ?? null;
            // 类型匹配键（P2 收口）：优先 monsterTypeId（CombatService 新口径）；回退 monsterId
            // 兼容旧载荷（旧口径传实例 id，与类型目标永不匹配，回退无副作用）。
            // The type-matching key (the P2 close-out): monsterTypeId first (the CombatService contract); a
            // monsterId fallback keeps old payloads working (they carried instance ids that never matched type targets).
            $monsterTypeId = $payload['monsterTypeId'] ?? $payload['monsterId'] ?? null;
            if (is_string($uid) && is_string($monsterTypeId)) {
                $this->reportKill($uid, $monsterTypeId);
            }
        });
        $dispatcher->listen(self::EVENT_PICKUP, function (array $payload): void {
            $uid = $payload['uid'] ?? null;
            $itemId = $payload['itemId'] ?? null;
            $count = $payload['count'] ?? null;
            if (is_string($uid) && is_string($itemId) && is_int($count)) {
                $this->reportCollect($uid, $itemId, $count);
            }
        });
    }

    /**
     * 击杀进度上报：source=kill 且 targetId 匹配的任务计数 +1。
     * Kill progress: quests with source=kill whose targetId matches gain one count.
     */
    public function reportKill(string $uid, string $monsterTypeId): void
    {
        $this->advance($uid, QuestDefinition::SOURCE_KILL, $monsterTypeId, 1);
    }

    /**
     * 收集进度上报：source=collect 且 targetId 匹配的任务按入包数量累计。
     * Collect progress: quests with source=collect whose targetId matches accumulate by the picked-up count.
     */
    public function reportCollect(string $uid, string $itemId, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $this->advance($uid, QuestDefinition::SOURCE_COLLECT, $itemId, $count);
    }

    /**
     * 对话进度上报：source=talk 且 targetId 匹配的任务计数 +1（同一 NPC 重复对话照常累计，
     * 由任务定义侧用 requiredCount 控制节奏）。
     * Talk progress: quests with source=talk whose targetId matches gain one count (repeated talks with the same
     * NPC accumulate as usual; pacing is governed by requiredCount on the quest definition).
     */
    public function reportTalk(string $uid, string $npcId): void
    {
        $this->advance($uid, QuestDefinition::SOURCE_TALK, $npcId, 1);
    }

    /**
     * 领奖：completed 且未领奖时把奖励表逐项入包并置 rewarded；否则 false（幂等）。
     * Claims rewards: when completed and unclaimed, adds every reward entry into the bag and sets rewarded;
     * otherwise false (idempotent).
     */
    public function claimReward(string $uid, string $questId, Inventory $inventory): bool
    {
        $definition = $this->quests->get($questId);
        $progress = $this->store->get($uid, $questId);
        if ($definition === null || $progress === null || !$progress->completed || $progress->rewarded) {
            return false;
        }

        foreach ($definition->rewards as $reward) {
            $inventory->add((string) $reward['itemId'], (int) $reward['count']);
        }
        $this->store->save(new QuestProgress($uid, $questId, $progress->count, true, true));

        return true;
    }

    /**
     * 查询某 uid 某任务的进度；无记录返回 null。
     * Looks up one uid's progress on one quest; null when unrecorded.
     */
    public function progressOf(string $uid, string $questId): ?QuestProgress
    {
        return $this->store->get($uid, $questId);
    }

    /**
     * 某 uid 的全部任务进度。
     * All of one uid's quest progress.
     *
     * @return list<QuestProgress>
     */
    public function allProgress(string $uid): array
    {
        return $this->store->all($uid);
    }

    /**
     * 任务定义注册表（组装层注册定义用）。
     * The quest-definition repository (the assembly layer registers definitions through it).
     */
    public function definitions(): QuestRepository
    {
        return $this->quests;
    }

    /**
     * 会话预热（写回缓冲接线，主循环 IO 剥离）：委托 store 在入场时一次性载入该 uid 的全部进度——
     * 仅当 store 是 CachedQuestStore 时生效（RedisQuestStore/InMemoryQuestStore 直连无缓冲则零操作）。
     * 组装层在玩家 attach 后调用，令后续 combat.kill/pickup 的进度读写全走内存。
     * Session preload (the write-back-buffer wiring, the hot-path IO strip): delegates to the store to load one
     * uid's whole progress set at attach time — effective only when the store is a CachedQuestStore (a direct
     * RedisQuestStore/InMemoryQuestStore is a no-op). The assembly layer calls it after a player attaches, so the
     * later combat.kill/pickup progress reads/writes all stay in memory.
     */
    public function preload(string $uid): void
    {
        if ($this->store instanceof CachedQuestStore) {
            $this->store->preload($uid);
        }
    }

    /**
     * 会话淘汰（断连/登出收尾）：回写该 uid 的未冲刷进度并释放缓冲；非写回后端零操作。
     * Session eviction (the disconnect/logout close-out): flushes one uid's unflushed progress and releases the
     * buffer; a no-op for non-write-back backends.
     */
    public function evict(string $uid): void
    {
        if ($this->store instanceof CachedQuestStore) {
            $this->store->evict($uid);
        }
    }

    /**
     * 全量兜底冲刷：把所有未回写的脏进度写回后端（供 30s 定时兜底；崩溃丢失窗口的边界，同 ArchivePipeline
     * 裁决 4）。非写回后端零操作。
     * The periodic backstop flush: writes every unflushed dirty progress back to the backend (for the 30s fallback;
     * the crash-loss-window boundary, the same as ArchivePipeline's ruling 4). A no-op for non-write-back backends.
     */
    public function flushPending(): void
    {
        if ($this->store instanceof CachedQuestStore) {
            $this->store->flushAll();
        }
    }

    /**
     * 会话开启钩子（SessionParticipantInterface）：委托 preload（写回后端未热身的 uid 整批载入）。
     * The session-open hook: delegates to preload (batch-loads the uid into the write-back store).
     */
    public function onSessionOpen(string $uid): void
    {
        $this->preload($uid);
    }

    /**
     * 会话结束钩子（SessionParticipantInterface）：委托 evict（回写脏进度并释放会话缓存）。
     * The session-close hook: delegates to evict (writes back dirty progress and frees the session cache).
     */
    public function onSessionClose(string $uid): void
    {
        $this->evict($uid);
    }

    /**
     * 进度推进公共路径：遍历匹配进度源与目标的任务——completed 后短路；链上未解锁任务忽略（P2 链式解锁）；
     * 累计后越线即置 completed 并回存。
     * The shared advancement path: walks quests matching the source and target — short-circuits past completion;
     * locked chain quests ignore the report (the P2 chained unlocking); crossing the line after accumulation sets
     * completed and saves back.
     */
    private function advance(string $uid, string $source, string $targetId, int $delta): void
    {
        foreach ($this->quests->all() as $definition) {
            if ($definition->source !== $source || $definition->targetId !== $targetId) {
                continue;
            }
            $progress = $this->store->get($uid, $definition->id) ?? new QuestProgress($uid, $definition->id);
            if ($progress->completed) {
                continue; // 已完成任务不再累计（幂等）。 Completed quests stop accumulating (idempotent).
            }

            $chain = QuestChainRules::chainOf($this->chains, $definition->id);
            if ($chain !== null) {
                $completedIds = $this->completedIdsOnChain($uid, $chain);
                if (!QuestChainRules::isUnlocked($chain, $completedIds, $definition->id)) {
                    continue; // 链上未解锁任务忽略进度（前序未完成）。 Locked chain quests ignore progress (a predecessor is incomplete).
                }
            }

            $count = $progress->count + $delta;
            $completed = $count >= $definition->requiredCount;
            $this->store->save(new QuestProgress($uid, $definition->id, min($count, $definition->requiredCount), $completed, $progress->rewarded));
        }
    }

    /**
     * 该 uid 在某链上的完成集（链内已完成任务 id 列表，供解锁判定）。
     * The uid's completion set on a chain (the chain's completed quest ids, for unlock judgments).
     *
     * @return list<string>
     */
    private function completedIdsOnChain(string $uid, QuestChain $chain): array
    {
        $completed = [];
        foreach ($chain->questIds as $questId) {
            $progress = $this->store->get($uid, $questId);
            if ($progress !== null && $progress->completed) {
                $completed[] = $questId;
            }
        }

        return $completed;
    }
}
