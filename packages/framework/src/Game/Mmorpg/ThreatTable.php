<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 威胁表状态组件（R4 mmorpg 类型模块试点）：per-actor 威胁记录——addThreat 累加（可选距离判定，
 * 超 aggroRange 忽略）、applyTaunt 嘲讽提升、decay 按规则衰减（衰减到零自动摘除）、topThreat/selectTarget
 * 查询最高威胁者、remove/clear 摘除与清空。由 MonsterActor 持有（受击方记录攻击者威胁），
 * 衰减由装配层定时驱动。
 * The threat-table state component (the R4 mmorpg type-module pilot): per-actor threat records — addThreat
 * accumulates (with an optional distance gate, ignored beyond aggroRange), applyTaunt boosts via taunt, decay
 * decays per the rules (auto-removing at zero), topThreat/selectTarget query the highest threat, remove/clear
 * drop and empty. Held by MonsterActor (the hit side records the attacker's threat); decay is driven by the
 * assembly layer's timer.
 */
final class ThreatTable
{
    /** @var array<string, float> actorId => 当前威胁值 actorId => current threat. */
    private array $threats = [];

    public function __construct(private readonly ThreatRules $rules)
    {
    }

    /**
     * 记录/累加威胁：可选距离判定（distance 非 null 且超 aggroRange 时忽略——攻击者不在仇恨列表范围内
     * 不记威胁）；累加后钳制非负再按规则钳制上限（reviewer MINOR-2：capThreat 只钳上限不钳下限，
     * 负值 amount 可把威胁压到负值；钳到零的 actor 与 decay 同口径摘除——零威胁不出现在仇恨列表）。
     * Records/accumulates threat: an optional distance gate (a non-null distance beyond aggroRange is ignored —
     * an attacker outside the hate-list range gains no threat); the sum is clamped non-negative then to the cap
     * per the rules (reviewer MINOR-2: capThreat only clamps the upper bound, a negative amount could drive the
     * threat negative; actors clamped to zero are removed like decay does — zero threat leaves the hate list).
     */
    public function addThreat(string $actorId, float $amount, ?float $distance = null): void
    {
        if ($distance !== null && !$this->rules->inAggroRange($distance)) {
            return;
        }

        $threat = $this->rules->capThreat(max(0.0, ($this->threats[$actorId] ?? 0.0) + $amount));
        if ($threat <= 0.0) {
            unset($this->threats[$actorId]);
        } else {
            $this->threats[$actorId] = $threat;
        }
    }

    /**
     * 嘲讽提升：把该 actor 的威胁提升到 amount × tauntMultiplier 与当前值的较大者（嘲讽语义：
     * 强制拉到嘲讽量级，不叠加）。
     * 装配状态（P1 标注）：demo 技能层暂无嘲讽技能，本入口无生产调用方——规则与状态组件就绪，
     * 待技能层接入 taunt 技能时经 MonsterActor/装配层消费（不做空接线）。
     * Taunt boost: raises the actor's threat to the larger of amount × tauntMultiplier and the current value
     * (taunt semantics: forced up to the taunt magnitude, not stacked).
     * Assembly status (P4b consumed the P1 reservation): the demo skill tier now carries a taunt skill whose cast
     * route calls MonsterActor::applyTaunt → this entry (no empty wiring since the P1 note).
     */
    public function applyTaunt(string $actorId, float $amount): void
    {
        $boosted = $this->rules->applyTaunt($amount);
        $this->threats[$actorId] = $this->rules->capThreat(max($this->threats[$actorId] ?? 0.0, $boosted));
    }

    /**
     * 按规则衰减全部威胁（dt 秒）；衰减到零的 actor 自动摘除（出仇恨列表）。
     * Decays every threat per the rules (dt seconds); actors decaying to zero are removed (leaving the hate list).
     */
    public function decay(float $dt): void
    {
        foreach ($this->threats as $actorId => $threat) {
            $decayed = $this->rules->decay($threat, $dt);
            if ($decayed <= 0.0) {
                unset($this->threats[$actorId]);
            } else {
                $this->threats[$actorId] = $decayed;
            }
        }
    }

    /**
     * 最高威胁者（只读查询，不改变状态）；空表返回 null。
     * The highest-threat actor (a read-only query, no state change); null on an empty table.
     */
    public function topThreat(): ?string
    {
        return $this->rules->selectTarget($this->threats);
    }

    /**
     * 选择攻击目标（aggro 语义命名，与 topThreat 同判据——最高威胁者）。
     * Selects the attack target (the aggro-semantics name, same criterion as topThreat — the highest threat).
     */
    public function selectTarget(): ?string
    {
        return $this->rules->selectTarget($this->threats);
    }

    /**
     * 查询某 actor 的当前威胁值（未记录返回 0）。
     * Queries an actor's current threat (0 when unrecorded).
     */
    public function threatOf(string $actorId): float
    {
        return $this->threats[$actorId] ?? 0.0;
    }

    /**
     * 摘除某 actor 的威胁记录（目标死亡/离场时由消费方调用）。
     * Removes an actor's threat record (invoked by the consumer on target death/departure).
     */
    public function remove(string $actorId): void
    {
        unset($this->threats[$actorId]);
    }

    /**
     * 清空全部威胁记录。
     * Clears every threat record.
     */
    public function clear(): void
    {
        $this->threats = [];
    }

    /**
     * 全部威胁记录快照（actorId => 威胁值）。
     * A snapshot of every threat record (actorId => threat).
     *
     * @return array<string, float>
     */
    public function all(): array
    {
        return $this->threats;
    }
}
