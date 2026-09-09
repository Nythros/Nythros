<?php

declare(strict_types=1);

/*
 * bucketOf 三种实现（升序链/降序链/二分）+ 多分布的 A/B 探针。
 * 资格：三实现输出对任意 float 全等（含 NaN/±0/负/边界/INF）后才比速度。
 */

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Kernel\PerfProbe;

// 与 HEAD 等价的两个变体
$desc = static function (float $ms): int {
    if ($ms >= 64.0) {
        return 8;
    }
    if ($ms >= 32.0) {
        return 7;
    }
    if ($ms >= 16.0) {
        return 6;
    }
    if ($ms >= 8.0) {
        return 5;
    }
    if ($ms >= 4.0) {
        return 4;
    }
    if ($ms >= 2.0) {
        return 3;
    }
    if ($ms >= 1.0) {
        return 2;
    }
    if ($ms >= 0.5) {
        return 1;
    }

    return 0;
};
// 升序 < 链：先兜 NaN/负值（原版语义 NaN→0、负→0）
$asc = static function (float $ms): int {
    if (is_nan($ms) || $ms < 0.5) {
        return 0;
    }   // NaN/负值/0（is_nan 显式，规避 tracing-JIT 对取反比较的边界差异）
    if ($ms < 1.0) {
        return 1;
    }
    if ($ms < 2.0) {
        return 2;
    }
    if ($ms < 4.0) {
        return 3;
    }
    if ($ms < 8.0) {
        return 4;
    }
    if ($ms < 16.0) {
        return 5;
    }
    if ($ms < 32.0) {
        return 6;
    }
    if ($ms < 64.0) {
        return 7;
    }

    return 8;
};
// 二分：8 边界
$edges = [0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 64.0];
$bisect = static function (float $ms) use ($edges): int {
    if (is_nan($ms) || $ms < 0.5) {
        return 0;
    }   // NaN/负值/0（is_nan 显式）
    $lo = 0;
    $hi = 7;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($ms >= $edges[$mid]) {
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }

    return $lo;
};

$ref = new ReflectionMethod(PerfProbe::class, 'bucketOf');
$ref->setAccessible(true);

// ── 正确性：全域对拍（每个实现 vs 真方法） ──
$samples = [NAN, -INF, -1.0, -0.0, 0.0, 0.0000001, 0.4999999, 0.5, 0.5000001, 1.0, 1.5, 2.0, 3.9999, 4.0, 8.0, 15.9999, 16.0, 32.0, 63.9999, 64.0, 64.00001, 1000.0, INF];
for ($i = 0; $i < 5000; $i++) {
    $samples[] = (float) random_int(0, 200_000) / 1000.0;   // 0..200ms
}
$parity = ['desc' => true, 'asc' => true, 'bisect' => true];
foreach ($samples as $v) {
    $r = $ref->invoke(null, $v);
    if ($desc($v) !== $r) {
        $parity['desc'] = false;
    }
    if ($asc($v) !== $r) {
        $parity['asc'] = false;
        echo '  asc FAIL v=' . var_export($v, true) . ' ref=' . $r . ' asc=' . $asc($v) . "\n";
    }
    if ($bisect($v) !== $r) {
        $parity['bisect'] = false;
    }
}
foreach ($parity as $name => $ok) {
    echo "parity $name: " . ($ok ? 'OK' : 'FAIL') . "\n";
}
if (in_array(false, $parity, true)) {
    exit(1);
}

// ── 吞吐：多分布 × 交替 5 轮中位 ──
$dists = [
    '帧耗时常态(90%@<0.5)' => static fn (): float => (function () {
        $r = random_int(0, 99);
        return $r < 90 ? $r / 200.0 : ($r < 97 ? 0.5 + ($r - 90) / 14.0 : ($r < 99 ? 1 + ($r - 97) : 8 + ($r - 99) * 40));
    })(),
    '均匀桶命中(每桶等概)' => static fn (): float => (function () {
        $b = random_int(0, 8);
        return match ($b) {
            0 => random_int(0, 499) / 1000, 1 => 0.5 + random_int(0, 499) / 1000, 2 => 1 + random_int(0, 999) / 1000, 3 => 2 + random_int(0, 1999) / 1000, 4 => 4 + random_int(0, 3999) / 1000, 5 => 8 + random_int(0, 7999) / 1000, 6 => 16 + random_int(0, 15999) / 1000, 7 => 32 + random_int(0, 31999) / 1000, default => 64 + random_int(0, 35999) / 1000
        };
    })(),
    '固定 0.264(探针口径)' => static fn (): float => 0.264,
];

function bench(callable $f, callable $gen, int $n): float
{
    $vals = [];
    for ($i = 0; $i < $n; $i++) {
        $vals[] = $gen();
    }
    $t0 = hrtime(true);
    foreach ($vals as $v) {
        $f($v);
    }

    return (hrtime(true) - $t0) / 1e6 / $n;
}
$med = static function (array $a): float {
    sort($a);

    return $a[intdiv(count($a), 2)];
};

foreach ($dists as $label => $gen) {
    $r = ['desc' => [], 'asc' => [], 'bisect' => [], 'ref' => []];
    for ($round = 0; $round < 5; $round++) {
        $r['ref'][] = bench(static fn (float $v) => $ref->invoke(null, $v), $gen, 200000);
        $r['desc'][] = bench($desc, $gen, 200000);
        $r['asc'][] = bench($asc, $gen, 200000);
        $r['bisect'][] = bench($bisect, $gen, 200000);
    }
    $rd = $med($r['desc']);
    $ra = $med($r['asc']);
    $rb = $med($r['bisect']);
    $rr = $med($r['ref']);
    printf(
        "%-26s 真方法(升序已落地) %.6f  升序 %.6f (%+.1f%%)  二分 %.6f (%+.1f%%)\n",
        $label,
        $rr,
        $ra,
        ($rr / $ra - 1) * 100,
        $rb,
        ($rr / $rb - 1) * 100
    );
}
