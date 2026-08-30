<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Inventory;
use PHPUnit\Framework\TestCase;

/**
 * InventoryTest - 覆盖入包累加、出包扣减与全量列举。
 * Tests covering add accumulation, remove deduction and full enumeration.
 */
final class InventoryTest extends TestCase
{
    public function testAddAccumulatesCounts(): void
    {
        $inventory = new Inventory();

        $inventory->add('gold', 5);
        $inventory->add('gold', 3);
        $inventory->add('potion', 2);

        self::assertSame(8, $inventory->count('gold'));
        self::assertSame(2, $inventory->count('potion'));
    }

    public function testCountReturnsZeroForUnknownItem(): void
    {
        $inventory = new Inventory();

        self::assertSame(0, $inventory->count('missing'));
    }

    public function testRemoveDeductsWithinHeldAmount(): void
    {
        $inventory = new Inventory();
        $inventory->add('gold', 5);

        $inventory->remove('gold', 2);

        self::assertSame(3, $inventory->count('gold'));
    }

    public function testRemoveClearsWholeGroupWhenInsufficient(): void
    {
        $inventory = new Inventory();
        $inventory->add('gold', 2);

        $inventory->remove('gold', 5);

        self::assertSame(0, $inventory->count('gold'));
        self::assertSame([], $inventory->all());
    }

    public function testAllReturnsItemCountMap(): void
    {
        $inventory = new Inventory();
        $inventory->add('gold', 5);
        $inventory->add('potion', 2);

        self::assertSame(['gold' => 5, 'potion' => 2], $inventory->all());
    }
}
