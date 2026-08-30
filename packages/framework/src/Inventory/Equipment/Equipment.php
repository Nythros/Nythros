<?php

declare(strict_types=1);

namespace Nythros\Framework\Inventory\Equipment;

use Nythros\Framework\Plugin\Item\ItemDefinition;

/**
 * 装备栏：按槽位管理已穿戴装备的容器（每玩家一份，由 BasePlayer 挂载消费）。
 * 穿戴校验双闸：① type 必须为 equipment（非装备不可穿戴）② slot 必须在 EquipmentSlot 注册表内
 * （非法槽位拒绝）。同槽位重复穿戴顶替旧装备（返回被顶替 itemId，交调用方回包处理）。
 * Equipment set: a per-player container of equipped items keyed by slot (mounted on and consumed by BasePlayer).
 * Equip validation has two gates: ① type must be equipment (non-equipment is unwearable) ② slot must be inside the
 * EquipmentSlot registry (illegal-slot rejection). Re-equipping a slot displaces the old item (the displaced itemId
 * is returned for the caller to put back into the bag).
 */
final class Equipment
{
    /** @var array<string, ItemDefinition> 槽位值 => 已穿戴物品定义 Slot value => equipped item definition. */
    private array $slots = [];

    /**
     * 穿戴：校验通过后写入槽位；同槽位已有装备时顶替。
     * Equip: writes the slot after validation; an occupied slot is displaced.
     *
     * @param ItemDefinition $item 待穿戴物品 The item to equip.
     * @return ?string 被顶替下线的物品 id；原槽位为空时 null The displaced item id; null when the slot was empty.
     * @throws \InvalidArgumentException 非 equipment 型 / 槽位未登记 Non-equipment type / unregistered slot.
     */
    public function equip(ItemDefinition $item): ?string
    {
        if ($item->type !== ItemDefinition::TYPE_EQUIPMENT) {
            throw new \InvalidArgumentException(sprintf('Equipment: 物品 %s 非 equipment 型，不可穿戴', $item->id));
        }

        if ($item->slot === null || EquipmentSlot::tryFrom($item->slot) === null) {
            throw new \InvalidArgumentException(sprintf('Equipment: 物品 %s 的槽位非法: %s', $item->id, (string) $item->slot));
        }

        $displaced = $this->slots[$item->slot] ?? null;
        $this->slots[$item->slot] = $item;

        return $displaced?->id;
    }

    /**
     * 卸下：槽位有装备时摘除并返回物品 id。
     * Unequip: removes and returns the item id when the slot holds one.
     *
     * @param string $slot 槽位值 Slot value.
     * @return ?string 卸下的物品 id；空槽位 null The unequipped item id; null when the slot was empty.
     * @throws \InvalidArgumentException 槽位未登记 Unregistered slot.
     */
    public function unequip(string $slot): ?string
    {
        if (EquipmentSlot::tryFrom($slot) === null) {
            throw new \InvalidArgumentException(sprintf('Equipment: 非法槽位: %s', $slot));
        }

        $item = $this->slots[$slot] ?? null;
        if ($item === null) {
            return null;
        }
        unset($this->slots[$slot]);

        return $item->id;
    }

    /**
     * 查询槽位当前穿戴的物品 id；空槽位/非法槽位均返回 null（查询路径不抛）。
     * Looks up the equipped item id of a slot; both empty and illegal slots return null (queries never throw).
     */
    public function itemIdIn(string $slot): ?string
    {
        $item = $this->slots[$slot] ?? null;

        return $item?->id;
    }

    /**
     * 全量已穿戴表（槽位值 => 物品定义）。
     * The full equipped table (slot value => item definition).
     *
     * @return array<string, ItemDefinition>
     */
    public function equipped(): array
    {
        return $this->slots;
    }

    /**
     * 单项属性加成：全部已穿戴装备该属性增量之和（BasePlayer maxHp 聚合的消费口径）。
     * Single-attribute bonus: the sum of that attribute across all equipped items (consumed by BasePlayer's maxHp aggregation).
     */
    public function attributeBonus(string $attribute): int
    {
        return $this->attributeBonuses()[$attribute] ?? 0;
    }

    /**
     * 全部属性加成聚合表（属性名 => 增量和）。
     * The aggregated bonus table (attribute name => summed increment).
     *
     * @return array<string, int>
     */
    public function attributeBonuses(): array
    {
        $bonuses = [];
        foreach ($this->slots as $item) {
            foreach ($item->attributes as $attribute => $increment) {
                $bonuses[$attribute] = ($bonuses[$attribute] ?? 0) + $increment;
            }
        }

        return $bonuses;
    }
}
