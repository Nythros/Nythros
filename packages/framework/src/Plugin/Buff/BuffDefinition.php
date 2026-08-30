<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Buff;

/**
 * Buff 定义：纯数据值对象。R3 玩法批正式化：effects 从占位描述升级为结构化约定键，
 * 并新增叠加规则三元组（stackRule/maxStacks/mutexGroup）供 BuffService 状态机裁决。
 * Buff definition: a plain-data value object. Formalized in the R3 gameplay batch: effects upgrades from a
 * placeholder description to structured convention keys, plus the stacking-rule triple (stackRule/maxStacks/
 * mutexGroup) adjudicated by the BuffService state machine.
 *
 * effects 约定键（BuffService 消费口径）：
 * - attributes: array<string, int> 属性修正表（属性名 => 每层整数增量，如 ['maxHp' => 30]），随层数线性放大；
 * - dot: array{damage: int, intervalSeconds: float|int} 持续伤害（每 intervalSeconds 结算一次 damage）。
 * effects convention keys (consumed by BuffService):
 * - attributes: array<string, int> attribute modifiers (attribute name => per-stack integer delta, e.g.
 *   ['maxHp' => 30]), scaling linearly with stacks;
 * - dot: array{damage: int, intervalSeconds: float|int} damage-over-time (deals damage every intervalSeconds).
 */
final readonly class BuffDefinition
{
    /** 叠加规则：重复施加刷新剩余时长（层数不变）。 Stack rule: re-application refreshes the remaining duration (stacks unchanged). */
    public const STACK_REFRESH = 'refresh';

    /** 叠加规则：重复施加叠层（封顶 maxStacks）并刷新时长。 Stack rule: re-application adds a stack (capped at maxStacks) and refreshes the duration. */
    public const STACK_STACK = 'stack';

    /** effects 键：属性修正表。 The effects key: attribute modifiers. */
    public const EFFECT_ATTRIBUTES = 'attributes';

    /** effects 键：持续伤害配置。 The effects key: damage-over-time configuration. */
    public const EFFECT_DOT = 'dot';

    /**
     * @param string $id Buff 唯一 id Unique buff id.
     * @param string $name Buff 名 Buff name.
     * @param float $durationSeconds 持续时间（秒，必须为正） Duration in seconds (must be positive).
     * @param array<string, mixed> $effects 效果表（约定键见类注释） Effect table (convention keys in the class docblock).
     * @param string $stackRule 叠加规则（refresh|stack） Stacking rule (refresh|stack).
     * @param int $maxStacks 最大层数下限保护（<1 按 1 处理） Maximum stacks (values below 1 are treated as 1).
     * @param ?string $mutexGroup 互斥组 id：同组 buff 在同一宿主上互斥，新施加顶替旧实例（null = 不参与互斥）
     *   Mutex-group id: buffs sharing a group are mutually exclusive on one host; a new application displaces the
     *   old instance (null = not part of any group).
     */
    public function __construct(
        public string $id,
        public string $name,
        public float $durationSeconds,
        public array $effects,
        public string $stackRule = self::STACK_REFRESH,
        public int $maxStacks = 1,
        public ?string $mutexGroup = null,
    ) {
    }

    /**
     * 属性修正表（effects.attributes；缺失返回空表）。
     * The attribute-modifier table (effects.attributes; empty when absent).
     *
     * @return array<string, int>
     */
    public function attributeModifiers(): array
    {
        $attributes = $this->effects[self::EFFECT_ATTRIBUTES] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * DOT 配置（effects.dot；缺失返回 null）。
     * The DOT configuration (effects.dot; null when absent).
     *
     * @return array{damage: int, intervalSeconds: float}|null
     */
    public function dot(): ?array
    {
        $dot = $this->effects[self::EFFECT_DOT] ?? null;
        if (!is_array($dot) || !isset($dot['damage']) || !is_int($dot['damage'])) {
            return null;
        }
        $interval = $dot['intervalSeconds'] ?? 0;
        if (!is_int($interval) && !is_float($interval)) {
            return null;
        }

        return ['damage' => $dot['damage'], 'intervalSeconds' => (float) $interval];
    }
}
