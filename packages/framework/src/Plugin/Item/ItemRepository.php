<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Item;

/**
 * 物品注册表：按 id 管理物品定义，供 demo 掉落/拾取校验与查询。
 * Item repository: manages item definitions by id for the demo drop/pickup validation and lookup.
 */
final class ItemRepository
{
    /**
     * @var array<string, ItemDefinition> id => 物品定义 id => item definition
     */
    private array $items = [];

    /**
     * 注册物品定义；同 id 后注册覆盖先注册。
     * Registers an item definition; a later registration with the same id overrides the earlier one.
     *
     * @param ItemDefinition $item 物品定义 The item definition.
     */
    public function register(ItemDefinition $item): void
    {
        $this->items[$item->id] = $item;
    }

    /**
     * 按 id 查询物品定义；未注册返回 null。
     * Looks up an item definition by id; returns null when not registered.
     *
     * @param string $id 物品 id The item id.
     */
    public function get(string $id): ?ItemDefinition
    {
        return $this->items[$id] ?? null;
    }

    /**
     * 返回全部物品定义（id => ItemDefinition）。
     * Returns all item definitions (id => ItemDefinition).
     *
     * @return array<string, ItemDefinition>
     */
    public function all(): array
    {
        return $this->items;
    }
}
