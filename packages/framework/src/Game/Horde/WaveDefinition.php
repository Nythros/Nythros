<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

/**
 * 波次刷怪定义（R4 horde 类型模块试点）：一波怪的网格布局与战斗参数——
 * 锚点带起点/列数/步距决定刷怪坐标（positionAt 纯函数），maxHp 决定击杀确定性。
 * Wave spawn definition (the R4 horde type-module pilot): one wave's grid layout and combat parameters —
 * the anchor-band start/columns/step derive spawn coordinates (a pure positionAt function) and maxHp pins kill determinism.
 */
final class WaveDefinition
{
    public function __construct(
        public readonly int $count,
        public readonly int $monsterMaxHp,
        public readonly int $gridStartX,
        public readonly int $gridStartY,
        public readonly int $columns,
        public readonly int $step,
    ) {
    }

    /**
     * 波内序号 → 刷怪坐标（行优先网格；纯函数，供装配层与测试复用）。
     * In-wave index → spawn coordinate (a row-major grid; a pure function reused by assembly and tests).
     *
     * @return array{x: int, y: int}
     */
    public function positionAt(int $index): array
    {
        return [
            'x' => $this->gridStartX + ($index % $this->columns) * $this->step,
            'y' => $this->gridStartY + intdiv($index, $this->columns) * $this->step,
        ];
    }
}
