<?php

declare(strict_types=1);

namespace Nythros\Framework;

use InvalidArgumentException;
use Nythros\Actor\BaseActor;
use Nythros\Framework\Actor\TickCadence;

/**
 * 怪物基类：AI 状态机骨架 + 最小战斗面；takeDamage 模板方法内闭环死亡结算。
 * Base monster class: AI state machine skeleton plus the minimal combat surface; death
 * settlement is closed inside the takeDamage template method.
 */
abstract class BaseMonster extends BaseActor implements Damageable
{
    use TickCadence;

    public const STATE_PATROL = 'patrol';

    public const STATE_CHASE = 'chase';

    public const STATE_ATTACK = 'attack';

    public const STATE_DEAD = 'dead';

    /**
     * 合法 AI 状态白名单：enterState 迁移校验用。
     * The legal AI state whitelist used for enterState transition validation.
     *
     * @var list<string>
     */
    private const VALID_STATES = [self::STATE_PATROL, self::STATE_CHASE, self::STATE_ATTACK, self::STATE_DEAD];

    protected int $hp;

    protected int $maxHp;

    protected string $aiState = self::STATE_PATROL;

    protected ?string $targetId = null;

    /** 最近一次有效伤害来源实体 id（击杀归属绑定依据；null = 尚未被命中） The entity id of the last effective damage source (the kill-ownership binding basis; null = never hit yet). */
    private ?string $lastAttackerId = null;

    /**
     * 伤害账本（P13 AoE 多源归属）：attackerId => 累计有效伤害——按攻击者聚合的参与记录，
     * 供击杀归属裁决（damage_leader）与多源统计消费；插入序保留（damage_leader 平局取先达）。
     * The damage ledger (the P13 AoE multi-source attribution): attackerId => cumulative effective damage —
     * a per-attacker participation record consumed by the kill-credit ruling (damage_leader) and multi-source
     * statistics; insertion order is preserved (damage_leader ties go to the first to reach).
     *
     * @var array<string, int>
     */
    private array $damageLedger = [];

    /**
     * @param string $monsterId 怪物唯一标识 Monster unique id.
     * @param int $maxHp 最大生命值 Maximum hit points.
     * @param string $typeId 怪物类型 id（任务击杀匹配/造型标识；缺省 '' = 未指定） Monster type id (quest kill
     *   matching / visual identity; default '' = unspecified).
     */
    public function __construct(private readonly string $monsterId, int $maxHp, private readonly string $typeId = '')
    {
        $this->maxHp = $maxHp;
        $this->hp = $maxHp;
    }

    public function monsterId(): string
    {
        return $this->monsterId;
    }

    /**
     * 怪物类型 id（如 'wolf'）：任务击杀进度源的匹配键；未指定时为空串。
     * The monster type id (e.g. 'wolf'): the quest kill-progress matching key; empty when unspecified.
     */
    public function typeId(): string
    {
        return $this->typeId;
    }

    public function hp(): int
    {
        return $this->hp;
    }

    public function maxHp(): int
    {
        return $this->maxHp;
    }

    public function aiState(): string
    {
        return $this->aiState;
    }

    public function targetId(): ?string
    {
        return $this->targetId;
    }

    /**
     * 设置/清除追击目标。
     * Sets or clears the chase target.
     *
     * @param ?string $targetId 目标实体标识；null 表示清除 The target entity id; null clears it.
     */
    public function setTarget(?string $targetId): void
    {
        $this->targetId = $targetId;
    }

    /**
     * 记录伤害来源（击杀归属绑定）：每次有效扣血前由结算方调用，死亡时以最后来源为击杀者。
     * Records the damage source (kill-ownership binding): invoked by the settlement side before each effective
     * damage; the last source at death is the killer.
     */
    public function noteAttacker(string $attackerId): void
    {
        $this->lastAttackerId = $attackerId;
    }

    /**
     * 最近一次伤害来源实体 id；未被命中过返回 null。
     * The entity id of the last damage source; null when never hit.
     */
    public function lastAttacker(): ?string
    {
        return $this->lastAttackerId;
    }

    /**
     * 记入伤害账本（P13 多源归属）：每次有效扣血前由结算方按伤害量累加（非负钳制，0 伤害不入账）。
     * Books damage into the ledger (the P13 multi-source attribution): the settlement side accumulates the
     * amount before each effective damage (clamped non-negative; zero damage books nothing).
     */
    public function noteDamage(string $attackerId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $this->damageLedger[$attackerId] = ($this->damageLedger[$attackerId] ?? 0) + $amount;
    }

    /**
     * 伤害账本快照（按累计伤害降序；平局按先达序——arsort 保持键序稳定性由插入序保证）。
     * The damage-ledger snapshot (sorted by cumulative damage descending; ties keep insertion/first-reached order).
     *
     * @return list<array{attackerId: string, damage: int}>
     */
    public function damageContributors(): array
    {
        $ledger = $this->damageLedger;
        arsort($ledger);
        $contributors = [];
        foreach ($ledger as $attackerId => $damage) {
            $contributors[] = ['attackerId' => $attackerId, 'damage' => $damage];
        }

        return $contributors;
    }

    /**
     * 伤害账本最高者（击杀归属 damage_leader 裁决；空账本返回 null；平局取先达）。
     * The damage-ledger leader (the damage_leader kill-credit ruling; null on an empty ledger; ties take the first reached).
     */
    public function damageLeader(): ?string
    {
        foreach ($this->damageContributors() as $contributor) {
            return $contributor['attackerId'];
        }

        return null;
    }

    /**
     * 状态迁移：白名单校验，非法状态抛 InvalidArgumentException；DEAD 为终态，不再迁出。
     * State transition: whitelist-validated; illegal states throw InvalidArgumentException; DEAD is terminal and never left.
     *
     * @param string $state 目标 AI 状态 The target AI state.
     */
    public function enterState(string $state): void
    {
        if (!in_array($state, self::VALID_STATES, true)) {
            throw new InvalidArgumentException(sprintf('非法 AI 状态: %s', $state));
        }
        if ($this->aiState === self::STATE_DEAD) {
            return; // DEAD 为终态：不再迁出 DEAD is terminal: no further transitions leave it.
        }
        $this->aiState = $state;
    }

    /**
     * 模板方法：扣血钳制归零；归零时迁移 DEAD 并幂等触发一次 onDeath。
     * Template method: damage is clamped to zero; on zero, transitions to DEAD and triggers onDeath idempotently.
     *
     * @param int $amount 伤害量 The damage amount.
     */
    final public function takeDamage(int $amount): void
    {
        if ($amount <= 0 || $this->hp <= 0) {
            return; // 已死/无效伤害：幂等短路，不重复结算 Already dead / invalid damage: idempotent short-circuit, no repeated settlement.
        }
        $this->hp = max(0, $this->hp - $amount);
        $this->onDamaged($this->lastAttackerId, $amount); // 受击钩子（R4 mmorpg 威胁表接入点） Hit hook (the R4 mmorpg threat-table hook point).
        if ($this->hp === 0) {
            $this->enterState(self::STATE_DEAD);
            $this->onDeath(); // 死亡结算：仅存活→死亡那次触发一次 Death settlement: triggered once on the transition hit.
        }
    }

    /**
     * 治疗：恢复生命值，钳制在 maxHp 内；已死不复活。
     * Heal: restore hit points clamped to maxHp; the dead are not revived.
     *
     * @param int $amount 治疗量 The heal amount.
     */
    public function heal(int $amount): void
    {
        if ($amount <= 0 || $this->hp <= 0) {
            return;
        }
        $this->hp = min($this->maxHp, $this->hp + $amount);
    }

    public function isDead(): bool
    {
        return $this->hp <= 0;
    }

    /**
     * 模板方法：按 aiState 分发钩子；DEAD 每帧只走 onDead，onDeath 仅在死亡瞬间触发一次。
     * Template method: dispatches hooks by aiState; DEAD only runs onDead each frame, while onDeath fires once at the death moment.
     */
    final public function update(): void
    {
        // tick 分频门（P9a 区域降频）：非到期帧跳过整个 AI 节拍——攻击冷却在 onAttack 内逐帧递减，
        // 分频下自然随档位降载（降档 = 行动更少，比例正确）；分频为 1 时零开销直通。
        // The tick-cadence gate (the P9a region downgrade): non-due frames skip the whole AI beat — the
        // attack cooldown decrements per onAttack, so a downgraded tier naturally sheds load proportionally
        // (a lower tier = fewer actions, exactly proportional); divisor 1 is a zero-overhead passthrough.
        if (!$this->pollCadence()) {
            return;
        }
        match ($this->aiState) {
            self::STATE_PATROL => $this->onPatrol(),
            self::STATE_CHASE => $this->onChase(),
            self::STATE_ATTACK => $this->onAttack(),
            self::STATE_DEAD => $this->onDead(),
            default => throw new InvalidArgumentException(sprintf('非法 AI 状态: %s', $this->aiState)),
        };
    }

    /**
     * 巡逻钩子：子类实现感知与随机/路径点巡逻。
     * Patrol hook: subclasses implement perception and random/waypoint patrol.
     */
    protected function onPatrol(): void
    {
    }

    /**
     * 追击钩子：子类实现目标丢失判定与朝目标移动。
     * Chase hook: subclasses implement target-lost checks and movement toward the target.
     */
    protected function onChase(): void
    {
    }

    /**
     * 攻击钩子：子类实现冷却判定与攻击结算。
     * Attack hook: subclasses implement cooldown checks and attack settlement.
     */
    protected function onAttack(): void
    {
    }

    /**
     * 死亡帧钩子：DEAD 状态下每帧调用。
     * Death frame hook: invoked every frame while in the DEAD state.
     */
    protected function onDead(): void
    {
    }

    /**
     * 死亡结算钩子（掉落等）：takeDamage 归零时幂等触发一次。
     * Death settlement hook (drops etc.): triggered idempotently when takeDamage zeroes hp.
     */
    protected function onDeath(): void
    {
    }

    /**
     * 受击钩子（R4 mmorpg 威胁表接入点）：每次有效扣血后调用，携带最近一次伤害来源实体 id
     * （null = 尚未被命中）。默认空实现——既有怪物行为零变化；MonsterActor 覆写接入威胁表。
     * Hit hook (the R4 mmorpg threat-table hook point): invoked after every effective damage, carrying the last
     * damage-source entity id (null = never hit). The default is an empty implementation — existing monster
     * behavior stays unchanged; MonsterActor overrides it to feed the threat table.
     *
     * @param ?string $attackerId 最近一次伤害来源实体 id The entity id of the last damage source.
     * @param int $amount 本次伤害量 This hit's damage amount.
     */
    protected function onDamaged(?string $attackerId, int $amount): void
    {
    }
}
