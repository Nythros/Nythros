<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 组队状态机存储契约（ADR-015 §1.6）：边界判定 + 读改写原子化，返回码枚举。
 * Team state-machine store contract (ADR-015 §1.6): boundary matrix + atomic read-modify-write, return-code enum.
 *
 * Redis Lua 实现（RedisTeamStore）必须遵守本签名与返回码语义。
 * 返回码（code）与 SocialService::handleTeam 的 team:error{code, message} 映射一一对应（ADR-015 §1.6 PHP 侧动作表）。
 * The Redis Lua implementation (RedisTeamStore) must honor this signature and return-code semantics. The code values
 * map one-to-one onto SocialService::handleTeam's team:error{code, message} mapping (ADR-015 §1.6 PHP-side action table).
 */
interface TeamStoreInterface
{
    // 返回码枚举（ADR-015 §1.6）
    public const CODE_OK = 0;
    public const CODE_NOT_LEADER = 1;
    public const CODE_TARGET_IN_TEAM = 2;
    public const CODE_TEAM_FULL = 3;
    public const CODE_INVITE_NOT_FOUND = 4;
    public const CODE_INVITE_NOT_FOR_YOU = 5;
    public const CODE_ALREADY_IN_TEAM = 6;
    public const CODE_TEAM_NOT_FOUND = 7;
    public const CODE_NOT_MEMBER = 8;
    public const CODE_TARGET_IS_SENDER = 9;

    /**
     * uid → 所在队伍 teamId。
     * Map a uid to its teamId.
     *
     * @return ?string 所在队伍 teamId；不在任何队伍 null The uid's teamId; null when not in any team.
     */
    public function findByUid(string $uid): ?string;

    /**
     * 读取队伍详情（auth 恢复下发 auth_ok.team 用）。
     * Read a team's details (for the auth_ok.team delivery on recovery).
     *
     * @return ?array{leaderUid: string, members: list<string>} 队伍数据；不存在 null Team data; null when absent.
     */
    public function get(string $teamId): ?array;

    /**
     * 邀请：无队 sender 自动建队（Lua 内 INCR seq + 判队原子）；自邀 9；目标已在队 2；
     * 非队长 1；满员 3；幂等刷新 30s；否则 append invites。
     * Invite: a teamless sender auto-creates the team (INCR seq + membership check atomically inside Lua);
     * self 9; target in team 2; not leader 1; full 3; idempotent 30s refresh; otherwise append invites.
     *
     * @param string $senderUid 邀请发起者（队长/建队者） The inviter (leader / team creator).
     * @param string $targetUid 被邀请者 The invitee.
     * @param int $maxSize 队伍人数上限 Team size cap.
     * @param int $teamTtl 队伍 TTL 秒数（每次写操作续期） Team TTL in seconds (renewed on every write).
     * @param float $now 当前时间（邀请条目 expiresAt = now + 30） Current time (invite expiresAt = now + 30).
     * @return array{code: int, teamId?: string, leaderUid?: string} 结果（ok 带 teamId/leaderUid） Result (ok carries teamId/leaderUid).
     */
    public function invite(string $senderUid, string $targetUid, int $maxSize, int $teamTtl, float $now): array;

    /**
     * 接受邀请：已在队 6（先于队伍不存在）；队伍不存在 7；无本人有效邀请 4/5；满员 3；否则入队。
     * Accept an invite: already in team 6 (before team-not-found); team gone 7; no valid self-invite 4/5; full 3; otherwise join.
     *
     * @return array{code: int, members?: list<string>} 结果（ok 带入队后 members） Result (ok carries the post-join members).
     */
    public function accept(string $uid, string $teamId, int $maxSize, int $teamTtl, float $now): array;

    /**
     * 拒绝邀请：队伍不存在/无本人有效邀请 4；邀请非本人 5；否则删条目。
     * Reject an invite: team gone / no valid self-invite 4; invite for someone else 5; otherwise delete the entry.
     *
     * @return array{code: int, leaderUid?: string} 结果（ok 带 leaderUid） Result (ok carries the leaderUid).
     */
    public function reject(string $uid, string $teamId, int $teamTtl, float $now): array;

    /**
     * 退队：非成员（含队伍不存在）8；队长离开 = 解散；成员离开 = 移除。
     * Leave: not a member (team gone included) 8; leader leaving = disband; member leaving = removal.
     *
     * @return array{code: int, action?: string, members?: list<string>} 结果（ok 带 action=left|disbanded 与 members） Result (ok carries action=left|disbanded and members).
     */
    public function leave(string $uid, string $teamId, int $teamTtl): array;

    /**
     * 解散：队伍不存在 7；非队长 1；否则解散。
     * Disband: team gone 7; not the leader 1; otherwise disband.
     *
     * @return array{code: int, members?: list<string>} 结果（ok 带解散前 members） Result (ok carries the pre-disband members).
     */
    public function disband(string $uid, string $teamId, int $teamTtl): array;
}
