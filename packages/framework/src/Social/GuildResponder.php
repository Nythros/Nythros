<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Protocol\Message;

/**
 * 帮派域响应器（ADR-015 §1.9 最小面 + R3 正式化面）：guild:join/leave 沿用最小实现；
 * guild:create/disband/kick/promote/notice/apply/approve 走 GuildStore 正式化面，返回码表驱动映射
 * guild:error；解散/踢人同步清场（分组 + session）。
 * The guild-domain responder (ADR-015 §1.9's minimal surface plus the R3 formalized surface): guild:join/leave keep
 * the minimal implementation; guild:create/disband/kick/promote/notice/apply/approve ride the formalized GuildStore
 * surface with return codes table-mapped onto guild:error; disband/kick clean up groups and sessions in sync.
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class GuildResponder
{
    /** 帮派人数上限缺省值（guild:create 未声明时使用） Default guild member cap (used when guild:create omits it). */
    private const DEFAULT_MAX_GUILD_SIZE = 100;

    public function __construct(
        private readonly SocialContext $ctx,
        private readonly GuildStoreInterface $guild,
    ) {
    }

    /**
     * 帮派语义入口：正式化面按消息类型分发，最小面（guild:join/leave）兜底。
     * The guild-semantics entry: the formalized surface dispatches by message type; the minimal surface (guild:join/leave) falls through.
     */
    public function handle(string $clientId, string $uid, Message $msg): void
    {
        switch ($msg->type) {
            case 'guild:create':
                $this->guildCreate($clientId, $uid, $msg);

                return;
            case 'guild:disband':
                $this->guildDisband($clientId, $uid, $msg);

                return;
            case 'guild:kick':
                $this->guildKick($clientId, $uid, $msg);

                return;
            case 'guild:promote':
                $this->guildPromote($clientId, $uid, $msg);

                return;
            case 'guild:notice':
                $this->guildNotice($clientId, $uid, $msg);

                return;
            case 'guild:apply':
                $this->guildApply($clientId, $uid, $msg);

                return;
            case 'guild:approve':
                $this->guildApprove($clientId, $uid, $msg);

                return;
        }

        // 最小面（ADR-015 §1.9）：guild:join / guild:leave
        // The minimal surface (ADR-015 §1.9): guild:join / guild:leave
        $guildId = $msg->payload['guildId'] ?? null;
        if (!is_string($guildId) || preg_match(SocialContext::SERVICE_ID_PATTERN, $guildId) !== 1) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload 缺少 guildId 字段'], $msg->requestId));

            return;
        }

        if ($msg->type === 'guild:join') {
            // 换帮拦截：uid 已在其他帮派 → 403 already_in_guild（MINOR-3）
            // Guild-switch guard: uid already in another guild → 403 already_in_guild (MINOR-3)
            if (!$this->guild->join($uid, $guildId)) {
                $this->ctx->send($clientId, Message::create('guild:error', ['code' => 403, 'message' => 'already_in_guild'], $msg->requestId));

                return;
            }
            $this->ctx->hub->joinGroup($clientId, 'guild:' . $guildId);
            $this->ctx->hub->updateSession($clientId, ['guildId' => $guildId]);
            $this->ctx->send($clientId, Message::create('guild:joined', ['guildId' => $guildId], $msg->requestId));

            return;
        }

        // guild:leave：非成员 403
        if (!$this->guild->leave($uid, $guildId)) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 403, 'message' => 'not_member'], $msg->requestId));

            return;
        }
        $this->ctx->hub->leaveGroup($clientId, 'guild:' . $guildId);
        $this->ctx->hub->updateSession($clientId, ['guildId' => null]);
        $this->ctx->send($clientId, Message::create('guild:left', ['guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:create：guildId/name/maxMembers 校验 → GuildStore::create → joinGroup + session → guild:ok。
     * guild:create: guildId/name/maxMembers validation → GuildStore::create → joinGroup + session → guild:ok.
     */
    private function guildCreate(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        if ($guildId === null) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }
        $name = $msg->payload['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload name 非法'], $msg->requestId));

            return;
        }
        $maxMembers = $msg->payload['maxMembers'] ?? self::DEFAULT_MAX_GUILD_SIZE;
        if (!is_int($maxMembers) || $maxMembers < 1) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload maxMembers 必须为正整数'], $msg->requestId));

            return;
        }

        $result = $this->guild->create($uid, $guildId, $name, $maxMembers);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->joinGroup($clientId, 'guild:' . $guildId);
        $this->ctx->hub->updateSession($clientId, ['guildId' => $guildId]);
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'create', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:disband（仅会长）：GuildStore::disband → notify 全帮 disbanded → 全员清场（分组 + session）→ guild:ok。
     * guild:disband (leader only): GuildStore::disband → notify the whole guild disbanded → whole-membership cleanup
     * (groups + sessions) → guild:ok.
     */
    private function guildDisband(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        if ($guildId === null) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->disband($uid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->sendToGroup('guild:' . $guildId, $this->ctx->enc(Message::create('guild:notify', [
            'type' => 'disbanded',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->ctx->leaveGroupAll($result['members'] ?? [], 'guild:' . $guildId, 'guildId');
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'disband', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:kick（会长/官员踢低阶位）：GuildStore::kick → 被踢者在线连接清场 + 通知 → guild:ok。
     * guild:kick (leader/officer kicking a lower rank): GuildStore::kick → clean up the kicked member's online
     * connections + notify → guild:ok.
     */
    private function guildKick(string $clientId, string $uid, Message $msg): void
    {
        [$guildId, $targetUid] = $this->guildTargetOf($msg);
        if ($guildId === null || $targetUid === null) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->kick($uid, $targetUid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        // 被踢者全部在线连接退帮组 + 清 session；离线时静默（无绑定连接可清）
        // Every online connection of the kicked member leaves the guild group and clears its session; silent when offline
        foreach ($this->ctx->hub->getClientIdByUid($targetUid) as $targetClientId) {
            $this->ctx->hub->leaveGroup($targetClientId, 'guild:' . $guildId);
            $this->ctx->hub->updateSession($targetClientId, ['guildId' => null]);
        }
        $this->ctx->hub->sendToUid($targetUid, $this->ctx->enc(Message::create('guild:notify', [
            'type' => 'kicked',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'kick', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:promote（仅会长，officer/member）：GuildStore::promote → 通知目标 → guild:ok。
     * guild:promote (leader only, officer/member): GuildStore::promote → notify the target → guild:ok.
     */
    private function guildPromote(string $clientId, string $uid, Message $msg): void
    {
        [$guildId, $targetUid] = $this->guildTargetOf($msg);
        $role = $msg->payload['role'] ?? null;
        if ($guildId === null || $targetUid === null
            || !is_string($role)
            || ($role !== GuildStoreInterface::ROLE_OFFICER && $role !== GuildStoreInterface::ROLE_MEMBER)
        ) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid/role 缺失或非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->promote($uid, $targetUid, $guildId, $role);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->sendToUid($targetUid, $this->ctx->enc(Message::create('guild:notify', [
            'type' => 'promoted',
            'guildId' => $guildId,
            'role' => $role,
            'fromUid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'promote', 'guildId' => $guildId, 'role' => $role], $msg->requestId));
    }

    /**
     * guild:notice（会长/官员）：GuildStore::setNotice → 帮派组广播 notice → guild:ok。
     * guild:notice (leader/officer): GuildStore::setNotice → broadcast the notice to the guild group → guild:ok.
     */
    private function guildNotice(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        $notice = $msg->payload['notice'] ?? null;
        if ($guildId === null || !is_string($notice) || $notice === '') {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/notice 缺失或非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->setNotice($uid, $guildId, $notice);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->sendToGroup('guild:' . $guildId, $this->ctx->enc(Message::create('guild:notify', [
            'type' => 'notice',
            'guildId' => $guildId,
            'notice' => $notice,
            'fromUid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'notice', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:apply：GuildStore::apply → guild:ok（审批方经 guild:approve 凭 targetUid 审批，无需列表帧）。
     * guild:apply: GuildStore::apply → guild:ok (approvers act on a targetUid via guild:approve — no listing frame needed).
     */
    private function guildApply(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        if ($guildId === null) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->apply($uid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'apply', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:approve（会长/官员）：accept=true 收编成员（入组 + session 同步 + 通知申请人）；false 拒绝并通知。
     * guild:approve (leader/officer): accept=true admits the member (group join + session sync + notifying the
     * applicant); false rejects and notifies.
     */
    private function guildApprove(string $clientId, string $uid, Message $msg): void
    {
        [$guildId, $targetUid] = $this->guildTargetOf($msg);
        $accept = $msg->payload['accept'] ?? null;
        if ($guildId === null || $targetUid === null || !is_bool($accept)) {
            $this->ctx->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid/accept 缺失或非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->approve($uid, $targetUid, $guildId, $accept);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        if ($accept) {
            // 申请人全部在线连接入帮组 + 写 session（与 guild:join 成功路径同口径）
            // Every online connection of the applicant joins the guild group and writes its session (same as the successful guild:join path)
            foreach ($this->ctx->hub->getClientIdByUid($targetUid) as $applicantClientId) {
                $this->ctx->hub->joinGroup($applicantClientId, 'guild:' . $guildId);
                $this->ctx->hub->updateSession($applicantClientId, ['guildId' => $guildId]);
            }
        }
        $this->ctx->hub->sendToUid($targetUid, $this->ctx->enc(Message::create('guild:notify', [
            'type' => $accept ? 'approved' : 'rejected',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('guild:ok', ['action' => 'approve', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * 读取 guildId 字段并做 SERVICE_ID 风格格式预校验（非法直接 400，不让 GuildStore 的
     * InvalidArgumentException 兜底成 500）。
     * Read the guildId field and pre-validate the SERVICE_ID-style format (an illegal value answers 400 directly,
     * never falling through to the GuildStore's InvalidArgumentException-as-500).
     */
    private function guildIdOf(Message $msg): ?string
    {
        $guildId = $msg->payload['guildId'] ?? null;

        return is_string($guildId) && preg_match(SocialContext::SERVICE_ID_PATTERN, $guildId) === 1 ? $guildId : null;
    }

    /**
     * 读取需要目标成员的 guild 语义（kick/promote/approve）的 guildId + targetUid 字段并做格式预校验。
     * Read the guildId + targetUid fields of target-member guild semantics (kick/promote/approve) with format pre-validation.
     *
     * @return array{?string, ?string} [guildId, targetUid]（任一非法为 null） [guildId, targetUid] (null when either is illegal).
     */
    private function guildTargetOf(Message $msg): array
    {
        return [$this->guildIdOf($msg), $this->ctx->targetUidOf($msg)];
    }

    /**
     * guild:error 下发：GuildStore 返回码 → HTTP 状态码 + message 映射（表驱动语义的统一出口）。
     * guild:error delivery: GuildStore return code → HTTP status + message mapping (the unified exit of table-driven semantics).
     */
    private function sendGuildError(string $clientId, Message $msg, int $code): void
    {
        $this->ctx->send($clientId, Message::create('guild:error', [
            'code' => $this->guildErrorHttpCode($code),
            'message' => $this->guildErrorMessage($code),
        ], $msg->requestId));
    }

    /**
     * GuildStore 返回码 → HTTP 状态码映射。
     * Maps a GuildStore return code to an HTTP status.
     */
    private function guildErrorHttpCode(int $code): int
    {
        return match ($code) {
            GuildStoreInterface::CODE_GUILD_EXISTS => 409,
            GuildStoreInterface::CODE_ALREADY_IN_GUILD => 403,
            GuildStoreInterface::CODE_GUILD_NOT_FOUND => 404,
            GuildStoreInterface::CODE_NOT_MEMBER => 403,
            GuildStoreInterface::CODE_PERMISSION_DENIED => 403,
            GuildStoreInterface::CODE_TARGET_INVALID => 400,
            GuildStoreInterface::CODE_GUILD_FULL => 409,
            GuildStoreInterface::CODE_ALREADY_APPLIED => 409,
            GuildStoreInterface::CODE_APPLICATION_NOT_FOUND => 404,
            default => 500,
        };
    }

    /**
     * GuildStore 返回码 → message 映射。
     * Maps a GuildStore return code to a message.
     */
    private function guildErrorMessage(int $code): string
    {
        return match ($code) {
            GuildStoreInterface::CODE_GUILD_EXISTS => 'guild_exists',
            GuildStoreInterface::CODE_ALREADY_IN_GUILD => 'already_in_guild',
            GuildStoreInterface::CODE_GUILD_NOT_FOUND => 'guild_not_found',
            GuildStoreInterface::CODE_NOT_MEMBER => 'not_member',
            GuildStoreInterface::CODE_PERMISSION_DENIED => 'permission_denied',
            GuildStoreInterface::CODE_TARGET_INVALID => 'target_invalid',
            GuildStoreInterface::CODE_GUILD_FULL => 'guild_full',
            GuildStoreInterface::CODE_ALREADY_APPLIED => 'already_applied',
            GuildStoreInterface::CODE_APPLICATION_NOT_FOUND => 'application_not_found',
            default => 'internal error',
        };
    }
}
