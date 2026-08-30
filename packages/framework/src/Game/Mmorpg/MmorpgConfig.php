<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

use Nythros\Framework\Quest\QuestChain;

/**
 * mmorpg 玩法参数化配置（R4 类型模块试点，ADR-020 §4）：威胁/仇恨参数组（aggroRange 进入仇恨列表距离、
 * threatDecayPerSec 每秒衰减、tauntMultiplier 嘲讽倍率、maxThreat 威胁上限）、世界怪物重生参数组
 * （respawnMs 死亡后重生延迟、spawnDensity 重生密度）与任务链配置（questChains，Quest 子系统消费）的只读聚合——
 * framework 提供参数与规则，starter-kit（MapChannelFactory/MapServer）装配消费。::default() 提供
 * mmorpg 开关开启时的缺省参数（威胁不衰减、无上限、5s 重生，与既有世界侧行为对齐）。
 * The mmorpg gameplay parameterization (the R4 type-module pilot, ADR-020 §4): a readonly aggregate of the
 * threat/hate parameter group (aggroRange — the distance to enter the hate list, threatDecayPerSec — decay per
 * second, tauntMultiplier — the taunt multiplier, maxThreat — the threat cap), the world monster-respawn
 * parameter group (respawnMs — the death-to-respawn delay, spawnDensity — the respawn density) and the quest-chain
 * configuration (questChains, consumed by the Quest subsystem) — the framework provides parameters and rules, the
 * starter kit (MapChannelFactory/MapServer) assembles and consumes them. ::default() supplies the defaults for
 * when the mmorpg switch is on (no threat decay, no cap, 5s respawn — aligned with the existing world-side behavior).
 */
final class MmorpgConfig
{
    /** 击杀归属裁决：最后一击来源（接入前语义，缺省）。 The kill-credit ruling: the last-hit source (the pre-integration semantics, the default). */
    public const KILL_CREDIT_LAST_HIT = 'last_hit';

    /** 击杀归属裁决：伤害账本最高者（P13 AoE 多源归属；平局取先达）。 The kill-credit ruling: the damage-ledger leader (the P13 AoE multi-source attribution; ties go to the first to reach). */
    public const KILL_CREDIT_DAMAGE_LEADER = 'damage_leader';

    /**
     * @param int $aggroRange 进入仇恨列表的距离（世界单位）：攻击者距受击怪物超过该距离不记威胁。
     *   The distance to enter the hate list (world units): attackers beyond it from the hit monster gain no threat.
     * @param float $threatDecayPerSec 威胁每秒衰减量（threat - decay × dt，钳制非负；0 = 不衰减）。
     *   The per-second threat decay (threat - decay × dt, clamped non-negative; 0 = no decay).
     * @param float $tauntMultiplier 嘲讽倍率（applyTaunt 的威胁提升倍数）。 The taunt multiplier (the threat boost of applyTaunt).
     * @param int $maxThreat 威胁上限（0 = 无上限）。 The threat cap (0 = unlimited).
     * @param int $respawnMs 怪物死亡后重生延迟（毫秒）。 The monster death-to-respawn delay in milliseconds.
     * @param int $spawnDensity 重生密度（每锚点重生数量）。 The respawn density (monsters per anchor).
     * @param int $playerRespawnMs 玩家自动复活延迟（毫秒；0 = 关闭，复活仅路由驱动——P5a 语义）。 The player
     *   auto-revive delay in milliseconds (0 = off, revive stays route-driven — the P5a semantics).
     * @param array{x: int, y: int, radius: int}|null $safeZone 出生安全区（圆心 + 半径；null = 未声明）：
     *   区内玩家对怪物 AI 不可见（感知/攻击跳过、威胁与嘲讽写入忽略、仇恨列表清理剔除）——显式替代
     *   「怪锚点外移避开出生格」的隐式约定。装配层应与出生点对齐。P13 扩展：区内同样禁 PVP（见 pvpEnabled）。
     *   The spawn safe zone (center + radius; null = undeclared): players inside are invisible to monster AI
     *   (perception/attacks skip, threat & taunt writes ignored, hate-list purge removes them) — an explicit
     *   replacement for the implicit "relocate monster anchors away from the spawn cell" convention. The
     *   assembly layer should align it with the spawn point. P13 extension: the zone also bans PVP (see pvpEnabled).
     * @param int $attackRange 怪物攻击距离（世界单位；0 = 缺省口径——视野命中即命中，行为与接入前逐字节等价）：
     *   正值时在视野命中之上叠加欧氏距离上限（CHASE→ATTACK 的进入判定与攻击结算共用）。
     *   The monster attack range (world units; 0 = the default convention — a view hit is a hit, byte-for-byte
     *   equivalent to the pre-integration behavior): a positive value stacks a Euclidean cap on top of the view
     *   hit (shared by the CHASE→ATTACK entry judgment and attack settlement).
     * @param HotCellPolicy|null $hotCell 热区策略（P9a 区域降频；null = 未启用，实体恒逐帧）。
     *   The hot-cell policy (the P9a region downgrade; null = off, entities always update per frame).
     * @param list<QuestChain> $questChains 任务链配置（链式任务聚合，Quest 模块消费）。 The quest-chain config (chained-quest aggregation, consumed by the Quest module).
     * @param DeathDropPolicy|null $deathDrop 死亡掉落策略（P13；null = 关闭——玩家死亡不掉落，接入前语义）。
     *   The death-drop policy (the P13; null = off — player deaths drop nothing, the pre-integration semantics).
     * @param bool $pvpEnabled PVP 开关（P13 对抗治理；false = 玩家间攻击路由拒绝 pvp_disabled——接入前
     *   玩家间攻击事实上可行，治理裁决缺省关闭，安全区/出生保护语义齐备后由部署显式开启）。
     *   The PVP switch (the P13 combat governance; false = player-vs-player attack routes reject with
     *   pvp_disabled — PVP was de-facto possible pre-integration, and the governance ruling defaults it off
     *   pending explicit deployment opt-in now that safe-zone/spawn-protection semantics exist).
     * @param string $killCredit 击杀归属裁决（P13 AoE 多源归属）：KILL_CREDIT_LAST_HIT = 最后一击来源
     *   （接入前语义，缺省零行为变化）；KILL_CREDIT_DAMAGE_LEADER = 伤害账本最高者（平局取先达）。
     *   The kill-credit ruling (the P13 AoE multi-source attribution): KILL_CREDIT_LAST_HIT = the last-hit
     *   source (the pre-integration semantics, the zero-change default); KILL_CREDIT_DAMAGE_LEADER = the
     *   damage-ledger leader (ties go to the first to reach).
     *
     * @throws \InvalidArgumentException 任一不变量被违反时（aggroRange/respawnMs/spawnDensity 必须为正、
     *   threatDecayPerSec/tauntMultiplier/maxThreat 必须非负、killCredit 须为白名单值）——装配期 fail-fast，
     *   把配置错误延迟到运行期才暴露会把不变量责任泄漏到消费点。
     *   When any invariant is violated (aggroRange/respawnMs/spawnDensity must be positive,
     *   threatDecayPerSec/tauntMultiplier/maxThreat must be non-negative, killCredit must be whitelisted) —
     *   fail-fast at assembly time; deferring config errors to runtime would leak the invariant's duty into consumers.
     */
    public function __construct(
        public readonly int $aggroRange = 10,
        public readonly float $threatDecayPerSec = 0.0,
        public readonly float $tauntMultiplier = 1.0,
        public readonly int $maxThreat = 0,
        public readonly int $respawnMs = 5000,
        public readonly int $spawnDensity = 1,
        public readonly int $playerRespawnMs = 0,
        public readonly ?array $safeZone = null,
        public readonly int $attackRange = 0,
        public readonly ?HotCellPolicy $hotCell = null,
        public readonly array $questChains = [],
        public readonly ?DeathDropPolicy $deathDrop = null,
        public readonly bool $pvpEnabled = false,
        public readonly string $killCredit = self::KILL_CREDIT_LAST_HIT,
    ) {
        if ($this->aggroRange <= 0) {
            throw new \InvalidArgumentException('mmorpg 配置 aggroRange 必须为正 / mmorpg config requires a positive aggroRange');
        }
        if ($this->threatDecayPerSec < 0) {
            throw new \InvalidArgumentException('mmorpg 配置 threatDecayPerSec 必须非负 / mmorpg config requires a non-negative threatDecayPerSec');
        }
        if ($this->tauntMultiplier < 0) {
            throw new \InvalidArgumentException('mmorpg 配置 tauntMultiplier 必须非负 / mmorpg config requires a non-negative tauntMultiplier');
        }
        if ($this->maxThreat < 0) {
            throw new \InvalidArgumentException('mmorpg 配置 maxThreat 必须非负（0 = 无上限） / mmorpg config requires a non-negative maxThreat (0 = unlimited)');
        }
        if ($this->respawnMs <= 0) {
            throw new \InvalidArgumentException('mmorpg 配置 respawnMs 必须为正 / mmorpg config requires a positive respawnMs');
        }
        if ($this->spawnDensity <= 0) {
            throw new \InvalidArgumentException('mmorpg 配置 spawnDensity 必须为正 / mmorpg config requires a positive spawnDensity');
        }
        if ($this->playerRespawnMs < 0) {
            throw new \InvalidArgumentException('mmorpg 配置 playerRespawnMs 必须非负（0 = 关闭自动复活） / mmorpg config requires a non-negative playerRespawnMs (0 = auto-revive off)');
        }
        if ($this->safeZone !== null && $this->safeZone['radius'] <= 0) {
            throw new \InvalidArgumentException('mmorpg 配置 safeZone radius 必须为正 / mmorpg config requires a positive safeZone radius');
        }
        if ($this->attackRange < 0) {
            throw new \InvalidArgumentException('mmorpg 配置 attackRange 必须非负（0 = 视野口径） / mmorpg config requires a non-negative attackRange (0 = the view convention)');
        }
        if (!in_array($this->killCredit, [self::KILL_CREDIT_LAST_HIT, self::KILL_CREDIT_DAMAGE_LEADER], true)) {
            throw new \InvalidArgumentException('mmorpg 配置 killCredit 必须为 last_hit|damage_leader 之一 / mmorpg config requires killCredit to be last_hit or damage_leader');
        }
    }

    /**
     * 缺省配置：威胁不衰减（threatDecayPerSec=0）、无上限（maxThreat=0）、嘲讽倍率 1.0、aggroRange 10
     * （与 MonsterActor 缺省巡逻半径同量级，AOI cellSize 10 → ±10 单位）、重生延迟 5s、密度 1、无任务链。
     * The default config: no threat decay (threatDecayPerSec=0), no cap (maxThreat=0), taunt multiplier 1.0,
     * aggroRange 10 (same magnitude as MonsterActor's default patrol radius; AOI cellSize 10 → ±10 units),
     * 5s respawn delay, density 1 and no quest chains.
     */
    public static function default(): self
    {
        return new self();
    }
}
