<?php

declare(strict_types=1);

namespace Nythros\Framework\Actor;

/**
 * 生命面 trait：hp/maxHp 状态与数值不变量的唯一定义点——读取（hp/maxHp/isDead）、治疗钳制
 * （heal：钳制在 maxHp() 口径内、已死不复活）与有效伤害结算核（settleDamage：钳制归零并回报
 * 是否致命）。takeDamage 模板方法保留在各基类：受击/死亡钩子签名本就因域而异（玩家无来源、
 * 怪物携带 lastAttackerId 并迁移 DEAD 终态），基类只负责幂等短路判定与钩子编排，
 * hp ≤ maxHp、扣血不为负等数值不变量统一收敛到本 trait，杜绝双份漂移。
 * The vitals trait: the single definition point of the hp/maxHp state and its numeric invariants — the reads
 * (hp/maxHp/isDead), the heal clamp (within the maxHp() ceiling; the dead are not revived) and the effective-damage
 * settlement core (settleDamage: clamps to zero and reports whether the hit was fatal). The takeDamage template
 * methods stay in the base classes: their hook signatures differ per domain by design (the player has no source,
 * the monster carries lastAttackerId and transitions to the DEAD terminal state), so base classes own only the
 * idempotent short-circuit check and the hook orchestration while the numeric invariants (hp ≤ maxHp, damage never
 * below zero) converge here, eliminating twin-implementation drift.
 */
trait Vitals
{
    protected int $hp = 100;

    protected int $maxHp = 100;

    public function hp(): int
    {
        return $this->hp;
    }

    /**
     * 最大生命值上限：缺省即基础值；需要合成口径（装备/属性临时修正加成）的子类覆盖本方法，
     * 所有读取该口径的钳制（heal 等）自动跟随合成值。
     * The maximum hp ceiling: the base value by default; subclasses needing a composed contract (equipment /
     * temporary-attribute-modifier bonuses) override this method, and every clamp reading the ceiling (heal etc.)
     * follows the composed value automatically.
     */
    public function maxHp(): int
    {
        return $this->maxHp;
    }

    public function isDead(): bool
    {
        return $this->hp <= 0;
    }

    /**
     * 治疗：恢复生命值，钳制在 maxHp() 口径内；已死不复活。
     * Heal: restore hit points clamped to the maxHp() ceiling; the dead are not revived.
     *
     * @param int $amount 治疗量 The heal amount.
     */
    public function heal(int $amount): void
    {
        if ($amount <= 0 || $this->hp <= 0) {
            return;
        }
        $this->hp = min($this->maxHp(), $this->hp + $amount);
    }

    /**
     * 有效伤害结算核：hp 钳制归零（单调递减、不为负），返回本次是否致命（hp 归零）。
     * 调用方负责幂等短路（amount <= 0 或已死直接返回）与受击/死亡钩子编排。
     * The effective-damage settlement core: hp is clamped to zero (monotonically non-increasing, never negative)
     * and the return reports whether this hit was fatal (hp at zero). The caller owns the idempotent short-circuit
     * (amount <= 0 or already dead) plus the damage/death hook orchestration.
     */
    protected function settleDamage(int $amount): bool
    {
        $this->hp = max(0, $this->hp - $amount);

        return $this->hp === 0;
    }
}
