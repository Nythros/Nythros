<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 可播种随机源（P14 E2E 工程化）：基于 PHP 内置 Mt19937 引擎（Random\Randomizer + Random\Engine\Mt19937）——
 * 同一种子产生同一随机序列，战斗伤害浮动/掉落 roll 全链可复现，E2E 断言从「时序容忍」升级为「数值确定」。
 * A seedable random source (the P14 E2E engineering): built on PHP's built-in Mt19937 engine
 * (Random\Randomizer + Random\Engine\Mt19937) — the same seed yields the same random sequence, making the whole
 * chain of combat damage variance and drop rolls reproducible, upgrading E2E assertions from "timing-tolerant"
 * to "numerically deterministic".
 *
 * 生产缺省仍为 SystemRandomSource（random_int）——种子源仅在部署显式注入 NYTHROS_RANDOM_SEED 时生效。
 * Production still defaults to SystemRandomSource (random_int) — the seeded source engages only when a
 * deployment explicitly injects NYTHROS_RANDOM_SEED.
 */
final class SeededRandomSource implements RandomSourceInterface
{
    private readonly \Random\Randomizer $randomizer;

    /**
     * @param int $seed 随机种子（Mt19937 状态初始化；同种子同序列）。 The random seed (the Mt19937 state initializer; same seed, same sequence).
     */
    public function __construct(int $seed)
    {
        $this->randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    public function randomInt(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }
}
