<?php

declare(strict_types=1);

namespace Nythros\Framework;

use Nythros\Actor\BaseActor;
use Nythros\Framework\Actor\TickCadence;
use Nythros\Framework\Inventory\Equipment\Equipment;

/**
 * 玩家基类：承载连接/身份与最小战斗面，模板方法 takeDamage 内闭环死亡结算。
 * 属性聚合挂载点（D6 缺口补齐）：maxHp = 基础值 + 装备加成——Equipment 挂载后 maxHp()/heal 钳制
 * 全部走合成口径；挂载/摘除时把 hp 收敛进新上限，保证 hp ≤ maxHp 不变量恒成立。
 * Base player class: carries connection/identity and the minimal combat surface, closing the
 * death settlement inside the takeDamage template method. Attribute-aggregation mount point (closing the D6 gap):
 * maxHp = base + equipment bonus — once an Equipment is mounted, maxHp() and the heal clamp both take the composed
 * value; mounting/unmounting clamps hp into the new ceiling so the hp ≤ maxHp invariant always holds.
 */
abstract class BasePlayer extends BaseActor implements Damageable
{
    use TickCadence;

    private ?string $connectionId = null;

    private ?string $uid = null;

    protected int $hp = 100;

    protected int $maxHp = 100;

    /** 装备栏挂载（缺省 null = 未装配装备系统，maxHp 退化为纯基础值） Mounted equipment set (default null = no equipment system, maxHp degrades to the pure base value). */
    private ?Equipment $equipment = null;

    /**
     * 属性临时修正聚合表（R3 玩法批：buff 等临时效果的属性聚合挂载点，属性名 => 增量和）。
     * 与装备加成同口径参与 maxHp 合成；BuffService 施加/到期按增量对称加减。
     * The temporary attribute-modifier aggregation table (the R3 gameplay batch: the attribute-aggregation mount
     * point for temporary effects such as buffs, attribute name => summed delta). It joins the maxHp composition in
     * the same way as equipment bonuses; BuffService adds/removes symmetric deltas on apply/expiry.
     *
     * @var array<string, int>
     */
    private array $attributeModifiers = [];

    /**
     * 绑定连接与玩家 uid。
     * Attaches a connection and the player uid.
     *
     * @param string $connectionId 连接标识 Connection id.
     * @param string $uid 玩家唯一标识 Player uid.
     */
    public function attachConnection(string $connectionId, string $uid): void
    {
        $this->connectionId = $connectionId;
        $this->uid = $uid;
    }

    /**
     * 解除连接绑定。
     * Detaches the bound connection.
     */
    public function detachConnection(): void
    {
        $this->connectionId = null;
        $this->uid = null;
    }

    /**
     * 当前连接标识；未绑定时为 null。
     * The current connection id; null when unbound.
     */
    public function connectionId(): ?string
    {
        return $this->connectionId;
    }

    /**
     * 玩家唯一标识；未绑定时为 null。
     * The player uid; null when unbound.
     */
    public function uid(): ?string
    {
        return $this->uid;
    }

    /**
     * 挂载装备栏（属性聚合入口）：挂载即把 hp 收敛进合成上限。
     * Mounts the equipment set (the attribute-aggregation entry): hp is clamped into the composed ceiling on mount.
     */
    public function attachEquipment(Equipment $equipment): void
    {
        $this->equipment = $equipment;
        $this->clampHpToMax();
    }

    /**
     * 摘除装备栏：加成清零后同样收敛 hp（卸下减益装备可能压低上限）。
     * Unmounts the equipment set: hp is clamped after bonuses clear too (unequipping may lower the ceiling).
     */
    public function detachEquipment(): void
    {
        $this->equipment = null;
        $this->clampHpToMax();
    }

    /**
     * 当前装备栏；未挂载为 null。
     * The current equipment set; null when none is mounted.
     */
    public function equipment(): ?Equipment
    {
        return $this->equipment;
    }

    /**
     * 叠加一条属性临时修正（增量可正可负）：聚合表累加后把 hp 收敛进新合成上限
     * （负增量可能压低上限，正增量为无操作收敛）。
     * Adds one temporary attribute modifier (delta may be negative): the aggregation accumulates, then hp is clamped
     * into the new composed ceiling (a negative delta may lower it; a positive one makes the clamp a no-op).
     */
    public function addAttributeModifier(string $attribute, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $this->attributeModifiers[$attribute] = ($this->attributeModifiers[$attribute] ?? 0) + $delta;
        $this->clampHpToMax();
    }

    /**
     * 回退一条属性临时修正（按施加时的同一增量对称回退）：归零键摘除，防止表无限膨胀。
     * Removes one temporary attribute modifier (symmetric rollback by the same delta applied): zeroed keys are
     * dropped, keeping the table from growing without bound.
     */
    public function removeAttributeModifier(string $attribute, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $remaining = ($this->attributeModifiers[$attribute] ?? 0) - $delta;
        if ($remaining === 0) {
            unset($this->attributeModifiers[$attribute]);
        } else {
            $this->attributeModifiers[$attribute] = $remaining;
        }
        $this->clampHpToMax();
    }

    /**
     * 查询某属性的临时修正当前和（未登记返回 0）。
     * The current summed modifier of an attribute (0 when unregistered).
     */
    public function attributeModifierSum(string $attribute): int
    {
        return $this->attributeModifiers[$attribute] ?? 0;
    }

    /**
     * 初始化生命基线（P18 玩法数据外置，auth 挂载时一次性调用）：覆盖基础 maxHp 并回满——
     * 装备/临时修正在 auth 时点尚未挂载，合成上限即基线值；缺省 100 = 逐字节等价（不调用即缺省）。
     * Initializes the vitals baseline (the P18 gameplay-data externalization, invoked once at auth mount):
     * overwrites the base maxHp and fills hp — equipment/temporary modifiers are not mounted at auth time, so
     * the composed ceiling equals the baseline; the default 100 stays byte-for-byte equivalent (never calling it).
     */
    public function initVitals(int $maxHp): void
    {
        $this->maxHp = max(1, $maxHp);
        $this->hp = $this->maxHp();
    }

    public function hp(): int
    {
        return $this->hp;
    }

    /**
     * 合成最大生命值：基础 maxHp + 装备 maxHp 加成 + 属性临时修正和（D6 聚合口径 + R3 玩法批临时修正）。
     * The composed maximum hp: base maxHp + the equipment maxHp bonus + the summed temporary attribute modifiers
     * (the D6 aggregation contract plus the R3 gameplay batch's temporary modifiers).
     */
    public function maxHp(): int
    {
        return $this->maxHp
            + ($this->equipment?->attributeBonus('maxHp') ?? 0)
            + ($this->attributeModifiers['maxHp'] ?? 0);
    }

    /**
     * 把当前 hp 收敛进合成上限（装备变更后的不变量维护点）。
     * Clamps the current hp into the composed ceiling (the invariant-maintenance point after equipment changes).
     */
    public function clampHpToMax(): void
    {
        $this->hp = min($this->hp, $this->maxHp());
    }

    /**
     * 模板方法：扣血钳制归零；从存活→死亡的那次伤害触发一次 onDeath。
     * Template method: damage is clamped to zero; the single hit that drops hp to zero triggers onDeath exactly once.
     *
     * @param int $amount 伤害量 The damage amount.
     */
    final public function takeDamage(int $amount): void
    {
        if ($amount <= 0 || $this->hp <= 0) {
            return; // 无效伤害/已死：幂等短路，不重复结算 Invalid damage / already dead: idempotent short-circuit, no repeated settlement.
        }
        $this->hp = max(0, $this->hp - $amount);
        $this->onDamaged($amount);
        if ($this->hp === 0) {
            $this->onDeath();
        }
    }

    /**
     * 治疗：恢复生命值，钳制在合成上限内；已死不复活。
     * Heal: restore hit points clamped to the composed ceiling; the dead are not revived.
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

    public function isDead(): bool
    {
        return $this->hp <= 0;
    }

    /**
     * 模板方法：每帧统一入口，交由子类 onTick 钩子实现具体帧逻辑。
     * Template method: per-frame entry point delegating frame logic to the onTick hook.
     */
    final public function update(): void
    {
        $this->onTick();
    }

    /**
     * 每帧钩子：子类覆盖实现冷却递减等帧逻辑。
     * Per-frame hook: subclasses override for cooldown decay and other frame logic.
     */
    protected function onTick(): void
    {
    }

    /**
     * 受伤钩子：每次有效扣血触发。
     * Damage hook: invoked on each effective damage taken.
     *
     * @param int $amount 本次实际造成的伤害量 The amount of damage dealt this hit.
     */
    protected function onDamaged(int $amount): void
    {
    }

    /**
     * 死亡结算钩子：takeDamage 归零时幂等触发一次（从存活→死亡那次）。
     * Death settlement hook: triggered idempotently when takeDamage zeroes hp (the transition hit).
     */
    protected function onDeath(): void
    {
    }
}
