<?php

declare(strict_types=1);

namespace Nythros\Framework\Inventory\Equipment;

/**
 * 装备槽位枚举：背包装备模型的合法槽位注册表（R3 经济批裁决：Equipment 子命名空间承载槽位定义）。
 * ItemDefinition::slot 的合法取值即本枚举的 value；未登记值在穿戴路径被拒绝（非法槽位拒绝）。
 * Equipment-slot enum: the legal slot registry of the bag-equipment model (the R3 economy-batch ruling keeps
 * slot definitions in the Equipment sub-namespace). Legal ItemDefinition::slot values are exactly this enum's
 * values; unregistered values are rejected on the equip path (illegal-slot rejection).
 */
enum EquipmentSlot: string
{
    /** 武器槽 Weapon slot. */
    case WEAPON = 'weapon';

    /** 护甲槽 Armor slot. */
    case ARMOR = 'armor';

    /** 饰品槽 Accessory slot. */
    case ACCESSORY = 'accessory';
}
