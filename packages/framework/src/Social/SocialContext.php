<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Protocol\Message;
use Nythros\Protocol\SerializerInterface;

/**
 * Social 域共享协作底座：连接投递与会话视图 + 序列化 + 跨域共用的载荷读取与全员清场工具。
 * 各域响应器（Chat/Team/Guild/Friend）与 SocialService 经由同一实例访问 hub/serializer，
 * 保证下行帧编码、会话读取、错误回发的口径唯一；id 格式白名单也集中于此（uid 进入键构造、
 * SERVICE_ID 风格 id、team-{seq}，ADR-015 §2），门面与响应器共用同一套预校验口径。
 * The Social-domain shared collaboration base: the connection delivery/session view plus serialization, plus the
 * cross-domain payload readers and whole-membership cleanup helpers. Domain responders (Chat/Team/Guild/Friend) and
 * the SocialService reach the hub/serializer through one shared instance, keeping downstream frame encoding, session
 * reads and error replies on a single contract; the id-format whitelists also live here (uid enters key construction,
 * SERVICE_ID-style ids, team-{seq}, ADR-015 §2) so the facade and every responder pre-validate with the same rules.
 *
 * @internal SocialService 的内部协作件，随门面公开面冻结；不属于公开 API。
 *           An internal collaborator of SocialService, frozen behind its facade; not part of the public API.
 */
final class SocialContext
{
    /** uid 格式白名单（uid 进入 location/offline/uid-guild 键构造，ADR-015 §2） uid format whitelist (uid enters key construction). */
    public const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** mapId/channelId/guildId 格式白名单（SERVICE_ID 风格，ADR-015 §2） mapId/channelId/guildId format whitelist (SERVICE_ID style). */
    public const SERVICE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._#:-]{0,63}$/';

    /** teamId 格式（team-{seq}，序列号十进制） teamId format (team-{seq}, decimal sequence). */
    public const TEAM_ID_PATTERN = '/^team-\d+$/';

    /**
     * @param ConnectionHubInterface $hub 社交连接层门面（绑定/分组/会话/投递，ADR-021） Social connection-tier facade (binding/groups/sessions/delivery, ADR-021)
     * @param SerializerInterface $serializer 消息序列化器 Message serializer
     */
    public function __construct(
        public readonly ConnectionHubInterface $hub,
        public readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * 读取会话数据（不可见时为空数组）。
     * Read the session data (empty when unavailable).
     *
     * @return array<string, mixed> 会话数据 Session data.
     */
    public function session(string $clientId): array
    {
        return $this->hub->getSession($clientId) ?? [];
    }

    /**
     * 编码消息为帧字节。
     * Encode a message into frame bytes.
     */
    public function enc(Message $message): string
    {
        return $this->serializer->encode($message)->bytes();
    }

    /**
     * 便捷发送：序列化消息并直发指定连接。
     * Convenience send: serialize the message and send it straight to a connection.
     */
    public function send(string $clientId, Message $message): void
    {
        $this->hub->sendToClient($clientId, $this->enc($message));
    }

    /**
     * 全员清理分组：遍历成员 → 各在线连接 leaveGroup + 清 session 指定键（teamId/guildId）。
     * Whole-membership cleanup: iterate members → for each online connection leaveGroup + clear the given session key (teamId/guildId).
     *
     * @param list<string> $members 成员 uid 列表 Member uid list.
     */
    public function leaveGroupAll(array $members, string $group, string $sessionKey): void
    {
        foreach ($members as $memberUid) {
            foreach ($this->hub->getClientIdByUid($memberUid) as $memberClientId) {
                $this->hub->leaveGroup($memberClientId, $group);
                $this->hub->updateSession($memberClientId, [$sessionKey => null]);
            }
        }
    }

    /**
     * 读取需要目标成员的语义（friend:* 与 guild:kick/promote/approve）的 targetUid 字段并做 uid 格式预校验。
     * Read the targetUid field of target-member semantics (friend:* and guild:kick/promote/approve) with uid-format pre-validation.
     */
    public function targetUidOf(Message $msg): ?string
    {
        $targetUid = $msg->payload['targetUid'] ?? null;

        return is_string($targetUid) && $targetUid !== '' && preg_match(self::UID_PATTERN, $targetUid) === 1
            ? $targetUid
            : null;
    }
}
