<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务链配置值对象（R4 mmorpg 类型模块试点 → Quest 子系统）：链式任务聚合——按顺序排列的任务 id 列表，
 * 供 QuestService 消费（链式解锁/顺序推进由 QuestChainRules 判定，本值对象只承载配置）。
 * Quest-chain config value object (the R4 mmorpg type-module pilot → the Quest subsystem): a chained-quest
 * aggregation — an ordered quest-id list consumed by QuestService (chained unlocking / sequential advancement is
 * judged by QuestChainRules; this value object only carries the configuration).
 */
final readonly class QuestChain
{
    /**
     * @param string $id 任务链唯一标识 The chain's unique id.
     * @param list<string> $questIds 链式任务 id 顺序列表（至少一个——空列表在构造期拒绝，见下）
     *   The ordered quest-id list (at least one — an empty list is rejected at construction, see below).
     *
     * @throws \InvalidArgumentException $questIds 为空时（任务链至少需要一个任务，装配期 fail-fast）。
     *   When $questIds is empty (a quest chain requires at least one quest; fail-fast at assembly time).
     */
    public function __construct(
        public string $id,
        public array $questIds,
    ) {
        if ($questIds === []) {
            throw new \InvalidArgumentException('任务链至少需要一个任务 / a quest chain requires at least one quest');
        }
    }
}
