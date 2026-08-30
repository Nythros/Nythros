<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 连接层传输端口：hub 的下行投递与踢线动作落到具体传输实现（由接入层绑定，如 Workerman 连接），
 * 使 InMemoryConnectionHub 的索引/会话语义可脱离传输实现单测（ADR-021）。
 * Connection-tier transport port: the hub's downstream delivery and kick actions land on a concrete transport
 * (bound by the access layer, e.g. Workerman connections), so InMemoryConnectionHub's indexing/session semantics
 * are unit-testable without any transport implementation (ADR-021).
 */
interface HubTransportInterface
{
    /**
     * 向指定连接写入帧字节；连接已不存在时静默丢弃。
     * Writes frame bytes to a connection; silently dropped when the connection no longer exists.
     */
    public function sendToConnection(string $clientId, string $message): void;

    /**
     * 关闭指定连接（触发接入层的 onClose 清理路径）；连接已不存在时静默忽略。
     * Closes a connection (triggering the access layer's onClose cleanup path); ignored when already gone.
     */
    public function close(string $clientId): void;
}
