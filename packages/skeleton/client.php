<?php

declare(strict_types=1);

// 入门套件演示客户端：连接一个 Map worker → auth → 收到 auth_ok 后发几次 move → 打印收到的帧 5 秒后退出。
// 用法：php client.php [uid] [port]     （缺省 uid=alice port=18081）
// Located at: client.php — the kit's demo client: connects to a Map worker → auth → sends a few moves
// after auth_ok → prints received frames and exits after 5 seconds.
// Usage: php client.php [uid] [port]    (defaults: uid=alice port=18081)

require __DIR__ . '/vendor/autoload.php';

use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Worker;

$uid = $argv[1] ?? 'alice';
$port = isset($argv[2]) && is_numeric($argv[2]) ? (int) $argv[2] : 18081;

// 消费完自定义参数后注入显式 start 命令（Workerman 的 parseCommand 会扫描 $argv，
// 位置参数 'alice'/'18081' 不在命令集内会打印 usage 并退出——与 map-worker 同款做法）。
// Consume the custom args and inject an explicit start command (Workerman's parseCommand scans argv;
// positional args like 'alice'/'18081' are outside the command set and would print usage and exit).
$GLOBALS['argv'] = [$argv[0], 'start'];

// 显式 pidFile（同 demo run-worker 的 G-5 教训）：缺省按 start_file 派生，同机并发跑多个
// client.php（如冒烟的 bob+alice）第二个起会被 Workerman 判 "already running" 静默退出。
// Explicit pidFile (same lesson as demo's run-worker): the default derives from start_file, so a second
// concurrent client.php (smoke runs bob+alice) would be misdetected as "already running" and exit silently.
Worker::$pidFile = sys_get_temp_dir() . sprintf('/nythros-skeleton-client-%s-%d.pid', $uid, getmypid());

$worker = new Worker();
$worker->onWorkerStart = static function () use ($uid, $port): void {
    $stopped = false;
    // 优雅退出：向 master 发 SIGINT（worker 回调内不能调 Worker::stopAll()，会被当成异常退出无限重启）
    // Graceful exit: send SIGINT to the master (never call Worker::stopAll() inside a worker callback —
    // the master treats it as an abnormal exit and restarts forever)
    $stop = static function () use (&$stopped): void {
        if ($stopped) {
            return;
        }
        $stopped = true;
        if (function_exists('posix_kill')) {
            posix_kill(posix_getppid(), SIGINT);
        }
    };

    $serializer = new JsonBatchSerializer(); // 引擎 JSON 批量序列化器（构造/解析帧） Engine JSON batch serializer (frames built/parsed)
    $movesSent = false; // 已发过一次 move 序列标记（只触发一次） Flag: the move sequence was sent once

    $connection = new AsyncTcpConnection(sprintf('ws://127.0.0.1:%d', $port));
    $connection->onConnect = static function (AsyncTcpConnection $connection) use ($uid, $serializer): void {
        // 用引擎的 JsonBatchSerializer 构造帧（timestamp/requestId 等协议字段由 Message 保证，无需手写 JSON）
        // Frames are built with the engine's JsonBatchSerializer (protocol fields such as timestamp/requestId
        // are guaranteed by Message — no hand-written JSON)
        $connection->send($serializer->encodeBatch([Message::create('auth', ['uid' => $uid])]));
        echo sprintf("[client] -> auth uid=%s\n", $uid);
    };
    $connection->onMessage = static function (AsyncTcpConnection $connection, mixed $data) use (&$movesSent, $serializer): void {
        $frames = json_decode((string) $data, true);
        if (is_array($frames) && !array_is_list($frames)) {
            $frames = [$frames]; // 单帧对象归一为列表 Normalize a single frame object into a list
        }
        foreach ($frames ?? [] as $frame) {
            echo sprintf("[client] <- %s %s\n", $frame['type'] ?? '?', json_encode($frame['payload'] ?? [], JSON_UNESCAPED_UNICODE));
            if (($frame['type'] ?? null) === 'auth_ok' && !$movesSent) {
                // 连发几次位移（各隔 0.5 秒一次）看看 entity_moved 广播
                // Send a few moves (0.5s apart) to observe the entity_moved broadcast
                $movesSent = true;
                $moves = [[1, 0], [0, 1], [-1, 0], [0, -1]];
                foreach ($moves as $i => [$dx, $dy]) {
                    Timer::add($i * 0.5, static function () use ($connection, $serializer, $dx, $dy): void {
                        $connection->send($serializer->encodeBatch([Message::create('move', ['dx' => $dx, 'dy' => $dy])]));
                        echo sprintf("[client] -> move dx=%d dy=%d\n", $dx, $dy);
                    }, [], false);
                }
                // 顺带 ping 一次 Pong
                Timer::add(2.0, static function () use ($connection, $serializer): void {
                    $connection->send($serializer->encodeBatch([Message::create('ping')]));
                }, [], false);
            }
        }
    };
    $connection->onClose = static function () use ($stop): void {
        $stop();
    };
    $connection->connect();

    // 超时兜底：5 秒后自动退出（防脚本挂死在事件循环）
    // Timeout backstop: auto-exit after 5 seconds (prevents hanging in the event loop)
    Timer::add(5.0, static function () use ($stop): void {
        echo "[client] 5s timeout, exiting\n";
        $stop();
    }, [], false);
};
Worker::runAll();
