<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Combat\RandomSourceInterface;

/**
 * FixedRandomSource - 确定性随机源：返回固定值或按序消耗的值队列（越界值钳制到 [min,max]）。
 * FixedRandomSource - a deterministic random source: returns a fixed value or a queue consumed in order (out-of-range values are clamped to [min,max]).
 */
final class FixedRandomSource implements RandomSourceInterface
{
    /** @var int|list<int> 固定值或值队列 A fixed value or a value queue. */
    private array|int $values;

    /**
     * @param int|list<int> $values 固定值或按序消耗的值队列 A fixed value or a queue consumed in order.
     */
    public function __construct(int|array $values = 100)
    {
        $this->values = $values;
    }

    public function randomInt(int $min, int $max): int
    {
        $value = is_array($this->values) ? (array_shift($this->values) ?? $min) : $this->values;

        return max($min, min($max, $value));
    }
}
