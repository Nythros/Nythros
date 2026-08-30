<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Framework\Social\HubTransportInterface;
use Nythros\Network\ConnectionClosedException;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;

/**
 * Workerman 传输绑定（ADR-021 接入层）：把 InMemoryConnectionHub 的下行投递/踢线落到真实 WebSocket 连接。
 * 自持 connId→连接表（经 server 的追加式 onConnect/onClose 回调同步），与 SocialServer 的骨架连接表互不干扰；
 * 对端已断时的发送失败静默丢弃（close 事件随后触发 hub 清理）。
 * Workerman transport binding (the ADR-021 access layer): lands InMemoryConnectionHub's downstream delivery/kicks on
 * real WebSocket connections. Keeps its own connId→connection table (synced via the server's appending onConnect/onClose
 * callbacks), independent of SocialServer's skeleton table; send failures against a dead peer are dropped silently
 * (its close event triggers the hub cleanup shortly after).
 */
final class WorkermanHubTransport implements HubTransportInterface
{
    /** @var array<string, \Nythros\Network\ConnectionInterface> connId => 存活连接 connId => live connection. */
    private array $connections = [];

    /**
     * 构造传输并挂接连接生命周期回调（追加式，与其它处理器并存）。
     * Constructs the transport and hooks the connection-lifecycle callbacks (appending; coexists with other handlers).
     */
    public function __construct(WorkermanWebSocketServer $server)
    {
        $server->onConnect(function (\Nythros\Network\ConnectionInterface $conn): void {
            $this->connections[$conn->getId()] = $conn;
        });
        $server->onClose(function (\Nythros\Network\ConnectionInterface $conn): void {
            unset($this->connections[$conn->getId()]);
        });
    }

    public function sendToConnection(string $clientId, string $message): void
    {
        $conn = $this->connections[$clientId] ?? null;
        if ($conn === null) {
            return;
        }

        try {
            $conn->send($message);
        } catch (ConnectionClosedException) {
            // 对端已断但 close 事件未触达：丢弃本帧，清理交给 onClose 路径
            // The peer died before its close event arrived: drop this frame, cleanup rides the onClose path
        }
    }

    public function close(string $clientId): void
    {
        $conn = $this->connections[$clientId] ?? null;
        if ($conn === null) {
            return;
        }

        $conn->close();
    }
}
