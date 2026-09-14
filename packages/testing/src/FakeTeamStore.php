<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Social\TeamStoreInterface;

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
