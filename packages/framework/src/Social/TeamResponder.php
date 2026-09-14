<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Protocol\Message;

/**
 * 组队域响应器（ADR-015 §1.6 状态机）：invite/accept/reject/leave/disband，委托 TeamStore，
 * 返回码表驱动映射 team:error；写操作附带 microtime 现场时间戳（Store 侧 TTL 续期）。
 * The team-domain responder (ADR-015 §1.6's state machine): invite/accept/reject/leave/disband, delegated to the
 * TeamStore with return codes table-mapped onto team:error; writes carry the microtime wall clock (Store-side TTL renewal).
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class TeamResponder
{
    /** 队伍 TTL（秒） Team TTL in seconds. */
    private const TEAM_TTL = 600;

    /** 队伍人数上限 Team size cap. */
    private const MAX_TEAM_SIZE = 5;

    public function __construct(
        private readonly SocialContext $ctx,
        private readonly TeamStoreInterface $team,
    ) {
    }

    /**
     * 组队状态机入口：按消息类型分发。
     * The team state-machine entry: dispatch by message type.
     */
    public function handle(string $clientId, string $uid, Message $msg): void
    {
        switch ($msg->type) {
            case 'team:invite':
                $this->teamInvite($clientId, $uid, $msg);

                return;
            case 'team:accept':
                $this->teamAccept($clientId, $uid, $msg);

                return;
            case 'team:reject':
                $this->teamReject($clientId, $uid, $msg);

                return;
            case 'team:leave':
                $this->teamLeave($clientId, $uid, $msg);

                return;
            case 'team:disband':
                $this->teamDisband($clientId, $uid, $msg);
        }
    }

    /**
     * team:invite（ADR-015 §1.6）：targetUid 校验 → 目标离线 pre-check → TeamStore::invite → 通知 + 回执。
     * team:invite (ADR-015 §1.6): targetUid validation → target-offline pre-check → TeamStore::invite → notify + receipt.
     */
    private function teamInvite(string $clientId, string $uid, Message $msg): void
    {
        $targetUid = $msg->payload['targetUid'] ?? null;
        if (!is_string($targetUid) || $targetUid === '') {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload 缺少 targetUid 字段'], $msg->requestId));

            return;
        }
        // 目标离线 pre-check（Gateway 在线态无法在 Redis 内判定，ADR-015 §1.6；竞态窗口由 sendToUid 自动丢弃兜底）
        if (!$this->ctx->hub->isUidOnline($targetUid)) {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 404, 'message' => 'target_offline'], $msg->requestId));

            return;
        }

        $result = $this->team->invite($uid, $targetUid, self::MAX_TEAM_SIZE, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $teamId = $result['teamId'] ?? null;
        if (!is_string($teamId) || $teamId === '') {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 500, 'message' => 'internal error'], $msg->requestId));

            return;
        }
        $this->ctx->hub->joinGroup($clientId, 'team:' . $teamId);
        $this->ctx->hub->updateSession($clientId, ['teamId' => $teamId]);
        $this->ctx->hub->sendToUid($targetUid, $this->ctx->enc(Message::create('team:notify', [
            'type' => 'invited',
            'teamId' => $teamId,
            'uid' => $targetUid,
            'fromUid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'invite'], $msg->requestId));
    }

    /**
     * team:accept（ADR-015 §1.6）：TeamStore::accept → joinGroup + updateSession → notify 全队 joined → team:ok。
     * team:accept (ADR-015 §1.6): TeamStore::accept → joinGroup + updateSession → notify the whole team joined → team:ok.
     */
    private function teamAccept(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->accept($uid, $teamId, self::MAX_TEAM_SIZE, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->joinGroup($clientId, 'team:' . $teamId);
        $this->ctx->hub->updateSession($clientId, ['teamId' => $teamId]);
        $this->ctx->hub->sendToGroup('team:' . $teamId, $this->ctx->enc(Message::create('team:notify', [
            'type' => 'joined',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'accept'], $msg->requestId));
    }

    /**
     * team:reject（ADR-015 §1.6）：TeamStore::reject → notify 队长 rejected → team:ok。
     * team:reject (ADR-015 §1.6): TeamStore::reject → notify the leader rejected → team:ok.
     */
    private function teamReject(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->reject($uid, $teamId, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $leaderUid = $result['leaderUid'] ?? null;
        if (!is_string($leaderUid) || $leaderUid === '') {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 500, 'message' => 'internal error'], $msg->requestId));

            return;
        }

        $this->ctx->hub->sendToUid($leaderUid, $this->ctx->enc(Message::create('team:notify', [
            'type' => 'rejected',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->ctx->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'reject'], $msg->requestId));
    }

    /**
     * team:leave（ADR-015 §1.6）：成员离开 notify left → leaveGroup；队长离开 notify disbanded → 全队清理分组。
     * team:leave (ADR-015 §1.6): member leave notifies left → leaveGroup; leader leave notifies disbanded → whole-team group cleanup.
     */
    private function teamLeave(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->leave($uid, $teamId, self::TEAM_TTL);
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $disbanded = ($result['action'] ?? null) === 'disbanded';
        $this->ctx->hub->sendToGroup('team:' . $teamId, $this->ctx->enc(Message::create('team:notify', [
            'type' => $disbanded ? 'disbanded' : 'left',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));

        if ($disbanded) {
            $this->ctx->leaveGroupAll($result['members'] ?? [], 'team:' . $teamId, 'teamId');
        } else {
            $this->ctx->hub->leaveGroup($clientId, 'team:' . $teamId);
            $this->ctx->hub->updateSession($clientId, ['teamId' => null]);
        }

        $this->ctx->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'leave'], $msg->requestId));
    }

    /**
     * team:disband（ADR-015 §1.6）：TeamStore::disband → notify disbanded → 全队清理分组 → team:ok。
     * team:disband (ADR-015 §1.6): TeamStore::disband → notify disbanded → whole-team group cleanup → team:ok.
     */
    private function teamDisband(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->ctx->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->disband($uid, $teamId, self::TEAM_TTL);
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $this->ctx->hub->sendToGroup('team:' . $teamId, $this->ctx->enc(Message::create('team:notify', [
            'type' => 'disbanded',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->ctx->leaveGroupAll($result['members'] ?? [], 'team:' . $teamId, 'teamId');
        $this->ctx->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'disband'], $msg->requestId));
    }

    /**
     * 读取 team:accept/reject/leave/disband 的 teamId 字段并做 team-{seq} 格式预校验（MINOR-2：非法格式直接 400，
     * 不让 TeamStore 的 InvalidArgumentException 兜底成 500）。
     * Read the teamId field for team:accept/reject/leave/disband and pre-validate the team-{seq} format (MINOR-2: an
     * illegal format answers 400 directly, never falling through to the TeamStore's InvalidArgumentException-as-500).
     */
    private function teamIdOf(Message $msg): ?string
    {
        $teamId = $msg->payload['teamId'] ?? null;

        return is_string($teamId) && preg_match(SocialContext::TEAM_ID_PATTERN, $teamId) === 1 ? $teamId : null;
    }

    /**
     * team:error 下发：TeamStore 返回码 → HTTP 状态码 + message 映射（ADR-015 §1.6 PHP 侧动作表）。
     * team:error delivery: TeamStore return code → HTTP status + message mapping (ADR-015 §1.6 PHP-side action table).
     */
    private function sendTeamError(string $clientId, Message $msg, int $code): void
    {
        $this->ctx->send($clientId, Message::create('team:error', [
            'code' => $this->teamErrorHttpCode($code),
            'message' => $this->teamErrorMessage($code),
        ], $msg->requestId));
    }

    /**
     * TeamStore 返回码 → HTTP 状态码映射。
     * Maps a TeamStore return code to an HTTP status.
     */
    private function teamErrorHttpCode(int $code): int
    {
        return match ($code) {
            TeamStoreInterface::CODE_NOT_LEADER => 403,
            TeamStoreInterface::CODE_TARGET_IN_TEAM => 409,
            TeamStoreInterface::CODE_TEAM_FULL => 409,
            TeamStoreInterface::CODE_INVITE_NOT_FOUND => 404,
            TeamStoreInterface::CODE_INVITE_NOT_FOR_YOU => 403,
            TeamStoreInterface::CODE_ALREADY_IN_TEAM => 409,
            TeamStoreInterface::CODE_TEAM_NOT_FOUND => 404,
            TeamStoreInterface::CODE_NOT_MEMBER => 403,
            TeamStoreInterface::CODE_TARGET_IS_SENDER => 400,
            default => 500,
        };
    }

    /**
     * TeamStore 返回码 → message 映射。
     * Maps a TeamStore return code to a message.
     */
    private function teamErrorMessage(int $code): string
    {
        return match ($code) {
            TeamStoreInterface::CODE_NOT_LEADER => 'not_leader',
            TeamStoreInterface::CODE_TARGET_IN_TEAM => 'target_in_team',
            TeamStoreInterface::CODE_TEAM_FULL => 'team_full',
            TeamStoreInterface::CODE_INVITE_NOT_FOUND => 'invite_not_found',
            TeamStoreInterface::CODE_INVITE_NOT_FOR_YOU => 'invite not for you',
            TeamStoreInterface::CODE_ALREADY_IN_TEAM => 'already_in_team',
            TeamStoreInterface::CODE_TEAM_NOT_FOUND => 'team_not_found',
            TeamStoreInterface::CODE_NOT_MEMBER => 'not_member',
            TeamStoreInterface::CODE_TARGET_IS_SENDER => 'target_is_sender',
            default => 'internal error',
        };
    }
}
