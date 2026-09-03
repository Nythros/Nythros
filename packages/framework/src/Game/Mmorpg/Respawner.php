<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 世界怪物重生器（R4 mmorpg 类型模块试点）：死亡登记 → 定时重生回锚点的纯调度组件——
 * registerDeath 记录怪物 id 与重生时刻（now + respawnMs），due 返回到期可重生的怪物 id 列表，
 * 实际重生动作（spawnMonster 回锚点）由装配层（MapServer）执行。framework 提供调度规则，
 * 组装层装配消费。
 * The world monster respawner (the R4 mmorpg type-module pilot): a pure scheduling component for
 * death-registration → timed respawn back to the anchor — registerDeath records the monster id and its respawn
 * instant (now + respawnMs), due returns the ids whose respawn is due, and the actual respawn action
 * (spawnMonster back to the anchor) is executed by the assembly layer (MapServer). The framework owns the
 * scheduling rule, the assembly layer assembles and consumes it.
 */
final class Respawner
{
    /** @var array<string, float> monsterId => 重生时刻（microtime 秒） respawn instant (microtime seconds). */
    private array $queue = [];

    public function __construct(private readonly int $respawnMs)
    {
        // 构造期不变量（reviewer MINOR-3）：respawnMs 必须为正——负值/零会让死亡登记立即到期重生；
        // 与 MmorpgConfig 同口径 fail-fast（Respawner 是公开类可被直接构造，不变量不能只靠配置层保证）。
        // Construction invariant (reviewer MINOR-3): respawnMs must be positive — a zero/negative value would
        // respawn deaths immediately; fail-fast with the same convention as MmorpgConfig (Respawner is a public
        // class constructible directly, so the invariant cannot rely on the config layer alone).
        if ($respawnMs <= 0) {
            throw new \InvalidArgumentException('Respawner 构造 respawnMs 必须为正 / Respawner requires a positive respawnMs');
        }
    }

    /**
     * 死亡登记：怪物死亡时记录重生时刻（now + respawnMs / 1000）；重复登记覆盖（幂等）。
     * $overrideMs（P11 怪物表逐怪重生参数）：按怪物覆盖全局 respawnMs（<=0 视为未声明回落全局值）。
     * Death registration: records the respawn instant on death (now + respawnMs / 1000); repeated registration
     * overwrites (idempotent). $overrideMs (the P11 per-monster respawn parameter from the monster table):
     * overrides the global respawnMs per monster (a <=0 value counts as undeclared and falls back to the global).
     */
    public function registerDeath(string $monsterId, float $now, ?int $overrideMs = null): void
    {
        $delayMs = $overrideMs !== null && $overrideMs > 0 ? $overrideMs : $this->respawnMs;
        $this->queue[$monsterId] = $now + $delayMs / 1000;
    }

    /**
     * 到期查询：返回重生时刻已到的怪物 id 列表（不改变状态——消费方逐 id clear 后执行重生）。
     * Due query: returns the monster ids whose respawn instant has arrived (no state change — the consumer
     * clears each id then respawns).
     *
     * @return list<string>
     */
    public function due(float $now): array
    {
        $due = [];
        foreach ($this->queue as $monsterId => $at) {
            if ($now >= $at) {
                $due[] = $monsterId;
            }
        }

        return $due;
    }

    /**
     * 摘除登记（重生执行后调用；未登记静默忽略）。
     * Removes a registration (invoked after the respawn executes; unregistered ids are silently ignored).
     */
    public function clear(string $monsterId): void
    {
        unset($this->queue[$monsterId]);
    }

    /**
     * 是否仍有待重生登记。
     * Whether any respawn registration remains.
     */
    public function pending(): bool
    {
        return $this->queue !== [];
    }
}
