<?php

declare(strict_types=1);

namespace Nythros\Entity\Tests;

use Nythros\Entity\RectangleShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RectangleShapeTest - 表驱动覆盖矩形形状的点包含判定：锚点为最小角、w/h 为几何跨度
 * （覆盖 [x, x+w] × [y, y+h]）、四边含入，另覆盖包围盒与构造校验。
 * Table-driven tests covering RectangleShape point containment: anchor at the min corner, w/h are
 * geometric extents (covering [x, x+w] × [y, y+h]), all four edges inclusive; plus bounds and construction validation.
 */
final class RectangleShapeTest extends TestCase
{
    /**
     * @return iterable<string, array{x: int, y: int, expected: bool}>
     */
    public static function containsProvider(): iterable
    {
        // 锚点 (2,3)、宽 5、高 4：覆盖 x∈[2,7]、y∈[3,7] anchor (2,3), w=5, h=4: covers x∈[2,7], y∈[3,7]
        return [
            '左上角锚点命中 top-left anchor corner' => ['x' => 2, 'y' => 3, 'expected' => true],
            '右下角命中 bottom-right corner' => ['x' => 7, 'y' => 7, 'expected' => true],
            '右上角命中 top-right corner' => ['x' => 7, 'y' => 3, 'expected' => true],
            '左下角命中 bottom-left corner' => ['x' => 2, 'y' => 7, 'expected' => true],
            '内部点命中 interior point' => ['x' => 4, 'y' => 5, 'expected' => true],
            '右边外一格排除 one past right edge' => ['x' => 8, 'y' => 4, 'expected' => false],
            '上边外一格排除 one past top edge' => ['x' => 4, 'y' => 2, 'expected' => false],
            '左负方向排除 negative side' => ['x' => 1, 'y' => 4, 'expected' => false],
            '下方外一格排除 one below bottom edge' => ['x' => 4, 'y' => 8, 'expected' => false],
        ];
    }

    #[DataProvider('containsProvider')]
    public function testContains(int $x, int $y, bool $expected): void
    {
        $rect = new RectangleShape(2, 3, 5, 4);

        self::assertSame($expected, $rect->contains($x, $y));
    }

    public function testBoundsMatchesExtent(): void
    {
        $rect = new RectangleShape(2, 3, 5, 4);

        self::assertSame(['minX' => 2, 'minY' => 3, 'maxX' => 7, 'maxY' => 7], $rect->bounds());
    }

    public function testNegativeCoordinatesSupported(): void
    {
        $rect = new RectangleShape(-10, -10, 4, 4);

        self::assertTrue($rect->contains(-10, -10));
        self::assertTrue($rect->contains(-6, -6));
        self::assertFalse($rect->contains(-5, -6));
        self::assertFalse($rect->contains(-6, -5));
        self::assertSame(['minX' => -10, 'minY' => -10, 'maxX' => -6, 'maxY' => -6], $rect->bounds());
    }

    public function testNegativeWidthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RectangleShape(0, 0, -1, 4);
    }

    public function testNegativeHeightRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RectangleShape(0, 0, 4, -1);
    }
}
