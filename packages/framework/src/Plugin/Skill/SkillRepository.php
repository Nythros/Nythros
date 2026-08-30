<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Skill;

/**
 * 技能注册表：按 id 管理技能定义，供 demo 战斗结算查询。
 * Skill repository: manages skill definitions by id for the demo combat resolution to query.
 */
final class SkillRepository
{
    /**
     * @var array<string, SkillDefinition> id => 技能定义 id => skill definition
     */
    private array $skills = [];

    /**
     * 注册技能定义；同 id 后注册覆盖先注册。
     * Registers a skill definition; a later registration with the same id overrides the earlier one.
     *
     * @param SkillDefinition $skill 技能定义 The skill definition.
     */
    public function register(SkillDefinition $skill): void
    {
        $this->skills[$skill->id] = $skill;
    }

    /**
     * 按 id 摘除技能定义（P11 技能表热载删除行用）；未注册返回 false。
     * Removes a skill definition by id (for deleting rows during P11 skill-table hot reload); returns false when not registered.
     *
     * @param string $id 技能 id The skill id.
     */
    public function remove(string $id): bool
    {
        if (!isset($this->skills[$id])) {
            return false;
        }
        unset($this->skills[$id]);

        return true;
    }

    /**
     * 全部已注册技能 id。
     * All registered skill ids.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->skills);
    }

    /**
     * 按 id 查询技能定义；未注册返回 null。
     * Looks up a skill definition by id; returns null when not registered.
     *
     * @param string $id 技能 id The skill id.
     */
    public function get(string $id): ?SkillDefinition
    {
        return $this->skills[$id] ?? null;
    }

    /**
     * 返回全部技能定义（id => SkillDefinition）。
     * Returns all skill definitions (id => SkillDefinition).
     *
     * @return array<string, SkillDefinition>
     */
    public function all(): array
    {
        return $this->skills;
    }
}
