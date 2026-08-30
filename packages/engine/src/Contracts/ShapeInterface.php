<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 形状契约：AoE 批量命中管线的引擎原语——纯函数式的点包含判定与包围盒粗筛依据。
 * Shape contract: an engine primitive of the AoE batch-hit pipeline — purely functional point containment plus a bounding box for coarse filtering.
 */
interface ShapeInterface
{
    /**
     * 点是否在形状内（整数坐标、浮点判定、边界含入）。
     * Whether the point lies inside the shape (integer coordinates, floating-point judgment allowed, boundaries inclusive).
     */
    public function contains(int $x, int $y): bool;

    /**
     * 包围盒：必须完整覆盖 contains=true 的格范围（AOI 粗筛依据，允许保守外扩、不允许遗漏）。
     * Bounding box: must fully cover the range of cells where contains=true (the AOI coarse-filter basis;
     * conservative overreach is allowed, omissions are not).
     *
     * @return array{minX: int, minY: int, maxX: int, maxY: int}
     */
    public function bounds(): array;
}
