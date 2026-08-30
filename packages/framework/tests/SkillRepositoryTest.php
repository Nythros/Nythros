<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use PHPUnit\Framework\TestCase;

/**
 * SkillRepositoryTest - 覆盖技能定义注册/查询/全量与未命中 null。
 * Tests covering skill definition registration, lookup, all and null on miss.
 */
final class SkillRepositoryTest extends TestCase
{
    public function testRegisterAndGetReturnTheSameDefinition(): void
    {
        $repository = new SkillRepository();
        $skill = new SkillDefinition('fireball', '火球术', 1.5, 2.0, 8);

        $repository->register($skill);

        self::assertSame($skill, $repository->get('fireball'));
    }

    public function testGetUnknownReturnsNull(): void
    {
        $repository = new SkillRepository();

        self::assertNull($repository->get('missing'));
    }

    public function testAllReturnsRegisteredDefinitions(): void
    {
        $repository = new SkillRepository();
        $fireball = new SkillDefinition('fireball', '火球术', 1.5, 2.0, 8);
        $icebolt = new SkillDefinition('icebolt', '冰箭术', 1.2, 1.5, 6);

        $repository->register($fireball);
        $repository->register($icebolt);

        self::assertSame(['fireball' => $fireball, 'icebolt' => $icebolt], $repository->all());
    }

    public function testRegisterOverridesById(): void
    {
        $repository = new SkillRepository();
        $first = new SkillDefinition('fireball', '火球术', 1.5, 2.0, 8);
        $second = new SkillDefinition('fireball', '大火球', 2.0, 3.0, 10);

        $repository->register($first);
        $repository->register($second);

        self::assertSame($second, $repository->get('fireball'), '同 id 后注册覆盖先注册。A later registration overrides the earlier one.');
        self::assertCount(1, $repository->all());
    }
}
