<?php

declare(strict_types=1);

namespace Nythros\Network;

/**
 * 服务器抽象：统一各传输实现的启动/停止与事件挂载入口。
 * Server abstraction: unifies start/stop and event-hook entry points across transport implementations.
 */
interface ServerInterface
{
    /**
     * 启动周期任务挂载点（Clock/Timer）
     * Mount point for periodic worker-start tasks (Clock/Timer).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onWorkerStart(callable $handler): void;

    /**
     * 注册 Worker 退出回调（追加式：优雅退出时按注册顺序依次执行，供 unregister 等清理钩子挂载）。
     * Registers a worker-stop handler (appending: handlers run in registration order on graceful shutdown, for cleanup hooks such as unregister).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onWorkerStop(callable $handler): void;

    /**
     * (ConnectionInterface $conn)
     * Registers a handler invoked when a connection is established.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onConnect(callable $handler): void;

    /**
     * (ConnectionInterface $conn, string $data) data 为已解帧负载，解码由上层 Serializer 完成
     * (ConnectionInterface $conn, string $data) data is the unframed payload; decoding is done by the upper-level Serializer.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onMessage(callable $handler): void;

    /**
     * (ConnectionInterface $conn)
     * Registers a handler invoked when a connection is closed.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onClose(callable $handler): void;

    /**
     * 启动服务器（阻塞运行事件循环）。
     * Starts the server (blocks running the event loop).
     */
    public function start(): void;

    /**
     * 停止服务器。
     * Stops the server.
     */
    public function stop(): void;
}
