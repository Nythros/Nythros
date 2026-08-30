<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman;

use Nythros\Kernel\PerfProbe;
use Nythros\Network\ConnectionClosedException;
use Nythros\Network\ConnectionInterface;
use Workerman\Connection\TcpConnection;

/**
 * ConnectionInterface 的 Workerman 适配器：把底层 TcpConnection 桥接为引擎统一连接视图。
 * Workerman adapter for ConnectionInterface: bridges the underlying TcpConnection to the engine's unified connection view.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class WorkermanConnection implements ConnectionInterface
{
    /**
     * 构造适配器。
     * Constructs the adapter.
     *
     * @param TcpConnection $tcpConnection Workerman 底层 TCP 连接 Underlying Workerman TCP connection.
     * @param ConnectionManager $manager 连接管理器（持有认证/最后活跃时间等元数据） Connection manager (holds metadata such as auth state and last-active time).
     */
    public function __construct(
        private readonly TcpConnection $tcpConnection,
        private readonly ConnectionManager $manager,
    ) {
    }

    /** 内部服务连接标志（rpc:hello 握手登记后置位，限流豁免依据，MINOR-3） Internal service connection flag (set after the rpc:hello handshake registers the connection; the rate-limiting exemption basis, MINOR-3). */
    private bool $internal = false;

    /**
     * 获取连接 ID：透传 TcpConnection 的数值 id，但加非数字前缀使其恒为字符串键。
     * 原因（e2e 实测踩坑）：PHP 数组键会把纯数字字符串自动转 int，在 strict_types 下 int 传给
     * string 参数（如 ConnectionRegistry::has(string)）会抛 TypeError——裸返回 (string)$id 时
     * ConnectionManager/MapServer/ConnectionRegistry 的数组键在运行时变成 int，导致类型撕裂。
     * Returns the connection ID: the underlying numeric TcpConnection id, prefixed so it stays a
     * string key everywhere. Why (found by the e2e smoke test): PHP array keys auto-cast pure-digit
     * strings to int, so under strict_types an int reaching a string parameter (e.g.
     * ConnectionRegistry::has(string)) throws TypeError — returning the bare (string)$id made the
     * ConnectionManager/MapServer/ConnectionRegistry array keys drift to int at runtime.
     *
     * @return string 连接 ID（非数字前缀 + 底层 id，如 "conn-34"） Connection ID (non-numeric prefix + raw id, e.g. "conn-34").
     */
    public function getId(): string
    {
        return 'conn-' . $this->tcpConnection->id;
    }

    /**
     * 获取远端地址。
     * Returns the remote address.
     *
     * @return string 远端地址（如 IP:port） Remote address (e.g. IP:port).
     */
    public function getRemoteAddress(): string
    {
        return $this->tcpConnection->getRemoteAddress();
    }

    /**
     * 发送负载：先检查连接状态，已关闭则抛异常，避免静默丢包。
     * Sends a payload: checks the connection state first and throws on closed connections to avoid silent packet loss.
     *
     * @param string $payload 待发送内容 Payload to send.
     * @throws ConnectionClosedException 在已关闭连接上发送时抛出 Thrown when sending on a closed connection.
     */
    public function send(string $payload): void
    {
        if ($this->isClosed()) {
            throw new ConnectionClosedException(sprintf('Cannot send on closed connection [%s]', $this->getId()));
        }

        $this->tcpConnection->send($payload);
    }

    /**
     * 批量发送负载：先检查连接状态（已关闭抛异常），再按序逐条透传给底层连接；空数组为空操作。
     * Sends payloads in batch: checks the connection state first (throws on closed), then forwards each payload to the underlying connection in order; an empty array is a no-op.
     *
     * @param list<string> $payloads 待发送内容列表（按序） Payloads to send, in order.
     * @throws ConnectionClosedException 在已关闭连接上发送时抛出 Thrown when sending on a closed connection.
     */
    public function sendBatch(array $payloads): void
    {
        if ($this->isClosed()) {
            throw new ConnectionClosedException(sprintf('Cannot send on closed connection [%s]', $this->getId()));
        }

        // 运行期探针：出站吞吐（字节 + 包数，按连接聚合） Runtime probe: outbound throughput (bytes + packets, aggregated per connection)
        $bytes = 0;
        foreach ($payloads as $payload) {
            $bytes += strlen($payload);
            $this->tcpConnection->send($payload);
        }
        PerfProbe::increment('network.out_bytes', $bytes);
        PerfProbe::increment('network.out_packets', count($payloads));
        PerfProbe::record('network.batch_packets', count($payloads));
    }

    /**
     * 获取底层发送队列积压字节数（透传给 TcpConnection::getSendBufferQueueSize，慢客户端检测用）。
     * Returns the underlying send-queue backlog in bytes (delegates to TcpConnection::getSendBufferQueueSize, for slow-client detection).
     */
    public function getSendBufferQueueSize(): int
    {
        return $this->tcpConnection->getSendBufferQueueSize();
    }

    /**
     * 关闭连接（透传给 TcpConnection）。
     * Closes the connection (delegates to TcpConnection).
     */
    public function close(): void
    {
        $this->tcpConnection->close();
    }

    /**
     * 判断连接是否已关闭。
     * Returns whether the connection is closed.
     *
     * @return bool true 表示已进入 ENDING/CLOSING/CLOSED 任一终结状态 true if in any terminal state (ENDING/CLOSING/CLOSED).
     */
    public function isClosed(): bool
    {
        $status = $this->tcpConnection->getStatus(true);

        return in_array($status, [
            TcpConnection::STATUS_ENDING,
            TcpConnection::STATUS_CLOSING,
            TcpConnection::STATUS_CLOSED,
        ], true);
    }

    /**
     * 获取最近消息时间（元数据由 ConnectionManager 持有，适配器不自行存状态）。
     * Returns the last message time (metadata is owned by ConnectionManager; the adapter keeps no state of its own).
     *
     * @return float Unix 时间戳（秒） Unix timestamp in seconds.
     */
    public function getLastMessageTime(): float
    {
        return $this->manager->getLastMessageTime($this);
    }

    /**
     * 标记为已认证（委托给 ConnectionManager 记录）。
     * Marks the connection as authenticated (delegates to ConnectionManager).
     */
    public function markAuthenticated(): void
    {
        $this->manager->markAuthenticated($this);
    }

    /**
     * 判断是否已认证。
     * Returns whether the connection is authenticated.
     *
     * @return bool true 表示已认证 true if authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->manager->isAuthenticated($this);
    }

    /**
     * 标记为内部服务连接（rpc:hello 握手登记后调用；限流豁免依据，MINOR-3）。
     * Marks the connection as internal (called after the rpc:hello handshake registers it; the rate-limiting exemption basis, MINOR-3).
     */
    public function markInternal(): void
    {
        $this->internal = true;
    }

    /**
     * 判断是否为内部服务连接。
     * Returns whether the connection is an internal service connection.
     *
     * @return bool true 表示内部连接 true if internal.
     */
    public function isInternal(): bool
    {
        return $this->internal;
    }

    /**
     * 注册缓冲写满回调（直接桥接到 TcpConnection 的 onBufferFull）。
     * Registers a buffer-full handler (bridges directly to TcpConnection::onBufferFull).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onBufferFull(callable $handler): void
    {
        $this->tcpConnection->onBufferFull = $handler;
    }

    /**
     * 注册缓冲排空回调（直接桥接到 TcpConnection 的 onBufferDrain）。
     * Registers a buffer-drain handler (bridges directly to TcpConnection::onBufferDrain).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onBufferDrain(callable $handler): void
    {
        $this->tcpConnection->onBufferDrain = $handler;
    }
}
