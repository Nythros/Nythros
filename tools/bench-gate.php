<?php

declare(strict_types=1);

// benchmark 回归门禁（R5 工程纵深）：engine-bench --json 产出与基线逐指标比对，关键指标劣化超阈值即失败。
// 方向判定按指标名后缀：*_ops_per_sec / *_batches_per_sec / *_frames_per_sec = 越高越好（吞吐）；
// *_ms_per_frame* = 越低越好（帧耗时）。基线：benchmarks/results/engine-bench.json（WSL 实测全量存档），
// 门禁只监听 WATCHED_METRICS 中的关键指标（跨机方差大的指标不入监听集，仅存档）。
// 用法：php tools/bench-gate.php <baseline.json> <current.json> [--threshold=0.2]
// php tools/bench-gate.php --self-test（内置正负向用例自测门禁自身，不触碰真实文件）。
// Benchmark regression gate (R5 engineering depth): compares engine-bench --json output against the baseline
// metric-by-metric and fails when a watched key metric regresses past the threshold. Direction is inferred from
// the metric-name suffix: *_ops_per_sec / *_batches_per_sec / *_frames_per_sec = higher-is-better (throughput);
// *_ms_per_frame* = lower-is-better (frame cost). Baseline: benchmarks/results/engine-bench.json (full WSL-measured
// archive); the gate only watches the key metrics in WATCHED_METRICS (high cross-machine-variance metrics stay
// archive-only).

/**
 * 关键指标监听集（R5 任务口径：World::update @1000、AOI queryShape、EventBus、序列化吞吐）。
 * 取实测跨进程方差 <15% 的稳定项；新指标入集前先观察多轮方差。
 * The watched key-metric set (task scope: World::update @1000, AOI queryShape, EventBus, serializer throughput).
 * Only metrics measured with <15% cross-process variance; observe variance across several runs before adding.
 */
const BENCH_GATE_WATCHED_METRICS = [
    'world_update_ms_per_frame_entities500',
    'world_update_ms_per_frame_entities1000',
    'aoi_query_ops_per_sec',
    'event_bus_enqueue100_flush_batches_per_sec',
    'binary_batch_encode_batches_per_sec',
    'binary_batch_decode_batches_per_sec',
];

/**
 * 单指标方向：true = 越低越好（帧耗时类），false = 越高越好（吞吐类）。
 * Per-metric direction: true = lower-is-better (frame-cost family), false = higher-is-better (throughput family).
 */
function benchMetricIsLowerBetter(string $metric): bool
{
    return str_contains($metric, '_ms_per_frame');
}

/**
 * 单指标比对：返回违规描述（null = 通过）。劣化幅度按基线归一（(cur-base)/base），
 * 与方向取反后超过阈值即违规；基线为零时仅接受同零（避免除零）。
 * Single-metric comparison: returns a violation description (null = pass). The regression ratio is normalized
 * against the baseline ((cur-base)/base); violating when it exceeds the threshold against the direction.
 * A zero baseline only accepts an equal zero (division-by-zero guard).
 */
function benchCompareMetric(string $metric, float $baseline, float $current, float $threshold): ?string
{
    $lowerIsBetter = benchMetricIsLowerBetter($metric);
    if ($baseline == 0.0) {
        return $current == 0.0 ? null : sprintf('%s: 基线为 0 而当前为 %s（无法归一比较）', $metric, $current);
    }

    // 劣化率：吞吐下降或耗时上升均为正数。 Regression ratio: throughput drops and frame-cost rises are both positive.
    $regression = $lowerIsBetter
        ? ($current - $baseline) / abs($baseline)
        : ($baseline - $current) / abs($baseline);

    if ($regression > $threshold) {
        return sprintf(
            '%s: %s（阈值 %.0f%%，实际劣化 %.1f%%）',
            $metric,
            $lowerIsBetter ? sprintf('%.6f ms → %.6f ms', $baseline, $current) : sprintf('%.0f → %.0f ops/s', $baseline, $current),
            $threshold * 100,
            $regression * 100,
        );
    }

    return null;
}

/**
 * 基线与当前 JSON 比对：只判定监听集内指标——任一监听指标在任一侧缺失即违规（键名漂移即契约破坏）；
 * 监听集外的指标仅随基线存档，不参与门禁（跨机方差大，不适合硬阈值）。
 * Compare baseline vs current JSON: only watched metrics are judged — any watched metric missing on either side
 * violates (key drift means the contract broke); unwatched metrics ride the baseline archive only, outside the
 * gate (their cross-machine variance does not suit hard thresholds).
 *
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $current
 * @return list<string> 违规描述列表 Violation descriptions.
 */
function benchGateCompare(array $baseline, array $current, float $threshold): array
{
    $violations = [];
    foreach (BENCH_GATE_WATCHED_METRICS as $metric) {
        if (!array_key_exists($metric, $baseline)) {
            $violations[] = "{$metric}: 基线缺失该监听指标";

            continue;
        }
        if (!array_key_exists($metric, $current)) {
            $violations[] = "{$metric}: 当前结果缺失该监听指标";

            continue;
        }
        if (!is_int($baseline[$metric]) && !is_float($baseline[$metric])) {
            $violations[] = "{$metric}: 基线值必须是数字";

            continue;
        }
        if (!is_int($current[$metric]) && !is_float($current[$metric])) {
            $violations[] = "{$metric}: 当前值必须是数字";

            continue;
        }
        $violation = benchCompareMetric($metric, (float) $baseline[$metric], (float) $current[$metric], $threshold);
        if ($violation !== null) {
            $violations[] = $violation;
        }
    }

    return $violations;
}

/**
 * 解析 engine-bench --json 输出文件为指标表。
 * Parse an engine-bench --json output file into the metric table.
 *
 * @return array<string, mixed>
 * @throws \RuntimeException 文件不可读或非合法 JSON 对象 Unreadable file or not a valid JSON object.
 */
function benchGateLoad(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("无法读取基准文件：{$path}");
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("基准文件不是合法 JSON：{$path}（{$e->getMessage()}）");
    }
    if (!is_array($data) || array_is_list($data)) {
        throw new RuntimeException("基准文件顶层必须是 JSON 对象（指标名 → 数值）：{$path}");
    }

    return $data;
}

/**
 * 门禁脚本自测：正负向用例覆盖方向判定、阈值边界、缺指标/类型错误/解析失败路径与监听集语义。
 * Gate self-test: positive/negative cases covering direction inference, threshold boundaries,
 * missing/mistyped metrics, parse-failure paths and the watched-set semantics.
 */
function runBenchGateSelfTest(): int
{
    $failures = [];
    $check = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };

    // 方向判定 Direction inference
    $check(!benchMetricIsLowerBetter('aoi_query_ops_per_sec'), '吞吐指标默认越高越好');
    $check(benchMetricIsLowerBetter('world_update_ms_per_frame_entities1000'), '帧耗时指标越低越好');

    // 吞吐劣化 Throughput regression
    $check(benchCompareMetric('aoi_query_ops_per_sec', 1000.0, 799.0, 0.2) !== null, '吞吐 -20.1% 违规');
    $check(benchCompareMetric('aoi_query_ops_per_sec', 1000.0, 800.0, 0.2) === null, '吞吐恰 -20% 过闸（阈值含边界）');
    $check(benchCompareMetric('aoi_query_ops_per_sec', 1000.0, 1200.0, 0.2) === null, '吞吐提升不违规');

    // 帧耗时劣化 Frame-cost regression
    $check(benchCompareMetric('world_update_ms_per_frame_entities1000', 1.0, 1.21, 0.2) !== null, '耗时 +21% 违规');
    $check(benchCompareMetric('world_update_ms_per_frame_entities1000', 1.0, 1.2, 0.2) === null, '耗时 +20% 内过闸');
    $check(benchCompareMetric('world_update_ms_per_frame_entities1000', 1.0, 0.5, 0.2) === null, '耗时下降不违规');

    // 零基线守卫 Zero-baseline guard
    $check(benchCompareMetric('x_ms_per_frame', 0.0, 0.0, 0.2) === null, '零基线同零过闸');
    $check(benchCompareMetric('x_ms_per_frame', 0.0, 1.0, 0.2) !== null, '零基线非零违规（不除零）');

    // 监听集语义 Watched-set semantics（用例须覆盖全部监听指标，否则缺项本身即违规）
    // Watched-set semantics (cases must cover every watched metric; a missing entry is itself a violation)
    $isFrameCost = static fn (string $m): bool => str_contains($m, '_ms_per_frame');
    $fullBaseline = [];
    foreach (BENCH_GATE_WATCHED_METRICS as $metric) {
        $fullBaseline[$metric] = $isFrameCost($metric) ? 1.0 : 800.0;
    }
    $regressedCurrent = [];
    foreach ($fullBaseline as $metric => $value) {
        $regressedCurrent[$metric] = $isFrameCost($metric) ? $value * 2 : $value / 2;
    }

    $violations = benchGateCompare(
        $fullBaseline,
        [...$fullBaseline, 'extra_metric_ops_per_sec' => 999999],
        0.2,
    );
    $check($violations === [], '监听指标全过 + 集外多指标不报错（仅存档）');
    $violations = benchGateCompare($fullBaseline, $regressedCurrent, 0.2);
    $check(count($violations) === count(BENCH_GATE_WATCHED_METRICS), '吞吐减半 + 耗时翻倍逐项违规');

    $missingCase = array_diff(BENCH_GATE_WATCHED_METRICS, [BENCH_GATE_WATCHED_METRICS[0]]);
    $violations = benchGateCompare(array_fill_keys(BENCH_GATE_WATCHED_METRICS, 1), array_fill_keys([...$missingCase, 'other' => 2], 1), 0.2);
    $check(count($violations) === 1 && str_contains($violations[0], '当前结果缺失'), '当前缺监听指标报错');
    $violations = benchGateCompare(array_fill_keys(BENCH_GATE_WATCHED_METRICS, 1), [], 0.2);
    $check(count($violations) === count(BENCH_GATE_WATCHED_METRICS), '空当前结果逐项报缺失');
    $violations = benchGateCompare([...$fullBaseline, BENCH_GATE_WATCHED_METRICS[0] => 'oops'], $fullBaseline, 0.2);
    $check(count($violations) === 1 && str_contains($violations[0], '数字'), '非数字基线值报错');

    // 解析失败路径 Parse-failure paths
    $tmp = sys_get_temp_dir() . '/bench-gate-selftest-' . uniqid();
    try {
        file_put_contents($tmp, '{not json');
        try {
            benchGateLoad($tmp);
            $check(false, '非法 JSON 必须抛异常');
        } catch (RuntimeException) {
            $check(true, '非法 JSON 必须抛异常');
        }
        file_put_contents($tmp, '[1, 2]');
        try {
            benchGateLoad($tmp);
            $check(false, '顶层列表必须抛异常');
        } catch (RuntimeException) {
            $check(true, '顶层列表必须抛异常');
        }
        file_put_contents($tmp, '{"a_ops_per_sec": 100}');
        $check(benchGateLoad($tmp) === ['a_ops_per_sec' => 100], '合法对象正常解析');
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }

    if ($failures !== []) {
        printf("[bench-gate] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo "[bench-gate] SELF-TEST PASS：正负向用例全过\n";

    return 0;
}

// ── 入口：--self-test 优先（不触碰真实仓库路径）──
if (in_array('--self-test', $argv, true)) {
    exit(runBenchGateSelfTest());
}

$positional = array_values(array_filter($argv ?? [], static fn (string $arg): bool => !str_starts_with($arg, '--')));
if (count($positional) !== 3) {
    echo "用法 USAGE: php tools/bench-gate.php <baseline.json> <current.json> [--threshold=0.2]\n";
    exit(2);
}
[, $baselinePath, $currentPath] = $positional;

$threshold = 0.2;
foreach ($argv as $arg) {
    if (preg_match('/^--threshold=(\d*\.?\d+)$/', $arg, $m) === 1) {
        $threshold = (float) $m[1];
    }
}

try {
    $baseline = benchGateLoad($baselinePath);
    $violations = benchGateCompare($baseline, benchGateLoad($currentPath), $threshold);
} catch (RuntimeException $e) {
    echo '[bench-gate] FAIL：' . $e->getMessage() . "\n";

    exit(1);
}

if ($violations !== []) {
    echo "[bench-gate] 基准回归超标（基线 {$baselinePath} vs 当前 {$currentPath}，阈值 " . sprintf('%.0f%%', $threshold * 100) . "）：\n";
    foreach ($violations as $violation) {
        echo "  - {$violation}\n";
    }

    exit(1);
}

printf(
    "[bench-gate] OK：%d 项监听指标全部在阈值内（基线 %s 存档 %d 项，阈值 %.0f%%）。\n",
    count(BENCH_GATE_WATCHED_METRICS),
    $baselinePath,
    count($baseline),
    $threshold * 100,
);

exit(0);
