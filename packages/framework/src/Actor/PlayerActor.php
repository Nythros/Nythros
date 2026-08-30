<?php

declare(strict_types=1);

namespace Nythros\Framework\Actor;

use Nythros\Framework\BasePlayer;
use Nythros\Framework\Combat\VisionBroadcasterInterface;

/**
 * 玩家 Actor：继承 BasePlayer，承载玩家身份与最小战斗面；钩子实现冷却递减、属性同步与死亡标记。
 * Player actor: extends BasePlayer, carrying the player identity and the minimal combat surface; hook implementations for cooldown decay, stat sync and death marking.
 */
final class PlayerActor extends BasePlayer
{
    /** 攻击冷却（帧数）：每次攻击后需等待该帧数方可再次攻击。Attack cooldown in frames: the frames to wait after each attack before attacking again. */
    private const ATTACK_COOLDOWN_FRAMES = 5;

    /**
     * 出生保护窗口（帧数）：enableSpawnProtection 激活后的无敌时长，按 50ms 基准 tick 折算
     * （60 帧 ≈ 3s）。保护期内怪物感知/攻击跳过该玩家（见 MonsterActor），防止出生格被怪锚点覆盖时
     * 登录瞬间即被集火打空血量（verify-room/verify-matching 实测踩坑）。
     * Spawn-protection window in frames: the invulnerable duration once enableSpawnProtection activates,
     * converted on the 50ms base tick (60 frames ≈ 3s). While protected, monster perception/attacks skip this
     * player (see MonsterActor), preventing the measured pitfall of being focused down at the login instant when
     * the spawn cell is covered by monster anchors (verify-room / verify-matching measured pitfalls).
     */
    public const SPAWN_PROTECTION_FRAMES = 60;

    /** 当前攻击冷却剩余帧数 Remaining attack-cooldown frames. */
    private int $attackCooldown = 0;

    /** 是否处于待复活状态（玩家死亡标记） Whether awaiting revive (the player-death marker). */
    private bool $awaitingRevive = false;

    /** 出生保护剩余帧数；0 = 无保护（缺省，显式激活后才开始倒数） Remaining spawn-protection frames; 0 = unprotected (default; the countdown starts only after explicit activation). */
    private int $spawnProtection = 0;

    /**
     * @param string $entityId 玩家实体 id（即 playerId） Player entity id (i.e. the playerId).
     * @param ?VisionBroadcasterInterface $broadcaster 属性同步定向广播（MapServer 注入）；null = 不广播（单测） Stat-sync directed broadcaster (injected by MapServer); null = no broadcast (unit tests).
     */
    public function __construct(
        private readonly string $entityId,
        private readonly ?VisionBroadcasterInterface $broadcaster = null,
    ) {
    }

    /**
     * 返回玩家实体 id（MapServerTest 依赖）。
     * Returns the player entity id (relied upon by MapServerTest).
     */
    public function getPlayerId(): string
    {
        return $this->entityId;
    }

    /**
     * 返回玩家实体 id（与 getPlayerId 等价，供 CombatService 解析 id）。
     * Returns the player entity id (equivalent to getPlayerId, used by CombatService for id resolution).
     */
    public function entityId(): string
    {
        return $this->entityId;
    }

    /**
     * 当前攻击冷却剩余帧数。
     * The remaining attack-cooldown frames.
     */
    public function attackCooldown(): int
    {
        return $this->attackCooldown;
    }

    /**
     * 是否可发起攻击（冷却已归零）。
     * Whether an attack may be initiated (cooldown expired).
     */
    public function isAttackReady(): bool
    {
        return $this->attackCooldown <= 0;
    }

    /**
     * 开始攻击冷却（攻击成功后由调用方触发）。
     * Starts the attack cooldown (invoked by the caller after a successful attack).
     */
    public function startAttackCooldown(): void
    {
        $this->attackCooldown = self::ATTACK_COOLDOWN_FRAMES;
    }

    /**
     * 是否处于待复活状态。
     * Whether the player is awaiting revive.
     */
    public function isAwaitingRevive(): bool
    {
        return $this->awaitingRevive;
    }

    /**
     * 复活（P5a 接入，消费 awaitingRevive 标记）：清待复活标记并回满血——demo 玩家死亡仅状态标记，
     * 复活为满血回生（hp 收敛进合成上限）；非待复活态幂等短路（不重复回血）。
     * Revives (the P5a wiring, consuming the awaitingRevive marker): clears the marker and restores full hp —
     * demo player death is a marker only, so revival is a full restore (hp clamped into the composed ceiling);
     * an idempotent short-circuit outside the awaiting-revive state (no repeated healing).
     */
    public function revive(): void
    {
        if (!$this->awaitingRevive) {
            return;
        }
        $this->awaitingRevive = false;
        $this->hp = $this->maxHp();
    }

    /**
     * 导入血量（P15 跨 map 迁移快照重建）：clamp 进 [1, 合成 maxHp]——不迁移死亡态（快照 hp ≤0 视为
     * 1，跨图入场复活走既有 revive 语义）；装备加成在导入时点尚未挂载，合成上限即基础值。
     * Imports hp (the P15 cross-map migration snapshot rebuild): clamped into [1, the composed maxHp] —
     * death state never migrates (a snapshot hp <=0 counts as 1; a cross-map entry revival rides the
     * existing revive semantics). Equipment bonuses are not mounted at import time, so the composed
     * ceiling is the base value.
     */
    public function importHp(int $hp): void
    {
        $this->hp = max(1, min($this->maxHp(), $hp));
    }

    /**
     * 激活出生保护窗口（auth 挂载时由装配层调用）：从下一帧起倒数 frames 帧（缺省 SPAWN_PROTECTION_FRAMES，
     * 可由装配层按类型模块配置覆盖，如 Game\Horde 的 SpawnProtectionConfig）；重复调用刷新窗口（重连即重新受保护）。
     * Activates the spawn-protection window (invoked by the assembly layer on auth mount): counts down frames from
     * the next frame (default SPAWN_PROTECTION_FRAMES, overridable by assembly per type-module config such as
     * Game\Horde's SpawnProtectionConfig); repeated calls refresh the window (a reconnect re-protects).
     */
    public function enableSpawnProtection(?int $frames = null): void
    {
        $this->spawnProtection = $frames ?? self::SPAWN_PROTECTION_FRAMES;
    }

    /**
     * 是否处于出生保护期（怪物感知/攻击跳过依据）。
     * Whether the player is inside the spawn-protection window (the monster perception/attack skip basis).
     */
    public function isSpawnProtected(): bool
    {
        return $this->spawnProtection > 0;
    }

    /**
     * 每帧钩子：攻击冷却递减 + 出生保护倒数。
     * Per-frame hook: attack-cooldown decay plus spawn-protection countdown.
     */
    protected function onTick(): void
    {
        // 出生保护不门控（P9a）：安全窗口恒按 base tick 走 wall-time，降档不延长无敌。
        // Spawn protection is never gated (the P9a): the safety window always elapses on base ticks —
        // a downgraded tier never extends invulnerability.
        if ($this->spawnProtection > 0) {
            $this->spawnProtection--;
        }
        // 攻击冷却门控（P9a 区域降频）：热区分档下冷却按「到期帧」递减——降档玩家攻击率随档位比例
        // 降载（pollCadence 在非到期帧返回 false，不递减）。
        // The attack-cooldown gate (the P9a region downgrade): in a hot tier the cooldown decrements only
        // on due frames — a downgraded player's attack rate sheds proportionally with the tier
        // (pollCadence returns false on non-due frames, skipping the decrement).
        if ($this->pollCadence() && $this->attackCooldown > 0) {
            $this->attackCooldown--;
        }
    }

    /**
     * 受伤钩子：定向广播 player:stats 属性同步帧（携带最新 hp/maxHp）。
     * Damage hook: directed player:stats stat-sync frame (carrying the latest hp/maxHp).
     */
    protected function onDamaged(int $amount): void
    {
        // id 字段供 frameMerger 同帧状态帧去重（player:stats 为 STATE 帧，以实体 id 为替换键）
        // The id field feeds the frameMerger's same-frame STATE dedup (player:stats is a STATE frame keyed by entity id)
        $this->broadcaster?->sendToEntity($this->entityId, 'player:stats', [
            'id' => $this->entityId,
            'hp' => $this->hp(),
            'maxHp' => $this->maxHp(),
        ]);
    }

    /**
     * 死亡结算钩子：标记待复活（demo 阶段简化，仅状态标记）。
     * Death settlement hook: marks the player as awaiting revive (demo-stage simplification, a state marker only).
     */
    protected function onDeath(): void
    {
        $this->awaitingRevive = true;
    }
}
