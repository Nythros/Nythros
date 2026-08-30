<?php

declare(strict_types=1);

namespace Nythros\Framework;

/**
 * 玩家背包：itemId => count 的计数表。
 * Player inventory: an itemId => count counting table.
 */
final class Inventory
{
    /** @var array<string, int> itemId => 数量 itemId => count. */
    private array $items = [];

    /**
     * 入包：同 itemId 数量累加。
     * Adds items; counts accumulate for the same itemId.
     *
     * @param string $itemId 物品 id Item id.
     * @param int $count 数量 Quantity.
     */
    public function add(string $itemId, int $count): void
    {
        $this->items[$itemId] = ($this->items[$itemId] ?? 0) + $count;
    }

    /**
     * 出包：数量不足时整组移除（不会出现负数）。
     * Removes items; when the held count is insufficient the whole group is removed (never going negative).
     *
     * @param string $itemId 物品 id Item id.
     * @param int $count 数量 Quantity.
     */
    public function remove(string $itemId, int $count): void
    {
        if (($this->items[$itemId] ?? 0) <= $count) {
            unset($this->items[$itemId]);

            return;
        }
        $this->items[$itemId] -= $count;
    }

    /**
     * 查询某物品数量；未持有返回 0。
     * Counts the held quantity of an item; 0 when not held.
     *
     * @param string $itemId 物品 id Item id.
     */
    public function count(string $itemId): int
    {
        return $this->items[$itemId] ?? 0;
    }

    /**
     * 返回全部物品（itemId => count）。
     * Returns all items (itemId => count).
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->items;
    }
}
