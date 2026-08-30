<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 好友关系存储契约（无 TTL，持久；好友关系双向——A→B 与 B→A 一致）。
 * Friend-relationship store contract (no TTL, persistent; friendships are bidirectional — A→B and B→A stay consistent).
 *
 * 返回码沿用 TeamStoreInterface 的整型常量先例，由 SocialService 映射 friend:error。
 * Return codes follow the TeamStoreInterface integer-constant precedent; SocialService maps them onto friend:error.
 */
interface FriendStoreInterface
{
    /** 成功 Success. */
    public const CODE_OK = 0;

    /** 不能与自己建立好友关系 Cannot befriend oneself. */
    public const CODE_SELF = 1;

    /** 已经是好友 Already friends. */
    public const CODE_ALREADY_FRIENDS = 2;

    /** 重复申请（待处理申请已存在） Duplicate application (a pending request already exists). */
    public const CODE_REQUEST_EXISTS = 3;

    /** 无待处理申请 No pending request. */
    public const CODE_REQUEST_NOT_FOUND = 4;

    /** 非好友关系 Not friends. */
    public const CODE_NOT_FRIENDS = 5;

    /**
     * 申请好友：fromUid → toUid 写入待处理申请；已是好友/重复申请/自邀拒绝。
     * Apply for friendship: writes a pending request fromUid → toUid; already-friends / duplicate / self are rejected.
     *
     * @return array{code: int} 返回码（FriendStoreInterface::CODE_*） The return code (FriendStoreInterface::CODE_*).
     */
    public function apply(string $fromUid, string $toUid): array;

    /**
     * 同意申请：applicantUid 向 acceptorUid 的待处理申请 → 双向写好友关系并清除申请。
     * Accept a request: the applicantUid → acceptorUid pending request becomes a bidirectional friendship and the request is cleared.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function accept(string $applicantUid, string $acceptorUid): array;

    /**
     * 拒绝申请：移除 applicantUid → rejectorUid 的待处理申请。
     * Reject a request: removes the applicantUid → rejectorUid pending request.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function reject(string $applicantUid, string $rejectorUid): array;

    /**
     * 删除好友：双向一致移除 uid ↔ targetUid 的好友关系。
     * Remove a friend: removes the uid ↔ targetUid friendship on both sides consistently.
     *
     * @return array{code: int} 返回码 The return code.
     */
    public function remove(string $uid, string $targetUid): array;

    /**
     * 好友列表。
     * The friend list.
     *
     * @return list<string> 好友 uid 列表 Friend uid list.
     */
    public function list(string $uid): array;
}
