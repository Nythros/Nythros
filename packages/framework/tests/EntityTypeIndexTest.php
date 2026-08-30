<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Combat\EntityTypeIndex;
use PHPUnit\Framework\TestCase;

/**
 * EntityTypeIndexTest - 覆盖实体类型登记/查询/摘除与未登记兜底。
 * Tests covering entity-kind registration, lookup, removal and the unregistered fallback.
 */
final class EntityTypeIndexTest extends TestCase
{
    public function testSetAndKindOfRoundTrip(): void
    {
        $index = new EntityTypeIndex();

        $index->set('player-1', EntityTypeIndex::KIND_PLAYER);
        $index->set('monster-1', EntityTypeIndex::KIND_MONSTER);
        $index->set('drop-1', EntityTypeIndex::KIND_DROP);

        self::assertSame(EntityTypeIndex::KIND_PLAYER, $index->kindOf('player-1'));
        self::assertSame(EntityTypeIndex::KIND_MONSTER, $index->kindOf('monster-1'));
        self::assertSame(EntityTypeIndex::KIND_DROP, $index->kindOf('drop-1'));
    }

    public function testKindOfReturnsNullForUnregisteredEntity(): void
    {
        $index = new EntityTypeIndex();

        self::assertNull($index->kindOf('unknown-entity'));
    }

    public function testRemoveClearsRegistration(): void
    {
        $index = new EntityTypeIndex();
        $index->set('player-1', EntityTypeIndex::KIND_PLAYER);

        $index->remove('player-1');

        self::assertNull($index->kindOf('player-1'));
    }

    public function testRemoveIsIdempotentForUnknownEntity(): void
    {
        $index = new EntityTypeIndex();

        $index->remove('never-registered');

        self::assertNull($index->kindOf('never-registered'));
    }

    public function testSetOverwritesPreviousKind(): void
    {
        $index = new EntityTypeIndex();
        $index->set('monster-1', EntityTypeIndex::KIND_MONSTER);

        $index->set('monster-1', EntityTypeIndex::KIND_DROP);

        self::assertSame(EntityTypeIndex::KIND_DROP, $index->kindOf('monster-1'));
    }
}
