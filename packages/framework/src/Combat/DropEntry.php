<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 掉落表条目值对象：单条目的权重与数量区间（掉落正式化，R3 经济批模块 2）。
 * Drop-table entry value object: one entry's weight and count range (drop formalization, economy-batch module 2).
 */
final readonly class DropEntry
{
    /**
     * @param string $itemId 物品 id Item id.
     * @param int $weight 掉落权重（独立 roll 的命中段宽） Drop weight (the hit-segment width of the independent roll).
     * @param int $minCount 数量下界（含） Count lower bound (inclusive).
     * @param int $maxCount 数量上界（含） Count upper bound (inclusive).
     */
    public function __construct(
        public string $itemId,
        public int $weight,
        public int $minCount = 1,
        public int $maxCount = 1,
    ) {
        if ($this->minCount < 1 || $this->maxCount < $this->minCount) {
            throw new \InvalidArgumentException(sprintf('DropEntry %s 数量区间非法: [%d, %d]', $this->itemId, $this->minCount, $this->maxCount));
        }
    }
}
