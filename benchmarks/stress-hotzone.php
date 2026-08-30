<?php

declare(strict_types=1);

/**
 * P10 聚团混战压测（stress-hotzone）：N 个 bot 客户端聚格混战，线缆观测 P9 区域降频的降/升往返
 * （world:tick_rate 帧序列）、每客户端带宽/帧率、attack→combat:hit 往返延迟、服务端 maps worker
 * 的 CPU/RSS（/proc 采样）。
 * The P10 hot-zone stress: N bot clients cluster and fight; observes P9's region-downgrade down/up
 * round trip on the wire (the world:tick_rate frame sequence), per-client bandwidth/frame rate, the
 * attack→combat:hit round-trip latency, and the maps workers' CPU/RSS (sampled via /proc).
 *
 * 用法 / usage:
 *   php benchmarks/stress-hotzone.php --players=30 --phase1=30 --phase2=15
 * 服务器启动（热区阈值按低密度配置以便少量 bot 触发降频）/ boot the server with low hot-cell tiers:
 *   NYTHROS_MMORPG=1 NYTHROS_MMORPG_HOT_CELL='3:1,8:2,0:4' NYTHROS_MMORPG_SAFE_ZONE='0,0,5' \
 *     NYTHROS_MMORPG_PLAYER_RESPAWN_MS=1000 NYTHROS_ACCOUNTS='2001=secret,...' \
 *     setsid -f php bin/server start
 *
 * 场景：bot 登录后聚拢到 (15,15)（monster-1 巡逻域，cell(1,1)）混战（攻击 + 小幅走位）——阶段 1 观测
 * 降档；阶段 2 全体远移散开——格子回温、divisor 回 1（降-升往返验收）。
 * Scenario: bots cluster at (15,15) (monster-1's patrol domain, cell(1,1)) and fight (attacks + small
 * walks) — phase 1 observes the downgrade; phase 2 disperses everyone — the cells cool and the divisor
 * returns to 1 (the down/up round-trip acceptance).
 *
 * 输出契约：[stress] 行 + RESULT 汇总；数字为当前开发机（WSL2）实测，量级形态供参考，
 * 绝对值以目标硬件复测为准。
 * Output contract: [stress] lines + a RESULT summary; numbers are measured on the current dev box (WSL2) —
 * the shape is indicative, absolute values must be re-measured on target hardware.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../packages/demo/bin/lib/map-codec.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081';
const BASE_UID = 2001;

$options = getopt('', ['players::', 'phase1::', 'phase2::', 'cluster::']);
$players = max(2, (int) ($options['players'] ?? 30));
$phase1 = (float) ($options['phase1'] ?? 30.0);
$phase2 = (float) ($options['phase2'] ?? 15.0);
$clusterParts = array_map('intval', explode(',', (string) ($options['cluster'] ?? '15,15')));
$clusterX = $clusterParts[0];
$clusterY = $clusterParts[1];

$GLOBALS['stress'] = [
    'players' => $players,
    'clients' => [],          // uid => 状态（uid 键为字符串，见 uidKey） uid => state (string keys, see uidKey)
    'loginPending' => $players,
    'phase' => 0,             // 0=login 1=cluster 2=disperse
    'tickrate' => [],         // [[dt, divisor], ...] 降/升往返时间线 the down/up timeline
    'lastDivisor' => 1,
    'maxDivisor' => 1,
    'samples' => [],          // [[t, jiffies, cpu, rssMb], ...]
    'pids' => [],
    'startedAt' => 0.0,
    'generatorErrors' => 0,
    'phasesStarted' => false,
    'closing' => false,
    'cx' => $clusterX,
    'cy' => $clusterY,
];

$uids = [];
for ($i = 0; $i < $players; $i++) {
    $uids[] = (string) (BASE_UID + $i);
}

/** uid 归一：PHP 数字字符串数组键自动转 int，统一取回字符串。 uid normalization: PHP auto-casts numeric string array keys to int. */
function uidKey(string|int $uid): string
{
    return (string) $uid;
}

/** 观测 tick_rate：任一客户端的去重时间线。 Records a tick_rate observation (deduped across clients). */
function observeTickRate(int $divisor): void
{
    $s = &$GLOBALS['stress'];
    if ($divisor === $s['lastDivisor']) {
        return;
    }
    $dt = $s['startedAt'] === 0.0 ? 0.0 : round(microtime(true) - $s['startedAt'], 1);
    $s['tickrate'][] = [$dt, $divisor];
    $s['lastDivisor'] = $divisor;
    $s['maxDivisor'] = max($s['maxDivisor'], $divisor);
}

// ── 服务端进程采样（maps worker 全部进程的 CPU%/RSS 求和，单核口径可 >100%） ──
// ── Server sampling (CPU%/RSS summed across maps-worker processes; single-core scale may exceed 100%) ──
function sampleServer(): void
{
    $s = &$GLOBALS['stress'];
    $jiffies = 0;
    $rssKb = 0;
    $pids = [];
    foreach (glob('/proc/[0-9]*/cmdline') as $cmdlineFile) {
        $cmd = @file_get_contents($cmdlineFile);
        if ($cmd === false || strpos($cmd, 'start-maps.php') === false) {
            continue;
        }
        $pid = (int) basename(dirname($cmdlineFile));
        $pids[] = $pid;
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat !== false) {
            $fields = explode(' ', $stat);
            $jiffies += (int) ($fields[13] ?? 0) + (int) ($fields[14] ?? 0); // utime+stime utime+stime
        }
        $status = @file_get_contents("/proc/$pid/status");
        if ($status !== false && preg_match('/VmRSS:\s+(\d+) kB/', $status, $m)) {
            $rssKb += (int) $m[1];
        }
    }
    $t = microtime(true);
    $cpu = 0.0;
    $prev = $s['samples'] === [] ? null : $s['samples'][count($s['samples']) - 1];
    if ($prev !== null && $s['pids'] === $pids) {
        $dt = $t - $prev['t'];
        $dj = $jiffies - $prev['jiffies'];
        $cpu = $dt > 0 ? ($dj / 100.0) / $dt * 100.0 : 0.0; // clk_tck=100 clk_tck=100
    }
    sort($pids);
    $s['pids'] = $pids;
    $s['samples'][] = ['t' => $t, 'jiffies' => $jiffies, 'cpu' => $cpu, 'rssMb' => round($rssKb / 1024, 1)];
}

// ── 单 bot 行为拍：混战（攻击 + 小幅走位，保持在热格内）/ 散开拍（仅走位供回温观测） ──
// ── Per-bot beat: melee (attacks + small walks inside the hot cell) / dispersion beats (walks for the recovery watch) ──
function botBeat(string|int $uid): void
{
    $uid = uidKey($uid);
    $s = &$GLOBALS['stress'];
    $state = &$s['clients'][$uid];
    if (!$state['ready'] || $s['phase'] === 0) {
        return;
    }
    if ($s['phase'] === 1) {
        if (mt_rand(1, 100) <= 60) {
            $state['conn']->send(frameMap('attack', ['targetId' => 'monster-1'], 'atk-' . ++$state['atkSeq']));
            $state['pending'][] = microtime(true);
            if (count($state['pending']) > 8) {
                array_shift($state['pending']); // 死怪窗口防堆积 anti pileup during the dead window
            }
        } else {
            // 小幅走位：±3 保持在聚格（cell(1,1)）内 small walk: ±3 stays inside the cluster cell
            $state['conn']->send(frameMap('move', ['dx' => mt_rand(-3, 3), 'dy' => mt_rand(-3, 3)]));
        }

        return;
    }
    // 散开拍：40% 走位（各自已远移，格子密度趋零） The dispersion beat: 40% walks (already spread, densities collapse).
    if (mt_rand(1, 100) <= 40) {
        $state['conn']->send(frameMap('move', ['dx' => mt_rand(-2, 2), 'dy' => mt_rand(-2, 2)]));
    }
}

// ── 登录流：gateway JSON auth → token → Map 二进制 auth ──
// ── Login flow: gateway JSON auth → token → Map binary auth ──
function loginBot(string|int $uid): void
{
    $uid = uidKey($uid);
    $s = &$GLOBALS['stress'];
    $state = &$s['clients'][$uid];
    $state['ready'] = false;
    $state['bytes'] = 0;
    $state['frames'] = 0;
    $state['hits'] = 0;
    $state['errors'] = 0;
    $state['atkSeq'] = 0;
    $state['pending'] = [];
    $state['lat'] = [];
    $state['types'] = [];

    $social = new AsyncTcpConnection(GW_WS);
    $social->onConnect = static function (AsyncTcpConnection $c) use ($uid): void {
        $c->send(json_encode([
            'type' => 'auth',
            'requestId' => 'login:' . $uid,
            'timestamp' => microtime(true),
            'payload' => ['username' => $uid, 'password' => 'secret', 'mapId' => 'map-1'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
    $social->onMessage = static function (AsyncTcpConnection $c, mixed $data) use ($uid): void {
        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'auth_ok') {
            echo "[stress] gateway 非 auth_ok uid=$uid resp=" . substr((string) $data, 0, 160) . "\n";
            $GLOBALS['stress']['generatorErrors']++;

            return;
        }
        $token = $decoded['payload']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $GLOBALS['stress']['generatorErrors']++;

            return;
        }
        // gateway 连接全程保活（P10 踩坑）：关闭即被网关标记离线并通知地图服断开 bot
        // The gateway connection stays open (a P10 pitfall): closing it marks the uid offline and the map drops the bot.
        mapLogin($uid, $token);
    };
    $social->onError = static function () use ($uid): void {
        $GLOBALS['stress']['generatorErrors']++;
        echo "[stress] gateway error uid=$uid\n";
    };
    $state['social'] = $social;
    $social->connect();
}

function mapLogin(string $uid, string $token): void
{
    $s = &$GLOBALS['stress'];
    $state = &$s['clients'][$uid];
    $map = new AsyncTcpConnection(MAP_WS);
    $map->onConnect = static function (AsyncTcpConnection $c) use ($token): void {
        $c->send(frameMap('auth', ['token' => $token], 'map-auth'));
    };
    $map->onMessage = static function (AsyncTcpConnection $c, mixed $data) use ($uid): void {
        $s = &$GLOBALS['stress'];
        $state = &$s['clients'][$uid];
        $raw = (string) $data;
        $state['bytes'] += strlen($raw);
        try {
            $frames = decodeMapFrames($raw);
        } catch (\Throwable $e) {
            echo "[stress] decode 失败 uid=$uid: " . $e->getMessage() . "\n";
            $state['decodeFail'] = ($state['decodeFail'] ?? 0) + 1;

            return;
        }
        $state['frames'] += count($frames);
        foreach ($frames as $f) {
            $type = $f['type'];
            if ($uid === '2001') {
                $state['types'][$type] = ($state['types'][$type] ?? 0) + 1;
            }
            if ($type === 'auth_ok') {
                $state['entityId'] = (string) ($f['payload']['id'] ?? '');
                $state['ready'] = true;
                if ($s['loginPending'] > 0 && --$s['loginPending'] === 0) {
                    startPhases();
                }

                continue;
            }
            if ($type === 'world:tick_rate') {
                observeTickRate((int) ($f['payload']['divisor'] ?? 0));

                continue;
            }
            if ($type === 'combat:hit' && ($f['payload']['attackerId'] ?? null) === ($state['entityId'] ?? '')) {
                $state['hits']++;
                $sentAt = array_pop($state['pending']);
                if ($sentAt !== null) {
                    $lat = (microtime(true) - $sentAt) * 1000.0;
                    if ($lat < 5000.0) {
                        $state['lat'][] = $lat;
                    }
                }

                continue;
            }
            if ($type === 'error') {
                $state['errors']++;
            }
        }
    };
    $map->onError = static function () use ($uid): void {
        $GLOBALS['stress']['generatorErrors']++;
        echo "[stress] map error uid=$uid\n";
    };
    $map->onClose = static function () use ($uid): void {
        $s = &$GLOBALS['stress'];
        if (!$s['closing']) {
            echo "[stress] map closed uid=" . var_export($uid, true) . " entity=" . ($state["entityId"] ?? "?") . " ready=" . var_export($state["ready"] ?? null, true) . " (runtime)
";
        }
    };
    $state['conn'] = $map;
    $map->connect();
}

/** 全员就位（单次触发）：聚拢 → 阶段 1 降档观测 → 阶段 2 散开回温 → 汇总。 All logged in (one shot): cluster → downgrade watch → disperse/recovery → summary. */
function startPhases(): void
{
    $s = &$GLOBALS['stress'];
    if ($s['phasesStarted']) {
        return; // 单次触发：登录竞态/兜底双路径只允许一份 One shot: login races and the backstop must not double-start.
    }
    $s['phasesStarted'] = true;
    $s['startedAt'] = microtime(true);
    $ready = 0;
    foreach ($s['clients'] as $state) {
        if ($state['ready']) {
            $ready++;
        }
    }
    echo "[stress] 启动行为拍（ready=$ready/{$s['players']}），聚拢到簇心（{$s['cx']},{$s['cy']}）\n";

    // 聚拢：出生点 (0,0) 一步到簇心附近（±3 抖动避免完全同点；仍在同一格子）
    // Cluster: one hop from the spawn (0,0) to near the center (±3 jitter; same cell).
    $i = 0;
    foreach ($s['clients'] as $state) {
        if (!$state['ready']) {
            continue;
        }
        $tx = $s['cx'] + ($i % 7) - 3;
        $ty = $s['cy'] + (intdiv($i, 7) % 7) - 3;
        $state['conn']->send(frameMap('move', ['dx' => $tx, 'dy' => $ty]));
        $i++;
    }

    $s['phase'] = 1;
    echo "[stress] PHASE1 聚团混战开始（{$GLOBALS['phase1']}s）——观测降档\n";

    foreach ($s['clients'] as $uid => $state) {
        if (!$state['ready']) {
            continue;
        }
        Timer::add(0.3, static function () use ($uid): void {
            botBeat($uid);
        });
    }
    Timer::add(2.0, static function (): void {
        sampleServer();
    });

    Timer::add($GLOBALS['phase1'], static function (): void {
        $s = &$GLOBALS['stress'];
        $s['phase'] = 2;
        echo "[stress] PHASE2 散开开始（{$GLOBALS['phase2']}s）——观测回温（滞回后 divisor 应回 1）\n";
        $i = 0;
        foreach ($s['clients'] as $state) {
            if (!$state['ready']) {
                continue;
            }
            // 各自远移：跨格散开（i*7 保证互不同格） Each bot hops far across cells (i*7 keeps them apart).
            $state['conn']->send(frameMap('move', ['dx' => 40 + $i * 7, 'dy' => 30]));
            $i++;
        }
    }, [], false);

    Timer::add($GLOBALS['phase1'] + $GLOBALS['phase2'], static function (): void {
        summarize();
    }, [], false);
}

function summarize(): void
{
    $s = &$GLOBALS['stress'];
    $cpuValues = array_map(static fn (array $x): float => $x['cpu'], array_slice($s['samples'], 1));
    sort($cpuValues);
    $cpuAvg = $cpuValues === [] ? 0.0 : array_sum($cpuValues) / count($cpuValues);
    $cpuMax = $cpuValues === [] ? 0.0 : $cpuValues[count($cpuValues) - 1];
    $rssMax = $s['samples'] === [] ? 0.0 : max(array_map(static fn (array $x): float => $x['rssMb'], $s['samples']));

    $bytes = $frames = $hits = $errs = 0;
    $allLat = [];
    $elapsed = max(0.001, microtime(true) - $s['startedAt']);
    foreach ($s['clients'] as $state) {
        $bytes += $state['bytes'];
        $frames += $state['frames'];
        $hits += $state['hits'];
        $errs += $state['errors'];
        foreach ($state['lat'] as $lat) {
            $allLat[] = $lat;
        }
    }
    sort($allLat);
    $n = count($allLat);
    $p50 = $n > 0 ? $allLat[(int) floor($n * 0.5)] : 0.0;
    $p95 = $n > 0 ? $allLat[min($n - 1, (int) floor($n * 0.95))] : 0.0;

    $tickline = implode(' → ', array_map(static fn (array $t): string => sprintf('%d@+%.1fs', $t[1], $t[0]), array_values(array_filter($s['tickrate'], static fn (array $t): bool => $t[0] > 0.0))));
    printf("[stress] 带宽/客户端: %.0f B/s   帧率/客户端: %.1f f/s   combat:hit 总数: %d\n", $bytes / $elapsed / $s['players'], $frames / $elapsed / $s['players'], $hits);
    printf("[stress] attack→hit p50: %.0fms p95: %.0fms   error 帧总数: %d（死怪窗口内攻击被拒属预期）\n", $p50, $p95, $errs);
    printf("[stress] 服务端 CPU%%: avg %.0f%% max %.0f%%（单核口径，maps 全 worker 求和）   RSS max: %.0f MB\n", $cpuAvg, $cpuMax, $rssMax);
    echo "[stress] world:tick_rate 时间线: " . ($tickline === '' ? '(无 — 未触发降档)' : $tickline) . "\n";
    echo "[stress] 2001 收帧直方图: " . json_encode($s['clients']['2001']['types'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";

    $ok1 = $s['maxDivisor'] >= 4;
    $ok2 = $s['lastDivisor'] === 1;
    echo $ok1 ? "[stress] [PASS] 降档观测：max divisor={$s['maxDivisor']}\n" : "[stress] [FAIL] 降档未触发（max divisor={$s['maxDivisor']}，检查 NYTHROS_MMORPG_HOT_CELL 与聚格密度）\n";
    echo $ok2 ? "[stress] [PASS] 回升观测：final divisor=1（滞回后回温）\n" : "[stress] [FAIL] 未回升（final divisor={$s['lastDivisor']}）\n";
    echo sprintf("[stress] RESULT: %s (players=%d maxDivisor=%d finalDivisor=%d)\n", ($ok1 && $ok2) ? 'PASSED' : 'DEGRADED', $s['players'], $s['maxDivisor'], $s['lastDivisor']);

    $s['closing'] = true;
    foreach ($s['clients'] as $state) {
        $state['conn']?->close();
        $state['social']?->close();
    }
    Timer::add(0.5, static function (): void {
        // 先 SIGINT 主进程防 monitor 重启子进程再跑一轮，再 stopAll 优雅收尾
        // SIGINT the master first (prevents the monitor restarting the child for another round), then stop gracefully.
        if (function_exists('posix_kill')) {
            posix_kill(posix_getppid(), SIGINT);
        }
        Worker::stopAll();
    }, [], false);
}

// Workerman 5.2 要求 argv 中显式含自身命令：注入 start（前台 DEBUG 模式）。
// Workerman 5.2 requires an explicit own command in argv: inject start (foreground DEBUG mode).
$GLOBALS['argv'] = [$argv[0] ?? 'stress-hotzone.php', 'start'];

$worker = new Worker();
$worker->count = 1; // 压测生成器单进程：避免按 CPU 数 fork 重复施压 The stress generator runs single-process: no forking.
$worker->onWorkerStart = static function () use ($uids, $players, $phase1, $phase2): void {
    echo "[stress] 聚团混战压测启动：players=$players phase1={$phase1}s phase2={$phase2}s\n";
    foreach ($uids as $i => $uid) {
        $GLOBALS['stress']['clients'][uidKey($uid)] = ['ready' => false];
        Timer::add(0.1 * $i, static function () use ($uid): void {
            loginBot($uid);
        }, [], false);
    }
    // 登录兜底：超时未全就位则以已就位 bot 继续压测
    // The login backstop: if some bots never finish, continue with whoever made it.
    Timer::add(0.1 * $players + 5.0, static function (): void {
        if (!$GLOBALS['stress']['phasesStarted']) {
            echo "[stress] WARN: 登录超时，以已就位 bot 继续\n";
            startPhases();
        }
    }, [], false);
};

Worker::runAll();
