<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Inventory;

use Nythros\Framework\BasePlayer;
use Nythros\Framework\Inventory\Equipment\Equipment;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use PHPUnit\Framework\TestCase;

/**
 * EquipmentTest - 覆盖穿戴/卸下/槽位冲突/属性加成聚合/非法槽位与非装备型拒绝（R3 经济批模块 1）。
 * EquipmentTest - covers equip/unequip, slot conflicts, attribute-bonus aggregation and illegal-slot /
 * non-equipment-type rejection (economy-batch module 1).
 */
final class EquipmentTest extends TestCase
{
    public function testEquipAndUnequipRoundTrip(): void
    {
        $equipment = new Equipment();
        $sword = new ItemDefinition('sword', '长剑', ItemDefinition::TYPE_EQUIPMENT, 'weapon', ['maxHp' => 20]);

        self::assertNull($equipment->equip($sword), '空槽位穿戴无顶替。Equipping an empty slot displaces nothing.');
        self::assertSame('sword', $equipment->itemIdIn('weapon'));
        self::assertSame(['weapon' => $sword], $equipment->equipped());

        self::assertSame('sword', $equipment->unequip('weapon'));
        self::assertNull($equipment->itemIdIn('weapon'));
        self::assertSame([], $equipment->equipped());
    }

    public function testUnequipEmptySlotReturnsNull(): void
    {
        $equipment = new Equipment();

        self::assertNull($equipment->unequip('armor'));
    }

    public function testEquipSameSlotDisplacesThePreviousItem(): void
    {
        $equipment = new Equipment();
        $leather = new ItemDefinition('leather_armor', '皮甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 30]);
        $plate = new ItemDefinition('plate_armor', '板甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 80]);

        $equipment->equip($leather);
        self::assertSame('leather_armor', $equipment->equip($plate), '同槽位重复穿戴返回被顶替物品。Re-equipping a slot returns the displaced item.');
        self::assertSame('plate_armor', $equipment->itemIdIn('armor'), '槽位最终持有新装备。The slot ends up holding the new item.');
    }

    public function testAttributeBonusesAggregateAcrossSlots(): void
    {
        $equipment = new Equipment();
        $equipment->equip(new ItemDefinition('sword', '长剑', ItemDefinition::TYPE_EQUIPMENT, 'weapon', ['maxHp' => 20, 'attack' => 5]));
        $equipment->equip(new ItemDefinition('ring', '守护戒指', ItemDefinition::TYPE_EQUIPMENT, 'accessory', ['maxHp' => 10]));

        self::assertSame(30, $equipment->attributeBonus('maxHp'), '多槽位同名属性累加。Same-name bonuses across slots accumulate.');
        self::assertSame(5, $equipment->attributeBonus('attack'));
        self::assertSame(0, $equipment->attributeBonus('magic'), '未加成属性返回 0。Unbonused attributes return 0.');
        self::assertSame(['maxHp' => 30, 'attack' => 5], $equipment->attributeBonuses());
    }

    public function testUnequipDropsItsBonus(): void
    {
        $equipment = new Equipment();
        $equipment->equip(new ItemDefinition('leather_armor', '皮甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 30]));
        $equipment->unequip('armor');

        self::assertSame(0, $equipment->attributeBonus('maxHp'));
    }

    public function testEquipRejectsNonEquipmentTypes(): void
    {
        $equipment = new Equipment();

        try {
            $equipment->equip(new ItemDefinition('potion', '生命药水', ItemDefinition::TYPE_CONSUMABLE));
            self::fail('非 equipment 型必须被拒绝。Non-equipment types must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('potion', $e->getMessage());
        }
    }

    public function testEquipRejectsUnregisteredSlots(): void
    {
        $equipment = new Equipment();

        try {
            $equipment->equip(new ItemDefinition('wings', '翅膀', ItemDefinition::TYPE_EQUIPMENT, 'wing'));
            self::fail('未登记槽位必须被拒绝。Unregistered slots must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('wing', $e->getMessage());
        }
    }

    public function testEquipRejectsMissingSlot(): void
    {
        $equipment = new Equipment();

        $this->expectException(\InvalidArgumentException::class);
        $equipment->equip(new ItemDefinition('broken_relic', '残缺遗物', ItemDefinition::TYPE_EQUIPMENT));
    }

    public function testUnequipRejectsUnregisteredSlots(): void
    {
        $equipment = new Equipment();

        $this->expectException(\InvalidArgumentException::class);
        $equipment->unequip('wing');
    }

    public function testItemIdInIllegalSlotReturnsNull(): void
    {
        $equipment = new Equipment();

        self::assertNull($equipment->itemIdIn('wing'), '查询路径对非法槽位不抛，恒 null。Queries never throw on illegal slots — always null.');
    }

    public function testBasePlayerComposesMaxHpWithEquipmentBonus(): void
    {
        $player = $this->makePlayer(100, 100);
        $equipment = new Equipment();
        $player->attachEquipment($equipment);

        self::assertSame(100, $player->maxHp(), '空装备栏 maxHp 等于基础值。An empty set keeps maxHp at the base value.');

        $equipment->equip(new ItemDefinition('leather_armor', '皮甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 30]));

        self::assertSame(130, $player->maxHp(), '穿戴后 maxHp 合成装备加成。After equipping, maxHp composes the equipment bonus.');

        $player->heal(50);
        self::assertSame(130, $player->hp(), 'heal 钳制走合成上限。The heal clamp takes the composed ceiling.');
    }

    public function testBasePlayerClampsHpWhenTheCeilingShrinks(): void
    {
        $player = $this->makePlayer(100, 100);
        $equipment = new Equipment();
        $equipment->equip(new ItemDefinition('leather_armor', '皮甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 50]));
        $player->attachEquipment($equipment);
        $player->heal(50);
        self::assertSame(150, $player->hp());

        // 卸下减益装备：上限回落 100，挂载/摘除路径把 hp 收敛进新上限
        // Unequip the bonus item: the ceiling falls back to 100 and the mount/unmount path clamps hp into it
        $equipment->unequip('armor');
        $player->clampHpToMax();

        self::assertSame(100, $player->maxHp());
        self::assertSame(100, $player->hp(), 'hp 必须收敛进回落后的上限。hp must be clamped into the fallen ceiling.');
    }

    public function testDetachEquipmentRestoresPureBaseMaxHp(): void
    {
        $player = $this->makePlayer(100, 100);
        $equipment = new Equipment();
        $equipment->equip(new ItemDefinition('leather_armor', '皮甲', ItemDefinition::TYPE_EQUIPMENT, 'armor', ['maxHp' => 30]));
        $player->attachEquipment($equipment);

        $player->detachEquipment();

        self::assertNull($player->equipment());
        self::assertSame(100, $player->maxHp());
    }

    /**
     * 构造最小玩家测试替身（与 BasePlayerTest 同口径）。
     * Builds a minimal player test double (same convention as BasePlayerTest).
     */
    private function makePlayer(int $hp, int $maxHp): BasePlayer
    {
        return new class ($hp, $maxHp) extends BasePlayer {
            public function __construct(int $hp, int $maxHp)
            {
                $this->hp = $hp;
                $this->maxHp = $maxHp;
            }
        };
    }
}
