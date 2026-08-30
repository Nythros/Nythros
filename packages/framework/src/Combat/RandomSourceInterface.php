<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 随机源：伤害浮动/掉落 roll 用，可注入确定实现做单测。
 * Random source: used for damage variance and drop rolls, injectable with a deterministic implementation for unit tests.
 */
interface RandomSourceInterface
{
    /**
     * 返回 [min, max] 闭区间内的随机整数。
     * Returns a random integer within the inclusive [min, max] range.
     *
     * @param int $min 下界（含） Lower bound (inclusive).
     * @param int $max 上界（含） Upper bound (inclusive).
     */
    public function randomInt(int $min, int $max): int;
}
