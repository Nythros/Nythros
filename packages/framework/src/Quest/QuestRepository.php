<?php

declare(strict_types=1);

namespace Nythros\Framework\Quest;

/**
 * 任务定义注册表：按 id 管理任务定义（比照 SkillRepository 风格）。
 * The quest-definition repository: manages quest definitions by id (styled after SkillRepository).
 */
final class QuestRepository
{
    /** @var array<string, QuestDefinition> id => 任务定义 id => quest definition. */
    private array $quests = [];

    /**
     * 注册任务定义；同 id 后注册覆盖先注册。
     * Registers a quest definition; a later registration with the same id overrides the earlier one.
     */
    public function register(QuestDefinition $quest): void
    {
        $this->quests[$quest->id] = $quest;
    }

    /**
     * 按 id 查询任务定义；未注册返回 null。
     * Looks up a quest definition by id; null when unregistered.
     */
    public function get(string $id): ?QuestDefinition
    {
        return $this->quests[$id] ?? null;
    }

    /**
     * 返回全部任务定义（id => QuestDefinition）。
     * Returns all quest definitions (id => QuestDefinition).
     *
     * @return array<string, QuestDefinition>
     */
    public function all(): array
    {
        return $this->quests;
    }
}
