<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Auth\Identity;
use Nythros\Framework\Social\ConnectionHubInterface;
use Nythros\Framework\Social\FriendStoreInterface;
use Nythros\Framework\Social\GuildStoreInterface;
use Nythros\Framework\Social\LocationStoreInterface;
use Nythros\Framework\Social\TeamStoreInterface;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;

/**
 * 共享 Social 层测试 fakes：ConnectionHub / TeamStore / LocationStore / GuildStore / Authenticator 的调用记录实现，供 SocialServiceTest 复用。
 * Shared Social-layer test fakes: call-recording implementations of ConnectionHub / TeamStore / LocationStore / GuildStore / Authenticator, reused by SocialServiceTest.
 */

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

/**
 * FakeTeamStore - 按配置表返回 findByUid/get；五个写操作返回可配置的固定结果（缺省 OK）。
 * FakeTeamStore - findByUid/get return from configuration tables; the five writes return configured canned results (OK by default).
 */
final class FakeTeamStore implements TeamStoreInterface
{
    /** @var array<string, string> uid => teamId 配置表 uid → teamId configuration table. */
    public array $uidTeam = [];

    /** @var array<string, array{leaderUid: string, members: list<string>}> teamId => team 配置表 team configuration table. */
    public array $teams = [];

    /** @var array<string, array{code: int, teamId?: string, leaderUid?: string}> invite 结果配置表 invite result configuration table. */
    public array $inviteResults = [];

    /** @var array<string, array{code: int, members?: list<string>}> accept 结果配置表 accept result configuration table. */
    public array $acceptResults = [];

    /** @var array<string, array{code: int, leaderUid?: string}> reject 结果配置表 reject result configuration table. */
    public array $rejectResults = [];

    /** @var array<string, array{code: int, action?: string, members?: list<string>}> leave 结果配置表 leave result configuration table. */
    public array $leaveResults = [];

    /** @var array<string, array{code: int, members?: list<string>}> disband 结果配置表 disband result configuration table. */
    public array $disbandResults = [];

    public function findByUid(string $uid): ?string
    {
        return $this->uidTeam[$uid] ?? null;
    }

    public function get(string $teamId): ?array
    {
        return $this->teams[$teamId] ?? null;
    }

    public function invite(string $senderUid, string $targetUid, int $maxSize, int $teamTtl, float $now): array
    {
        $key = $senderUid . '|' . $targetUid;

        return $this->inviteResults[$key] ?? ['code' => self::CODE_OK, 'teamId' => 'team-1', 'leaderUid' => $senderUid];
    }

    public function accept(string $uid, string $teamId, int $maxSize, int $teamTtl, float $now): array
    {
        return $this->acceptResults[$teamId] ?? ['code' => self::CODE_OK, 'members' => ['u1', $uid]];
    }

    public function reject(string $uid, string $teamId, int $teamTtl, float $now): array
    {
        return $this->rejectResults[$teamId] ?? ['code' => self::CODE_OK, 'leaderUid' => 'leader'];
    }

    public function leave(string $uid, string $teamId, int $teamTtl): array
    {
        return $this->leaveResults[$teamId] ?? ['code' => self::CODE_OK, 'action' => 'left', 'members' => [$uid]];
    }

    public function disband(string $uid, string $teamId, int $teamTtl): array
    {
        return $this->disbandResults[$teamId] ?? ['code' => self::CODE_OK, 'members' => [$uid]];
    }
}

/**
 * FakeLocationStore - 按配置表返回 isOffline/getLocation；记录写调用。
 * FakeLocationStore - isOffline/getLocation return from configuration tables; write calls are recorded.
 */
final class FakeLocationStore implements LocationStoreInterface
{
    /** @var list<string> markOffline 调用记录 markOffline call records. */
    public array $markOfflines = [];

    /** @var list<string> clearOffline 调用记录 clearOffline call records. */
    public array $clearOfflines = [];

    /** @var list<array{uid: string, mapId: string, channelId: string, x: ?float, y: ?float}> saveLocation 调用记录 saveLocation call records. */
    public array $saves = [];

    /** @var array<string, bool> uid => isOffline 配置表 isOffline configuration per uid. */
    public array $offline = [];

    /** @var array<string, array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float}> uid => 位置配置表 location configuration per uid. */
    public array $locations = [];

    public function markOffline(string $uid): void
    {
        $this->markOfflines[] = $uid;
    }

    public function isOffline(string $uid): bool
    {
        return $this->offline[$uid] ?? false;
    }

    public function saveLocation(string $uid, string $mapId, string $channelId, ?float $x = null, ?float $y = null): void
    {
        $this->saves[] = ['uid' => $uid, 'mapId' => $mapId, 'channelId' => $channelId, 'x' => $x, 'y' => $y];
    }

    public function getLocation(string $uid): ?array
    {
        return $this->locations[$uid] ?? null;
    }

    public function clearOffline(string $uid): void
    {
        $this->clearOfflines[] = $uid;
    }
}

/**
 * FakeGuildStore - 内存帮派存储：与 GuildStore 同语义（正式化面 + 最小面 + 权限矩阵），供 SocialService 单测。
 * FakeGuildStore - an in-memory guild store sharing GuildStore's semantics (the formalized surface + minimal surface
 * + permission matrix), serving the SocialService unit tests.
 */
final class FakeGuildStore implements GuildStoreInterface
{
    /** @var array<string, string> uid => guildId 配置表 uid → guildId configuration table. */
    public array $uidGuild = [];

    /**
     * @var array<string, array{name: ?string, notice: string, maxMembers: int, leaderUid: ?string, members: list<string>, roles: array<string, string>, applicants: list<string>}>
     *         guildId => 帮派数据表 guildId => guild data table.
     */
    public array $guilds = [];

    /** @var list<string> join 调用记录（"uid|guildId"） join call records. */
    public array $joins = [];

    /** @var list<string> leave 调用记录（"uid|guildId"） leave call records. */
    public array $leaves = [];

    private const ROLE_RANKS = [
        self::ROLE_LEADER => 3,
        self::ROLE_OFFICER => 2,
        self::ROLE_MEMBER => 1,
    ];

    private const PERMISSION_MATRIX = [
        'disband' => [self::ROLE_LEADER],
        'kick' => [self::ROLE_LEADER, self::ROLE_OFFICER],
        'promote' => [self::ROLE_LEADER],
        'notice' => [self::ROLE_LEADER, self::ROLE_OFFICER],
        'approve' => [self::ROLE_LEADER, self::ROLE_OFFICER],
    ];

    public function create(string $uid, string $guildId, ?string $name, int $maxMembers): array
    {
        if (isset($this->uidGuild[$uid])) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }
        if (isset($this->guilds[$guildId])) {
            return ['code' => self::CODE_GUILD_EXISTS];
        }

        $this->guilds[$guildId] = [
            'name' => $name,
            'notice' => '',
            'maxMembers' => $maxMembers,
            'leaderUid' => $uid,
            'members' => [$uid],
            'roles' => [$uid => self::ROLE_LEADER],
            'applicants' => [],
        ];
        $this->uidGuild[$uid] = $guildId;

        return ['code' => self::CODE_OK];
    }

    public function disband(string $operatorUid, string $guildId): array
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'disband');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        foreach ($guild['members'] as $member) {
            unset($this->uidGuild[$member]);
        }
        unset($this->guilds[$guildId]);

        return ['code' => self::CODE_OK, 'members' => $guild['members']];
    }

    public function kick(string $operatorUid, string $targetUid, string $guildId): array
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'kick');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        $targetRole = $guild['roles'][$targetUid] ?? null;
        if ($targetRole === null || $targetUid === $operatorUid
            || self::ROLE_RANKS[$targetRole] >= self::ROLE_RANKS[$guild['roles'][$operatorUid]]
        ) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $this->removeMember($guildId, $targetUid);

        return ['code' => self::CODE_OK];
    }

    public function promote(string $operatorUid, string $targetUid, string $guildId, string $role): array
    {
        if ($role !== self::ROLE_OFFICER && $role !== self::ROLE_MEMBER) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'promote');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        $targetRole = $guild['roles'][$targetUid] ?? null;
        if ($targetRole === null || $targetUid === $operatorUid || $targetRole === self::ROLE_LEADER) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $this->guilds[$guildId]['roles'][$targetUid] = $role;

        return ['code' => self::CODE_OK];
    }

    public function setNotice(string $operatorUid, string $guildId, string $notice): array
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'notice');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        $this->guilds[$guildId]['notice'] = $notice;

        return ['code' => self::CODE_OK];
    }

    public function apply(string $uid, string $guildId): array
    {
        if (isset($this->uidGuild[$uid])) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        if (in_array($uid, $guild['applicants'], true)) {
            return ['code' => self::CODE_ALREADY_APPLIED];
        }
        if (count($guild['members']) >= $guild['maxMembers']) {
            return ['code' => self::CODE_GUILD_FULL];
        }

        $this->guilds[$guildId]['applicants'][] = $uid;

        return ['code' => self::CODE_OK];
    }

    public function approve(string $approverUid, string $applicantUid, string $guildId, bool $accept): array
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($approverUid, $guild, 'approve');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }
        if (!in_array($applicantUid, $guild['applicants'], true)) {
            return ['code' => self::CODE_APPLICATION_NOT_FOUND];
        }

        $this->guilds[$guildId]['applicants'] = array_values(array_filter(
            $guild['applicants'],
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));

        if (!$accept) {
            return ['code' => self::CODE_OK];
        }
        if (isset($this->uidGuild[$applicantUid])) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }
        if (count($this->guilds[$guildId]['members']) >= $this->guilds[$guildId]['maxMembers']) {
            return ['code' => self::CODE_GUILD_FULL];
        }

        $this->appendMember($guildId, $applicantUid, self::ROLE_MEMBER);

        return ['code' => self::CODE_OK];
    }

    public function roleOf(string $uid, string $guildId): ?string
    {
        return $this->guilds[$guildId]['roles'][$uid] ?? null;
    }

    public function members(string $guildId): array
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            return [];
        }

        $members = [];
        foreach ($guild['members'] as $member) {
            $members[] = ['uid' => $member, 'role' => $guild['roles'][$member] ?? self::ROLE_MEMBER];
        }

        return $members;
    }

    public function join(string $uid, string $guildId): bool
    {
        // 与 GuildStore 对齐的换帮拦截：已在其他帮派 → false（不进记录、不改索引）
        // Guild-switch guard aligned with GuildStore: already in a different guild → false (no record, no index change)
        $existing = $this->uidGuild[$uid] ?? null;
        if ($existing !== null && $existing !== $guildId) {
            return false;
        }
        if ($existing === $guildId) {
            return true;
        }

        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null) {
            $guild = [
                'name' => null,
                'notice' => '',
                'maxMembers' => 100,
                'leaderUid' => null,
                'members' => [],
                'roles' => [],
                'applicants' => [],
            ];
        }
        if (count($guild['members']) >= $guild['maxMembers']) {
            return false;
        }

        $this->joins[] = $uid . '|' . $guildId;
        $this->appendMember($guildId, $uid, self::ROLE_MEMBER);

        return true;
    }

    public function leave(string $uid, string $guildId): bool
    {
        $guild = $this->guilds[$guildId] ?? null;
        if ($guild === null || !in_array($uid, $guild['members'], true)) {
            return false;
        }

        $this->leaves[] = $uid . '|' . $guildId;
        $remaining = array_values(array_filter(
            $guild['members'],
            static fn (string $member): bool => $member !== $uid,
        ));
        if ($remaining === []) {
            unset($this->guilds[$guildId]);
        } else {
            $roles = $guild['roles'];
            unset($roles[$uid]);
            $this->guilds[$guildId]['members'] = $remaining;
            $this->guilds[$guildId]['roles'] = $roles;
        }
        unset($this->uidGuild[$uid]);

        return true;
    }

    public function findByUid(string $uid): ?string
    {
        return $this->uidGuild[$uid] ?? null;
    }

    public function get(string $guildId): ?array
    {
        $guild = $this->guilds[$guildId] ?? null;

        return $guild === null ? null : ['name' => $guild['name'], 'notice' => $guild['notice'], 'members' => $guild['members']];
    }

    /**
     * 追加成员（members/roles/索引三处同步，与 GuildStore 同口径）。
     * Append a member (members/roles/index written in sync, same as GuildStore).
     */
    private function appendMember(string $guildId, string $uid, string $role): void
    {
        if (!isset($this->guilds[$guildId])) {
            $this->guilds[$guildId] = [
                'name' => null,
                'notice' => '',
                'maxMembers' => 100,
                'leaderUid' => null,
                'members' => [],
                'roles' => [],
                'applicants' => [],
            ];
        }
        if (!in_array($uid, $this->guilds[$guildId]['members'], true)) {
            $this->guilds[$guildId]['members'][] = $uid;
        }
        $this->guilds[$guildId]['roles'][$uid] = $role;
        $this->uidGuild[$uid] = $guildId;
    }

    /**
     * 移除成员（members/roles/索引三处同步，与 GuildStore 同口径）。
     * Remove a member (members/roles/index written in sync, same as GuildStore).
     */
    private function removeMember(string $guildId, string $uid): void
    {
        $guild = $this->guilds[$guildId];
        $guild['members'] = array_values(array_filter(
            $guild['members'],
            static fn (string $member): bool => $member !== $uid,
        ));
        unset($guild['roles'][$uid]);
        $this->guilds[$guildId] = $guild;
        unset($this->uidGuild[$uid]);
    }

    /**
     * 权限矩阵表驱动校验（与 GuildStore 同表）。
     * The table-driven permission-matrix check (same table as GuildStore).
     */
    private function checkPermission(string $operatorUid, array $guild, string $operation): ?int
    {
        $role = $guild['roles'][$operatorUid] ?? null;
        if ($role === null) {
            return self::CODE_NOT_MEMBER;
        }
        if (!in_array($role, self::PERMISSION_MATRIX[$operation], true)) {
            return self::CODE_PERMISSION_DENIED;
        }

        return null;
    }
}

/**
 * FakeFriendStore - 内存好友关系存储：与 RedisFriendStore 同语义（双向一致/申请去重），供 SocialService 单测。
 * FakeFriendStore - an in-memory friend store with the same semantics as RedisFriendStore (bidirectional
 * consistency / application dedupe), serving the SocialService unit tests.
 */
final class FakeFriendStore implements FriendStoreInterface
{
    /** @var array<string, list<string>> uid => 好友 uid 列表 uid => friend uid list. */
    public array $friends = [];

    /** @var array<string, list<string>> uid => 指向 uid 的申请方列表 uid => applicants targeting uid. */
    public array $requests = [];

    public function apply(string $fromUid, string $toUid): array
    {
        if ($fromUid === $toUid) {
            return ['code' => self::CODE_SELF];
        }
        if (in_array($toUid, $this->friends[$fromUid] ?? [], true)) {
            return ['code' => self::CODE_ALREADY_FRIENDS];
        }
        if (in_array($fromUid, $this->requests[$toUid] ?? [], true)) {
            return ['code' => self::CODE_REQUEST_EXISTS];
        }
        $this->requests[$toUid][] = $fromUid;

        return ['code' => self::CODE_OK];
    }

    public function accept(string $applicantUid, string $acceptorUid): array
    {
        if ($applicantUid === $acceptorUid) {
            return ['code' => self::CODE_SELF];
        }
        $requests = $this->requests[$acceptorUid] ?? [];
        if (!in_array($applicantUid, $requests, true)) {
            return ['code' => self::CODE_REQUEST_NOT_FOUND];
        }
        $this->requests[$acceptorUid] = array_values(array_filter(
            $requests,
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));
        $this->requests[$applicantUid] = array_values(array_filter(
            $this->requests[$applicantUid] ?? [],
            static fn (string $applicant): bool => $applicant !== $acceptorUid,
        ));
        $this->friends[$applicantUid][] = $acceptorUid;
        $this->friends[$acceptorUid][] = $applicantUid;

        return ['code' => self::CODE_OK];
    }

    public function reject(string $applicantUid, string $rejectorUid): array
    {
        $requests = $this->requests[$rejectorUid] ?? [];
        if (!in_array($applicantUid, $requests, true)) {
            return ['code' => self::CODE_REQUEST_NOT_FOUND];
        }
        $this->requests[$rejectorUid] = array_values(array_filter(
            $requests,
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));

        return ['code' => self::CODE_OK];
    }

    public function remove(string $uid, string $targetUid): array
    {
        if ($uid === $targetUid) {
            return ['code' => self::CODE_SELF];
        }
        if (!in_array($targetUid, $this->friends[$uid] ?? [], true)) {
            return ['code' => self::CODE_NOT_FRIENDS];
        }
        $this->friends[$uid] = array_values(array_filter(
            $this->friends[$uid],
            static fn (string $friend): bool => $friend !== $targetUid,
        ));
        $this->friends[$targetUid] = array_values(array_filter(
            $this->friends[$targetUid] ?? [],
            static fn (string $friend): bool => $friend !== $uid,
        ));

        return ['code' => self::CODE_OK];
    }

    public function list(string $uid): array
    {
        $friends = $this->friends[$uid] ?? [];
        sort($friends, SORT_STRING);

        return $friends;
    }
}

/**
 * FakeSocialAuthenticator - 可配置抛出异常或返回指定 uid（缺省取 credentials['username']）。
 * FakeSocialAuthenticator - configurable to throw or return a fixed uid (defaults to credentials['username']).
 */
final class FakeSocialAuthenticator implements AuthenticatorInterface
{
    /** @var ?\Throwable 抛出的异常（非 null 时抛） The throwable thrown when set. */
    public ?\Throwable $exception = null;

    /** @var ?string 固定返回的 uid（null = 取 credentials['username']） The fixed uid (null = credentials['username']). */
    public ?string $uid = null;

    public function authenticate(array $credentials): IdentityInterface
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $uid = $this->uid ?? (string) ($credentials['username'] ?? 'u1');

        return new Identity($uid, $uid);
    }
}
