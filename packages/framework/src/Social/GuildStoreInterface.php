<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 帮派存储契约（无 TTL，持久）：最小 join/leave 面（ADR-015 §1.9）+ R3 正式化面
 * （建会/解散/踢人/职位 leader|officer|member/公告/审批/人数上限）。
 * Guild store contract (no TTL, persistent): the minimal join/leave surface (ADR-015 §1.9) plus the R3
 * formalized surface (create/disband/kick/roles leader|officer|member/notice/approval/size cap).
 *
 * 权限矩阵（表驱动，实现类持有同表）：解散=会长；踢人=会长/官员（且只能踢低阶位）；任命=会长；
 * 公告=会长/官员；审批=会长/官员；建会=任意无会 uid。
 * Permission matrix (table-driven, implementations hold the same table): disband = leader; kick = leader/officer
 * (and only lower-ranked targets); promote = leader; notice = leader/officer; approve = leader/officer;
 * create = any guildless uid.
 *
 * 返回码沿用 TeamStoreInterface 的整型常量先例，由 SocialService 映射 guild:error。
 * Return codes follow the TeamStoreInterface integer-constant precedent; SocialService maps them onto guild:error.
 */
interface GuildStoreInterface
{
    /** 职位：会长 Role: leader. */
    public const ROLE_LEADER = 'leader';

    /** 职位：官员 Role: officer. */
    public const ROLE_OFFICER = 'officer';

    /** 职位：成员 Role: member. */
    public const ROLE_MEMBER = 'member';

    /** 成功 Success. */
    public const CODE_OK = 0;

    /** guildId 已被占用（建会） guildId already taken (create). */
    public const CODE_GUILD_EXISTS = 1;

    /** uid 已在其他帮派（建会/申请/加入） uid already in another guild (create/apply/join). */
    public const CODE_ALREADY_IN_GUILD = 2;

    /** 帮派不存在 Guild not found. */
    public const CODE_GUILD_NOT_FOUND = 3;

    /** 操作者非本帮成员 Operator is not a member of this guild. */
    public const CODE_NOT_MEMBER = 4;

    /** 权限矩阵拒绝 Permission-matrix denial. */
    public const CODE_PERMISSION_DENIED = 5;

    /** 目标非法（非成员/阶位不低于操作者/自指/职位值非法） Illegal target (not a member / rank not below the operator / self / illegal role value). */
    public const CODE_TARGET_INVALID = 6;

    /** 帮派满员 Guild full. */
    public const CODE_GUILD_FULL = 7;

    /** 重复申请 Duplicate application. */
    public const CODE_ALREADY_APPLIED = 8;

    /** 无待审批申请 No pending application. */
    public const CODE_APPLICATION_NOT_FOUND = 9;

    /**
     * 建会：creator 成为会长；guildId 已存在或 creator 已有帮派时拒绝。
     * Create a guild: the creator becomes leader; rejected when the guildId exists or the creator already has a guild.
     *
     * @param ?string $name 帮派名（可空） Guild name (nullable).
     * @param int $maxMembers 人数上限（正整数） Member cap (positive integer).
     * @return array{code: int} 返回码 The return code.
     */
    public function create(string $uid, string $guildId, ?string $name, int $maxMembers): array;

    /**
     * 解散帮派（仅会长）：删除帮派数据与全部成员索引，返回原成员列表供分组清场。
     * Disband a guild (leader only): deletes the guild data and every member index, returning the former members for group cleanup.
     *
     * @return array{code: int, members?: list<string>} 返回码 + 解散时在册成员 The return code plus the members registered at disband time.
     */
    public function disband(string $operatorUid, string $guildId): array;

    /**
     * 踢人（会长/官员，且目标阶位必须低于操作者）。
     * Kick a member (leader/officer, and the target's rank must be below the operator's).
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function kick(string $operatorUid, string $targetUid, string $guildId): array;

    /**
     * 任命（仅会长）：把目标改为 officer 或 member（不可指向自己或会长）。
     * Promote (leader only): changes the target to officer or member (never oneself or the leader).
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function promote(string $operatorUid, string $targetUid, string $guildId, string $role): array;

    /**
     * 公告（会长/官员）：写帮派公告字段。
     * Set the notice (leader/officer): writes the guild notice field.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function setNotice(string $operatorUid, string $guildId, string $notice): array;

    /**
     * 申请入会：写入待审批列表；已有帮派/已是成员/重复申请/满员拒绝。
     * Apply to join: appends to the pending list; rejected when already in a guild / already a member / duplicate / full.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function apply(string $uid, string $guildId): array;

    /**
     * 审批（会长/官员）：accept=true 把申请人收为成员（受人数上限约束）；false 移除申请。
     * Approve (leader/officer): accept=true admits the applicant as a member (subject to the size cap); false removes the application.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function approve(string $approverUid, string $applicantUid, string $guildId, bool $accept): array;

    /**
     * uid 在指定帮派的职位；非成员 null。
     * The uid's role in the given guild; null when not a member.
     */
    public function roleOf(string $uid, string $guildId): ?string;

    /**
     * 成员与职位列表。
     * The member-and-role list.
     *
     * @return list<array{uid: string, role: string}> 成员列表（按 uid 排序） Member list (sorted by uid).
     */
    public function members(string $guildId): array;

    /**
     * 加入帮派（ADR-015 §1.9 最小面，保留）：members 追加（幂等）+ 写 uid-guild 索引；
     * uid 已在其他帮派时拒绝；新成员职位 member；受人数上限约束。
     * Join a guild (the ADR-015 §1.9 minimal surface, kept): append to members (idempotent) + write the uid-guild
     * index; rejected when already in another guild; new members get the member role; subject to the size cap.
     *
     * @return bool true = 已加入或已在本帮；false = 已在其他帮派或满员 true when joined or already in this guild; false when in another guild or full.
     */
    public function join(string $uid, string $guildId): bool;

    /**
     * 退出帮派。
     * Leave a guild.
     *
     * @return bool true = 已退；false = 非成员 true when left; false when not a member.
     */
    public function leave(string $uid, string $guildId): bool;

    /**
     * uid → 所在帮派 guildId。
     * Map a uid to its guildId.
     *
     * @return ?string 所在帮派 guildId；无帮 null The uid's guildId; null when in no guild.
     */
    public function findByUid(string $uid): ?string;

    /**
     * 读取帮派详情（auth 恢复下发用）。
     * Read a guild's details (for the auth-recovery delivery).
     *
     * @return ?array{name: ?string, notice: string, members: list<string>} 帮派数据；不存在 null Guild data; null when absent.
     */
    public function get(string $guildId): ?array;
}
