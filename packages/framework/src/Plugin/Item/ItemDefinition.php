<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Item;

/**
 * 物品定义：纯数据值对象；type 取值见本类常量（consumable/material/currency/equipment）。
 * equipment 型专用字段：slot 为装备槽位值（合法取值由 Framework\Inventory\Equipment 的槽位注册表裁决），
 * attributes 为属性加成表（属性名 => 整数增量，如 ['maxHp' => 30]）；非 equipment 型两字段恒为缺省值。
 * Item definition: a plain-data value object; the type values are the constants of this class
 * (consumable/material/currency/equipment). Equipment-only fields: slot is the equipment slot value (legality is
 * adjudicated by the slot registry of Framework\Inventory\Equipment) and attributes maps attribute name to an integer
 * bonus (e.g. ['maxHp' => 30]); non-equipment types keep both fields at their defaults.
 */
final readonly class ItemDefinition
{
    public const TYPE_CONSUMABLE = 'consumable';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_CURRENCY = 'currency';
    public const TYPE_EQUIPMENT = 'equipment';

    /**
     * @param string $id 物品唯一 id Unique item id.
     * @param string $name 物品名 Item name.
     * @param string $type 物品类型（consumable|material|currency|equipment） Item type (consumable|material|currency|equipment).
     * @param ?string $slot 装备槽位值（仅 equipment 型；如 weapon/armor/accessory） Equipment slot value (equipment type only, e.g. weapon/armor/accessory).
     * @param array<string, int> $attributes 属性加成表（仅 equipment 型） Attribute bonuses (equipment type only).
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $slot = null,
        public array $attributes = [],
    ) {
    }
}
