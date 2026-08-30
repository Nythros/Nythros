<?php

declare(strict_types=1);

namespace Nythros\Entity;

/**
 * 二维位置值对象：只读坐标加平移运算，平移返回新实例而非原地修改。
 * Two-dimensional position value object: read-only coordinates plus translation that returns a new instance instead of mutating in place.
 */
final class Position
{
    /**
     * 构造一个二维位置。
     * Creates a two-dimensional position.
     *
     * @param int $x X 轴坐标 X-axis coordinate.
     * @param int $y Y 轴坐标 Y-axis coordinate.
     */
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    /**
     * 按增量平移并返回新 Position；原实例保持不变（不可变语义）。
     * Translates by the given deltas and returns a new Position; the original instance is left unchanged (immutable semantics).
     *
     * @param int $dx X 轴增量 X-axis delta.
     * @param int $dy Y 轴增量 Y-axis delta.
     * @return Position 平移后的新位置 The translated new position.
     */
    public function move(int $dx, int $dy): Position
    {
        return new Position($this->x + $dx, $this->y + $dy);
    }
}
