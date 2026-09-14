<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Social\GuildStoreInterface;

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
     *
     * @param array{name: ?string, notice: string, maxMembers: int, leaderUid: ?string, members: list<string>, roles: array<string, string>, applicants: list<string>} $guild 帮派数据表行 A guild data-table row.
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
