<?php

declare(strict_types=1);

// 多服务器部署启动器（入门套件版）：读 config/servers.php → 逐服务 spawn 一个 map-worker 子进程 →
// 打印端口/pid → 前台等待并转发退出信号（SIGINT/SIGTERM 全部停止）。
// 阶段 4 无进程监督：worker 崩溃不自动拉起（重新执行本脚本即可）。
// Located at: bin/launch.php — the multi-server deployment launcher (starter-kit edition).
// Reads config/servers.php → spawns one map-worker child per service → prints ports/pids → waits in the
// foreground forwarding exit signals (SIGINT/SIGTERM stop everything). Phase-4 style: no process supervision —
// a crashed worker is not auto-restarted (re-run this script).

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Framework\Config\Config;

stream_set_write_buffer(STDOUT, 0);
stream_set_write_buffer(STDERR, 0);

$configPath = dirname(__DIR__) . '/config/servers.php';
$servers = Config::fromPhpFile($configPath)->get('servers', []);
if (!is_array($servers) || $servers === []) {
    fwrite(STDERR, "[launch] fatal: config/servers.php 未声明任何服务
");
    exit(1);
}

// 校验：端口全局唯一、serviceId（mapId#channelId）全局唯一（拓扑错误必须显性化）
// Validation: globally unique ports and serviceIds (mapId#channelId) — topology errors must be surfaced
$seenPorts = [];
$seenIds = [];
foreach ($servers as $entry) {
    $mapId = $entry['mapId'] ?? null;
    $channelId = $entry['channelId'] ?? null;
    $port = $entry['port'] ?? null;
    if (!is_string($mapId) || $mapId === '' || !is_string($channelId) || $channelId === '') {
        fwrite(STDERR, "[launch] fatal: 每个服务必须声明非空 mapId 与 channelId
");
        exit(1);
    }
    if (!is_int($port)) {
        fwrite(STDERR, sprintf("[launch] fatal: %s#%s 缺少 int port
", $mapId, $channelId));
        exit(1);
    }
    if (isset($seenPorts[$port])) {
        fwrite(STDERR, sprintf("[launch] fatal: 端口 %d 重复声明
", $port));
        exit(1);
    }
    $serviceId = $mapId . '#' . $channelId;
    if (isset($seenIds[$serviceId])) {
        fwrite(STDERR, sprintf("[launch] fatal: serviceId %s 重复声明
", $serviceId));
        exit(1);
    }
    $seenPorts[$port] = true;
    $seenIds[$serviceId] = true;
}

$workerScript = __DIR__ . '/map-worker.php';

// 逐服务 spawn：数组命令 + bypass_shell（无 argv 注入面）；stdout/stderr 落独立日志文件
// （多子进程共享同一 stdout fd 会并发交错丢行）
// Spawn per service: array command + bypass_shell (no argv injection surface); stdout/stderr go to a
// per-service log file (sharing one stdout fd across children would interleave and lose lines)
/** @var array<int, array{proc: resource, port: int}> $children pid => child info. */
$children = [];
foreach ($servers as $entry) {
    $command = [PHP_BINARY, $workerScript, '--mapId=' . $entry['mapId'], '--channelId=' . $entry['channelId']];
    $logPath = sprintf('%s/nythros-skeleton-%s#%s.log', sys_get_temp_dir(), $entry['mapId'], $entry['channelId']);
    $proc = proc_open(
        $command,
        [0 => STDIN, 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, sprintf("[launch] spawn 失败: %s
", implode(' ', $command)));
        continue;
    }

    $status = proc_get_status($proc);
    $pid = $status['pid'];
    $children[$pid] = ['proc' => $proc, 'port' => $entry['port']];
    printf("[launch] spawned %-28s port=%-5d pid=%d log=%s
", $entry['mapId'] . '#' . $entry['channelId'] . ' (' . ($entry['worldType'] ?? 'aoi') . ')', $entry['port'], $pid, $logPath);
}

if ($children === []) {
    fwrite(STDERR, "[launch] fatal: 未成功 spawn 任何 worker
");
    exit(1);
}

echo sprintf("[launch] %d worker(s) 已启动；Ctrl+C 全部停止（无进程监督，崩溃不自动拉起）
", count($children));

// 前台等待 + 信号转发：SIGINT/SIGTERM → 向全部子进程转发 SIGTERM（Workerman 优雅 stopAll）后退出；
// SIGCHLD → 收割并打印退出的 worker（不重启）
// Foreground wait + signal forwarding: SIGINT/SIGTERM → forward SIGTERM to every child (Workerman's graceful
// stopAll) then exit; SIGCHLD → reap and report exited workers (no restart)
if (function_exists('pcntl_signal')) {
    $running = true;
    $handler = static function (int $signo) use (&$running, &$children): void {
        if ($signo === SIGCHLD) {
            while (($exited = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                $entry = $children[$exited] ?? null;
                if ($entry !== null) {
                    echo sprintf("[launch] worker exited pid=%d port=%d（不自动拉起）
", $exited, $entry['port']);
                }
                unset($children[$exited]);
            }

            return;
        }

        echo sprintf("[launch] received signal %d, stopping all workers...
", $signo);
        foreach ($children as $entry) {
            proc_terminate($entry['proc'], 15);
        }
        $running = false;
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGCHLD, $handler);

    while ($running) {
        pcntl_signal_dispatch();
        usleep(200000);
        if ($children === []) {
            $running = false;
        }
    }
} else {
    // 非 POSIX：无信号转发，前台轮询子进程状态（子进程全部退出后本脚本结束；手动清理由用户负责）
    // Non-POSIX: no signal forwarding; poll child process status in the foreground (exits when all children exit)
    $anyRunning = true;
    while ($anyRunning) {
        usleep(200000);
        $anyRunning = false;
        foreach ($children as $entry) {
            if (proc_get_status($entry['proc'])['running']) {
                $anyRunning = true;
                break;
            }
        }
    }
}
