<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 技能冷却表（R3 玩法批收编）：按「施法者键 × 技能 id」维度管理技能独立冷却（秒制，与普攻攻击冷却的
 * 帧制互不干扰）。此前冷却语义散落在 demo 路由侧（PlayerActor 攻击冷却 + SkillDefinition.cooldownSeconds
 * 未被消费）；本表把 per-skill 冷却收进 framework，施法路由经 isReady/remaining 校验、start 置冷。
 *
 * 双冷却共存语义：本表（skill:cast 秒制冷却）与普攻帧制冷却（PlayerActor 攻击间隔）是两套独立状态——
 * 互不读写、互不重置：普攻不查本表，施法不动普攻计时；同一施法者可同时处于两套冷却中，属预期行为。
 * The skill-cooldown table (absorbed in the R3 gameplay batch): manages per-skill cooldowns keyed by
 * "caster key × skill id" in seconds, independent of the frame-based normal-attack cooldown. Cooldown semantics used
 * to be scattered across the demo route side (the PlayerActor attack cooldown plus an unconsumed
 * SkillDefinition.cooldownSeconds); this table pulls the per-skill cooldown into the framework — cast routes validate
 * via isReady/remaining and chill down via start.
 *
 * Dual-cooldown coexistence: this table (the skill:cast second-based cooldown) and the normal-attack frame-based
 * cooldown (the PlayerActor attack interval) are two independent states — neither reads/writes nor resets the other:
 * normal attacks never consult this table, casts never touch the attack timer, and one caster sitting in both at once
 * is expected behavior.
 */
final class SkillCooldownTable
{
    /** @var array<string, array<string, float>> casterKey => skillId => 就绪时刻（microtime 秒） casterKey => skillId => ready-at instant (microtime seconds). */
    private array $readyAt = [];

    /**
     * 置冷：记录 casterKey×skillId 的就绪时刻 = now + cooldownSeconds（非正冷却视为瞬时就绪，仍覆盖旧记录）。
     * Chills down: records the ready instant as now + cooldownSeconds for the caster×skill pair (a non-positive
     * cooldown counts as instantly ready but still overwrites the old record).
     */
    public function start(string $casterKey, string $skillId, float $cooldownSeconds, float $now): void
    {
        $this->readyAt[$casterKey][$skillId] = $now + max(0.0, $cooldownSeconds);
    }

    /**
     * 是否就绪：无记录或 now ≥ 就绪时刻即就绪。
     * Readiness: ready when unrecorded or now is past the ready instant.
     */
    public function isReady(string $casterKey, string $skillId, float $now): bool
    {
        return $this->remaining($casterKey, $skillId, $now) <= 0.0;
    }

    /**
     * 剩余冷却秒数（已就绪返回 0.0）。
     * The remaining cooldown seconds (0.0 when already ready).
     */
    public function remaining(string $casterKey, string $skillId, float $now): float
    {
        return max(0.0, ($this->readyAt[$casterKey][$skillId] ?? 0.0) - $now);
    }

    /**
     * 清空某施法者的全部冷却记录（断连清理路径）。
     * Clears every cooldown record of one caster (the disconnect-cleanup path).
     */
    public function reset(string $casterKey): void
    {
        unset($this->readyAt[$casterKey]);
    }
}
