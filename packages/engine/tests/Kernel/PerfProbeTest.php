<?php

declare(strict_types=1);

namespace Nythros\Kernel\Tests;

use Nythros\Kernel\PerfProbe;
use PHPUnit\Framework\TestCase;

/**
 * PerfProbeTest - 经公共入口 recordDuration/record/drain 锁定桶分配语义：
 * 全域边界对拍（0/±0、负值、NaN、INF、八个边界恰值与邻值、超大值）+ JIT 热态复验
 * （先以数万混合值压热 recordDuration，令 tracing JIT 编译该路径，再复跑边界断言——
 * 8.3.33 tracing JIT 曾对取反守卫的 NaN 分支实测误编译，本测试为该回归的现形网）。
 * Tests locking bucket assignment via the public surface: full-domain boundary parity (zeros/negatives/NaN/INF,
 * exact and adjacent bound values) plus a JIT-hot re-verification (heat recordDuration with tens of thousands
 * of mixed values so tracing JIT compiles the path, then repeat the boundary assertions — a real
 * PHP 8.3.33 tracing-JIT miscompile of a negated NaN guard was caught this way; this test is its regression net).
 */
final class PerfProbeTest extends TestCase
{
    /**
     * 每个值 → 期望桶下标（FRAME_BUCKETS_MS 右开区间；NaN/负值/0 → 0，≥64 → 8）。
     * Each value maps to its expected bucket index.
     *
     * @return array<string, array{0: float, 1: int}>
     */
    private const CASES = [
        'nan' => [NAN, 0],
        'negative-inf' => [-INF, 0],
        'negative' => [-1.0, 0],
        'minus-zero' => [-0.0, 0],
        'zero' => [0.0, 0],
        'just-under-half' => [0.4999999, 0],
        'half' => [0.5, 1],
        'just-over-half' => [0.5000001, 1],
        'just-under-one' => [0.9999999, 1],
        'one' => [1.0, 2],
        'two' => [2.0, 3],
        'four' => [4.0, 4],
        'eight' => [8.0, 5],
        'sixteen' => [16.0, 6],
        'thirty-two' => [32.0, 7],
        'sixty-four' => [64.0, 8],
        'huge' => [100000.0, 8],
        'inf' => [INF, 8],
    ];

    public function testBucketAssignmentAcrossFullDomain(): void
    {
        PerfProbe::drain(); // 起点清零，避免其他测试遗留数据干扰逐键断言
        foreach (self::CASES as $name => [$value, $expectedBucket]) {
            PerfProbe::recordDuration('probe.' . $name, $value);
            $histogram = PerfProbe::snapshot()['histograms']['probe.' . $name];
            self::assertSame([$expectedBucket => 1], $histogram, $name . ' 应落桶 ' . $expectedBucket);
        }
        PerfProbe::drain();
    }

    /**
     * JIT 热态复验：先压热 recordDuration（数万混合值，含 NaN——触发 tracing JIT 编译），
     * 再重跑全边界断言。冷编译通过、热编译后语义漂移（或反之）都会在此现形。
     */
    public function testBucketAssignmentStableUnderHotExecution(): void
    {
        $values = [];
        foreach (self::CASES as [$value, $_]) {
            $values[] = $value;
        }
        $count = count($values);
        // 压热：5 万轮全边界循环（JIT 阈值远低于此）
        for ($i = 0; $i < 50_000; $i++) {
            PerfProbe::recordDuration('probe.heat', $values[$i % $count]);
        }
        PerfProbe::drain();

        foreach (self::CASES as $name => [$value, $expectedBucket]) {
            PerfProbe::recordDuration('probe.hot.' . $name, $value);
            $histogram = PerfProbe::snapshot()['histograms']['probe.hot.' . $name];
            self::assertSame([$expectedBucket => 1], $histogram, $name . ' 热态下应仍落桶 ' . $expectedBucket);
        }
        PerfProbe::drain();
    }
}
