<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Skill;

/**
 * 技能定义：纯数据值对象，统一公式占位（demo 阶段不引入每技能独立类）。
 * R3 玩法批扩展（受控破坏性变更）：新增 AoE 形状描述与施法消耗两组可选字段——
 * - $aoe 为形状参数（非 ShapeInterface 引用）：本类是纯数据 readonly 值对象（与 ItemDefinition 同风格），
 *   持有引擎形状实例会把「定义」耦合到施法时构造的查询原语（同一实例跨多次施法共享引用、无法数据化比较）；
 *   形状查询归引擎原语（queryShape），定义侧只声明 shape/radius 参数，施法路径按需构造 CircleShape 等值对象。
 *   null = 单体技能（castSkill 路径）；非 null = 具备 AoE 施法能力（castSkillAoE 路径可用其作缺省形状）。
 * - 消耗三字段：mp 为法力消耗（0 = 无消耗）；itemId+count 为物品消耗（null id = 无物品消耗），
 *   结算口径由消费方（技能施放路由）裁决，定义侧只承载数据。
 * mmorpg 试点第二批扩展：$tauntThreat 为嘲讽威胁量（0 = 非嘲讽技能，缺省）——命中怪物时由施法路由经
 *   MonsterActor::applyTaunt 写入目标威胁表（tauntMultiplier 倍率裁决归威胁表，定义侧只承载数据，
 *   与 mp/itemId 消耗字段同风格）；非怪物目标上该字段静默无效果。
 * Skill definition: a plain-data value object with a unified formula placeholder (no per-skill classes at the demo stage).
 * R3 gameplay-batch extension (a controlled breaking change): two optional field groups are added — the AoE shape
 * description and the cast costs. $aoe carries shape parameters (not a ShapeInterface reference): this class is a
 * plain-data readonly value object (same style as ItemDefinition), and holding an engine shape instance would couple
 * the definition to a query primitive constructed at cast time (one instance shared across casts, not comparable as
 * data); shape queries belong to the engine primitive (queryShape), so the definition declares only shape/radius
 * parameters while the cast path constructs CircleShape-like value objects on demand. null = single-target skill
 * (the castSkill path); non-null = AoE-capable (castSkillAoE may use it as the default shape). The cost triple:
 * mp is the mana cost (0 = free); itemId+count form the item cost (null id = no item cost); settlement is ruled by
 * the consumer (the skill-cast route) — the definition only carries data.
 * The mmorpg pilot second-batch extension: $tauntThreat is the taunt threat amount (0 = not a taunt skill, the
 * default) — on a monster hit the cast route writes it into the target's threat table via MonsterActor::applyTaunt
 * (the tauntMultiplier adjudication stays inside the threat table; the definition only carries data, same style as
 * the mp/itemId cost fields); on non-monster targets the field silently has no effect.
 */
final readonly class SkillDefinition
{
    /** AoE 形状参数键：圆形（radius 生效）。 The AoE shape key: circle (radius applies). */
    public const SHAPE_CIRCLE = 'circle';

    /** AoE 形状参数键：矩形（width/height 生效）。 The AoE shape key: rectangle (width/height apply). */
    public const SHAPE_RECT = 'rect';

    /**
     * @param string $id 技能唯一 id Unique skill id.
     * @param string $name 技能名 Skill name.
     * @param float $damageMultiplier 相对普攻的伤害倍率 Damage multiplier relative to a normal attack.
     * @param float $cooldownSeconds 冷却秒数 Cooldown in seconds.
     * @param int $range 作用距离 Effective range.
     * @param array{shape: string, radius: int}|array{shape: string, width: int, height: int}|null $aoe AoE 形状参数
     *   （shape=circle 时 radius 半径；shape=rect 时 width/height 宽高；null = 单体技能） AoE shape parameters
     *   (shape=circle carries radius; shape=rect carries width/height; null = single-target skill).
     * @param int $mpCost 施法 MP 消耗（0 = 无消耗） Mana cost per cast (0 = free).
     * @param ?string $itemCostId 施法物品消耗 id（null = 无物品消耗） Item-cost id per cast (null = no item cost).
     * @param int $itemCostCount 施法物品消耗数量 Item-cost quantity per cast.
     * @param float $tauntThreat 嘲讽威胁量（0 = 非嘲讽技能；命中怪物时写入其威胁表） Taunt threat amount (0 = not
     *   a taunt skill; on a monster hit it is written into the target's threat table).
     */
    public function __construct(
        public string $id,
        public string $name,
        public float $damageMultiplier,
        public float $cooldownSeconds,
        public int $range,
        public ?array $aoe = null,
        public int $mpCost = 0,
        public ?string $itemCostId = null,
        public int $itemCostCount = 0,
        public float $tauntThreat = 0.0,
    ) {
    }
}
