<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 进程内连接注册表：uid↔connections 多对多表 + group→conns 索引 + 连接会话存取（ADR-021 自研单栈的连接层实现）。
 * In-process connection registry: a many-to-many uid↔connections table, group→connections indexes and per-connection
 * sessions (the connection tier of ADR-021's self-built single stack).
 *
 * 行为承诺对齐 gateway-worker 内置语义：bindUid/joinGroup/setSession 幂等登记；detachConnection 在 onClose 一次性
 * 清全部索引（对齐 gateway 的自动解绑）；对外发送与踢线经构造注入的 HubTransportInterface（接入层绑定 Workerman 连接），
 * 本类不感知传输细节。
 * Behavioral promises align with gateway-worker's built-ins: bindUid/joinGroup/setSession register idempotently;
 * detachConnection clears every index at once on onClose (matching gateway's auto-unbind); outbound sends and kicks go
 * through the constructor-injected HubTransportInterface (bound to Workerman connections by the access layer) — this
 * class never sees transport details.
 */
final class InMemoryConnectionHub implements ConnectionHubInterface
{
    /** @var array<string, list<string>> uid => 绑定连接列表（插入序） uid => bound client ids (insertion order). */
    private array $uids = [];

    /** @var array<string, string> clientId => 绑定 uid（反查索引） clientId => bound uid (reverse index). */
    private array $uidByClient = [];

    /** @var array<string, array<string, true>> group => connId 集合 group => connId set. */
    private array $groupConns = [];

    /** @var array<string, array<string, true>> connId => 所属分组集合（反查索引） connId => joined groups (reverse index). */
    private array $groupsByConn = [];

    /** @var array<string, array<string, mixed>> connId => 会话数据 connId => session data. */
    private array $sessions = [];

    /** @var array<string, true> 存活连接表（onConnect 登记、detachConnection 摘除；sendToAll 的全集来源） Live-connection table (registered on connect, removed by detachConnection; the sendToAll universe). */
    private array $connections = [];

    /**
     * 构造连接注册表。
     * Constructs the connection hub.
     *
     * @param HubTransportInterface $transport 下行传输端口（发送/踢线落到具体连接） Downstream transport port (sends/kicks land on real connections).
     */
    public function __construct(private readonly HubTransportInterface $transport)
    {
    }

    public function bindUid(string $clientId, string $uid): void
    {
        // 重复绑定同一 uid 幂等；换 uid 重绑先摘旧映射，避免反查索引残留脏项
        // Rebinding the same uid is idempotent; rebinding to another uid removes the old mapping first so the reverse index never keeps stale entries
        $oldUid = $this->uidByClient[$clientId] ?? null;
        if ($oldUid !== null) {
            if ($oldUid === $uid) {
                return;
            }
            $this->unbind($clientId, $oldUid);
        }

        $this->uidByClient[$clientId] = $uid;
        $this->uids[$uid][] = $clientId;
    }

    public function getClientIdByUid(string $uid): array
    {
        return $this->uids[$uid] ?? [];
    }

    public function closeClient(string $clientId): void
    {
        $this->transport->close($clientId);
    }

    public function sendToAll(string $message, ?string $excludeClientId = null): void
    {
        foreach (array_keys($this->connections) as $clientId) {
            if ($clientId === $excludeClientId) {
                continue;
            }
            $this->transport->sendToConnection($clientId, $message);
        }
    }

    public function sendToGroup(string $group, string $message, ?string $excludeClientId = null): void
    {
        foreach (array_keys($this->groupConns[$group] ?? []) as $clientId) {
            if ($clientId === $excludeClientId) {
                continue;
            }
            $this->transport->sendToConnection($clientId, $message);
        }
    }

    public function sendToUid(string $uid, string $message): void
    {
        foreach ($this->getClientIdByUid($uid) as $clientId) {
            $this->transport->sendToConnection($clientId, $message);
        }
    }

    public function sendToClient(string $clientId, string $message): void
    {
        $this->transport->sendToConnection($clientId, $message);
    }

    public function isUidOnline(string $uid): bool
    {
        return ($this->uids[$uid] ?? []) !== [];
    }

    public function getSession(string $clientId): ?array
    {
        return $this->sessions[$clientId] ?? null;
    }

    public function setSession(string $clientId, array $session): void
    {
        $this->sessions[$clientId] = $session;
    }

    public function updateSession(string $clientId, array $session): void
    {
        $this->sessions[$clientId] = array_replace($this->sessions[$clientId] ?? [], $session);
    }

    public function joinGroup(string $clientId, string $group): void
    {
        // 重复加入幂等（集合语义）
        // Joining twice is idempotent (set semantics)
        if (isset($this->groupsByConn[$clientId][$group])) {
            return;
        }

        $this->groupsByConn[$clientId][$group] = true;
        $this->groupConns[$group][$clientId] = true;
    }

    public function leaveGroup(string $clientId, string $group): void
    {
        unset($this->groupsByConn[$clientId][$group], $this->groupConns[$group][$clientId]);
        if (($this->groupsByConn[$clientId] ?? []) === []) {
            unset($this->groupsByConn[$clientId]);
        }
        if (($this->groupConns[$group] ?? []) === []) {
            unset($this->groupConns[$group]);
        }
    }

    /**
     * 连接建立登记（onConnect 调用）：进入存活连接表，sendToAll 广播全集由此而来。
     * Connection-establishment registration (called on connect): enters the live-connection table, the universe sendToAll broadcasts to.
     */
    public function attachConnection(string $clientId): void
    {
        $this->connections[$clientId] = true;
    }

    /**
     * 连接关闭清理（onClose 一次性调用）：摘存活登记、uid 绑定、全部所属分组与会话——对齐 gateway-worker 自动解绑的行为承诺。
     * Connection-close cleanup (called once on onClose): removes the live entry, the uid binding, every joined group and the
     * session — matching gateway-worker's auto-unbind promise.
     */
    public function detachConnection(string $clientId): void
    {
        unset($this->connections[$clientId]);

        $uid = $this->uidByClient[$clientId] ?? null;
        if ($uid !== null) {
            $this->unbind($clientId, $uid);
        }

        foreach (array_keys($this->groupsByConn[$clientId] ?? []) as $group) {
            unset($this->groupConns[$group][$clientId]);
            if (($this->groupConns[$group] ?? []) === []) {
                unset($this->groupConns[$group]);
            }
        }
        unset($this->groupsByConn[$clientId], $this->sessions[$clientId]);
    }

    /**
     * 摘除单条 uid↔conn 绑定（双向索引同步清理，空表回收键）。
     * Removes one uid↔conn binding (both indexes cleaned in sync; empty tables drop their keys).
     */
    private function unbind(string $clientId, string $uid): void
    {
        unset($this->uidByClient[$clientId]);
        if (!isset($this->uids[$uid])) {
            return;
        }
        $this->uids[$uid] = array_values(array_filter(
            $this->uids[$uid],
            static fn (string $bound): bool => $bound !== $clientId,
        ));
        if ($this->uids[$uid] === []) {
            unset($this->uids[$uid]);
        }
    }
}
