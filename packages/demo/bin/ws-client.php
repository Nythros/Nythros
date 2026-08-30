<?php

declare(strict_types=1);

// 定位：packages/demo/bin/ws-client.php — 最小 WebSocket 客户端：向 echo-server 发一条消息、打印回显后退出。
// Located at: packages/demo/bin/ws-client.php — minimal WebSocket client: sends one message to the echo server, prints the echo, then exits.

require __DIR__ . '/../../../vendor/autoload.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

// v5 客户端必须 connect() 且在事件循环内运行：空 worker + onWorkerStart 内建连接
// v5 clients must call connect() and run inside an event loop: an empty worker plus a connection built in onWorkerStart
$worker = new Worker();
$worker->onWorkerStart = static function (): void {
    $stopped = false;
    // worker 回调内不能调用 Worker::stopAll()——master 会将其视为异常退出并无限重启 worker。
    // Never call Worker::stopAll() inside a worker callback — the master treats it as an abnormal exit and restarts the worker forever.
    // 正确做法是向 master 发送 SIGINT，由 master 优雅退出整个进程。
    // The correct approach is to send SIGINT to the master so the whole process exits gracefully.
    $stop = static function () use (&$stopped): void {
        if ($stopped) {
            return;
        }
        $stopped = true;
        // 幂等：onMessage 与 onClose 可能先后触发，$stopped 保证只发一次 SIGINT
        // Idempotent: onMessage and onClose may both fire; $stopped ensures SIGINT is sent only once
        posix_kill(posix_getppid(), SIGINT);
    };

    // 连接到 echo-server(18080)，连上即发一条文本
    // Connect to the echo server (18080) and send one text frame on connect
    $connection = new AsyncTcpConnection('ws://127.0.0.1:18080');
    $connection->onConnect = static function (AsyncTcpConnection $connection): void {
        $connection->send('hello nythros');
    };
    $connection->onMessage = static function (AsyncTcpConnection $connection, mixed $data) use ($stop): void {
        // 收到回显即成功：打印后关连接并优雅退出
        // Receiving the echo means success: print it, close and exit gracefully
        echo '[client] received: ' . $data . PHP_EOL;
        $connection->close();
        $stop();
    };
    $connection->onClose = static function () use ($stop): void {
        // 服务器主动断开也要退出，避免脚本挂死在事件循环里
        // Also exit when the server closes the connection, otherwise the script hangs in the event loop
        $stop();
    };
    $connection->connect();
};
Worker::runAll();
