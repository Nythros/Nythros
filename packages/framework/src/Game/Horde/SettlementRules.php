<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

/**
 * 房间结算规则（R4 horde 类型模块试点）：以刷怪/击杀计数纯函数判定结算结论——
 * framework 提供规则，starter-kit 装配（击杀计数可由 combat.kill 埋点事件驱动）。
 * Room settlement rules (the R4 horde type-module pilot): a pure-function verdict from spawn/kill counts —
 * the framework owns the rule, the starter kit assembles it (kill counts can be driven by the combat.kill instrumentation event).
 */
final class SettlementRules
{
    /**
     * @param int $minKillRatio 结算所需最低击杀比例（百分比，100 = 全清才可结算） The minimum kill ratio required to settle (percent; 100 = a full clear).
     */
    public function __construct(
        public readonly int $minKillRatio = 100,
    ) {
    }

    /**
     * 判定一波刷怪是否达成结算条件：刷怪数为 0 恒不可结算（空房不产出结算结论）；
     * 击杀数达到 ceil(count × minKillRatio / 100) 即达成。
     * Whether a spawned wave meets the settlement bar: a zero count never settles (an empty room yields no verdict);
     * kills reaching ceil(count × minKillRatio / 100) meet it.
     */
    public function isCleared(int $spawnedCount, int $killedCount): bool
    {
        if ($spawnedCount <= 0) {
            return false;
        }

        $required = (int) ceil($spawnedCount * $this->minKillRatio / 100);

        return $killedCount >= $required;
    }
}
