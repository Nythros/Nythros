<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务定义值对象：进度源类型 × 目标 × 所需数量 × 奖励表。
 * Quest definition value object: progress-source type × target × required count × reward table.
 */
final readonly class QuestDefinition
{
    /** 进度源：击杀（targetId = 怪物类型 id）。 Progress source: kill (targetId = the monster-type id). */
    public const SOURCE_KILL = 'kill';

    /** 进度源：收集（targetId = 物品 id，按入包数量累计）。 Progress source: collect (targetId = the item id, accumulating pickup counts). */
    public const SOURCE_COLLECT = 'collect';

    /** 进度源：对话（targetId = NPC id，一次对话计 1）。 Progress source: talk (targetId = the NPC id; one talk counts once). */
    public const SOURCE_TALK = 'talk';

    /**
     * @param string $id 任务唯一 id Unique quest id.
     * @param string $name 任务名 Quest name.
     * @param string $source 进度源类型（kill|collect|talk） The progress-source type (kill|collect|talk).
     * @param string $targetId 进度目标 id（怪物类型/物品/NPC） The progress-target id (monster type/item/NPC).
     * @param int $requiredCount 完成所需数量 Required count to complete.
     * @param list<array{itemId: string, count: int}> $rewards 奖励表（发放走 Inventory） The reward table (granted through Inventory).
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $source,
        public string $targetId,
        public int $requiredCount,
        public array $rewards = [],
    ) {
        if ($this->requiredCount < 1) {
            throw new \InvalidArgumentException('QuestDefinition requiredCount 必须为正 / requiredCount must be positive');
        }
        if (!in_array($this->source, [self::SOURCE_KILL, self::SOURCE_COLLECT, self::SOURCE_TALK], true)) {
            throw new \InvalidArgumentException(sprintf('QuestDefinition 进度源非法: %s / illegal progress source: %s', $this->source, $this->source));
        }
    }
}
