<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务链规则（R4 mmorpg 类型模块试点 → Quest 子系统）：链式解锁/顺序推进的纯函数集合——
 * 链上任务按序解锁（前序全部完成后才可推进）、链完成判定与链归属查询。framework 提供规则，
 * QuestService（状态机）消费——advance 在链上未解锁任务处短路。
 * Quest-chain rules (the R4 mmorpg type-module pilot → the Quest subsystem): a pure-function set for chained
 * unlocking / sequential advancement — chain quests unlock in order (a quest advances only once every predecessor
 * is complete), chain-completion verdicts and chain-membership queries. The framework owns the rules, the
 * QuestService (state machine) consumes them — advance short-circuits at locked chain quests.
 */
final class QuestChainRules
{
    /**
     * 查询包含某任务的链；不属于任何链返回 null（无链任务恒解锁）。
     * Finds the chain containing a quest; null when it belongs to no chain (chainless quests are always unlocked).
     *
     * @param list<QuestChain> $chains 全部任务链配置 All chain configs.
     */
    public static function chainOf(array $chains, string $questId): ?QuestChain
    {
        foreach ($chains as $chain) {
            if (in_array($questId, $chain->questIds, true)) {
                return $chain;
            }
        }

        return null;
    }

    /**
     * 链上某任务是否已解锁：任务须属于该链，且其全部前序任务已完成（首任务恒解锁）。
     * Whether a chain quest is unlocked: the quest must belong to the chain and every predecessor must be
     * completed (the first quest is always unlocked).
     *
     * @param list<string> $completedQuestIds 已完成任务 id 列表（本 uid 该链上的完成集） Completed quest ids (this uid's completion set on the chain).
     */
    public static function isUnlocked(QuestChain $chain, array $completedQuestIds, string $questId): bool
    {
        $position = array_search($questId, $chain->questIds, true);
        if ($position === false) {
            return false; // 不在链上 = 不是链任务，判定 false（由调用方先做归属判断） Not in the chain — judged false (callers check membership first).
        }

        foreach (array_slice($chain->questIds, 0, $position) as $predecessor) {
            if (!in_array($predecessor, $completedQuestIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 链上下一个待推进任务：第一个未完成的任务；链全完成返回 null。
     * The chain's next quest to advance: the first incomplete quest; null once the chain is complete.
     *
     * @param list<string> $completedQuestIds 已完成任务 id 列表 Completed quest ids.
     */
    public static function nextQuestId(QuestChain $chain, array $completedQuestIds): ?string
    {
        foreach ($chain->questIds as $questId) {
            if (!in_array($questId, $completedQuestIds, true)) {
                return $questId;
            }
        }

        return null;
    }

    /**
     * 链是否全部完成（每个链上任务都在完成集中）。
     * Whether the chain is fully complete (every chain quest is in the completion set).
     *
     * @param list<string> $completedQuestIds 已完成任务 id 列表 Completed quest ids.
     */
    public static function isChainComplete(QuestChain $chain, array $completedQuestIds): bool
    {
        foreach ($chain->questIds as $questId) {
            if (!in_array($questId, $completedQuestIds, true)) {
                return false;
            }
        }

        return true;
    }
}
