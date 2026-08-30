<?php

declare(strict_types=1);

// 定位：packages/demo/bin/echo-server.php — 最小回显服务（18080）：收到什么回什么，用于最简联调。
// Located at: packages/demo/bin/echo-server.php — minimal echo service (18080): echoes back whatever it receives, for the simplest smoke test.

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\NetworkWorkerman\WorkermanWebSocketServer;

$server = new WorkermanWebSocketServer(
    listenAddress: 'websocket://0.0.0.0:18080',
    authTimeoutSeconds: null,   // echo 阶段不启用认证超时 echo phase does not enable auth timeout
);
// 核心：原样回显，前缀 echo: 便于肉眼核对
// Core: echo the payload back verbatim with an echo: prefix for easy eyeballing
$server->onMessage(function ($conn, string $data): void {
    $conn->send('echo: ' . $data);
});
$server->onConnect(function ($conn): void {
    echo '[server] connect: ' . $conn->getId() . ' from ' . $conn->getRemoteAddress() . PHP_EOL;
});
$server->onClose(function ($conn): void {
    echo '[server] close: ' . $conn->getId() . PHP_EOL;
});
echo "[server] starting on 18080..." . PHP_EOL;
$server->start();
