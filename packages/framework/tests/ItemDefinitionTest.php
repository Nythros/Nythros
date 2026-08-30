<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Plugin\Item\ItemDefinition;
use PHPUnit\Framework\TestCase;

/**
 * ItemDefinitionTest - 覆盖 equipment 型扩展字段与既有类型缺省值（R3 经济批提交 0 的受控破坏性变更回归面）。
 * Tests covering the equipment-type extension fields and the defaults kept by the existing types
 * (the controlled breaking change of economy-batch commit 0's regression surface).
 */
final class ItemDefinitionTest extends TestCase
{
    public function testEquipmentTypeCarriesSlotAndAttributes(): void
    {
        $sword = new ItemDefinition('sword', '长剑', ItemDefinition::TYPE_EQUIPMENT, 'weapon', ['maxHp' => 20]);

        self::assertSame(ItemDefinition::TYPE_EQUIPMENT, $sword->type);
        self::assertSame('weapon', $sword->slot);
        self::assertSame(['maxHp' => 20], $sword->attributes);
    }

    public function testLegacyTypesKeepSlotAndAttributesAtDefaults(): void
    {
        $potion = new ItemDefinition('potion', '生命药水', ItemDefinition::TYPE_CONSUMABLE);

        self::assertNull($potion->slot);
        self::assertSame([], $potion->attributes);
    }

    public function testEquipmentTypeWithoutSlotIsRepresentable(): void
    {
        // equipment 型允许 slot 缺省构造（槽位合法性由 Equipment 槽位注册表在装备时裁决，
        // 定义层不强制——非法槽位在穿戴路径被拒绝并有测试锁定）。
        // The equipment type may construct without a slot (slot legality is adjudicated by the Equipment slot
        // registry at equip time, not enforced at definition level — illegal slots are rejected on the equip path
        // and locked by tests).
        $broken = new ItemDefinition('broken_relic', '残缺遗物', ItemDefinition::TYPE_EQUIPMENT);

        self::assertNull($broken->slot);
        self::assertSame([], $broken->attributes);
    }
}
