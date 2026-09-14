<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Social\ConnectionHubInterface;

/**
 * FakeConnectionHub - 记录全部连接层调用；session/在线态/uid→clientId 按配置表返回。
 * FakeConnectionHub - records every connection-tier call; session / online state / uid→clientId are returned from configuration tables.
 */
final class FakeConnectionHub implements ConnectionHubInterface
{
    /** @var list<string> bindUid 调用记录（"clientId|uid"） bindUid call records. */
    public array $binds = [];

    /** @var list<string> closeClient 调用记录 closeClient call records. */
    public array $closes = [];

    /** @var list<string> joinGroup 调用记录（"clientId|group"） joinGroup call records. */
    public array $joinGroups = [];

    /** @var list<string> leaveGroup 调用记录（"clientId|group"） leaveGroup call records. */
    public array $leaveGroups = [];

    /** @var list<array{clientId: string, session: array<string, mixed>}> setSession 调用记录 setSession call records. */
    public array $setSessions = [];

    /** @var list<array{clientId: string, session: array<string, mixed>}> updateSession 调用记录 updateSession call records. */
    public array $updateSessions = [];

    /** @var list<string> sendToClient 调用记录（帧字节） sendToClient call records (frame bytes). */
    public array $sendToClients = [];

    /** @var list<array{uid: string, message: string}> sendToUid 调用记录（帧字节） sendToUid call records (frame bytes). */
    public array $sendToUids = [];

    /** @var list<array{group: string, message: string, exclude: ?string}> sendToGroup 调用记录 sendToGroup call records. */
    public array $sendToGroups = [];

    /** @var list<array{message: string, exclude: ?string}> sendToAll 调用记录 sendToAll call records. */
    public array $sendToAlls = [];

    /** @var array<string, array<string, mixed>> clientId => session 配置表 Session configuration per clientId. */
    public array $sessions = [];

    /** @var array<string, list<string>> uid => clientId 列表配置表 clientId list configuration per uid. */
    public array $clientIdsByUid = [];

    /** @var array<string, bool> uid => 是否在线配置表 Online configuration per uid. */
    public array $online = [];

    public function bindUid(string $clientId, string $uid): void
    {
        $this->binds[] = $clientId . '|' . $uid;
        $this->clientIdsByUid[$uid][] = $clientId;
    }

    public function getClientIdByUid(string $uid): array
    {
        return $this->clientIdsByUid[$uid] ?? [];
    }

    public function closeClient(string $clientId): void
    {
        $this->closes[] = $clientId;
    }

    public function sendToAll(string $message, ?string $excludeClientId = null): void
    {
        $this->sendToAlls[] = ['message' => $message, 'exclude' => $excludeClientId];
    }

    public function sendToGroup(string $group, string $message, ?string $excludeClientId = null): void
    {
        $this->sendToGroups[] = ['group' => $group, 'message' => $message, 'exclude' => $excludeClientId];
    }

    public function sendToUid(string $uid, string $message): void
    {
        $this->sendToUids[] = ['uid' => $uid, 'message' => $message];
    }

    public function sendToClient(string $clientId, string $message): void
    {
        $this->sendToClients[] = $message;
    }

    public function isUidOnline(string $uid): bool
    {
        return $this->online[$uid] ?? false;
    }

    public function getSession(string $clientId): ?array
    {
        return $this->sessions[$clientId] ?? null;
    }

    public function setSession(string $clientId, array $session): void
    {
        $this->setSessions[] = ['clientId' => $clientId, 'session' => $session];
        $this->sessions[$clientId] = $session;
    }

    public function updateSession(string $clientId, array $session): void
    {
        $this->updateSessions[] = ['clientId' => $clientId, 'session' => $session];
        $this->sessions[$clientId] = array_replace($this->sessions[$clientId] ?? [], $session);
    }

    public function joinGroup(string $clientId, string $group): void
    {
        $this->joinGroups[] = $clientId . '|' . $group;
    }

    public function leaveGroup(string $clientId, string $group): void
    {
        $this->leaveGroups[] = $clientId . '|' . $group;
    }
}
