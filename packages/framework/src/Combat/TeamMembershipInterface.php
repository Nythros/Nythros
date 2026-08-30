<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 队伍归属查询契约（掉落归属绑定的同队判定依赖，R3 经济批模块 2）。
 * 战斗层只依赖本窄接口，不耦合完整组队状态机；装配层以 RedisTeamStore 等实现适配。
 * Team-membership query contract (the same-team predicate of drop-ownership binding; economy-batch module 2).
 * The combat tier depends on this narrow interface only, never on the full team state machine; the assembly layer
 * adapts implementations such as RedisTeamStore.
 */
interface TeamMembershipInterface
{
    /**
     * uid → 所在队伍 id；未组队返回 null。
     * Maps a uid to its team id; null when not in a team.
     */
    public function teamOf(string $uid): ?string;
}
