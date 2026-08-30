<?php

declare(strict_types=1);

// 定位：packages/demo/bin/perf-stats.php — 运行期性能观测脚本（只读 Redis 快照，不触碰服务进程）。
// 查询 PerfSampler 写入的指标（计数器 Hash / 直方图 Hash / 累计 Hash），打印最近采样窗口的
// 帧耗时分布、信封吞吐、网络出站字节/包，并给出可读摘要（P50/P90/P99 由桶分布近似估算）。
// Located at: packages/demo/bin/perf-stats.php — the runtime performance observability script (read-only Redis
// snapshots; never touches service processes). It reads the metrics PerfSampler wrote (counter Hash / histogram
// Hash / totals Hash) and prints the latest sampling window: frame-cost distribution, envelope throughput and
// outbound network bytes/packets, plus a human summary (P50/P90/P99 approximated from the buckets).

require __DIR__ . '/../../../vendor/autoload.php';

$opts = ['serviceId' => 'map-1#ch-1', 'redisHost' => '127.0.0.1', 'redisPort' => 6379, 'json' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--serviceId=(.+)$/', $arg, $m)) {
        $opts['serviceId'] = $m[1];
    } elseif (preg_match('/^--redisHost=(.+)$/', $arg, $m)) {
        $opts['redisHost'] = $m[1];
    } elseif (preg_match('/^--redisPort=(.+)$/', $arg, $m)) {
        $opts['redisPort'] = (int) $m[1];
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    }
}

$redis = new \Redis();
if ($redis->connect($opts['redisHost'], $opts['redisPort'], 1.0) !== true) {
    fwrite(STDERR, "[perf-stats] fatal: 无法连接 Redis\n");
    exit(1);
}

$prefix = 'nythros:perf:' . $opts['serviceId'];
$counters = $redis->hGetAll($prefix . ':counters') ?: [];
$hist = $redis->hGetAll($prefix . ':hist') ?: [];
$totals = $redis->hGetAll($prefix . ':totals') ?: [];
$last = $redis->get($prefix . ':last');

if ($counters === [] && $hist === [] && $totals === []) {
    echo "[perf-stats] 无指标（服务未启动或尚无采样）。 No metrics (service not up or no samples yet)." . PHP_EOL;
    exit(0);
}

/** 帧耗时桶（与 PerfProbe::FRAME_BUCKETS_MS 一致）。 Frame-cost buckets, matching PerfProbe::FRAME_BUCKETS_MS. */
$buckets = [0.0, 0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 64.0];

/** 近似分位数：从直方图桶累计比例。 Approximate percentile from the histogram buckets. */
$percentile = static function (array $bucketCounts, float $p) use ($buckets): float {
    $total = array_sum($bucketCounts);
    if ($total === 0) {
        return 0.0;
    }
    $target = $total * $p;
    $acc = 0.0;
    foreach ($buckets as $i => $bound) {
        $c = $bucketCounts[$i] ?? 0;
        $acc += $c;
        if ($acc >= $target) {
            $next = $buckets[$i + 1] ?? $bound * 2;
            return $bound + ($next - $bound) * ($target - ($acc - $c)) / max(1, $c);
        }
    }
    return end($buckets) * 2;
};

$frameHist = [];
foreach ($hist as $key => $count) {
    if (str_starts_with($key, 'world.frame_ms.')) {
        $frameHist[(int) substr($key, strlen('world.frame_ms.'))] = (int) $count;
    }
}

$out = [
    'serviceId' => $opts['serviceId'],
    'lastSample' => $last !== false ? json_decode($last, true) : null,
    'counters' => array_map('intval', $counters),
    'frameMs' => [
        'P50' => round($percentile($frameHist, 0.50), 3),
        'P90' => round($percentile($frameHist, 0.90), 3),
        'P99' => round($percentile($frameHist, 0.99), 3),
        'max_bucket_ms' => $buckets[max(array_keys($frameHist) ?: [0])] ?? null,
        'samples' => array_sum($frameHist),
    ],
    'totals' => $totals,
    'histogram' => $hist,
];

if ($opts['json']) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$ts = isset($out['lastSample']['ts']) ? date('H:i:s', (int) $out['lastSample']['ts']) : 'n/a';
echo sprintf("== 运行期性能快照（%s） ==%s", $opts['serviceId'], PHP_EOL);
echo "采样时间: " . $ts . PHP_EOL;
echo sprintf("帧耗时(ms): P50=%.3f  P90=%.3f  P99=%.3f  样本=%d%s", $out['frameMs']['P50'], $out['frameMs']['P90'], $out['frameMs']['P99'], $out['frameMs']['samples'], PHP_EOL);
echo "信封发布: " . ($counters['world.envelope_published'] ?? 0) . PHP_EOL;
echo "事件分发: " . ($counters['eventbus.envelopes_dispatched'] ?? 0) . PHP_EOL;
// 丢弃率交叉核对（仪表修正后的健康度口径）：dropped_total 应远小于 dispatched；
// published − dispatched − queued ≈ dropped（同窗口内 publish 与 flush 有一个 tick 的相位差，小量负值属正常）。
// The drop-rate cross-check (the post-fix health metric): dropped_total must sit far below dispatched;
// published − dispatched − queued ≈ dropped (publish and flush are one tick out of phase, so a small
// negative residual is normal).
$dispatched = (int) ($counters['eventbus.envelopes_dispatched'] ?? 0);
$dropped = (int) ($counters['eventbus.dropped_total'] ?? 0);
$dropRatio = ($dispatched + $dropped) > 0 ? $dropped / ($dispatched + $dropped) : 0.0;
echo sprintf("信封丢弃: %d（占比 %.4f%%，published−dispatched=%d 交叉核对）%s", $dropped, $dropRatio * 100, (int) ($counters['world.envelope_published'] ?? 0) - $dispatched, PHP_EOL);
echo sprintf("网络出站: %.1f KB / %d packets%s", (float) ($counters['network.out_bytes'] ?? 0) / 1024, (int) ($counters['network.out_packets'] ?? 0), PHP_EOL);
echo "事件总线 dropped: " . $dropped . PHP_EOL;
