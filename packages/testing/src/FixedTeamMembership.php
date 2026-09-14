<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Combat\TeamMembershipInterface;

/**
 * FixedTeamMembership - 按配置表返回 uid → 队伍 id 的归属查询 fake（掉落同队拾取判定测试用）。
 * FixedTeamMembership - a membership-lookup fake backed by a uid → team-id table (for drop same-team pickup tests).
 */
final class FixedTeamMembership implements TeamMembershipInterface
{
    /** @param array<string, string> $teams uid => teamId 映射 The uid => teamId mapping. */
    public function __construct(private readonly array $teams = [])
    {
    }

    public function teamOf(string $uid): ?string
    {
        return $this->teams[$uid] ?? null;
    }
}
