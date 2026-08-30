<?php

declare(strict_types=1);

namespace Nythros\Framework;

/**
 * 可损伤面：玩家与怪物共同实现的最小战斗契约，使战斗服务（CombatService）的 attack
 * 以统一签名承载「玩家→怪物」与「怪物→玩家」双向攻击。
 * Damageable: the minimal combat contract implemented by both players and monsters, allowing
 * the combat service's attack to carry bidirectional damage (player → monster / monster → player).
 */
interface Damageable
{
    /**
     * 当前生命值。
     * Current hit points.
     */
    public function hp(): int;

    /**
     * 最大生命值上限。
     * Maximum hit point ceiling.
     */
    public function maxHp(): int;

    /**
     * 模板方法：扣血钳制归零，归零时幂等触发死亡结算（见 BasePlayer/BaseMonster 实现）。
     * Template method: damage is clamped to zero; upon reaching zero the death settlement is triggered idempotently (see BasePlayer/BaseMonster).
     *
     * @param int $amount 伤害量 The damage amount.
     */
    public function takeDamage(int $amount): void;

    /**
     * 治疗：恢复生命值，不越过上限。
     * Heal: restore hit points, never exceeding the ceiling.
     *
     * @param int $amount 治疗量 The heal amount.
     */
    public function heal(int $amount): void;

    /**
     * 是否已死亡（生命值归零）。
     * Whether the subject is dead (hit points at zero).
     */
    public function isDead(): bool;
}
