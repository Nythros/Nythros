<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Plugin\Skill\SkillDefinition;
use PHPUnit\Framework\TestCase;

/**
 * SkillDefinitionTest - 覆盖技能定义值对象：既有五字段位置构造兼容、AoE 形状参数与消耗字段的缺省/显式口径。
 * Tests covering the skill-definition value object: positional construction compatibility of the legacy five fields,
 * plus default/explicit semantics of the AoE shape parameters and cost fields.
 */
final class SkillDefinitionTest extends TestCase
{
    public function testLegacyPositionalConstructionKeepsDefaults(): void
    {
        $skill = new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3);

        self::assertSame('fireball', $skill->id);
        self::assertSame(1.5, $skill->damageMultiplier);
        self::assertNull($skill->aoe, '未声明 AoE 形状 = 单体技能。No AoE shape declared = single-target skill.');
        self::assertSame(0, $skill->mpCost);
        self::assertNull($skill->itemCostId);
        self::assertSame(0, $skill->itemCostCount);
    }

    public function testAoeShapeAndCostsCarryExplicitValues(): void
    {
        $skill = new SkillDefinition(
            'fireball',
            '火球术',
            1.5,
            2.0,
            3,
            ['shape' => SkillDefinition::SHAPE_CIRCLE, 'radius' => 70],
            10,
            'potion',
            1,
        );

        self::assertSame(['shape' => 'circle', 'radius' => 70], $skill->aoe);
        self::assertSame(SkillDefinition::SHAPE_CIRCLE, $skill->aoe['shape']);
        self::assertSame(70, $skill->aoe['radius']);
        self::assertSame(10, $skill->mpCost);
        self::assertSame('potion', $skill->itemCostId);
        self::assertSame(1, $skill->itemCostCount);
    }
}
