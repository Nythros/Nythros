<?php

declare(strict_types=1);

// 定位：benchmarks/stress-map.php — Map 频道并发压力测试（真实 WebSocket 链路，stream_select 引擎）。
// Located at: benchmarks/stress-map.php — the Map-channel concurrent pressure test (real WebSocket links,
// driven by a stream_select engine).
//
// 用法 Usage:
//   php benchmarks/stress-map.php --clients=50 --seconds=15 [--json]
//   php benchmarks/stress-map.php --self-test
// 统计：auth 成功数（Map 二进制 auth_ok）、帧到达吞吐、帧到达延迟 P50/P90/P99（收包间隙近似）、字节吞吐。
// 引擎说明：v1 用 Workerman AsyncTcpConnection 做客户端——为旧网关拓扑所写，随 ADR-021 单栈化后
// 客户端侧握手/认证时序腐化（10 客户端 25s 仅 3 个完成建链，单连接建链耗时 ~10s 且机制性漂移）。
// v2 改为原生 socket + stream_select 多路复用：登录探针同款最小 RFC6455 客户端（benchmarks/lib/
// drill-harness.php 共用），与演练器（soak/fault-drill）共享同一套已验证的帧协议实现；
// 不再依赖 Workerman 客户端行为，单连接建链 <100ms。
// Engine note: v1 used a Workerman AsyncTcpConnection client — written for the legacy gateway topology, its
// client-side handshake/auth timing rotted after the ADR-021 single-stack migration (3 of 10 clients completed
// within 25s; a single connection took ~10s with drift). v2 uses raw sockets + stream_select multiplexing,
// sharing the battle-tested minimal RFC6455 client with the drill harness (benchmarks/lib/drill-harness.php);
// no Workerman client dependency, per-connection establishment <100ms.
//
// 前置 Precondition: the stack is running (`php bin/server start`); accounts 1001..N (N ≤ 10 with demo defaults).

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/drill-harness.php';
require __DIR__ . '/../packages/demo/bin/lib/map-codec.php';

const STRESS_BUCKET_EDGES = [0, 10, 20, 40, 80, 160, 320, 640, 1280];

if (in_array('--self-test', $argv, true)) {
    exit(stressSelfTest());
}

$opts = ['clients' => 50, 'seconds' => 15, 'json' => false, 'moveMs' => 1000, 'settleMoves' => 0, 'mapIds' => 'map-1'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(clients|seconds)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = (int) $m[2];
    } elseif (preg_match('/^--move-ms=(\d+)$/', $arg, $m)) {
        $opts['moveMs'] = max(50, (int) $m[1]);
    } elseif (preg_match('/^--settle-moves=(\d+)$/', $arg, $m)) {
        $opts['settleMoves'] = (int) $m[1];
    } elseif (preg_match('/^--map-ids=([\w,-]+)$/', $arg, $m)) {
        $opts['mapIds'] = $m[1];
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    }
}
// mapId 轮转：客户端按索引分散到多个地图（gateway selectChannel 在该地图的频道间再按负载分配）——
// 均衡拓扑容量测试用，避免全部客户端挤进单一地图的 2 个频道（单 worker 过热的实测根因，blueprint/33 §6）。
// mapId round-robin: clients spread across maps by index (selectChannel then load-balances within each map's
// channels) — for balanced-topology capacity tests, avoiding everyone piling into a single map's 2 channels
// (the measured root cause of the single-worker oversubscription, blueprint/33 §6).
$mapIdList = array_values(array_filter(array_map('trim', explode(',', $opts['mapIds']))));
$clients = max(1, $opts['clients']);
$seconds = max(1, $opts['seconds']);
$stats = ['frames' => 0, 'bytes' => 0, 'authOk' => 0, 'peakFps' => 0.0, 'windowFrames' => 0, 'windowAt' => 0.0];
$stats['windowAt'] = microtime(true);
$latencyHist = [];

/**
 * 帧延迟记录（收包间隙，毫秒；同批并包记 0——跨批间隙反映广播周期/拥塞）。
 * Records the frame-arrival gap (ms); same-batch coalescing counts 0 — cross-batch gaps reflect the
 * server's broadcast period/congestion.
 */
$recordLatency = static function (float $ms) use (&$latencyHist): void {
    $idx = 0;
    foreach (STRESS_BUCKET_EDGES as $i => $edge) {
        if ($ms >= $edge) {
            $idx = $i;
        }
    }
    $latencyHist[$idx] = ($latencyHist[$idx] ?? 0) + 1;
};

$percentile = static function (float $p) use (&$latencyHist): float {
    $total = array_sum($latencyHist);
    if ($total === 0) {
        return 0.0;
    }
    $target = $total * $p;
    $acc = 0;
    foreach (STRESS_BUCKET_EDGES as $i => $edge) {
        $acc += $latencyHist[$i] ?? 0;
        if ($acc >= $target) {
            $next = STRESS_BUCKET_EDGES[$i + 1] ?? $edge * 2;

            return (float) $edge + (($next - $edge) * ($target - ($acc - ($latencyHist[$i] ?? 0)))) / max(1, $latencyHist[$i] ?? 1);
        }
    }

    return (float) end(STRESS_BUCKET_EDGES) * 2;
};

// ── ① 建链：每客户端 gateway JSON 登录（同步，毫秒级）→ token + map 地址 → Map 二进制 auth ──
// ── ① Establish: per-client gateway JSON login (synchronous, milliseconds) -> token + map addr -> Map binary auth ──
$conns = []; // streamId => ['stream','buf','lastArrival','name','lastMove']
$failed = 0;
for ($i = 1; $i <= $clients; ++$i) {
    $name = (string) (1000 + $i);
    $gw = drillWsHandshake('127.0.0.1', 18285);
    if ($gw === false) {
        ++$failed;
        continue;
    }
    drillWsSend($gw, json_encode([
        'type' => 'auth',
        'requestId' => "stress:{$name}",
        'timestamp' => microtime(true),
        'version' => 1,
        'payload' => ['username' => $name, 'password' => 'secret', 'mapId' => $mapIdList[$i % count($mapIdList)], 'version' => 1],
    ], JSON_UNESCAPED_UNICODE));

    $token = null;
    $mapAddr = null;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $frame = drillReadWsFrame($gw, 2.0);
        if ($frame === null || in_array($frame['opcode'], [0x8, 0x9], true)) {
            break;
        }
        $msg = json_decode($frame['payload'], true);
        if (($msg['type'] ?? '') === 'auth_ok') {
            $token = $msg['payload']['token'] ?? null;
            $mapAddr = $msg['payload']['map']['wsAddress'] ?? null;
            break;
        }
        if (($msg['type'] ?? '') === 'auth_failed') {
            break;
        }
    }
    fclose($gw);
    if (!is_string($token) || !is_string($mapAddr) || preg_match('#^ws://([^:]+):(\d+)$#', $mapAddr, $m) !== 1) {
        ++$failed;
        continue;
    }

    $map = drillWsHandshake($m[1], (int) $m[2]);
    if ($map === false) {
        ++$failed;
        continue;
    }
    stream_set_blocking($map, false);
    drillWsSend($map, frameMap('auth', ['token' => $token, 'version' => 1], "map-auth:{$name}"), 0x2);
    // 真实负载模型：客户端按索引落到 4 条对角走廊之一（离散走位 → AOI 视野受限，不再全图互见），
    // 走廊内 ping-pong 折返（settle-moves 步折返，保持有界且持续产生视野差分）。
    // The realistic-load model: clients take one of 4 diagonal corridors by index (dispersed walking limits the
    // AOI view instead of everyone seeing everyone), ping-ponging within the corridor (turning every
    // settle-moves steps) to stay bounded while continuously generating vision diffs.
    $dirs = [[1, 1], [1, -1], [-1, 1], [-1, -1]];
    $conns[(int) $map] = [
        'stream' => $map, 'buf' => '', 'lastArrival' => 0.0, 'name' => $name, 'lastMove' => microtime(true),
        'dir' => $dirs[$i % 4], 'steps' => 0, 'turnAt' => max(1, $opts['settleMoves']),
    ];
}

$startedAt = microtime(true);
$deadline = $startedAt + $seconds;

// ── ② select 循环：读帧计数 + 每连接每秒 move ──
// ── ② The select loop: count arriving frames + a per-connection move each second ──
while (microtime(true) < $deadline && $conns !== []) {
    $read = [];
    foreach ($conns as $c) {
        $read[] = $c['stream'];
    }
    if (stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }
    $now = microtime(true);
    foreach ($read as $stream) {
        $key = (int) $stream;
        $chunk = @fread($stream, 65536);
        if ($chunk === '' || $chunk === false) {
            unset($conns[$key]); // 对端关闭 Peer closed.
            continue;
        }
        $conns[$key]['buf'] .= $chunk;
        foreach (drillParseWsBuffer($conns[$key]['buf']) as $frame) {
            if ($frame['opcode'] === 0x8) {
                unset($conns[$key]);
                continue 2;
            }
            if ($frame['opcode'] !== 0x2) {
                continue;
            }
            $frames = decodeMapFrames($frame['payload']);
            $stats['frames'] += count($frames);
            $stats['windowFrames'] += count($frames);
            $stats['bytes'] += strlen($frame['payload']);
            foreach ($frames as $f) {
                if (($f['type'] ?? null) === 'auth_ok') {
                    ++$stats['authOk'];
                }
            }
            if ($conns[$key]['lastArrival'] > 0.0) {
                $recordLatency(($now - $conns[$key]['lastArrival']) * 1000);
            }
            $conns[$key]['lastArrival'] = $now;
        }
    }
    // 每连接按 move-ms 节奏移动（真实负载 ≈150ms/步 ≈ 6.7 步/s）；走廊 ping-pong：走满 turnAt 步即折返
    // A move per connection at the move-ms cadence (realistic ≈150ms/step ≈ 6.7 steps/s); corridor ping-pong:
    // reverse at turnAt steps.
    foreach ($conns as $c) {
        if (($now - $c['lastMove']) * 1000 >= $opts['moveMs']) {
            $c['lastMove'] = $now;
            $c['steps']++;
            if ($c['steps'] % $c['turnAt'] === 0) {
                $c['dir'] = [-$c['dir'][0], -$c['dir'][1]];
            }
            drillWsSend($c['stream'], frameMap('move', ['dx' => $c['dir'][0], 'dy' => $c['dir'][1]], 'mv:' . $c['name']), 0x2);
        }
    }
    // 每秒吞吐窗口：更新 peakFps The per-second throughput window: update peakFps.
    if ($now - $stats['windowAt'] >= 1.0) {
        $stats['peakFps'] = max($stats['peakFps'], $stats['windowFrames'] / ($now - $stats['windowAt']));
        $stats['windowFrames'] = 0;
        $stats['windowAt'] = $now;
    }
}

$elapsed = max(0.001, microtime(true) - $startedAt);
foreach ($conns as $c) {
    fclose($c['stream']);
}

$fps = round($stats['frames'] / $elapsed, 1);
$p50 = $percentile(0.5);
$p90 = $percentile(0.9);
$p99 = $percentile(0.99);

if ($opts['json']) {
    echo json_encode([
        'clients' => $clients,
        'seconds' => round($elapsed, 1),
        'moveMs' => $opts['moveMs'],
        'settleMoves' => $opts['settleMoves'],
        'authOk' => $stats['authOk'],
        'establishFailed' => $failed,
        'frames' => $stats['frames'],
        'bytesKB' => round($stats['bytes'] / 1024, 1),
        'fps' => $fps,
        'peakFps' => round($stats['peakFps'], 1),
        'latencyMs' => ['P50' => round($p50, 1), 'P90' => round($p90, 1), 'P99' => round($p99, 1), 'samples' => array_sum($latencyHist)],
        'p99' => round($p99, 1),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo sprintf("客户端=%d auth=%d 建链失败=%d fps=%.1f(peak %.1f) 帧=%d bytes=%.1fKB\n", $clients, $stats['authOk'], $failed, $fps, $stats['peakFps'], $stats['frames'], $stats['bytes'] / 1024);
echo sprintf("延迟ms P50=%.1f P90=%.1f P99=%.1f 样本=%d\n", $p50, $p90, $p99, array_sum($latencyHist));

/**
 * 自测：ws 缓冲帧解析器（分片到达/长帧/多帧粘包），无网络依赖。解析器本体已上收 drill-harness
 * （stress-play 混合引擎共用），此处保留回归用例。
 * Self-test: the ws buffer-frame parser (fragmented arrival / long frames / coalesced frames), no network.
 * The parser itself moved up to drill-harness (shared with stress-play); the regression cases stay here.
 */
function stressSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };
    $wire = static function (string $payload, int $opcode = 0x2): string {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        $head .= $len < 126 ? chr($len) : ($len < 65536 ? chr(126) . pack('n', $len) : chr(127) . pack('J', $len));

        return $head . $payload;
    };

    // 多帧粘包一次解析
    $buf = $wire('abc') . $wire('de');
    $frames = drillParseWsBuffer($buf);
    $assert(count($frames) === 2 && $frames[0]['payload'] === 'abc' && $frames[1]['payload'] === 'de' && $buf === '', '多帧粘包全解析');

    // 分片到达：残帧保留在缓冲，补齐后解析
    $buf = $wire('hello');
    $buf = substr($buf, 0, 3);
    drillParseWsBuffer($buf);
    $buf .= substr($wire('hello'), 3);
    $frames = drillParseWsBuffer($buf);
    $assert(count($frames) === 1 && $frames[0]['payload'] === 'hello' && $buf === '', '残帧补齐后解析');

    // 长帧（127 长度字段路径）
    $long = str_repeat('x', 70000);
    $longWire = $wire($long);
    $frames = drillParseWsBuffer($longWire);
    $assert(count($frames) === 1 && $frames[0]['payload'] === $long, '64KB+ 长帧（127 长度）解析');

    if ($failures !== []) {
        printf("[stress-map] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo '[stress-map] SELF-TEST PASS' . "\n";

    return 0;
}
