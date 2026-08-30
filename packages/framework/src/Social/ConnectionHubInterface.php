<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 社交连接层契约：uid↔连接登记、分组索引、会话存取与下行投递的最小面（ADR-021：取代 GatewayClientInterface，
 * 由自研连接注册表模型承载 gateway-worker 静态 API 的行为承诺）。
 * Social connection-tier contract: the minimal surface of uid↔connection registration, group indexing, session storage
 * and downstream delivery (ADR-021: replaces GatewayClientInterface, carried by the self-built connection-registry model).
 *
 * 组键是社交业务约定（engine 出局）：`map:{mapId}:{channelId}` / `team:{teamId}` / `guild:{guildId}`。
 * Group keys are a social-business convention (kept out of the engine): `map:{mapId}:{channelId}` / `team:{teamId}` / `guild:{guildId}`.
 */
interface ConnectionHubInterface
{
    /**
     * 将 clientId 与 uid 绑定（单点登录的在线态依据；一 uid 多连接多对多）。
     * Bind a clientId to a uid (the online-state basis for single sign-on; many connections per uid, many-to-many).
     */
    public function bindUid(string $clientId, string $uid): void;

    /**
     * 获取与 uid 绑定的全部 clientId。
     * Returns all client ids bound to the uid.
     *
     * @return list<string> clientId 列表（未绑定/离线为空列表） Client id list (empty when unbound/offline).
     */
    public function getClientIdByUid(string $uid): array;

    /**
     * 关闭指定连接（踢下线；传输层负责真正断开并触发 onClose 清理）。
     * Close a connection (kick it; the transport performs the actual disconnect and triggers the onClose cleanup).
     */
    public function closeClient(string $clientId): void;

    /**
     * 向所有客户端广播（可排除指定连接，如发送者本人）。
     * Broadcast to all clients (optionally excluding one connection, e.g. the sender).
     */
    public function sendToAll(string $message, ?string $excludeClientId = null): void;

    /**
     * 向分组广播（可排除指定连接）。
     * Broadcast to a group (optionally excluding one connection).
     */
    public function sendToGroup(string $group, string $message, ?string $excludeClientId = null): void;

    /**
     * 向 uid 定向发送（全部绑定连接各一份；离线自动丢弃）。
     * Directed send to a uid (one copy per bound connection; dropped automatically when offline).
     */
    public function sendToUid(string $uid, string $message): void;

    /**
     * 向指定连接直接发送（未绑定的认证失败回执等场景）。
     * Directed send to a specific connection (e.g. auth-failure receipts before binding).
     */
    public function sendToClient(string $clientId, string $message): void;

    /**
     * 判断 uid 是否在线（存在绑定连接）。
     * Whether the uid is online (has a bound connection).
     */
    public function isUidOnline(string $uid): bool;

    /**
     * 读取连接的会话数据。
     * Read a connection's session data.
     *
     * @return ?array<string, mixed> 会话数据；不可见时 null Session data; null when unavailable.
     */
    public function getSession(string $clientId): ?array;

    /**
     * 整量覆盖会话（丢弃旧字段）。
     * Replace the whole session (dropping old fields).
     *
     * @param array<string, mixed> $session 会话数据 Session data.
     */
    public function setSession(string $clientId, array $session): void;

    /**
     * 与会话合并（未提及字段保留）。
     * Merge into the session (untouched fields survive).
     *
     * @param array<string, mixed> $session 会话增量 Session delta.
     */
    public function updateSession(string $clientId, array $session): void;

    /**
     * 将连接加入分组。
     * Add a connection to a group.
     */
    public function joinGroup(string $clientId, string $group): void;

    /**
     * 将连接移出分组。
     * Remove a connection from a group.
     */
    public function leaveGroup(string $clientId, string $group): void;
}
