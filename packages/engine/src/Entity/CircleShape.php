<?php

declare(strict_types=1);

namespace Nythros\Entity;

use Nythros\Contracts\ShapeInterface;

/**
 * 圆形形状值对象：圆心加半径，contains 用整数平方距离精确判定（无浮点误差、边界含入）。
 * Circle shape value object: center plus radius; contains uses exact integer squared-distance arithmetic
 * (no floating-point error, boundaries inclusive).
 */
final class CircleShape implements ShapeInterface
{
    /**
     * 构造圆形形状。
     * Creates a circle shape.
     *
     * @param int $cx 圆心 X 轴坐标 Center X-axis coordinate.
     * @param int $cy 圆心 Y 轴坐标 Center Y-axis coordinate.
     * @param int $r 半径（非负） Radius (non-negative).
     */
    public function __construct(
        private readonly int $cx,
        private readonly int $cy,
        private readonly int $r,
    ) {
        if ($r < 0) {
            throw new \InvalidArgumentException('CircleShape 半径必须非负 / radius must be non-negative');
        }
    }

    /**
     * 点是否在圆内（含圆周）：平方距离比较，全程整数运算、结果精确。
     * Whether the point lies inside the circle (circumference included): squared-distance comparison in
     * exact integer arithmetic.
     */
    public function contains(int $x, int $y): bool
    {
        $dx = $x - $this->cx;
        $dy = $y - $this->cy;

        return $dx * $dx + $dy * $dy <= $this->r * $this->r;
    }

    /**
     * 包围盒：圆的外接正方形。
     * Bounding box: the circle's circumscribed square.
     *
     * @return array{minX: int, minY: int, maxX: int, maxY: int}
     */
    public function bounds(): array
    {
        return [
            'minX' => $this->cx - $this->r,
            'minY' => $this->cy - $this->r,
            'maxX' => $this->cx + $this->r,
            'maxY' => $this->cy + $this->r,
        ];
    }
}
