<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Protocol\Message;

/**
 * 好友域响应器（R3 社交批五语义）：friend:apply/accept/reject/remove/list，委托 FriendStore，
 * 返回码映射 friend:error。在线通知复用 hub sendToUid（离线自动丢弃 = 静默）；
 * 未装配 FriendStore 时一律 500。
 * The friend-domain responder (the R3 social batch's five semantics): friend:apply/accept/reject/remove/list,
 * delegated to the FriendStore with return codes mapped onto friend:error. Online notifications reuse hub sendToUid
 * (dropped silently when offline); without a wired FriendStore everything answers 500.
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class FriendResponder
{
    public function __construct(
        private readonly SocialContext $ctx,
        private readonly ?FriendStoreInterface $friend,
    ) {
    }

    /**
     * 好友五语义入口：store 未装配直接 500，动作型语义经 targetUid 预校验后分发。
     * The five-semantics friend entry: a missing store answers 500 directly; action semantics dispatch after targetUid pre-validation.
     */
    public function handle(string $clientId, string $uid, Message $msg): void
    {
        if ($this->friend === null) {
            $this->ctx->send($clientId, Message::create('friend:error', ['code' => 500, 'message' => 'friend store not wired'], $msg->requestId));

            return;
        }

        switch ($msg->type) {
            case 'friend:list':
                $this->ctx->send($clientId, Message::create('friend:ok', ['action' => 'list', 'uids' => $this->friend->list($uid)], $msg->requestId));

                return;
            case 'friend:apply':
            case 'friend:accept':
            case 'friend:reject':
            case 'friend:remove':
                break;
            default:
                return;
        }

        $targetUid = $this->ctx->targetUidOf($msg);
        if ($targetUid === null) {
            $this->ctx->send($clientId, Message::create('friend:error', ['code' => 400, 'message' => 'payload targetUid 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = match ($msg->type) {
            'friend:apply' => $this->friend->apply($uid, $targetUid),
            'friend:accept' => $this->friend->accept($targetUid, $uid),
            'friend:reject' => $this->friend->reject($targetUid, $uid),
            default => $this->friend->remove($uid, $targetUid),
        };
        if ($result['code'] !== FriendStoreInterface::CODE_OK) {
            $this->ctx->send($clientId, Message::create('friend:error', [
                'code' => $this->friendErrorHttpCode($result['code']),
                'message' => $this->friendErrorMessage($result['code']),
            ], $msg->requestId));

            return;
        }

        // 在线通知（离线静默：hub sendToUid 对无绑定连接自动丢弃）
        // Online notification (silent when offline: hub sendToUid drops unbound uids automatically)
        $notifyType = match ($msg->type) {
            'friend:apply' => 'applied',
            'friend:accept' => 'accepted',
            'friend:reject' => 'rejected',
            default => 'removed',
        };
        $this->ctx->hub->sendToUid($targetUid, $this->ctx->enc(Message::create('friend:notify', [
            'type' => $notifyType,
            'fromUid' => $uid,
        ])));

        $action = substr($msg->type, strlen('friend:'));
        $this->ctx->send($clientId, Message::create('friend:ok', ['action' => $action], $msg->requestId));
    }

    /**
     * FriendStore 返回码 → HTTP 状态码映射。
     * Maps a FriendStore return code to an HTTP status.
     */
    private function friendErrorHttpCode(int $code): int
    {
        return match ($code) {
            FriendStoreInterface::CODE_SELF => 400,
            FriendStoreInterface::CODE_ALREADY_FRIENDS => 409,
            FriendStoreInterface::CODE_REQUEST_EXISTS => 409,
            FriendStoreInterface::CODE_REQUEST_NOT_FOUND => 404,
            FriendStoreInterface::CODE_NOT_FRIENDS => 404,
            default => 500,
        };
    }

    /**
     * FriendStore 返回码 → message 映射。
     * Maps a FriendStore return code to a message.
     */
    private function friendErrorMessage(int $code): string
    {
        return match ($code) {
            FriendStoreInterface::CODE_SELF => 'self_not_allowed',
            FriendStoreInterface::CODE_ALREADY_FRIENDS => 'already_friends',
            FriendStoreInterface::CODE_REQUEST_EXISTS => 'request_exists',
            FriendStoreInterface::CODE_REQUEST_NOT_FOUND => 'request_not_found',
            FriendStoreInterface::CODE_NOT_FRIENDS => 'not_friends',
            default => 'internal error',
        };
    }
}
