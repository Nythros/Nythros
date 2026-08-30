<?php

declare(strict_types=1);

namespace Nythros\Entity;

use Nythros\Contracts\ShapeInterface;

/**
 * 扇形形状值对象：angleDeg 为朝向中心线方向、fovDeg 为全张角（半张角 = fovDeg/2），
 * 距离用整数平方距离精确判定，角度用 atan2 浮点判定并做跨 0° 归一化（容差 1e-9 保边界含入）。
 * Sector shape value object: angleDeg is the facing centerline and fovDeg the full aperture
 * (half-aperture = fovDeg/2); distance uses exact integer squared arithmetic while the angle is judged
 * in floating point via atan2 with cross-0° normalization (1e-9 tolerance preserves inclusive boundaries).
 */
final class SectorShape implements ShapeInterface
{
    /** 角度判定的浮点容差：吸收 atan2/度转换的表示噪声，保证精确边界点含入。Angular float tolerance: absorbs atan2/degree-conversion representation noise so exact boundary points stay inclusive. */
    private const ANGLE_EPSILON = 1e-9;

    /**
     * 构造扇形形状。
     * Creates a sector shape.
     *
     * @param int $cx 圆心 X 轴坐标 Center X-axis coordinate.
     * @param int $cy 圆心 Y 轴坐标 Center Y-axis coordinate.
     * @param int $r 半径（非负） Radius (non-negative).
     * @param float $angleDeg 朝向中心线角度（度，任意实数，内部归一化；0° 指向 +x，逆时针为正） Facing centerline angle in degrees (any real number, normalized internally; 0° points to +x, positive counterclockwise).
     * @param float $fovDeg 全张角（度，非负；≥360° 等价整圆） Full aperture in degrees (non-negative; ≥360° equals the whole disc).
     */
    public function __construct(
        private readonly int $cx,
        private readonly int $cy,
        private readonly int $r,
        private readonly float $angleDeg,
        private readonly float $fovDeg,
    ) {
        if ($r < 0) {
            throw new \InvalidArgumentException('SectorShape 半径必须非负 / radius must be non-negative');
        }
        if ($fovDeg < 0) {
            throw new \InvalidArgumentException('SectorShape 张角必须非负 / aperture must be non-negative');
        }
    }

    /**
     * 点是否在扇形内（边界含入）：距离不超半径且与朝向的角差不超过半张角；
     * 圆心到自身距离为零、方向无定义，恒命中。
     * Whether the point lies inside the sector (boundaries inclusive): within radius and whose angular
     * offset from the facing direction is within half the aperture; the center itself is at zero distance
     * with undefined direction and is always contained.
     */
    public function contains(int $x, int $y): bool
    {
        $dx = $x - $this->cx;
        $dy = $y - $this->cy;

        // 距离判定走整数平方运算，结果精确 distance check via exact integer squared arithmetic
        if ($dx * $dx + $dy * $dy > $this->r * $this->r) {
            return false;
        }

        // 圆心特判：atan2(0,0) 方向无定义，不能参与角度判定 center special case: atan2(0,0) has no defined direction and must bypass the angular check
        if ($dx === 0 && $dy === 0) {
            return true;
        }

        $halfFov = $this->fovDeg / 2.0;
        $pointAngle = atan2((float) $dy, (float) $dx) * 180.0 / M_PI;

        return abs($this->angularDelta($pointAngle, $this->angleDeg)) <= $halfFov + self::ANGLE_EPSILON;
    }

    /**
     * 包围盒：取整圆外接框——保守粗筛保证不漏任何 contains=true 的格，精判交给 contains。
     * Bounding box: the full circle's circumscribed box — a conservative coarse filter that never misses
     * contained cells, with precision left to contains.
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

    /**
     * 计算点角相对朝向角的最小有符号角差（度，[-180°, +180°]）：先归一化到 [0°, 360°)，
     * 再折返超过 180° 的部分，使跨 0° 方向（如朝向 350°、点在 5°）得到正确的 +15° 而非 -345°。
     * Computes the minimal signed angular delta in degrees ([−180°, +180°]) between the point angle and the
     * facing angle: normalizes into [0°, 360°) first, then folds back values beyond 180°, so headings across
     * 0° (e.g. facing 350°, point at 5°) yield the correct +15° instead of −345°.
     */
    private function angularDelta(float $pointAngle, float $facingAngle): float
    {
        $delta = fmod($pointAngle - $facingAngle, 360.0);
        if ($delta < 0.0) {
            $delta += 360.0;
        }
        if ($delta > 180.0) {
            $delta -= 360.0;
        }

        return $delta;
    }
}
