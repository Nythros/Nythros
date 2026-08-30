<?php

declare(strict_types=1);

namespace Nythros\Entity\Tests;

use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use PHPUnit\Framework\TestCase;

/**
 * BaseEntityTest - 覆盖 BaseEntity 的初始 ID/位置与移动后的位置更新行为。
 * Tests covering BaseEntity initial id/position and position updates after move.
 */
final class BaseEntityTest extends TestCase
{
    public function testInitialIdAndPosition(): void
    {
        $entity = new BaseEntity('entity-1', new Position(1, 2));

        self::assertSame('entity-1', $entity->getId());
        self::assertSame(['x' => 1, 'y' => 2], $entity->getPosition());
    }

    public function testMoveUpdatesPosition(): void
    {
        $entity = new BaseEntity('entity-1', new Position(1, 2));

        $entity->move(3, 4);

        self::assertSame(['x' => 4, 'y' => 6], $entity->getPosition());
    }

    public function testSetPositionRepositionsAbsolutely(): void
    {
        $entity = new BaseEntity('entity-1', new Position(1, 2));

        $entity->setPosition(-7, 13);

        self::assertSame(['x' => -7, 'y' => 13], $entity->getPosition());
    }

    public function testSetPositionMarksMoved(): void
    {
        $entity = new BaseEntity('entity-1', new Position(1, 2));

        $entity->setPosition(5, 6);

        // 与 move() 同路径：绝对重定位必须置位 moved，供 AOI moved-dirty 增量刷新感知 same path as move(): absolute repositioning must set moved for the AOI moved-dirty incremental refresh
        self::assertTrue($entity->consumeMoved());
        self::assertFalse($entity->consumeMoved());
    }

    public function testSetPositionToSameCoordinatesStillMarksMoved(): void
    {
        $entity = new BaseEntity('entity-1', new Position(1, 2));

        $entity->setPosition(1, 2);

        // move(0,0) 同样置位 moved：绝对定位语义不因坐标未变而豁免 move(0,0) also sets moved: absolute positioning is not exempted by unchanged coordinates
        self::assertTrue($entity->consumeMoved());
    }
}
