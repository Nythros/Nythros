<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 威胁/仇恨规则（R4 mmorpg 类型模块试点）：纯函数集合——aggro 选择（最高威胁者）、衰减计算
 * （threat - decay × dt，钳制非负）、嘲讽倍率应用与威胁上限钳制。framework 提供规则，
 * starter-kit 装配（ThreatTable 状态组件持有本规则实例）。
 * Threat/hate rules (the R4 mmorpg type-module pilot): a pure-function set — aggro selection (the highest threat),
 * decay computation (threat - decay × dt, clamped non-negative), taunt-multiplier application and the threat-cap
 * clamp. The framework owns the rules, the starter kit assembles them (the ThreatTable state component holds a
 * rules instance).
 */
final class ThreatRules
{
    /**
     * @param int $aggroRange 进入仇恨列表的距离（世界单位） The distance to enter the hate list (world units).
     * @param float $threatDecayPerSec 每秒衰减量（0 = 不衰减） The per-second decay (0 = no decay).
     * @param float $tauntMultiplier 嘲讽倍率 The taunt multiplier.
     * @param int $maxThreat 威胁上限（0 = 无上限） The threat cap (0 = unlimited).
     */
    public function __construct(
        public readonly int $aggroRange = 10,
        public readonly float $threatDecayPerSec = 0.0,
        public readonly float $tauntMultiplier = 1.0,
        public readonly int $maxThreat = 0,
    ) {
    }

    /**
     * 距离是否在仇恨列表范围内（≤ aggroRange）。
     * Whether the distance falls inside the hate-list range (≤ aggroRange).
     */
    public function inAggroRange(float $distance): bool
    {
        return $distance <= $this->aggroRange;
    }

    /**
     * 衰减计算：threat - decay × dt，钳制非负（衰减到零即出仇恨列表，由调用方摘除）。
     * Decay computation: threat - decay × dt, clamped non-negative (decaying to zero leaves the hate list,
     * removed by the caller).
     */
    public function decay(float $threat, float $dt): float
    {
        return max(0.0, $threat - $this->threatDecayPerSec * $dt);
    }

    /**
     * 嘲讽倍率应用：amount × tauntMultiplier（嘲讽技能的威胁提升量）。
     * Taunt-multiplier application: amount × tauntMultiplier (the taunt skill's threat boost).
     */
    public function applyTaunt(float $amount): float
    {
        return $amount * $this->tauntMultiplier;
    }

    /**
     * 威胁上限钳制：maxThreat > 0 时钳制到上限，否则原样返回。
     * Threat-cap clamp: clamped to the cap when maxThreat > 0, returned as-is otherwise.
     */
    public function capThreat(float $threat): float
    {
        if ($this->maxThreat > 0 && $threat > $this->maxThreat) {
            return (float) $this->maxThreat;
        }

        return $threat;
    }

    /**
     * aggro 选择：最高威胁者；空表返回 null；平局取先记录者（数组保持插入顺序）。
     * Aggro selection: the highest-threat actor; null on an empty table; ties go to the earlier-recorded actor
     * (arrays keep insertion order).
     *
     * @param array<string, float> $threats actorId => 威胁值 actorId => threat.
     */
    public function selectTarget(array $threats): ?string
    {
        if ($threats === []) {
            return null;
        }

        $best = null;
        $bestThreat = -1.0;
        foreach ($threats as $actorId => $threat) {
            if ($threat > $bestThreat) {
                $best = (string) $actorId;
                $bestThreat = (float) $threat;
            }
        }

        return $best;
    }
}
