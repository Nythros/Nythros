<?php

declare(strict_types=1);

namespace Nythros\Entity\Tests;

use Nythros\Entity\Position;
use PHPUnit\Framework\TestCase;

/**
 * PositionTest - 覆盖 Position 的不可变移动语义（返回新实例且原实例不变）。
 * Tests covering Position's immutable move semantics (new instance returned, original unchanged).
 */
final class PositionTest extends TestCase
{
    public function testMoveReturnsNewInstanceWithCorrectCoordinates(): void
    {
        $position = new Position(3, 4);

        $moved = $position->move(2, -1);

        self::assertNotSame($position, $moved);
        self::assertSame(3, $position->x);
        self::assertSame(4, $position->y);
        self::assertSame(5, $moved->x);
        self::assertSame(3, $moved->y);
    }
}
