<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

/**
 * 死亡掉落策略（P13 死亡与对抗治理，参数草案裁决值见 blueprint/26 §一）：
 * 玩家死亡时随身背包按本策略掉落为 DropEntity——归属窗口内仅击杀者（及同队）可拾
 * （复用 DropEntity ownerUid/expiresAt 与 CombatService not_owner 拾取保护，零新协议），
 * 窗口过期转自由拾取，到期由 purgeExpiredDrops 回收。
 * The death-drop policy (the P13 death & combat governance; the draft rulings live in blueprint/26 §1):
 * on player death the inventory drops as DropEntities per this policy — inside the ownership window only
 * the killer (and teammates) may pick (reusing DropEntity ownerUid/expiresAt and CombatService's not_owner
 * pickup protection, zero new protocol), after expiry the drop turns free-for-all and purgeExpiredDrops
 * reclaims it at end of life.
 *
 * 产品参数草案（blueprint/26 §一，缺省见 ::default()）：
 * - 逐单位独立 roll：每件物品的每个计数单位以 dropRatioPercent 概率掉落（自然下取整 + 余数随机）；
 * - 绑定物品恒不掉落（boundItemIds，如账本货币 gold）；
 * - 单次死亡掉落条目上限 maxDropsPerDeath（防背包巨量掉落风暴）；
 * - 归属窗口 ownerWindowSeconds：窗口内击杀者/同队专享，过期自由拾取。
 * Product-parameter draft (blueprint/26 §1, defaults in ::default()):
 * - Per-unit independent rolls: every count unit of every item kind drops with dropRatioPercent probability;
 * - Bound items never drop (boundItemIds, e.g. the ledger currency gold);
 * - maxDropsPerDeath caps the dropped kinds per death (guarding against inventory-sized drop storms);
 * - ownerWindowSeconds: killer/team-exclusive inside the window, free pickup after expiry.
 */
final readonly class DeathDropPolicy
{
    /**
     * @param int $dropRatioPercent 每单位掉落概率（百分比，0-100）。 The per-unit drop probability (percent, 0-100).
     * @param int $ownerWindowSeconds 归属窗口秒数（击杀者/同队专享时长，≥1）。 The ownership window in seconds
     *   (killer/team-exclusive duration, >=1).
     * @param int $maxDropsPerDeath 单次死亡掉落条目上限（≥1）。 The max dropped item kinds per death (>=1).
     * @param list<string> $boundItemIds 绑定物品 id（恒不掉落）。 Bound item ids (never dropped).
     *
     * @throws \InvalidArgumentException 任一不变量被违反时（ratio 0-100、window ≥1、max ≥1）——装配期 fail-fast。
     *   When any invariant is violated (ratio within 0-100, window >=1, max >=1) — fail-fast at assembly time.
     */
    public function __construct(
        public readonly int $dropRatioPercent,
        public readonly int $ownerWindowSeconds,
        public readonly int $maxDropsPerDeath,
        public readonly array $boundItemIds = [],
    ) {
        if ($this->dropRatioPercent < 0 || $this->dropRatioPercent > 100) {
            throw new \InvalidArgumentException('死亡掉落配置 dropRatioPercent 必须在 0-100 之间 / death-drop config requires dropRatioPercent within 0-100');
        }
        if ($this->ownerWindowSeconds < 1) {
            throw new \InvalidArgumentException('死亡掉落配置 ownerWindowSeconds 必须为正 / death-drop config requires a positive ownerWindowSeconds');
        }
        if ($this->maxDropsPerDeath < 1) {
            throw new \InvalidArgumentException('死亡掉落配置 maxDropsPerDeath 必须为正 / death-drop config requires a positive maxDropsPerDeath');
        }
    }

    /**
     * 草案缺省：30% 逐单位掉率、60s 归属窗口、单次死亡最多 8 种、无绑定物品。
     * The draft defaults: 30% per-unit ratio, a 60s ownership window, at most 8 kinds per death, no bound items.
     */
    public static function default(): self
    {
        return new self(30, 60, 8, []);
    }
}
