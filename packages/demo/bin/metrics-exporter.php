<?php

declare(strict_types=1);

// 定位：packages/demo/bin/metrics-exporter.php — Prometheus 指标导出器（只读 Redis，不触碰服务进程）。
// 将 PerfSampler 写入的 nythros:perf:{serviceId}:* 键翻译为 Prometheus 文本格式，经独立 HTTP 端口暴露，
// 供 Prometheus/Grafana 抓取。语义与 docs/performance.md §3、packages/demo/bin/perf-stats.php 一致。
// Located at: packages/demo/bin/metrics-exporter.php — the Prometheus metrics exporter (read-only Redis;
// never touches service processes). Translates the nythros:perf:{serviceId}:* keys written by PerfSampler into
// the Prometheus text format on a standalone HTTP port for Prometheus/Grafana scraping. Semantics match
// docs/performance.md §3 and packages/demo/bin/perf-stats.php.
//
// 用法 Usage:
//   php packages/demo/bin/metrics-exporter.php [--addr=0.0.0.0:19100] [--redisHost=127.0.0.1] [--redisPort=6379]
//   php packages/demo/bin/metrics-exporter.php --self-test
//
// 暴露指标 Exported metrics（service = serviceId，如 map-1#ch-1）:
//   nythros_perf_counter{service,event}                         事件计数（单调累计） monotonic event counters
//   nythros_perf_hist_bucket{service,metric,le}                 world.frame_ms 直方图（Prometheus 标准累积桶，le 毫秒）
//   nythros_perf_hist{service,metric,bucket}                    其他直方图按原始桶序号暴露（raw bucket gauge）
//   nythros_perf_total_ms{service,metric}                       累计毫秒（均值 = total / 对应 counter）
//   nythros_perf_last_sample_timestamp_seconds{service}         最近采样时间戳
//   nythros_perf_scrape_errors_total                            导出器自身抓取失败计数

require __DIR__ . '/../../../vendor/autoload.php';

/** 与 engine PerfProbe::FRAME_BUCKETS_MS 对齐（perf-stats.php 同源）。 Buckets, matching PerfProbe::FRAME_BUCKETS_MS. */
const FRAME_BUCKETS_MS = [0.0, 0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 64.0];

/**
 * 键族迭代（phpredis 版本兼容）：新版 scan() 单次返回键数组（游标耗尽后 false），旧版逐键返回字符串。
 * Key-family iteration (phpredis version compatibility): newer phpredis scan() returns a KEY ARRAY per call
 * (false once the cursor exhausts); older builds return one key string per call. Support both shapes.
 *
 * @return \Generator<int, string>
 */
function scanKeys(\Redis $redis, string $pattern): \Generator
{
    $it = null;
    do {
        $res = $redis->scan($it, $pattern);
        if ($res === false) {
            return;
        }
        foreach ((array) $res as $key) {
            if (is_string($key) && $key !== '') {
                yield $key;
            }
        }
    } while ($it > 0);
}

/**
 * 读取全部 PerfSampler 键，返回 serviceId => [counters, hist, totals, last]。
 *
 * @return array<string, array{counters: array<string,int>, hist: array<string,int>, totals: array<string,float>, last: ?float}>
 */
function collectPerf(\Redis $redis): array
{
    $services = [];
    foreach (['counters', 'hist', 'totals'] as $kind) {
        foreach (scanKeys($redis, 'nythros:perf:*:' . $kind) as $key) {
            if (!preg_match('/^nythros:perf:(.+):' . $kind . '$/', $key, $m)) {
                continue;
            }
            $serviceId = $m[1];
            $services[$serviceId] ??= ['counters' => [], 'hist' => [], 'totals' => [], 'last' => null];
            $raw = $redis->hGetAll($key) ?: [];
            $services[$serviceId][$kind] = match ($kind) {
                'totals' => array_map('floatval', $raw),
                default => array_map('intval', $raw),
            };
        }
    }
    foreach (scanKeys($redis, 'nythros:perf:*:last') as $key) {
        if (!preg_match('/^nythros:perf:(.+):last$/', $key, $m)) {
            continue;
        }
        $payload = json_decode((string) $redis->get($key), true);
        if (is_array($payload) && isset($payload['ts'])) {
            $services[$m[1]]['last'] = (float) $payload['ts'];
        }
    }

    ksort($services);

    return $services;
}

/** Prometheus 文本格式转义（label value）。 */
function escapeLabel(string $value): string
{
    return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\\n'], $value);
}

/**
 * 把 collectPerf 的产物渲染为 Prometheus 文本格式。
 *
 * @param array<string, array{counters: array<string,int>, hist: array<string,int>, totals: array<string,float>, last: ?float}> $perf
 */
function renderMetrics(array $perf): string
{
    $lines = [];
    $counters = array_merge(...array_values(array_map(
        static fn (array $s): array => $s['counters'],
        $perf ?: [['counters' => []]]
    )));

    $lines[] = '# HELP nythros_perf_counter Nythros 引擎事件计数（单调累计，进程生命周期内）。';
    $lines[] = '# TYPE nythros_perf_counter counter';
    foreach ($perf as $serviceId => $s) {
        foreach ($s['counters'] as $event => $count) {
            $lines[] = sprintf(
                'nythros_perf_counter{service="%s",event="%s"} %d',
                escapeLabel($serviceId),
                escapeLabel($event),
                $count
            );
        }
    }

    $lines[] = '# HELP nythros_perf_hist_bucket world.frame_ms 帧耗时直方图（累积桶，单位 ms）。';
    $lines[] = '# TYPE nythros_perf_hist_bucket histogram';
    foreach ($perf as $serviceId => $s) {
        $frameBuckets = [];
        foreach ($s['hist'] as $field => $count) {
            if (preg_match('/^world\.frame_ms\.(\d+)$/', $field, $m)) {
                $frameBuckets[(int) $m[1]] = $count;
            }
        }
        if ($frameBuckets === []) {
            continue;
        }
        ksort($frameBuckets);
        $cumulative = 0;
        foreach (FRAME_BUCKETS_MS as $i => $bound) {
            if ($i === 0) {
                continue; // 桶 0 为 [0,0.5) 的下界占位，无样本语义
            }
            $cumulative += $frameBuckets[$i] ?? 0;
            $lines[] = sprintf(
                'nythros_perf_hist_bucket{service="%s",metric="world.frame_ms",le="%g"} %d',
                escapeLabel($serviceId),
                $bound,
                $cumulative
            );
        }
        $lines[] = sprintf(
            'nythros_perf_hist_bucket{service="%s",metric="world.frame_ms",le="+Inf"} %d',
            escapeLabel($serviceId),
            $cumulative
        );
    }

    $lines[] = '# HELP nythros_perf_hist 非 frame_ms 直方图的原始桶计数（bucket = PerfProbe 桶序号，非累积）。';
    $lines[] = '# TYPE nythros_perf_hist gauge';
    foreach ($perf as $serviceId => $s) {
        foreach ($s['hist'] as $field => $count) {
            if (str_starts_with($field, 'world.frame_ms.')) {
                continue;
            }
            if (!preg_match('/^(.+)\.(\d+)$/', $field, $m)) {
                continue;
            }
            $lines[] = sprintf(
                'nythros_perf_hist{service="%s",metric="%s",bucket="%s"} %d',
                escapeLabel($serviceId),
                escapeLabel($m[1]),
                $m[2],
                $count
            );
        }
    }

    $lines[] = '# HELP nythros_perf_total_ms 累计耗时（ms；均值 = 该值 / 同名 counter）。';
    $lines[] = '# TYPE nythros_perf_total_ms gauge';
    foreach ($perf as $serviceId => $s) {
        foreach ($s['totals'] as $metric => $ms) {
            $lines[] = sprintf(
                'nythros_perf_total_ms{service="%s",metric="%s"} %s',
                escapeLabel($serviceId),
                escapeLabel($metric),
                rtrim(rtrim(number_format($ms, 3, '.', ''), '0'), '.')
            );
        }
    }

    $lines[] = '# HELP nythros_perf_last_sample_timestamp_seconds 最近一次 PerfSampler 采样时间戳。';
    $lines[] = '# TYPE nythros_perf_last_sample_timestamp_seconds gauge';
    foreach ($perf as $serviceId => $s) {
        if ($s['last'] !== null) {
            $lines[] = sprintf(
                'nythros_perf_last_sample_timestamp_seconds{service="%s"} %s',
                escapeLabel($serviceId),
                number_format($s['last'], 3, '.', '')
            );
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * 门禁脚本自测：无 Redis 依赖，直接以 fixture 断言渲染产物形状。
 */
function runSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };
    $perf = [
        'map-1#ch-1' => [
            'counters' => ['world.envelope_published' => 42, 'network.out_bytes' => 1024],
            'hist' => ['world.frame_ms.2' => 7, 'world.frame_ms.3' => 3, 'other.metric.0' => 5],
            'totals' => ['world.frame_ms' => 12.5],
            'last' => 1725000000.125,
        ],
        'social-gateway' => ['counters' => [], 'hist' => [], 'totals' => [], 'last' => null],
    ];
    $doc = renderMetrics($perf);
    $assert(str_contains($doc, 'nythros_perf_counter{service="map-1#ch-1",event="world.envelope_published"} 42'), '计数器逐事件暴露');
    // 累积桶：le=4 应含桶 2+3 = 10
    $assert(str_contains($doc, 'nythros_perf_hist_bucket{service="map-1#ch-1",metric="world.frame_ms",le="4"} 10'), 'frame_ms 累积桶正确累计');
    $assert(str_contains($doc, 'le="+Inf"} 10'), '终止 +Inf 桶存在');
    $assert(str_contains($doc, 'nythros_perf_hist{service="map-1#ch-1",metric="other.metric",bucket="0"} 5'), '非 frame_ms 原始桶暴露');
    $assert(str_contains($doc, 'nythros_perf_total_ms{service="map-1#ch-1",metric="world.frame_ms"} 12.5'), '累计值暴露');
    $assert(str_contains($doc, 'nythros_perf_last_sample_timestamp_seconds{service="map-1#ch-1"} 1725000000.125'), '最近采样时间戳暴露');
    $assert(!str_contains($doc, 'social-gateway'), '无采样数据的 service 不产生指标行');
    $assert(substr_count($doc, "HELP") === 5, 'HELP 头数量正确');

    if ($failures !== []) {
        printf("[metrics-exporter] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo "[metrics-exporter] SELF-TEST PASS\n";

    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$opts = ['addr' => '0.0.0.0:19100', 'redisHost' => '127.0.0.1', 'redisPort' => 6379];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--addr=(.+)$/', $arg, $m)) {
        $opts['addr'] = $m[1];
    } elseif (preg_match('/^--redisHost=(.+)$/', $arg, $m)) {
        $opts['redisHost'] = $m[1];
    } elseif (preg_match('/^--redisPort=(.+)$/', $arg, $m)) {
        $opts['redisPort'] = (int) $m[1];
    }
}

$redis = new \Redis();
$scrapeErrors = 0;
$server = @stream_socket_server('tcp://' . $opts['addr'], $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "[metrics-exporter] fatal: 监听 {$opts['addr']} 失败：$errstr\n");
    exit(1);
}
echo "[metrics-exporter] serving http://{$opts['addr']}/metrics (redis={$opts['redisHost']}:{$opts['redisPort']})\n";

while (true) {
    $conn = @stream_socket_accept($server, 1.0);
    if ($conn === false) {
        continue; // 周期性空转，保持进程可被信号打断
    }
    // 读请求头（Prometheus GET /metrics），忽略内容，直接回当前快照
    $request = '';
    while (!feof($conn) && strlen($request) < 8192) {
        $chunk = fread($conn, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (str_contains($request, "\r\n\r\n")) {
            break;
        }
    }
    $body = '';
    $ok = false;
    try {
        if (!$redis->isConnected()) {
            if (!$redis->connect($opts['redisHost'], $opts['redisPort'], 1.0)) {
                throw new \RuntimeException('redis connect failed');
            }
            // 生产 Redis 认证与库选择（与 run-worker 同口径，ADR-028）
            // Production Redis auth & db selection (same convention as run-worker, ADR-028).
            $redisPassword = getenv('NYTHROS_REDIS_PASSWORD');
            if (is_string($redisPassword) && $redisPassword !== '') {
                @$redis->auth($redisPassword);
            }
            $redisDb = getenv('NYTHROS_REDIS_DB');
            if (is_string($redisDb) && $redisDb !== '' && preg_match('/^\d+$/', $redisDb) === 1) {
                @$redis->select((int) $redisDb);
            }
        }
        $body = renderMetrics(collectPerf($redis));
        $ok = true;
    } catch (\Throwable $e) {
        ++$scrapeErrors;
        $body = "nythros_perf_scrape_errors_total {$scrapeErrors}\n";
        error_log("[metrics-exporter] scrape failed: {$e->getMessage()}");
    }
    $status = $ok ? '200 OK' : '500 Internal Server Error';
    fwrite($conn, "HTTP/1.1 {$status}\r\nContent-Type: text/plain; version=0.0.4; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($conn);
}
