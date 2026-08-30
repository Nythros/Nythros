<?php

declare(strict_types=1);

namespace Nythros\Entity;

use Nythros\Contracts\ShapeInterface;

/**
 * 矩形形状值对象：锚点为最小角（minX/minY），w/h 为正向宽高，四边含入。
 * Rectangle shape value object: anchor at the min corner (minX/minY), w/h are positive extents, all four edges inclusive.
 */
final class RectangleShape implements ShapeInterface
{
    /**
     * 构造矩形形状。
     * Creates a rectangle shape.
     *
     * @param int $x 锚点 X 轴坐标（最小角） Anchor X-axis coordinate (min corner).
     * @param int $y 锚点 Y 轴坐标（最小角） Anchor Y-axis coordinate (min corner).
     * @param int $w 宽度（非负） Width (non-negative).
     * @param int $h 高度（非负） Height (non-negative).
     */
    public function __construct(
        private readonly int $x,
        private readonly int $y,
        private readonly int $w,
        private readonly int $h,
    ) {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('RectangleShape 宽高必须非负 / width and height must be non-negative');
        }
    }

    /**
     * 点是否在矩形内（含四边）：逐轴闭区间比较。
     * Whether the point lies inside the rectangle (edges included): per-axis closed-interval comparison.
     */
    public function contains(int $px, int $py): bool
    {
        return $px >= $this->x
            && $px <= $this->x + $this->w
            && $py >= $this->y
            && $py <= $this->y + $this->h;
    }

    /**
     * 包围盒：矩形本身。
     * Bounding box: the rectangle itself.
     *
     * @return array{minX: int, minY: int, maxX: int, maxY: int}
     */
    public function bounds(): array
    {
        return [
            'minX' => $this->x,
            'minY' => $this->y,
            'maxX' => $this->x + $this->w,
            'maxY' => $this->y + $this->h,
        ];
    }
}
