<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 系统随机源：基于 random_int 的真实随机实现，生产组装用；测试注入确定实现。
 * System random source: the real implementation based on random_int, used in production assembly; tests inject deterministic implementations.
 */
final class SystemRandomSource implements RandomSourceInterface
{
    public function randomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
