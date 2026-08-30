<?php

declare(strict_types=1);

/**
 * P10 房间容量压测（stress-rooms）：单进程 M 个房间 × 6 个 bot（room:create/join + room:spawn 刷怪 +
 * room:aoe 周期施压），观测服务端 RSS/CPU 与 registry 心跳指标（rooms/roomsDeferred——预算顺延即
 * 三层调度的「进程预算层」生效信号）。
 * The P10 room-capacity stress: M rooms × 6 bots per process (room:create/join + room:spawn waves +
 * periodic room:aoe casts), watching server RSS/CPU and the registry heartbeat metrics (rooms/roomsDeferred —
 * the deferral signal of the P9c process-budget layer).
 *
 * 用法 / usage:
 *   php benchmarks/stress-rooms.php --rooms=15 --seconds=25
 * 服务器启动 / boot:
 *   NYTHROS_MMORPG=1 NYTHROS_ROOMS=1 NYTHROS_ACCOUNTS='2001=secret,...' setsid -f php bin/server start
 * 输出契约：[stress] 行 + RESULT；数字为 WSL2 开发机实测，绝对值以目标硬件复测为准。
 * Output contract: [stress] lines + RESULT; measured on the WSL2 dev box — re-measure on target hardware.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../packages/demo/bin/lib/map-codec.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

const GW_WS = 'ws://127.0.0.1:18285';
const MAP_WS = 'ws://127.0.0.1:18081';
const BASE_UID = 2001;
const TEAM = 6;

$options = getopt('', ['rooms::', 'seconds::']);
$roomCount = max(1, (int) ($options['rooms'] ?? 10));
$seconds = (float) ($options['seconds'] ?? 25.0);
$players = $roomCount * TEAM;

$GLOBALS['stress'] = [
    'rooms' => $roomCount,
    'clients' => [],
    'loginPending' => $players,
    'createPending' => $roomCount,
    'joinPending' => $roomCount * (TEAM - 1),
    'phase' => 0,
    'samples' => [],
    'pids' => [],
    'startedAt' => 0.0,
    'closing' => false,
    'generatorErrors' => 0,
];

$uids = [];
for ($i = 0; $i < $players; $i++) {
    $uids[] = (string) (BASE_UID + $i);
}

function uidKey(string|int $uid): string
{
    return (string) $uid;
}

/** 房间编号 → 房间 id / 成员序号。 Room index → room id / member slot. */
function roomOf(int $botIndex): array
{
    $room = intdiv($botIndex, TEAM);
    $slot = $botIndex % TEAM;

    return ['roomId' => sprintf('stress-room-%d', $room), 'room' => $room, 'slot' => $slot];
}

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
            $jiffies += (int) ($fields[13] ?? 0) + (int) ($fields[14] ?? 0);
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
        $cpu = $dt > 0 ? ($dj / 100.0) / $dt * 100.0 : 0.0;
    }
    sort($pids);
    $s['pids'] = $pids;
    $s['samples'][] = ['t' => $t, 'jiffies' => $jiffies, 'cpu' => $cpu, 'rssMb' => round($rssKb / 1024, 1)];
}

/** 房主行为拍：周期 room:spawn（补怪）+ room:aoe（AoE 施压）。 Leader beat: re-spawn waves + AoE casts. */
function leaderBeat(string|int $uid): void
{
    $uid = uidKey($uid);
    $s = &$GLOBALS['stress'];
    $state = &$s['clients'][$uid];
    if (!$state['ready'] || empty($state['roomId'])) {
        return;
    }
    $state['spawnTick'] = ($state['spawnTick'] ?? 0) + 1;
    if ($state['spawnTick'] % 10 === 1) {
        $state['conn']->send(frameMap('room:spawn', ['roomId' => $state['roomId'], 'count' => 4]));
    }
    if ($state['spawnTick'] % 2 === 0) {
        $state['conn']->send(frameMap('room:aoe', ['roomId' => $state['roomId'], 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => 70]));
    }
}

function loginBot(string|int $uid): void
{
    $uid = uidKey($uid);
    $s = &$GLOBALS['stress'];
    $state = &$s['clients'][$uid];
    $state['ready'] = false;
    $state['bytes'] = 0;
    $state['frames'] = 0;
    $state['aoe'] = 0;
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
            echo "[stress] gateway 非 auth_ok uid=$uid\n";
            $GLOBALS['stress']['generatorErrors']++;

            return;
        }
        $token = $decoded['payload']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $GLOBALS['stress']['generatorErrors']++;

            return;
        }
        mapLogin($uid, $token); // gateway 连接保活 keep the gateway connection open
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

            return;
        }
        $state['frames'] += count($frames);
        foreach ($frames as $f) {
            $type = $f['type'];
            if ($uid === '2001') {
                $state['types'][$type] = ($state['types'][$type] ?? 0) + 1;
            }
            if ($type === 'auth_ok') {
                $state['ready'] = true;
                if ($s['loginPending'] > 0 && --$s['loginPending'] === 0) {
                    startPhases();
                }

                continue;
            }
            if ($type === 'combat:aoe') {
                $state['aoe']++;
            }
        }
    };
    $map->onError = static function () use ($uid): void {
        $GLOBALS['stress']['generatorErrors']++;
        echo "[stress] map error uid=$uid\n";
    };
    $state['conn'] = $map;
    $map->connect();
}

function startPhases(): void
{
    $s = &$GLOBALS['stress'];
    if ($s['phasesStarted']) {
        return;
    }
    $s['phasesStarted'] = true;
    $s['startedAt'] = microtime(true);
    $ready = 0;
    foreach ($s['clients'] as $state) {
        if ($state['ready']) {
            $ready++;
        }
    }
    echo "[stress] 启动房间编排（ready=$ready/" . count($s['clients']) . "，rooms={$s['rooms']}）\n";

    // 建房：每房房主 room:create，随后成员依次 room:join（transfer 约定路径）
    // Room setup: each leader creates, then members join in order (the transfer convention).
    $botIndex = 0;
    foreach ($s['clients'] as $uid => $state) {
        $meta = roomOf($botIndex);
        $state['roomId'] = $meta['roomId'];
        $state['slot'] = $meta['slot'];
        $botIndex++;
        if (!$state['ready'] || $state['conn'] === null) {
            continue;
        }
        if ($meta['slot'] === 0) {
            $state['conn']->send(frameMap('room:create', ['roomId' => $meta['roomId']]));
            // 建房后：房主先刷一波怪
            // After creation: the leader spawns the first wave.
            Timer::add(0.4, static function () use ($uid): void {
                $st = &$GLOBALS['stress']['clients'][uidKey($uid)];
                if ($st['ready'] && !empty($st['roomId']) && $st['conn'] !== null) {
                    $st['conn']->send(frameMap('room:spawn', ['roomId' => $st['roomId'], 'count' => 6]));
                }
            }, [], false);
        } else {
            $delay = 0.2 + $meta['room'] * 0.1 + $meta['slot'] * 0.1;
            Timer::add($delay, static function () use ($uid, $meta): void {
                $st = &$GLOBALS['stress']['clients'][uidKey($uid)];
                if ($st['ready'] && $st['conn'] !== null) {
                    $st['conn']->send(frameMap('room:join', ['roomId' => $meta['roomId']]));
                }
            }, [], false);
        }
        // 房主行为拍（AoE 压力）
        // The leader's behavior beat (the AoE pressure).
        if ($meta['slot'] === 0) {
            Timer::add(1.0, static function () use ($uid): void {
                leaderBeat($uid);
            });
        }
    }

    Timer::add(2.0, static function (): void {
        sampleServer();
    });

    Timer::add($GLOBALS['seconds'], static function (): void {
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

    $bytes = $frames = $aoe = 0;
    $elapsed = max(0.001, microtime(true) - $s['startedAt']);
    foreach ($s['clients'] as $state) {
        $bytes += $state['bytes'];
        $frames += $state['frames'];
        $aoe += $state['aoe'];
    }
    printf("[stress] rooms=%d bots=%d  带宽/客户端: %.0f B/s  帧率/客户端: %.1f f/s  combat:aoe 帧总数: %d\n", $s['rooms'], count($s['clients']), $bytes / $elapsed / count($s['clients']), $frames / $elapsed / count($s['clients']), $aoe);
    printf("[stress] 服务端 CPU%%: avg %.0f%% max %.0f%%（单核口径）  RSS max: %.0f MB\n", $cpuAvg, $cpuMax, $rssMax);
    echo "[stress] RESULT: DONE (rooms={$s['rooms']})\n";

    $s['closing'] = true;
    foreach ($s['clients'] as $state) {
        $state['conn']?->close();
        $state['social']?->close();
    }
    Timer::add(0.5, static function (): void {
        if (function_exists('posix_kill')) {
            posix_kill(posix_getppid(), SIGINT);
        }
        Worker::stopAll();
    }, [], false);
}

// Workerman 5.2 要求 argv 中显式含自身命令：注入 start。
// Workerman 5.2 requires an explicit own command in argv: inject start.
$GLOBALS['argv'] = [$argv[0] ?? 'stress-rooms.php', 'start'];

$worker = new Worker();
$worker->count = 1;
$worker->onWorkerStart = static function () use ($uids, $players, $roomCount, $seconds): void {
    echo "[stress] 房间容量压测启动：rooms=$roomCount bots=$players seconds={$seconds}s\n";
    foreach ($uids as $i => $uid) {
        $GLOBALS['stress']['clients'][uidKey($uid)] = ['ready' => false];
        Timer::add(0.15 * $i, static function () use ($uid): void {
            loginBot($uid);
        }, [], false);
    }
    Timer::add(0.15 * $players + 6.0, static function (): void {
        if (!$GLOBALS['stress']['phasesStarted']) {
            echo "[stress] WARN: 登录超时，以已就位 bot 继续\n";
            startPhases();
        }
    }, [], false);
};

Worker::runAll();
