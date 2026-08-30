<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use PHPUnit\Framework\TestCase;

/**
 * ItemRepositoryTest - 覆盖物品定义注册/查询/全量与未命中 null。
 * Tests covering item definition registration, lookup, all and null on miss.
 */
final class ItemRepositoryTest extends TestCase
{
    public function testRegisterAndGetReturnTheSameDefinition(): void
    {
        $repository = new ItemRepository();
        $item = new ItemDefinition('herb', '草药', ItemDefinition::TYPE_MATERIAL);

        $repository->register($item);

        self::assertSame($item, $repository->get('herb'));
    }

    public function testGetUnknownReturnsNull(): void
    {
        $repository = new ItemRepository();

        self::assertNull($repository->get('missing'));
    }

    public function testAllReturnsRegisteredDefinitions(): void
    {
        $repository = new ItemRepository();
        $herb = new ItemDefinition('herb', '草药', ItemDefinition::TYPE_MATERIAL);
        $potion = new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE);
        $gold = new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY);

        $repository->register($herb);
        $repository->register($potion);
        $repository->register($gold);

        self::assertSame(['herb' => $herb, 'potion' => $potion, 'gold' => $gold], $repository->all());
    }

    public function testRegisterOverridesById(): void
    {
        $repository = new ItemRepository();
        $first = new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE);
        $second = new ItemDefinition('potion', '大药水', ItemDefinition::TYPE_CONSUMABLE);

        $repository->register($first);
        $repository->register($second);

        self::assertSame($second, $repository->get('potion'), '同 id 后注册覆盖先注册。A later registration overrides the earlier one.');
        self::assertCount(1, $repository->all());
    }
}
