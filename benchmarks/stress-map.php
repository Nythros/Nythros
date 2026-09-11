<?php

declare(strict_types=1);

// 定位：benchmarks/stress-map.php — Map 频道并发压力测试（真实 WebSocket 链路，stream_select 引擎）。
// Located at: benchmarks/stress-map.php — the Map-channel concurrent pressure test (real WebSocket links,
// driven by a stream_select engine).
//
// 用法 Usage:
//   php benchmarks/stress-map.php --clients=50 --seconds=15 [--json]
//   php benchmarks/stress-map.php --clients=1600 --seconds=15 --procs=8 --json
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
// 多进程模式（--procs=N，2026-09 新增）：单进程客户端在 ~400 连接时自身饱和（单 PHP 进程要收 30 万+ 帧/s，
// P99 被客户端侧拖高，测不到服务端天花板）。--procs=N 由父进程 fork N 个子 worker，每个 worker 独占一段
// 连续 uid（1001 起按索引切分）并跑独立的 stream_select 循环；子 worker 把本组统计写入临时 JSON，父进程
// 等待全部退出后合并（计数求和、延迟直方图分桶相加、窗口 = 各 worker 运行窗均值——worker 建链完成时刻
// 因网关 bcrypt 串行而错峰，并集窗会低估 fps），并负责服务端 CPU/RSS 采样与最终输出。
// 窗口口径、JSON 字段与单进程模式一致；`peakFps` 为各 worker 峰值之和（近似上界）。
// Multi-process mode (--procs=N, added 2026-09): the single-process client saturates itself at ~400 connections
// (one PHP process must receive 300k+ frames/s, inflating P99 on the client side). With --procs=N the parent
// forks N workers, each owning a contiguous uid slice from 1001 and running its own stream_select loop; each
// worker writes its stats to a temp JSON file, and the parent merges after all exit (counts summed, latency
// histogram buckets summed, window = mean of per-worker run windows — the gateway serializes bcrypt so workers
// finish establishing at staggered times and a union window would understate fps) and owns server CPU/RSS
// sampling plus the final output. Ready barrier: a worker drops a ready file after establishing and waits for
// the parent's go file, so the timed loops start together instead of letting early workers collide with the
// later workers' login storm. `peakFps` is the sum of per-worker peaks (approximate upper bound).
//
// 前置 Precondition: the stack is running (`php bin/server start`); accounts 1001..N (N ≤ 10 with demo defaults).
// 多进程模式需 pcntl（Linux/WSL2）；Windows 无 pcntl 时自动回退 procs=1。

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/drill-harness.php';
require __DIR__ . '/../packages/demo/bin/lib/map-codec.php';

const STRESS_BUCKET_EDGES = [0, 10, 20, 40, 80, 160, 320, 640, 1280];

if (in_array('--self-test', $argv, true)) {
    exit(stressSelfTest());
}

$opts = ['clients' => 50, 'seconds' => 15, 'procs' => 1, 'json' => false, 'moveMs' => 1000, 'settleMoves' => 0, 'mapIds' => 'map-1'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(clients|seconds|procs)=(\d+)$/', $arg, $m)) {
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
$procs = min(max(1, $opts['procs']), $clients);
if ($procs > 1 && !function_exists('pcntl_fork')) {
    fwrite(STDERR, "[stress-map] --procs>1 需要 pcntl（Windows 无 pcntl，请用 WSL2 运行）；已回退 procs=1\n");
    $procs = 1;
}

/**
 * 服务端进程采样（maps worker 的 RSS 求和 + jiffies 求和）：每连接内存标定的观测侧。
 * **采样对象修正（2026-09 实测根因）**：旧口径按 cmdline 含 `start-maps.php` 匹配，命中的是
 * Workerman **master**（不承载连接，CPU/RSS 恒平）——这正是 §6.3「CPU 恒 0%/RSS 恒定」的真相
 * （此前误判为 jiffies 分辨率不足）。真实负载在 **worker 子进程**（标题 `worker process ... websocket://`），
 * 故改为「master 的子进程」求和；jiffies 解析同时从最后一个 ')' 起切分（comm 含空格不错位），
 * CPU 用首末累计差分（跨全窗，低负载也有分辨率）。
 * Server-process sampling (maps workers' RSS + jiffies): the observation side for per-connection memory.
 * Sample-target fix (2026-09 root cause): the old matcher (`start-maps.php` in cmdline) hit the Workerman
 * MASTER (carries no connections, flat CPU/RSS) — the true story behind §6.3's "CPU always 0% / RSS constant"
 * (previously misread as jiffy resolution). Real load lives in the worker children (`worker process ...
 * websocket://`), so we sum those; jiffies are parsed after the last ')' (spaces in comm cannot misalign),
 * and CPU uses the first/last cumulative delta (sub-jiffy resolution over the whole window).
 *
 * @return array{pids: list<int>, jiffies: int, rssKb: int, t: float}
 */
$sampleServer = static function (): array {
    $masterPids = [];
    foreach (glob('/proc/[0-9]*/cmdline') as $cmdlineFile) {
        $cmd = @file_get_contents($cmdlineFile);
        if ($cmd !== false && strpos($cmd, 'start-maps.php') !== false) {
            $masterPids[(int) basename(dirname($cmdlineFile))] = true;
        }
    }

    $jiffies = 0;
    $rssKb = 0;
    $pids = [];
    foreach (glob('/proc/[0-9]*/stat') as $statFile) {
        $stat = @file_get_contents($statFile);
        if ($stat === false) {
            continue;
        }
        $rp = strrpos($stat, ')');
        if ($rp === false) {
            continue;
        }
        $fields = explode(' ', substr($stat, $rp + 2));
        $ppid = (int) ($fields[1] ?? 0);
        if (!isset($masterPids[$ppid])) {
            continue;
        }
        $pid = (int) basename(dirname($statFile));
        $pids[] = $pid;
        $jiffies += (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0); // utime+stime utime+stime
        $status = @file_get_contents(sprintf('/proc/%d/status', $pid));
        if ($status !== false && preg_match('/VmRSS:\s+(\d+) kB/', $status, $m)) {
            $rssKb += (int) $m[1];
        }
    }
    sort($pids);

    return ['pids' => $pids, 'jiffies' => $jiffies, 'rssKb' => $rssKb, 't' => microtime(true)];
};

/** 延迟值 → 直方图桶下标（右开区间，与 STRESS_BUCKET_EDGES 对齐）。 Latency value to histogram bucket index. */
function stressBucketIndex(float $ms): int
{
    $idx = 0;
    foreach (STRESS_BUCKET_EDGES as $i => $edge) {
        if ($ms >= $edge) {
            $idx = $i;
        }
    }

    return $idx;
}

/**
 * 桶直方图 → 分位数（桶内线性插值；无样本返回 0）。
 * Bucket histogram to percentile (linear interpolation inside the bucket; 0 when empty).
 *
 * @param array<int, int> $hist 桶下标 → 计数 Bucket index to count.
 */
function stressPercentile(array $hist, float $p): float
{
    $total = array_sum($hist);
    if ($total === 0) {
        return 0.0;
    }
    $target = $total * $p;
    $acc = 0;
    foreach (STRESS_BUCKET_EDGES as $i => $edge) {
        $count = $hist[$i] ?? 0;
        $acc += $count;
        if ($acc >= $target) {
            $next = STRESS_BUCKET_EDGES[$i + 1] ?? $edge * 2;

            return (float) $edge + (($next - $edge) * ($target - ($acc - $count))) / max(1, $count);
        }
    }
    $edges = STRESS_BUCKET_EDGES;

    return (float) $edges[count($edges) - 1] * 2;
}

/**
 * 单个客户端组：建立 [fromIdx..toIdx] 的连续 uid 连接并跑满 select 循环，返回本组统计。
 * 多进程模式下由各子 worker 调用；单进程模式下由主流程直接调用（fromIdx=1, toIdx=clients）。
 * 就绪栅栏（可选）：建链完成后落 $readyFile 并向父进程的 $goFile 轮询等待，全部 worker 就绪后统一开表——
 * 避免先建好的 worker 立即开跑、与后建 worker 的登录洪峰互撞（网关 bcrypt 串行使建链完成时刻错峰数秒）。
 * One client group: establishes the contiguous uid range [fromIdx..toIdx] and runs the select loop to the
 * deadline, returning this group's stats. Called by each forked worker in multi-process mode, or directly
 * by the main flow in single-process mode. Optional ready barrier: after establishing, the worker drops
 * $readyFile and polls for the parent's $goFile so all workers start their timed loops together.
 *
 * @param array<string, mixed> $opts 运行参数 Run options (seconds/moveMs/settleMoves).
 * @param list<string> $mapIdList mapId 轮转表 mapId round-robin list.
 * @param string|null $readyFile 就绪栅栏文件（null = 不启用）Ready-barrier file (null = disabled).
 * @param string|null $goFile 开表信号文件（null = 不启用）Go-signal file (null = disabled).
 * @return array<string, mixed> 本组统计（计数/直方图/循环窗口绝对时间）This group's stats (counts, histogram, absolute loop window).
 */
function stressRunWorker(array $opts, array $mapIdList, int $fromIdx, int $toIdx, ?string $readyFile = null, ?string $goFile = null): array
{
    $conns = []; // streamId => ['stream','buf','lastArrival','name','lastMove','dir','steps','turnAt']
    $failed = 0;
    $latencyHist = [];
    $stats = ['frames' => 0, 'bytes' => 0, 'authOk' => 0, 'peakFps' => 0.0, 'windowFrames' => 0];

    // ── ① 建链：每客户端 gateway JSON 登录（同步，毫秒级）→ token + map 地址 → Map 二进制 auth ──
    // ── ① Establish: per-client gateway JSON login (synchronous, milliseconds) -> token + map addr -> Map binary auth ──
    for ($i = $fromIdx; $i <= $toIdx; ++$i) {
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
            'version' => 2,
            'payload' => ['username' => $name, 'password' => 'secret', 'mapId' => $mapIdList[$i % count($mapIdList)], 'version' => 2],
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
        drillWsSend($map, frameMap('auth', ['token' => $token, 'version' => 2], "map-auth:{$name}"), 0x2);
        // 真实负载模型：客户端按索引落到 4 条对角走廊之一（离散走位 → AOI 视野受限，不再全图互见），
        // 走廊内 ping-pong 折返（settle-moves 步折返，保持有界且持续产生视野差分）。
        // The realistic-load model: clients take one of 4 diagonal corridors by index (dispersed walking limits the
        // AOI view instead of everyone seeing each other), ping-ponging within the corridor (turning every
        // settle-moves steps) to stay bounded while continuously generating vision diffs.
        $dirs = [[1, 1], [1, -1], [-1, 1], [-1, -1]];
        $conns[(int) $map] = [
            'stream' => $map, 'buf' => '', 'lastArrival' => 0.0, 'name' => $name, 'lastMove' => microtime(true),
            'dir' => $dirs[$i % 4], 'steps' => 0, 'turnAt' => max(1, (int) $opts['settleMoves']),
        ];
    }

    // ── ② select 循环：读帧计数 + 每连接按 move-ms 节奏 move ──
    // ── ② The select loop: count arriving frames + a per-connection move at the moveMs cadence ──
    // 就绪栅栏：本组建链完毕 → 落 ready → 等父进程 go（超时上限 120s，防父进程异常时永久挂起）。
    // Ready barrier: this group finished establishing -> drop ready -> wait for the parent's go (120s cap).
    if ($readyFile !== null && $goFile !== null) {
        file_put_contents($readyFile, '1');
        $goDeadline = microtime(true) + 120.0;
        while (!file_exists($goFile) && microtime(true) < $goDeadline) {
            usleep(20000);
        }
    }
    $startedAt = microtime(true);
    $deadline = $startedAt + (int) $opts['seconds'];
    $windowAt = $startedAt;
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
                    $idx = stressBucketIndex(($now - $conns[$key]['lastArrival']) * 1000);
                    $latencyHist[$idx] = ($latencyHist[$idx] ?? 0) + 1;
                }
                $conns[$key]['lastArrival'] = $now;
            }
        }
        // 每连接按 move-ms 节奏移动（真实负载 ≈150ms/步 ≈ 6.7 步/s）；走廊 ping-pong：走满 turnAt 步即折返
        // A move per connection at the moveMs cadence (realistic ≈150ms/step ≈ 6.7 steps/s); corridor ping-pong:
        // reverse at turnAt steps.
        foreach ($conns as $c) {
            if (($now - $c['lastMove']) * 1000 >= (int) $opts['moveMs']) {
                $c['lastMove'] = $now;
                $c['steps']++;
                if ($c['steps'] % $c['turnAt'] === 0) {
                    $c['dir'] = [-$c['dir'][0], -$c['dir'][1]];
                }
                drillWsSend($c['stream'], frameMap('move', ['dx' => $c['dir'][0], 'dy' => $c['dir'][1]], 'mv:' . $c['name']), 0x2);
            }
        }
        // 每秒吞吐窗口：更新 peakFps The per-second throughput window: update peakFps.
        if ($now - $windowAt >= 1.0) {
            $stats['peakFps'] = max($stats['peakFps'], $stats['windowFrames'] / ($now - $windowAt));
            $stats['windowFrames'] = 0;
            $windowAt = $now;
        }
    }
    $endedAt = microtime(true);
    foreach ($conns as $c) {
        fclose($c['stream']);
    }

    return [
        'clients' => $toIdx - $fromIdx + 1,
        'authOk' => $stats['authOk'],
        'establishFailed' => $failed,
        'frames' => $stats['frames'],
        'bytes' => $stats['bytes'],
        'peakFps' => $stats['peakFps'],
        'latencyHist' => $latencyHist,
        'loopStartedAt' => $startedAt,
        'loopEndedAt' => $endedAt,
    ];
}

/**
 * 合并各 worker 统计：计数求和、直方图分桶相加、窗口 = 各 worker 运行窗均值。
 * 窗口取均值而非 max(结束)−min(开始)：worker 并行 fork 但建链完成时刻错峰（网关 bcrypt 串行，
 * 每 worker 错开数秒），并集窗会把未并行期计入分母、系统性低估 fps；均值窗等价于「各 worker fps 之和」。
 * Merges worker stats: counts summed, histogram buckets summed, window = mean of per-worker run windows.
 * Mean (not max(end)−min(start)): workers fork together but finish establishing at staggered times (the
 * gateway serializes bcrypt), so the union window would count non-parallel time and understate fps;
 * the mean window equals "sum of per-worker fps".
 *
 * @param list<array<string, mixed>> $results 各 worker 的 stressRunWorker 返回值 Per-worker stats.
 * @return array<string, mixed> 合并统计 Merged stats.
 */
function stressMergeResults(array $results): array
{
    $clients = $authOk = $failed = $frames = $bytes = 0;
    $peakFps = 0.0;
    $hist = [];
    $elapsedSum = 0.0;
    foreach ($results as $r) {
        $clients += (int) $r['clients'];
        $authOk += (int) $r['authOk'];
        $failed += (int) $r['establishFailed'];
        $frames += (int) $r['frames'];
        $bytes += (int) $r['bytes'];
        $peakFps += (float) $r['peakFps'];
        foreach ($r['latencyHist'] as $b => $c) {
            $hist[$b] = ($hist[$b] ?? 0) + $c;
        }
        $elapsedSum += max(0.001, (float) $r['loopEndedAt'] - (float) $r['loopStartedAt']);
    }
    $window = $results === [] ? 0.001 : max(0.001, $elapsedSum / count($results));

    return [
        'clients' => $clients,
        'authOk' => $authOk,
        'establishFailed' => $failed,
        'frames' => $frames,
        'bytes' => $bytes,
        'peakFps' => $peakFps,
        'latencyHist' => $hist,
        'window' => $window,
    ];
}

// ── 服务端基线采样（建链前）→ 执行（单进程或 fork 多进程）→ 运行末采样 ──
// ── Server baseline sample (before establishing) -> run (single-process or forked) -> end sample ──
$serverBefore = $sampleServer();

$workerResults = [];
if ($procs === 1) {
    $workerResults[] = stressRunWorker($opts, $mapIdList, 1, $clients);
} else {
    $slice = (int) ceil($clients / $procs);
    $runId = getmypid();
    $tmpDir = sys_get_temp_dir();
    $goFile = sprintf('%s/stress-map-%d.go', $tmpDir, $runId);
    @unlink($goFile);
    $children = [];
    for ($w = 0; $w < $procs; ++$w) {
        $from = $w * $slice + 1;
        $to = min($clients, ($w + 1) * $slice);
        if ($from > $to) {
            break;
        }
        $resultFile = sprintf('%s/stress-map-%d-w%d.json', $tmpDir, $runId, $w);
        $readyFile = sprintf('%s/stress-map-%d-w%d.ready', $tmpDir, $runId, $w);
        @unlink($resultFile);
        @unlink($readyFile);
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "[stress-map] fork 失败（worker {$w}）\n");
            foreach ($children as [$childPid]) {
                @posix_kill($childPid, SIGTERM);
            }
            exit(1);
        }
        if ($pid === 0) {
            // 子 worker：建链 → 就绪栅栏 → 定时循环 → 写结果文件后退出（不执行父进程的合并/输出）。
            $res = stressRunWorker($opts, $mapIdList, $from, $to, $readyFile, $goFile);
            file_put_contents($resultFile, json_encode($res));
            exit(0);
        }
        $children[] = [$pid, $resultFile, $readyFile];
    }
    // 就绪栅栏：等全部 worker 建链完成（上限 120s）后放行 → 定时循环同时开表，避免登录洪峰污染测量窗。
    // Ready barrier: wait for every worker to finish establishing (120s cap), then release them together.
    $readyDeadline = microtime(true) + 120.0;
    $allReady = false;
    while (microtime(true) < $readyDeadline) {
        $allReady = true;
        foreach ($children as [, , $readyFile]) {
            if (!file_exists($readyFile)) {
                $allReady = false;
                break;
            }
        }
        if ($allReady) {
            break;
        }
        usleep(50000);
    }
    if (!$allReady) {
        fwrite(STDERR, "[stress-map] 就绪栅栏超时（部分 worker 建链未完成），继续放行\n");
    }
    file_put_contents($goFile, '1');
    foreach ($children as [$pid, $resultFile, $readyFile]) {
        pcntl_waitpid($pid, $status);
        @unlink($readyFile);
        $data = @file_get_contents($resultFile);
        @unlink($resultFile);
        if ($data === false) {
            fwrite(STDERR, "[stress-map] worker 未产出结果（pid {$pid}）\n");
            continue;
        }
        $workerResults[] = json_decode($data, true);
    }
    @unlink($goFile);
}

$serverAfter = $sampleServer();
$merged = stressMergeResults($workerResults);
$elapsed = (float) $merged['window'];
$rssPerConnKb = $merged['authOk'] > 0 ? (($serverAfter['rssKb'] - $serverBefore['rssKb']) / $merged['authOk']) : 0.0;
$cpuPct = ($serverAfter['pids'] !== [] && $serverBefore['pids'] === $serverAfter['pids'])
    ? (($serverAfter['jiffies'] - $serverBefore['jiffies']) / 100.0) / $elapsed * 100.0
    : 0.0;

$fps = round($merged['frames'] / $elapsed, 1);
$p50 = stressPercentile($merged['latencyHist'], 0.5);
$p90 = stressPercentile($merged['latencyHist'], 0.9);
$p99 = stressPercentile($merged['latencyHist'], 0.99);

if ($opts['json']) {
    echo json_encode([
        'clients' => $clients,
        'procs' => $procs,
        'seconds' => round($elapsed, 1),
        'moveMs' => $opts['moveMs'],
        'settleMoves' => $opts['settleMoves'],
        'authOk' => $merged['authOk'],
        'establishFailed' => $merged['establishFailed'],
        'frames' => $merged['frames'],
        'bytesKB' => round($merged['bytes'] / 1024, 1),
        'fps' => $fps,
        'peakFps' => round($merged['peakFps'], 1),
        'latencyMs' => ['P50' => round($p50, 1), 'P90' => round($p90, 1), 'P99' => round($p99, 1), 'samples' => array_sum($merged['latencyHist'])],
        'p99' => round($p99, 1),
        'workers' => array_map(static fn (array $r): array => [
            'clients' => $r['clients'],
            'authOk' => $r['authOk'],
            'establishFailed' => $r['establishFailed'],
            'frames' => $r['frames'],
            'peakFps' => round((float) $r['peakFps'], 1),
        ], $workerResults),
        'server' => [
            'rssBeforeKb' => $serverBefore['rssKb'],
            'rssAfterKb' => $serverAfter['rssKb'],
            'rssPerConnKb' => round($rssPerConnKb, 1),
            'cpuPct' => round($cpuPct, 2),
            'cpuPctPerConn' => $merged['authOk'] > 0 ? round($cpuPct / $merged['authOk'], 4) : 0.0,
            'pids' => $serverAfter['pids'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo sprintf(
    "客户端=%d procs=%d auth=%d 建链失败=%d fps=%.1f(peak %.1f) 帧=%d bytes=%.1fKB\n",
    $clients,
    $procs,
    $merged['authOk'],
    $merged['establishFailed'],
    $fps,
    $merged['peakFps'],
    $merged['frames'],
    $merged['bytes'] / 1024,
);
echo sprintf("延迟ms P50=%.1f P90=%.1f P99=%.1f 样本=%d\n", $p50, $p90, $p99, array_sum($merged['latencyHist']));
echo sprintf(
    "服务端(maps %d worker)：RSS %.1f→%.1f MB（每连接 %.1f KB） CPU 累计 %.2f%%（每连接 %.4f%%）\n",
    count($serverAfter['pids']),
    $serverBefore['rssKb'] / 1024,
    $serverAfter['rssKb'] / 1024,
    $rssPerConnKb,
    $cpuPct,
    $merged['authOk'] > 0 ? $cpuPct / $merged['authOk'] : 0.0,
);

/**
 * 自测：ws 缓冲帧解析器（分片到达/长帧/多帧粘包）+ 多进程结果合并（分桶相加与分位），无网络依赖。
 * 解析器本体已上收 drill-harness（stress-play 混合引擎共用），此处保留回归用例。
 * Self-test: the ws buffer-frame parser (fragmented arrival / long frames / coalesced frames) plus the
 * multi-process result merge (bucket sums and percentiles), no network. The parser itself moved up to
 * drill-harness (shared with stress-play); the regression cases stay here.
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

    // 多进程合并：计数求和 / 窗口 = 各 worker 运行窗均值 / 直方图分桶相加 / 分位由合并直方图算
    $merged = stressMergeResults([
        ['clients' => 2, 'authOk' => 2, 'establishFailed' => 0, 'frames' => 100, 'bytes' => 1000, 'peakFps' => 10.0, 'latencyHist' => [0 => 5, 1 => 5], 'loopStartedAt' => 100.0, 'loopEndedAt' => 110.0],
        ['clients' => 2, 'authOk' => 2, 'establishFailed' => 1, 'frames' => 50, 'bytes' => 500, 'peakFps' => 6.0, 'latencyHist' => [1 => 5, 2 => 5], 'loopStartedAt' => 101.0, 'loopEndedAt' => 113.0],
    ]);
    $assert(
        $merged['clients'] === 4 && $merged['authOk'] === 4 && $merged['establishFailed'] === 1 && $merged['frames'] === 150 && $merged['bytes'] === 1500,
        '合并：计数求和',
    );
    $assert(abs($merged['window'] - 11.0) < 0.001, '合并：窗口=各 worker 运行窗均值（10 与 12 → 11）');
    $assert(($merged['latencyHist'][1] ?? 0) === 10 && ($merged['latencyHist'][0] ?? 0) === 5, '合并：直方图分桶相加');
    $p50 = stressPercentile($merged['latencyHist'], 0.5);
    $assert($p50 > 0.0 && $p50 < 40.0, '合并：分位由合并直方图计算');

    if ($failures !== []) {
        printf("[stress-map] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo '[stress-map] SELF-TEST PASS' . "\n";

    return 0;
}
