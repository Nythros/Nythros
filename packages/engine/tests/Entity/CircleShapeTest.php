<?php

declare(strict_types=1);

namespace Nythros\Entity\Tests;

use Nythros\Entity\CircleShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CircleShapeTest - 表驱动覆盖圆形形状的点包含判定（整数坐标精确判定、边界含入）与包围盒语义。
 * Table-driven tests covering CircleShape point containment (exact integer arithmetic, inclusive boundaries) and bounds semantics.
 */
final class CircleShapeTest extends TestCase
{
    /**
     * @return iterable<string, array{shape: CircleShape, x: int, y: int, expected: bool}>
     */
    public static function containsProvider(): iterable
    {
        $origin = new CircleShape(0, 0, 5);
        $offset = new CircleShape(10, -3, 2);

        yield '圆心命中 center hit' => ['shape' => $origin, 'x' => 0, 'y' => 0, 'expected' => true];
        yield '半径边界点命中（轴向）boundary on axis' => ['shape' => $origin, 'x' => 5, 'y' => 0, 'expected' => true];
        yield '半径边界点命中（3-4-5 对角）boundary on diagonal (3-4-5)' => ['shape' => $origin, 'x' => 3, 'y' => 4, 'expected' => true];
        yield '负坐标方向边界命中 boundary in negative quadrant' => ['shape' => $origin, 'x' => -3, 'y' => -4, 'expected' => true];
        yield '半径外一点排除 just outside radius' => ['shape' => $origin, 'x' => 4, 'y' => 4, 'expected' => false];
        yield '远点排除 far outside' => ['shape' => $origin, 'x' => 100, 'y' => 0, 'expected' => false];
        yield '偏移圆心内命中 inside offset circle' => ['shape' => $offset, 'x' => 11, 'y' => -3, 'expected' => true];
        yield '偏移圆心边界命中 boundary of offset circle' => ['shape' => $offset, 'x' => 10, 'y' => -1, 'expected' => true];
        yield '偏移圆心外排除 outside offset circle' => ['shape' => $offset, 'x' => 13, 'y' => -3, 'expected' => false];
    }

    #[DataProvider('containsProvider')]
    public function testContains(CircleShape $shape, int $x, int $y, bool $expected): void
    {
        self::assertSame($expected, $shape->contains($x, $y));
    }

    public function testZeroRadiusContainsOnlyCenter(): void
    {
        $shape = new CircleShape(2, 3, 0);

        self::assertTrue($shape->contains(2, 3));
        self::assertFalse($shape->contains(3, 3));
    }

    public function testBoundsCoversContainedRange(): void
    {
        $shape = new CircleShape(10, -3, 2);

        // 包围盒须完整覆盖 contains=true 的格范围（圆外接框） bounds must fully cover the contains=true range (circumscribed box)
        self::assertSame(['minX' => 8, 'minY' => -5, 'maxX' => 12, 'maxY' => -1], $shape->bounds());
    }

    public function testNegativeRadiusRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CircleShape(0, 0, -1);
    }
}
