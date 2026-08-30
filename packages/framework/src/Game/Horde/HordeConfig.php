<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

/**
 * horde 玩法参数化配置（R4 类型模块试点，ADR-020 §4）：波次刷怪定义、房间容量与 tick 周期、
 * AoE 半径上限、掉落风暴配置、出生保护参数与结算规则的只读聚合——framework 提供参数与规则，
 * starter-kit（RoomHub/MapChannelFactory）装配消费。::default() 与既有 demo 常量逐值对齐
 * （迁移期行为零变化）。
 * Horde gameplay parameterization (the R4 type-module pilot, ADR-020 §4): a readonly aggregate of wave spawn
 * definitions, room capacity and tick period, the AoE radius cap, drop-storm config, spawn-protection parameters
 * and settlement rules — the framework provides parameters and rules, the starter kit (RoomHub /
 * MapChannelFactory) assembles and consumes them. ::default() aligns value-for-value with the former demo
 * constants (zero behavior change through the migration).
 */
final class HordeConfig
{
    /**
     * @param list<WaveDefinition> $waves 波次刷怪定义（每波一份网格布局与战斗参数；至少一波——空列表在
     *   构造期拒绝，见下） Wave spawn definitions (one grid layout plus combat parameters per wave; at least one —
     *   an empty list is rejected at construction, see below).
     * @param int $periodMs 房间 tick 周期（毫秒，ADR-024 §D-B horde 50ms） Room tick period in milliseconds (the horde 50ms of ADR-024 §D-B).
     * @param int $maxMembers 成员上限（仅约束 join 路径的受管成员，ADR-024 §9 V4） Member cap (managed join-path members only, ADR-024 §9 V4).
     * @param int $aoeMaxRadius room:aoe 半径业务上限（认证后 DoS 防线，R2 审查 MAJOR-1） The room:aoe radius business cap (the post-auth DoS line, R2 review MAJOR-1).
     *
     * @throws \InvalidArgumentException waves 为空时（reviewer MINOR-3：「至少一波」是本聚合的不变量，
     *   装配期 fail-fast——handleSpawn 等消费点的 waves[0] 依赖此前置；运行期 400 会把配置错误延迟到
     *   首次刷怪才暴露且把不变量责任泄漏到路由层）
     *   When $waves is empty (reviewer MINOR-3: "at least one wave" is this aggregate's invariant, fail-fast at
     *   assembly time — consumers such as handleSpawn's waves[0] rely on it; a runtime 400 would defer the config
     *   error to the first spawn and leak the invariant's duty into the routing layer).
     */
    public function __construct(
        public readonly array $waves,
        public readonly int $periodMs = 50,
        public readonly int $maxMembers = 512,
        public readonly int $aoeMaxRadius = 300,
        public readonly DropStormConfig $dropStorm = new DropStormConfig(),
        public readonly SpawnProtectionConfig $spawnProtection = new SpawnProtectionConfig(),
        public readonly SettlementRules $settlement = new SettlementRules(),
    ) {
        if ($waves === []) {
            throw new \InvalidArgumentException('horde 配置至少需要一个波次定义 / horde config requires at least one wave definition');
        }
    }

    /**
     * 缺省配置：与 RoomHub 迁移前常量逐值一致（网格 x∈[24,62] y 起点 -24 步距 2、怪 maxHp=12、
     * 半径上限 300、掉落寿命 300s、出生保护 60 帧）。
     * The default config: value-for-value identical to RoomHub's pre-migration constants (grid x in [24,62],
     * y start -24, step 2; monster maxHp 12; radius cap 300; drop lifetime 300s; spawn protection 60 frames).
     */
    public static function default(): self
    {
        return new self(
            waves: [new WaveDefinition(count: 200, monsterMaxHp: 12, gridStartX: 24, gridStartY: -24, columns: 20, step: 2)],
        );
    }
}
