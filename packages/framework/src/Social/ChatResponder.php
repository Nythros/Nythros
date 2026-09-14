<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Protocol\Message;

/**
 * 聊天域响应器（ADR-015 §1.5 五语义）：world/channel/team/guild/private，错误一律 chat:error 回发起方。
 * channel 只允许本频道发言（payload 与 session loc 不符 → 404）；team/guild 会话缺失时回退对应
 * Store::findByUid；private 定向 sendToUid（离线 404）。组播帧经共享 SocialContext 编码投递。
 * The chat-domain responder (ADR-015 §1.5's five semantics): world/channel/team/guild/private; failures answer the
 * sender with chat:error. Channel chat stays within one's own channel (a payload mismatching the session loc → 404);
 * team/guild fall back to the matching Store::findByUid when the session lacks the id; private goes directed via
 * sendToUid (404 when offline). Group frames are encoded and delivered through the shared SocialContext.
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class ChatResponder
{
    public function __construct(
        private readonly SocialContext $ctx,
        private readonly TeamStoreInterface $team,
        private readonly GuildStoreInterface $guild,
    ) {
    }

    /**
     * 聊天五语义入口：scope 分发。
     * The five-semantics chat entry: dispatch by scope.
     */
    public function handle(string $clientId, string $uid, Message $msg): void
    {
        $scope = $msg->payload['scope'] ?? null;
        if (!is_string($scope)) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 scope 字段'], $msg->requestId));

            return;
        }

        $content = $msg->payload['content'] ?? null;
        if (!is_string($content)) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 content 字段'], $msg->requestId));

            return;
        }

        switch ($scope) {
            case 'world':
                $this->ctx->hub->sendToAll(
                    $this->ctx->enc(Message::create('chat:message', ['scope' => 'world', 'content' => $content, 'fromUid' => $uid])),
                    $clientId,
                );

                return;
            case 'channel':
                $this->chatChannel($clientId, $uid, $content, $msg);

                return;
            case 'team':
                $this->chatTeam($clientId, $uid, $content, $msg);

                return;
            case 'guild':
                $this->chatGuild($clientId, $uid, $content, $msg);

                return;
            case 'private':
                $this->chatPrivate($clientId, $uid, $content, $msg);

                return;
            default:
                $this->ctx->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => sprintf('unknown scope: %s', $scope)], $msg->requestId));
        }
    }

    /**
     * channel 发言：只允许本频道（payload 带 mapId/channelId 且与 session loc 不符 → 404），sendToGroup 本频道组。
     * Channel chat: only within one's own channel (a payload mapId/channelId mismatching the session loc → 404); sendToGroup the channel group.
     */
    private function chatChannel(string $clientId, string $uid, string $content, Message $msg): void
    {
        $loc = $this->ctx->session($clientId)['loc'] ?? null;
        if (!is_array($loc) || !is_string($loc['mapId'] ?? null) || !is_string($loc['channelId'] ?? null)) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'channel unknown'], $msg->requestId));

            return;
        }

        $mapId = $msg->payload['mapId'] ?? null;
        $channelId = $msg->payload['channelId'] ?? null;
        if (($mapId !== null && $mapId !== $loc['mapId']) || ($channelId !== null && $channelId !== $loc['channelId'])) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'channel unknown'], $msg->requestId));

            return;
        }

        $this->ctx->hub->sendToGroup(
            'map:' . $loc['mapId'] . ':' . $loc['channelId'],
            $this->ctx->enc(Message::create('chat:message', ['scope' => 'channel', 'content' => $content, 'fromUid' => $uid])),
            $clientId,
        );
    }

    /**
     * team 发言：session teamId 缺失回退 TeamStore::findByUid；无队 404；sendToGroup 队伍组。
     * Team chat: fall back to TeamStore::findByUid when the session teamId is missing; no team → 404; sendToGroup the team group.
     */
    private function chatTeam(string $clientId, string $uid, string $content, Message $msg): void
    {
        $teamId = $this->ctx->session($clientId)['teamId'] ?? null;
        if (!is_string($teamId) || $teamId === '') {
            $teamId = $this->team->findByUid($uid);
        }
        if ($teamId === null) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'not in team'], $msg->requestId));

            return;
        }

        $this->ctx->hub->sendToGroup(
            'team:' . $teamId,
            $this->ctx->enc(Message::create('chat:message', ['scope' => 'team', 'content' => $content, 'fromUid' => $uid])),
            $clientId,
        );
    }

    /**
     * guild 发言：session guildId 缺失回退 GuildStore::findByUid；无帮 404；sendToGroup 帮派组。
     * Guild chat: fall back to GuildStore::findByUid when the session guildId is missing; no guild → 404; sendToGroup the guild group.
     */
    private function chatGuild(string $clientId, string $uid, string $content, Message $msg): void
    {
        $guildId = $this->ctx->session($clientId)['guildId'] ?? null;
        if (!is_string($guildId) || $guildId === '') {
            $guildId = $this->guild->findByUid($uid);
        }
        if ($guildId === null) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'not in guild'], $msg->requestId));

            return;
        }

        $this->ctx->hub->sendToGroup(
            'guild:' . $guildId,
            $this->ctx->enc(Message::create('chat:message', ['scope' => 'guild', 'content' => $content, 'fromUid' => $uid])),
            $clientId,
        );
    }

    /**
     * private 发言：targetUid 缺失 400；目标离线 404；sendToUid 定向。
     * Private chat: missing targetUid 400; target offline 404; directed sendToUid.
     */
    private function chatPrivate(string $clientId, string $uid, string $content, Message $msg): void
    {
        $targetUid = $msg->payload['targetUid'] ?? null;
        if (!is_string($targetUid) || $targetUid === '') {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 targetUid 字段'], $msg->requestId));

            return;
        }
        if (!$this->ctx->hub->isUidOnline($targetUid)) {
            $this->ctx->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'target offline'], $msg->requestId));

            return;
        }

        $this->ctx->hub->sendToUid(
            $targetUid,
            $this->ctx->enc(Message::create('chat:message', ['scope' => 'private', 'content' => $content, 'fromUid' => $uid])),
        );
    }
}
