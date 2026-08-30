<?php

declare(strict_types=1);

namespace Nythros\Network;

/**
 * 网络连接抽象：屏蔽底层实现，提供发送/关闭/认证/缓冲回压的统一视图。
 * Network connection abstraction: hides the underlying transport and exposes a unified view of send/close/auth/buffer backpressure.
 */
interface ConnectionInterface
{
    /**
     * 获取连接唯一标识符。
     * Returns the unique connection identifier.
     *
     * @return string 连接 ID Connection ID.
     */
    public function getId(): string;

    /**
     * 获取远端地址。
     * Returns the remote address.
     *
     * @return string 远端地址（如 IP:port） Remote address (e.g. IP:port).
     */
    public function getRemoteAddress(): string;

    /**
     * 向连接发送负载。
     * Sends a payload to the connection.
     *
     * @param string $payload 待发送内容 Payload to send.
     * @throws ConnectionClosedException 已关闭连接上发送 Thrown when sending on a closed connection.
     */
    public function send(string $payload): void;

    /**
     * 按顺序批量发送多条负载；空数组为空操作，语义与 send 一致。
     * Sends multiple payloads in order; an empty array is a no-op, with semantics identical to send.
     *
     * @param list<string> $payloads 待发送内容列表（按序） Payloads to send, in order.
     * @throws ConnectionClosedException 已关闭连接上发送 Thrown when sending on a closed connection.
     */
    public function sendBatch(array $payloads): void;

    /**
     * 获取底层发送队列中尚未写入内核的字节数（慢客户端软/硬阈值检测用；0 表示无积压）。
     * Returns the number of bytes still buffered in the underlying send queue, i.e. written but not yet flushed to
     * the kernel (used by slow-client soft/hard threshold detection; 0 means no backlog).
     */
    public function getSendBufferQueueSize(): int;

    /**
     * 关闭连接。
     * Closes the connection.
     */
    public function close(): void;

    /**
     * 判断连接是否已关闭。
     * Returns whether the connection is closed.
     *
     * @return bool true 表示已关闭 true if closed.
     */
    public function isClosed(): bool;

    /**
     * 获取最近一次收到消息的时间戳。
     * Returns the timestamp of the last received message.
     *
     * @return float Unix 时间戳（秒） Unix timestamp in seconds.
     */
    public function getLastMessageTime(): float;

    /**
     * 将连接标记为已通过认证。
     * Marks the connection as authenticated.
     */
    public function markAuthenticated(): void;

    /**
     * 判断连接是否已通过认证。
     * Returns whether the connection is authenticated.
     *
     * @return bool true 表示已认证 true if authenticated.
     */
    public function isAuthenticated(): bool;

    /**
     * 将连接标记为内部服务连接（服务间 RPC transport，rpc:hello 握手登记后调用；限流豁免依据，MINOR-3）。
     * Marks the connection as an internal service connection (inter-service RPC transport, called after the
     * rpc:hello handshake registers it; the rate-limiting exemption basis, MINOR-3).
     */
    public function markInternal(): void;

    /**
     * 判断连接是否为内部服务连接。
     * Returns whether the connection is an internal service connection.
     *
     * @return bool true 表示内部连接 true if internal.
     */
    public function isInternal(): bool;

    /**
     * 注册发送缓冲区写满时的回调。
     * Registers a handler for when the send buffer becomes full.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onBufferFull(callable $handler): void;

    /**
     * 注册发送缓冲区排空时的回调。
     * Registers a handler for when the send buffer drains.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onBufferDrain(callable $handler): void;
}
