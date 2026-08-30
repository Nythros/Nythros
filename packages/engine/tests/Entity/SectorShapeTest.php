<?php

declare(strict_types=1);

namespace Nythros\Entity\Tests;

use Nythros\Entity\SectorShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SectorShapeTest - 表驱动覆盖扇形形状的点包含判定：angleDeg 为朝向中心线、fovDeg 为全张角，
 * 距离与角度双判定、边界含入、圆心恒命中，并显式覆盖跨 0° 方向的精度边界。
 * Table-driven tests covering SectorShape point containment: angleDeg is the facing centerline and
 * fovDeg the full aperture; dual distance/angle checks with inclusive boundaries, center always hit,
 * and explicit precision-boundary coverage across the 0° heading.
 */
final class SectorShapeTest extends TestCase
{
    /**
     * @return iterable<string, array{shape: SectorShape, x: int, y: int, expected: bool}>
     */
    public static function containsProvider(): iterable
    {
        // 朝向正东（0°）、全张角 90°：角跨度 [-45°, +45°] facing east (0°), full aperture 90°: angular span [-45°, +45°]
        $east = new SectorShape(0, 0, 20, 0, 90);

        yield '朝向中心线命中 dead-center ray' => ['shape' => $east, 'x' => 10, 'y' => 0, 'expected' => true];
        yield '上边界角命中（45°）upper angular boundary (45°)' => ['shape' => $east, 'x' => 5, 'y' => 5, 'expected' => true];
        yield '下边界角命中（-45°）lower angular boundary (-45°)' => ['shape' => $east, 'x' => 5, 'y' => -5, 'expected' => true];
        yield '上边界角外排除（>45°）just past upper boundary' => ['shape' => $east, 'x' => 5, 'y' => 6, 'expected' => false];
        yield '朝向反侧排除 opposite the facing' => ['shape' => $east, 'x' => -5, 'y' => 0, 'expected' => false];
        yield '距离边界命中（r=5，角 36.87° 在跨度内）distance boundary inside span' => ['shape' => new SectorShape(0, 0, 5, 0, 90), 'x' => 4, 'y' => 3, 'expected' => true];
        yield '距离超界排除（角在跨度内）distance exceeded though angle in span' => ['shape' => new SectorShape(0, 0, 5, 0, 90), 'x' => 4, 'y' => 4, 'expected' => false];

        // 跨 0° 边界：朝向 350°、全张角 30°：角跨度 [335°, 365°]≡[335°,360°)∪[0°,5°] cross-0° heading: facing 350°, aperture 30°: span [335°, 365°]
        $crossZero = new SectorShape(0, 0, 20, 350, 30);

        yield '跨 0°：正东（0°）命中 cross-0°: due east hit' => ['shape' => $crossZero, 'x' => 10, 'y' => 0, 'expected' => true];
        yield '跨 0°：上缘内（4.76°）cross-0°: inside upper rim (4.76°)' => ['shape' => $crossZero, 'x' => 12, 'y' => 1, 'expected' => true];
        yield '跨 0°：上缘外（5.19°）cross-0°: outside upper rim (5.19°)' => ['shape' => $crossZero, 'x' => 11, 'y' => 1, 'expected' => false];
        yield '跨 0°：下缘内（343.3°）cross-0°: inside lower rim (343.3°)' => ['shape' => $crossZero, 'x' => 10, 'y' => -3, 'expected' => true];
        yield '跨 0°：下缘临界内（335.22°）cross-0°: near lower rim inside (335.22°)' => ['shape' => $crossZero, 'x' => 13, 'y' => -6, 'expected' => true];
        yield '跨 0°：下缘外（333.43°）cross-0°: outside lower rim (333.43°)' => ['shape' => $crossZero, 'x' => 12, 'y' => -6, 'expected' => false];

        // 朝向归一化：370°≡10°，全张角 90°：角跨度 [-35°, +55°] heading normalization: 370°≡10°, aperture 90°: span [-35°, +55°]
        $normalized = new SectorShape(0, 0, 20, 370, 90);

        yield '归一化：45° 命中 normalization: 45° hit' => ['shape' => $normalized, 'x' => 5, 'y' => 5, 'expected' => true];
        yield '归一化：80° 排除 normalization: 80° excluded' => ['shape' => $normalized, 'x' => 0, 'y' => 5, 'expected' => false];
        yield '归一化：负方向 -11.31° 命中 normalization: -11.31° hit' => ['shape' => $normalized, 'x' => 5, 'y' => -1, 'expected' => true];

        // 非原点圆心 non-origin center
        $offset = new SectorShape(100, 100, 10, 0, 90);

        yield '偏移圆心内命中 inside offset sector' => ['shape' => $offset, 'x' => 105, 'y' => 102, 'expected' => true];
        yield '偏移圆心反侧排除 opposite side of offset sector' => ['shape' => $offset, 'x' => 95, 'y' => 100, 'expected' => false];

        // 全张角 360° 等价整圆 full 360° aperture equals the whole disc
        $full = new SectorShape(0, 0, 5, 0, 360);

        yield '360° 张角：正后方命中 360° aperture: due behind hit' => ['shape' => $full, 'x' => -5, 'y' => 0, 'expected' => true];
        yield '360° 张角：正下方命中 360° aperture: straight down hit' => ['shape' => $full, 'x' => 0, 'y' => -5, 'expected' => true];

        // 零张角仅剩朝向射线 zero aperture leaves only the facing ray
        $ray = new SectorShape(0, 0, 10, 0, 0);

        yield '零张角：射线上命中 zero aperture: on-ray hit' => ['shape' => $ray, 'x' => 5, 'y' => 0, 'expected' => true];
        yield '零张角：偏离射线排除 zero aperture: off-ray excluded' => ['shape' => $ray, 'x' => 5, 'y' => 1, 'expected' => false];
    }

    #[DataProvider('containsProvider')]
    public function testContains(SectorShape $shape, int $x, int $y, bool $expected): void
    {
        self::assertSame($expected, $shape->contains($x, $y));
    }

    public function testCenterAlwaysContainedRegardlessOfHeading(): void
    {
        // 圆心到自身距离为零、方向无定义：无论朝向如何恒命中（AoE 以施法者为中心必含自身）
        // The center is at zero distance with undefined direction: always contained regardless of heading (an AoE centered on its caster must include them)
        self::assertTrue((new SectorShape(0, 0, 5, 350, 30))->contains(0, 0));
        self::assertTrue((new SectorShape(2, -7, 1, 123, 10))->contains(2, -7));
    }

    public function testBoundsIsConservativeCircleBox(): void
    {
        // 扇形包围盒取整圆外接框：保守粗筛保证不漏 contains=true 的格（精判交给 contains）
        // A sector's bounds take the circumscribed box of the full circle: a conservative coarse filter that never misses contained cells (precision left to contains)
        $shape = new SectorShape(10, -3, 4, 350, 30);

        self::assertSame(['minX' => 6, 'minY' => -7, 'maxX' => 14, 'maxY' => 1], $shape->bounds());
    }

    public function testNegativeRadiusRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SectorShape(0, 0, -1, 0, 90);
    }

    public function testNegativeFovRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SectorShape(0, 0, 5, 0, -1);
    }
}
