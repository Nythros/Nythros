<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Cluster\ServiceInstance;
use Nythros\Cluster\ServiceRegistryInterface;
use Nythros\Protocol\Message;
use Nythros\Protocol\SerializerInterface;
use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenStatus;

/**
 * 社交业务核心：auth（完整握手 + token 消费登录）/ chat 五语义 / team 状态机 / map:enter / map:join /
 * guild（最小 join/leave + 正式化 create/disband/kick/promote/notice/approve）/ friend 五语义。
 * Social business core: auth (full handshake plus token-consume login) / five chat semantics / team state machine /
 * map:enter / map:join / guild (minimal join/leave plus the formalized create/disband/kick/promote/notice/approve) /
 * the five friend semantics.
 *
 * 纯业务、可单测：依赖全部经构造注入（ConnectionHubInterface / TeamStore 可注入替身），
 * 不直接触达连接运行时或 Redis（Redis 收敛在 LocationStore/GuildStore/TeamStore/FriendStore 内）。
 * 消息编解码经 Serializer 注入；下行帧统一经 ConnectionHub 投递。
 * Pure business, unit-testable: every dependency is constructor-injected (ConnectionHubInterface / TeamStore can be
 * substituted by fakes), never touching the connection runtime or Redis directly (Redis lives inside
 * LocationStore/GuildStore/TeamStore/FriendStore). Codec goes through the injected Serializer; downstream frames are
 * delivered via the ConnectionHub.
 */
final class SocialService
{
    /** 队伍 TTL（秒） Team TTL in seconds. */
    private const TEAM_TTL = 600;

    /** 队伍人数上限 Team size cap. */
    private const MAX_TEAM_SIZE = 5;

    /** token 有效秒数（auth 初始凭证与 map:enter 续签共用） Token TTL in seconds (shared by the auth initial credential and the map:enter renewal). */
    private const TOKEN_TTL = 30;

    /** uid 格式白名单（uid 进入 location/offline/uid-guild 键构造，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** mapId/channelId/guildId 格式白名单（SERVICE_ID 风格，ADR-015 §2） mapId/channelId/guildId format whitelist (SERVICE_ID style). */
    private const SERVICE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._#:-]{0,63}$/';

    /** teamId 格式（team-{seq}，序列号十进制） teamId format (team-{seq}, decimal sequence). */
    private const TEAM_ID_PATTERN = '/^team-\d+$/';

    /**
     * 组装社交业务依赖。
     * Wire the social service dependencies.
     *
     * @param ConnectionHubInterface $hub 社交连接层门面（绑定/分组/会话/投递，ADR-021） Social connection-tier facade (binding/groups/sessions/delivery, ADR-021)
     * @param TokenManagerInterface $tokenManager Token 签发/消费（auth 初始多 scope + map:enter 续签仅 ['map']） Token issuing/consumption
     * @param ServiceRegistryInterface $registry 服务注册表（discover('map') 选频道） Service registry (discover('map') channel selection)
     * @param AuthenticatorInterface $authenticator 登录认证器 Login authenticator
     * @param LocationStoreInterface $location 位置快照 + 掉线标记 Location snapshot + offline marker
     * @param GuildStoreInterface $guild 帮派成员关系 Guild membership
     * @param TeamStoreInterface $team 组队状态机 Team state machine
     * @param SerializerInterface $serializer 消息序列化器 Message serializer
     * @param list<string> $mapIds 合法 mapId 白名单 Allowed mapId whitelist
     * @param array{chat?: string, team?: string} $endpointAddresses chat/team 服务对外 ws 地址（部署拓扑声明注入；
     *                                                                  缺省空 = auth_ok 不含 endpoints 字段） Public ws addresses of the chat/team services (injected from the deployment topology; default empty = auth_ok carries no endpoints field)
     * @param ?FriendStoreInterface $friend 好友关系存储；缺省 null = friend:* 路由回 500 未装配 The friend-relationship store; default null = friend:* routes answer a not-wired 500.
     */
    public function __construct(
        private readonly ConnectionHubInterface $hub,
        private readonly TokenManagerInterface $tokenManager,
        private readonly ServiceRegistryInterface $registry,
        private readonly AuthenticatorInterface $authenticator,
        private readonly LocationStoreInterface $location,
        private readonly GuildStoreInterface $guild,
        private readonly TeamStoreInterface $team,
        private readonly SerializerInterface $serializer,
        /** @var list<string> 合法 mapId 白名单 Allowed mapId whitelist */
        private readonly array $mapIds,
        private readonly array $endpointAddresses = [],
        private readonly ?FriendStoreInterface $friend = null,
        /** @var int|null 最低客户端协议版本（null = 版本守卫不启用；见 handleAuth ⓪）。 The minimum client protocol version (null = the guard is off; see handleAuth's step ⓪). */
        private readonly ?int $minClientVersion = null,
    ) {
    }

    /**
     * 暴露社交连接层门面（运行时入口读会话/清理时用）。
     * Expose the social connection-tier facade (runtime entries read sessions / clean up through it).
     */
    public function hub(): ConnectionHubInterface
    {
        return $this->hub;
    }

    /**
     * 认证登录（ADR-015 §1.4 完整流程）：authenticate → mapId 白名单 → 踢旧连新 → 恢复判定 →
     * 选频道 → issue(['map','chat','team'])（ADR-021 §3.2 多 scope）→ bindUid + session → 写位置快照 →
     * 恢复分组 → clearOffline → auth_ok（uid/token/map/team/guild + 可选 endpoints 三地址）。
     * Auth login (ADR-015 §1.4 full flow): authenticate → mapId whitelist → kick-old-keep-new → recovery verdict →
     * channel selection → issue(['map','chat','team']) (multi-scope, ADR-021 §3.2) → bindUid + session → location
     * snapshot → group recovery → clearOffline → auth_ok (uid/token/map/team/guild plus the optional three-address endpoints).
     */
    public function handleAuth(string $clientId, Message $msg): void
    {
        // ⓪ 协议版本守卫（版本协商，ADR-027）：最低版本非 null 时，version 缺失/非法/过低拒绝——
        // 在 authenticate 之前（不给旧版本客户端任何认证计算量），老部署缺省 null = 零影响。
        // ⓪ The protocol-version guard (version negotiation, ADR-027): when the minimum version is non-null,
        // a missing/invalid/too-old version is rejected — before authenticate (old-version clients get no auth
        // compute at all); legacy assemblies default to null = zero impact.
        $version = $msg->payload['version'] ?? null;
        if ($this->minClientVersion !== null
            && (!is_int($version) || $version < $this->minClientVersion)) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => 'client_version_too_old'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ① authenticate → uid；失败 auth_failed{401} + closeClient
        try {
            $identity = $this->authenticator->authenticate($msg->payload);
        } catch (AuthenticationException $e) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 401, 'message' => $e->getMessage()], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        $uid = $identity->getUserId();
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => '非法 uid 格式'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ② mapId 白名单校验（未知/缺失 → 400）
        $mapId = $msg->payload['mapId'] ?? null;
        if (!is_string($mapId) || !in_array($mapId, $this->mapIds, true)) {
            $this->send($clientId, Message::create('auth_failed', [
                'code' => 400,
                'message' => is_string($mapId) ? sprintf('unknown mapId: %s', $mapId) : 'payload 缺少 mapId 字段',
            ], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ③ 单点登录：存在旧连接（排除本连接）→ 踢旧连新
        foreach ($this->hub->getClientIdByUid($uid) as $oldClientId) {
            if ($oldClientId !== $clientId) {
                $this->hub->closeClient($oldClientId);
            }
        }

        // ④ 恢复判定（掉线标记命中 → 读位置快照；null 视为新登录）
        $location = $this->location->isOffline($uid) ? $this->location->getLocation($uid) : null;

        // ⑤ 选频道（恢复模式优先原频道，否则最少在线；空 → 503）
        $channel = $this->selectChannel($mapId, $location);
        if ($channel === null) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 503, 'message' => 'no available channel'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }
        $channelId = $this->channelIdOf($channel);

        // ⑥ 签发 token（先选频道后签发，频道失败不产生孤儿 token）；多 scope：map/chat/team 各服务消费自己的 scope（ADR-021 §3.2）
        try {
            $token = $this->tokenManager->issue($uid, $mapId, ['map', 'chat', 'team'], self::TOKEN_TTL);
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] token issue failed: uid=%s err=%s', $uid, $e->getMessage()));
            $this->send($clientId, Message::create('auth_failed', ['code' => 500, 'message' => 'token issue failed'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ⑦ bindUid + session（一次性 setSession 同时写 uid 与 loc，避免整体覆盖丢字段）
        $this->hub->bindUid($clientId, $uid);
        $this->hub->setSession($clientId, ['uid' => $uid, 'loc' => ['mapId' => $mapId, 'channelId' => $channelId]]);

        // ⑧ 写位置快照
        $this->location->saveLocation($uid, $mapId, $channelId);

        // ⑨ 恢复分组：队伍 / 帮派 / 频道（登录即进频道组——auth 已分配频道）
        $teamInfo = null;
        $teamId = $this->team->findByUid($uid);
        if ($teamId !== null) {
            $this->hub->joinGroup($clientId, 'team:' . $teamId);
            $this->hub->updateSession($clientId, ['teamId' => $teamId]);
            $teamInfo = $this->team->get($teamId);
        }
        $guildInfo = null;
        $guildId = $this->guild->findByUid($uid);
        if ($guildId !== null) {
            $this->hub->joinGroup($clientId, 'guild:' . $guildId);
            $this->hub->updateSession($clientId, ['guildId' => $guildId]);
            $guildInfo = $this->guild->get($guildId);
        }
        $this->hub->joinGroup($clientId, 'map:' . $mapId . ':' . $channelId);

        // ⑩ 清除掉线标记
        $this->location->clearOffline($uid);

        // ⑪ auth_ok：uid / token / map / team / guild + 可选 endpoints（chat/team 服务地址，部署拓扑注入）
        // ⑪ auth_ok: uid / token / map / team / guild plus the optional endpoints (chat/team service addresses, injected from the deployment topology)
        $endpoints = [];
        foreach (['chat', 'team'] as $endpointType) {
            $address = $this->endpointAddresses[$endpointType] ?? null;
            if (is_string($address) && $address !== '') {
                $endpoints[$endpointType] = ['wsAddress' => $address];
            }
        }

        $payload = [
            'uid' => $uid,
            'token' => $token,
            'map' => ['wsAddress' => $channel->meta['wsAddress'] ?? null, 'mapId' => $mapId, 'channelId' => $channelId],
            'team' => $teamInfo !== null ? ['teamId' => $teamId, 'leaderUid' => $teamInfo['leaderUid'], 'members' => $teamInfo['members']] : null,
            'guild' => $guildInfo !== null ? ['guildId' => $guildId, 'members' => $guildInfo['members']] : null,
        ];
        if ($endpoints !== []) {
            $payload['endpoints'] = $endpoints;
        }
        $this->send($clientId, Message::create('auth_ok', $payload, $msg->requestId));
    }

    /**
     * token 消费登录（ADR-021 §3.2 多 scope 兑现）：chat/team 角色对 gateway 完整握手签发的多 scope token
     * 消费本角色 scope——token 字段校验 → peek（只读，不可见时 consume 归因五态）→ consume(角色 scope)
     * （per-scope 墓碑一次性）→ uid 白名单 → bindUid + session → auth_ok{uid}（不重复签发 token）。
     * 失败一律 auth_failed + 断开（reason 对齐 MapServer 的五态映射）；完整握手路径（handleAuth）保持不变。
     * Token-consume login (fulfilling ADR-021 §3.2's multi-scope promise): the chat/team roles consume this role's
     * scope of the multi-scope token issued by gateway's full handshake — token-field validation → peek (read-only;
     * when invisible, consume attributes the five-state verdict) → consume(role scope) (one-shot per-scope tombstone)
     * → uid whitelist → bindUid + session → auth_ok{uid} (no re-issue). Every failure answers auth_failed and closes
     * (the reason mirrors MapServer's five-state mapping); the full-handshake path (handleAuth) stays untouched.
     *
     * @param string $scope 本角色消费的授权域（由部署角色决定：chat 角色固定 'chat'、team 角色固定 'team'） The scope this role consumes (fixed per deployment role: 'chat' for chat, 'team' for team).
     */
    public function handleTokenAuth(string $clientId, Message $msg, string $scope): void
    {
        // ① token 字段校验（缺失/非字符串 → 400）
        $token = $msg->payload['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => 'payload 缺少 token 字段'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ② peek 只读预检：不可见（格式非法/不存在/已过期）时以 consume 归因五态——expired/replayed 态在服务端链路可见
        //    （对齐 MapServer 的归因模式；peek null 一律拒绝，consume 在此仅用于 reason 归因）
        $record = $this->tokenManager->peek($token);
        if ($record === null) {
            $status = $this->tokenManager->consume($token, $scope);
            $this->send($clientId, Message::create('auth_failed', ['code' => 403, 'reason' => $this->tokenFailureReason($status)], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ③ consume 该角色 scope（per-scope 墓碑一次性；Unauthorized = token 有效但未授权该 scope，不写墓碑）
        $status = $this->tokenManager->consume($token, $scope);
        if ($status !== TokenStatus::Valid) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 403, 'reason' => $this->tokenFailureReason($status)], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ④ uid 白名单（uid 进入 hub 键构造，与 handleAuth 同规则）
        $uid = $record->uid;
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            $this->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => '非法 uid 格式'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ⑤ bindUid + session（仅 uid——chat/team 连接不承担位置状态机，loc/分组恢复仍归 gateway 完整握手）
        $this->hub->bindUid($clientId, $uid);
        $this->hub->setSession($clientId, ['uid' => $uid]);

        // ⑥ auth_ok{uid}：凭证已在 gateway 签发，此处只消费不再签发
        $this->send($clientId, Message::create('auth_ok', ['uid' => $uid], $msg->requestId));
    }

    /**
     * 聊天五语义（ADR-015 §1.5）：world/channel/team/guild/private，错误一律 chat:error 回发起方。
     * Chat five semantics (ADR-015 §1.5): world/channel/team/guild/private; failures answer the sender with chat:error.
     */
    public function handleChat(string $clientId, string $uid, Message $msg): void
    {
        $scope = $msg->payload['scope'] ?? null;
        if (!is_string($scope)) {
            $this->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 scope 字段'], $msg->requestId));

            return;
        }

        $content = $msg->payload['content'] ?? null;
        if (!is_string($content)) {
            $this->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 content 字段'], $msg->requestId));

            return;
        }

        switch ($scope) {
            case 'world':
                $this->hub->sendToAll(
                    $this->enc(Message::create('chat:message', ['scope' => 'world', 'content' => $content, 'fromUid' => $uid])),
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
                $this->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => sprintf('unknown scope: %s', $scope)], $msg->requestId));
        }
    }

    /**
     * 组队状态机（ADR-015 §1.6）：invite/accept/reject/leave/disband，委托 TeamStore，返回码映射 team:error。
     * Team state machine (ADR-015 §1.6): invite/accept/reject/leave/disband, delegated to TeamStore with return-code mapping to team:error.
     */
    public function handleTeam(string $clientId, string $uid, Message $msg): void
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
     * map:enter 进图/重连凭证续签（ADR-015 §1.7）：mapId 白名单 → 选频道 → issue(['map']) → map:entered。
     * map:enter map-entry/reconnect credential renewal (ADR-015 §1.7): mapId whitelist → channel selection → issue(['map']) → map:entered.
     */
    public function handleMapEnter(string $clientId, string $uid, Message $msg): void
    {
        $mapId = $msg->payload['mapId'] ?? null;
        if (!is_string($mapId) || !in_array($mapId, $this->mapIds, true)) {
            $this->send($clientId, Message::create('map:error', [
                'code' => 400,
                'message' => is_string($mapId) ? sprintf('unknown mapId: %s', $mapId) : 'payload 缺少 mapId 字段',
            ], $msg->requestId));

            return;
        }

        // ② 选频道：同图重入优先当前会话频道（session loc → Redis 位置快照兜底）——登录即进图确定性：
        // auth_ok 承诺的频道不得因心跳水位漂移在 map:enter 时被悄悄换掉（R1 e2e 实测缺陷：登录分到
        // 低负载 ch-2、map:enter 心跳归零后重选回 ch-1，channel 聊天组随之错位）；仅切图或原频道
        // 死亡/stopping 时才最少在线重选。
        // Channel selection: a same-map re-entry prefers the current session channel (session loc, falling back to the
        // Redis location snapshot) — login-to-map determinism: the channel promised by auth_ok must not be silently
        // swapped at map:enter because heartbeat watermarks drifted (an R1 e2e defect: login picked the low-load ch-2,
        // then map:enter re-picked ch-1 once its heartbeat zeroed and the channel chat group misaligned); only a map
        // switch or a dead/stopping original channel falls back to least-loaded selection.
        $loc = $this->session($clientId)['loc'] ?? null;
        $preferred = null;
        if (is_array($loc)
            && ($loc['mapId'] ?? null) === $mapId
            && is_string($loc['channelId'] ?? null)
            && $loc['channelId'] !== ''
        ) {
            $preferred = ['mapId' => $mapId, 'channelId' => $loc['channelId'], 'x' => null, 'y' => null, 'updatedAt' => 0.0];
        } else {
            $snapshot = $this->location->getLocation($uid);
            if ($snapshot !== null && $snapshot['mapId'] === $mapId) {
                $preferred = $snapshot;
            }
        }
        $channel = $this->selectChannel($mapId, $preferred);
        if ($channel === null) {
            $this->send($clientId, Message::create('map:error', ['code' => 503, 'message' => 'no available channel'], $msg->requestId));

            return;
        }
        $channelId = $this->channelIdOf($channel);

        // ③ 签发一次性凭证（先选频道后签发）
        try {
            $token = $this->tokenManager->issue($uid, $mapId, ['map'], self::TOKEN_TTL);
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] token issue failed: uid=%s err=%s', $uid, $e->getMessage()));
            $this->send($clientId, Message::create('map:error', ['code' => 500, 'message' => 'token issue failed'], $msg->requestId));

            return;
        }

        // ④ map:entered 下发凭证；不写位置快照、不 joinGroup（等 map:join 上报确认）
        $this->send($clientId, Message::create('map:entered', [
            'token' => $token,
            'map' => ['wsAddress' => $channel->meta['wsAddress'] ?? null, 'mapId' => $mapId, 'channelId' => $channelId],
        ], $msg->requestId));
    }

    /**
     * map:join 进图/切图上报（ADR-015 §1.7）：白名单 → 退旧频道组 → 写位置快照 → joinGroup → map:joined。
     * map:join map-entry/switch report (ADR-015 §1.7): whitelist → leave old channel group → location snapshot → joinGroup → map:joined.
     */
    public function handleMapJoin(string $clientId, string $uid, Message $msg): void
    {
        $mapId = $msg->payload['mapId'] ?? null;
        $channelId = $msg->payload['channelId'] ?? null;
        if (!is_string($mapId) || !in_array($mapId, $this->mapIds, true)
            || !is_string($channelId) || $channelId === ''
            || preg_match(self::SERVICE_ID_PATTERN, $channelId) !== 1
        ) {
            $this->send($clientId, Message::create('map:error', ['code' => 400, 'message' => 'payload mapId/channelId 非法'], $msg->requestId));

            return;
        }

        // ② 读旧 loc：新旧不同 → leaveGroup 旧频道组
        $loc = $this->session($clientId)['loc'] ?? null;
        if (is_array($loc)
            && is_string($loc['mapId'] ?? null)
            && is_string($loc['channelId'] ?? null)
            && ($loc['mapId'] !== $mapId || $loc['channelId'] !== $channelId)
        ) {
            $this->hub->leaveGroup($clientId, 'map:' . $loc['mapId'] . ':' . $loc['channelId']);
        }

        // ③ 写位置快照 + updateSession loc
        $x = $msg->payload['x'] ?? null;
        $y = $msg->payload['y'] ?? null;
        $this->location->saveLocation(
            $uid,
            $mapId,
            $channelId,
            is_int($x) || is_float($x) ? (float) $x : null,
            is_int($y) || is_float($y) ? (float) $y : null,
        );
        $this->hub->updateSession($clientId, ['loc' => ['mapId' => $mapId, 'channelId' => $channelId]]);

        // ④ joinGroup 新频道组
        $this->hub->joinGroup($clientId, 'map:' . $mapId . ':' . $channelId);

        // ⑤ map:joined 确认回执
        $this->send($clientId, Message::create('map:joined', ['mapId' => $mapId, 'channelId' => $channelId], $msg->requestId));
    }

    /** 帮派人数上限缺省值（guild:create 未声明时使用） Default guild member cap (used when guild:create omits it). */
    private const DEFAULT_MAX_GUILD_SIZE = 100;

    /**
     * 帮派语义（ADR-015 §1.9 最小面 + R3 正式化面）：guild:join/leave 沿用最小实现；
     * guild:create/disband/kick/promote/notice/apply/approve 走 GuildStore 正式化面，
     * 返回码表驱动映射 guild:error；解散/踢人同步清场（分组 + session）。
     * Guild semantics (the ADR-015 §1.9 minimal surface plus the R3 formalized surface): guild:join/leave keep the
     * minimal implementation; guild:create/disband/kick/promote/notice/apply/approve ride the formalized GuildStore
     * surface with return codes table-mapped onto guild:error; disband/kick clean up groups and sessions in sync.
     */
    public function handleGuild(string $clientId, string $uid, Message $msg): void
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
        if (!is_string($guildId) || preg_match(self::SERVICE_ID_PATTERN, $guildId) !== 1) {
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload 缺少 guildId 字段'], $msg->requestId));

            return;
        }

        if ($msg->type === 'guild:join') {
            // 换帮拦截：uid 已在其他帮派 → 403 already_in_guild（MINOR-3）
            // Guild-switch guard: uid already in another guild → 403 already_in_guild (MINOR-3)
            if (!$this->guild->join($uid, $guildId)) {
                $this->send($clientId, Message::create('guild:error', ['code' => 403, 'message' => 'already_in_guild'], $msg->requestId));

                return;
            }
            $this->hub->joinGroup($clientId, 'guild:' . $guildId);
            $this->hub->updateSession($clientId, ['guildId' => $guildId]);
            $this->send($clientId, Message::create('guild:joined', ['guildId' => $guildId], $msg->requestId));

            return;
        }

        // guild:leave：非成员 403
        if (!$this->guild->leave($uid, $guildId)) {
            $this->send($clientId, Message::create('guild:error', ['code' => 403, 'message' => 'not_member'], $msg->requestId));

            return;
        }
        $this->hub->leaveGroup($clientId, 'guild:' . $guildId);
        $this->hub->updateSession($clientId, ['guildId' => null]);
        $this->send($clientId, Message::create('guild:left', ['guildId' => $guildId], $msg->requestId));
    }

    /**
     * 好友五语义（R3 社交批）：friend:apply/accept/reject/remove/list，委托 FriendStore，返回码映射 friend:error。
     * 在线通知复用 hub sendToUid（离线自动丢弃 = 静默）；未装配 FriendStore 时一律 500。
     * The five friend semantics (the R3 social batch): friend:apply/accept/reject/remove/list, delegated to the
     * FriendStore with return codes mapped onto friend:error. Online notifications reuse hub sendToUid (dropped
     * silently when offline); without a wired FriendStore everything answers 500.
     */
    public function handleFriend(string $clientId, string $uid, Message $msg): void
    {
        if ($this->friend === null) {
            $this->send($clientId, Message::create('friend:error', ['code' => 500, 'message' => 'friend store not wired'], $msg->requestId));

            return;
        }

        switch ($msg->type) {
            case 'friend:list':
                $this->send($clientId, Message::create('friend:ok', ['action' => 'list', 'uids' => $this->friend->list($uid)], $msg->requestId));

                return;
            case 'friend:apply':
            case 'friend:accept':
            case 'friend:reject':
            case 'friend:remove':
                break;
            default:
                return;
        }

        $targetUid = $this->targetUidOf($msg);
        if ($targetUid === null) {
            $this->send($clientId, Message::create('friend:error', ['code' => 400, 'message' => 'payload targetUid 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = match ($msg->type) {
            'friend:apply' => $this->friend->apply($uid, $targetUid),
            'friend:accept' => $this->friend->accept($targetUid, $uid),
            'friend:reject' => $this->friend->reject($targetUid, $uid),
            default => $this->friend->remove($uid, $targetUid),
        };
        if ($result['code'] !== FriendStoreInterface::CODE_OK) {
            $this->send($clientId, Message::create('friend:error', [
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
        $this->hub->sendToUid($targetUid, $this->enc(Message::create('friend:notify', [
            'type' => $notifyType,
            'fromUid' => $uid,
        ])));

        $action = substr($msg->type, strlen('friend:'));
        $this->send($clientId, Message::create('friend:ok', ['action' => $action], $msg->requestId));
    }

    /**
     * guild:create：guildId/name/maxMembers 校验 → GuildStore::create → joinGroup + session → guild:ok。
     * guild:create: guildId/name/maxMembers validation → GuildStore::create → joinGroup + session → guild:ok.
     */
    private function guildCreate(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        if ($guildId === null) {
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }
        $name = $msg->payload['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload name 非法'], $msg->requestId));

            return;
        }
        $maxMembers = $msg->payload['maxMembers'] ?? self::DEFAULT_MAX_GUILD_SIZE;
        if (!is_int($maxMembers) || $maxMembers < 1) {
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload maxMembers 必须为正整数'], $msg->requestId));

            return;
        }

        $result = $this->guild->create($uid, $guildId, $name, $maxMembers);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->joinGroup($clientId, 'guild:' . $guildId);
        $this->hub->updateSession($clientId, ['guildId' => $guildId]);
        $this->send($clientId, Message::create('guild:ok', ['action' => 'create', 'guildId' => $guildId], $msg->requestId));
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
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->disband($uid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->sendToGroup('guild:' . $guildId, $this->enc(Message::create('guild:notify', [
            'type' => 'disbanded',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->leaveGroupAll($result['members'] ?? [], 'guild:' . $guildId, 'guildId');
        $this->send($clientId, Message::create('guild:ok', ['action' => 'disband', 'guildId' => $guildId], $msg->requestId));
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
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->kick($uid, $targetUid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        // 被踢者全部在线连接退帮组 + 清 session；离线时静默（无绑定连接可清）
        // Every online connection of the kicked member leaves the guild group and clears its session; silent when offline
        foreach ($this->hub->getClientIdByUid($targetUid) as $targetClientId) {
            $this->hub->leaveGroup($targetClientId, 'guild:' . $guildId);
            $this->hub->updateSession($targetClientId, ['guildId' => null]);
        }
        $this->hub->sendToUid($targetUid, $this->enc(Message::create('guild:notify', [
            'type' => 'kicked',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->send($clientId, Message::create('guild:ok', ['action' => 'kick', 'guildId' => $guildId], $msg->requestId));
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
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid/role 缺失或非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->promote($uid, $targetUid, $guildId, $role);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->sendToUid($targetUid, $this->enc(Message::create('guild:notify', [
            'type' => 'promoted',
            'guildId' => $guildId,
            'role' => $role,
            'fromUid' => $uid,
        ])));
        $this->send($clientId, Message::create('guild:ok', ['action' => 'promote', 'guildId' => $guildId, 'role' => $role], $msg->requestId));
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
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/notice 缺失或非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->setNotice($uid, $guildId, $notice);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->sendToGroup('guild:' . $guildId, $this->enc(Message::create('guild:notify', [
            'type' => 'notice',
            'guildId' => $guildId,
            'notice' => $notice,
            'fromUid' => $uid,
        ])));
        $this->send($clientId, Message::create('guild:ok', ['action' => 'notice', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * guild:apply：GuildStore::apply → guild:ok（审批方经 guild:approve 凭 targetUid 审批，无需列表帧）。
     * guild:apply: GuildStore::apply → guild:ok (approvers act on a targetUid via guild:approve — no listing frame needed).
     */
    private function guildApply(string $clientId, string $uid, Message $msg): void
    {
        $guildId = $this->guildIdOf($msg);
        if ($guildId === null) {
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->guild->apply($uid, $guildId);
        if ($result['code'] !== GuildStoreInterface::CODE_OK) {
            $this->sendGuildError($clientId, $msg, $result['code']);

            return;
        }

        $this->send($clientId, Message::create('guild:ok', ['action' => 'apply', 'guildId' => $guildId], $msg->requestId));
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
            $this->send($clientId, Message::create('guild:error', ['code' => 400, 'message' => 'payload guildId/targetUid/accept 缺失或非法'], $msg->requestId));

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
            foreach ($this->hub->getClientIdByUid($targetUid) as $applicantClientId) {
                $this->hub->joinGroup($applicantClientId, 'guild:' . $guildId);
                $this->hub->updateSession($applicantClientId, ['guildId' => $guildId]);
            }
        }
        $this->hub->sendToUid($targetUid, $this->enc(Message::create('guild:notify', [
            'type' => $accept ? 'approved' : 'rejected',
            'guildId' => $guildId,
            'fromUid' => $uid,
        ])));
        $this->send($clientId, Message::create('guild:ok', ['action' => 'approve', 'guildId' => $guildId], $msg->requestId));
    }

    /**
     * 连接关闭：写掉线标记（ADR-015 §1.8）。
     * Connection close: write the offline marker (ADR-015 §1.8).
     */
    public function handleClose(string $uid): void
    {
        $this->location->markOffline($uid);
    }

    /**
     * channel 发言：只允许本频道（payload 带 mapId/channelId 且与 session loc 不符 → 404），sendToGroup 本频道组。
     * Channel chat: only within one's own channel (a payload mapId/channelId mismatching the session loc → 404); sendToGroup the channel group.
     */
    private function chatChannel(string $clientId, string $uid, string $content, Message $msg): void
    {
        $loc = $this->session($clientId)['loc'] ?? null;
        if (!is_array($loc) || !is_string($loc['mapId'] ?? null) || !is_string($loc['channelId'] ?? null)) {
            $this->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'channel unknown'], $msg->requestId));

            return;
        }

        $mapId = $msg->payload['mapId'] ?? null;
        $channelId = $msg->payload['channelId'] ?? null;
        if (($mapId !== null && $mapId !== $loc['mapId']) || ($channelId !== null && $channelId !== $loc['channelId'])) {
            $this->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'channel unknown'], $msg->requestId));

            return;
        }

        $this->hub->sendToGroup(
            'map:' . $loc['mapId'] . ':' . $loc['channelId'],
            $this->enc(Message::create('chat:message', ['scope' => 'channel', 'content' => $content, 'fromUid' => $uid])),
            $clientId,
        );
    }

    /**
     * team 发言：session teamId 缺失回退 TeamStore::findByUid；无队 404；sendToGroup 队伍组。
     * Team chat: fall back to TeamStore::findByUid when the session teamId is missing; no team → 404; sendToGroup the team group.
     */
    private function chatTeam(string $clientId, string $uid, string $content, Message $msg): void
    {
        $teamId = $this->session($clientId)['teamId'] ?? null;
        if (!is_string($teamId) || $teamId === '') {
            $teamId = $this->team->findByUid($uid);
        }
        if ($teamId === null) {
            $this->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'not in team'], $msg->requestId));

            return;
        }

        $this->hub->sendToGroup(
            'team:' . $teamId,
            $this->enc(Message::create('chat:message', ['scope' => 'team', 'content' => $content, 'fromUid' => $uid])),
            $clientId,
        );
    }

    /**
     * guild 发言：session guildId 缺失回退 GuildStore::findByUid；无帮 404；sendToGroup 帮派组。
     * Guild chat: fall back to GuildStore::findByUid when the session guildId is missing; no guild → 404; sendToGroup the guild group.
     */
    private function chatGuild(string $clientId, string $uid, string $content, Message $msg): void
    {
        $guildId = $this->session($clientId)['guildId'] ?? null;
        if (!is_string($guildId) || $guildId === '') {
            $guildId = $this->guild->findByUid($uid);
        }
        if ($guildId === null) {
            $this->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'not in guild'], $msg->requestId));

            return;
        }

        $this->hub->sendToGroup(
            'guild:' . $guildId,
            $this->enc(Message::create('chat:message', ['scope' => 'guild', 'content' => $content, 'fromUid' => $uid])),
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
            $this->send($clientId, Message::create('chat:error', ['code' => 400, 'message' => 'payload 缺少 targetUid 字段'], $msg->requestId));

            return;
        }
        if (!$this->hub->isUidOnline($targetUid)) {
            $this->send($clientId, Message::create('chat:error', ['code' => 404, 'message' => 'target offline'], $msg->requestId));

            return;
        }

        $this->hub->sendToUid(
            $targetUid,
            $this->enc(Message::create('chat:message', ['scope' => 'private', 'content' => $content, 'fromUid' => $uid])),
        );
    }

    /**
     * team:invite（ADR-015 §1.6）：targetUid 校验 → 目标离线 pre-check → TeamStore::invite → 通知 + 回执。
     * team:invite (ADR-015 §1.6): targetUid validation → target-offline pre-check → TeamStore::invite → notify + receipt.
     */
    private function teamInvite(string $clientId, string $uid, Message $msg): void
    {
        $targetUid = $msg->payload['targetUid'] ?? null;
        if (!is_string($targetUid) || $targetUid === '') {
            $this->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload 缺少 targetUid 字段'], $msg->requestId));

            return;
        }
        // 目标离线 pre-check（Gateway 在线态无法在 Redis 内判定，ADR-015 §1.6；竞态窗口由 sendToUid 自动丢弃兜底）
        if (!$this->hub->isUidOnline($targetUid)) {
            $this->send($clientId, Message::create('team:error', ['code' => 404, 'message' => 'target_offline'], $msg->requestId));

            return;
        }

        $result = $this->team->invite($uid, $targetUid, self::MAX_TEAM_SIZE, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $teamId = $result['teamId'] ?? null;
        if (!is_string($teamId) || $teamId === '') {
            $this->send($clientId, Message::create('team:error', ['code' => 500, 'message' => 'internal error'], $msg->requestId));

            return;
        }
        $this->hub->joinGroup($clientId, 'team:' . $teamId);
        $this->hub->updateSession($clientId, ['teamId' => $teamId]);
        $this->hub->sendToUid($targetUid, $this->enc(Message::create('team:notify', [
            'type' => 'invited',
            'teamId' => $teamId,
            'uid' => $targetUid,
            'fromUid' => $uid,
        ])));
        $this->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'invite'], $msg->requestId));
    }

    /**
     * team:accept（ADR-015 §1.6）：TeamStore::accept → joinGroup + updateSession → notify 全队 joined → team:ok。
     * team:accept (ADR-015 §1.6): TeamStore::accept → joinGroup + updateSession → notify the whole team joined → team:ok.
     */
    private function teamAccept(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->accept($uid, $teamId, self::MAX_TEAM_SIZE, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->joinGroup($clientId, 'team:' . $teamId);
        $this->hub->updateSession($clientId, ['teamId' => $teamId]);
        $this->hub->sendToGroup('team:' . $teamId, $this->enc(Message::create('team:notify', [
            'type' => 'joined',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'accept'], $msg->requestId));
    }

    /**
     * team:reject（ADR-015 §1.6）：TeamStore::reject → notify 队长 rejected → team:ok。
     * team:reject (ADR-015 §1.6): TeamStore::reject → notify the leader rejected → team:ok.
     */
    private function teamReject(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->reject($uid, $teamId, self::TEAM_TTL, microtime(true));
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $leaderUid = $result['leaderUid'] ?? null;
        if (!is_string($leaderUid) || $leaderUid === '') {
            $this->send($clientId, Message::create('team:error', ['code' => 500, 'message' => 'internal error'], $msg->requestId));

            return;
        }

        $this->hub->sendToUid($leaderUid, $this->enc(Message::create('team:notify', [
            'type' => 'rejected',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'reject'], $msg->requestId));
    }

    /**
     * team:leave（ADR-015 §1.6）：成员离开 notify left → leaveGroup；队长离开 notify disbanded → 全队清理分组。
     * team:leave (ADR-015 §1.6): member leave notifies left → leaveGroup; leader leave notifies disbanded → whole-team group cleanup.
     */
    private function teamLeave(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->leave($uid, $teamId, self::TEAM_TTL);
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $disbanded = ($result['action'] ?? null) === 'disbanded';
        $this->hub->sendToGroup('team:' . $teamId, $this->enc(Message::create('team:notify', [
            'type' => $disbanded ? 'disbanded' : 'left',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));

        if ($disbanded) {
            $this->leaveGroupAll($result['members'] ?? [], 'team:' . $teamId, 'teamId');
        } else {
            $this->hub->leaveGroup($clientId, 'team:' . $teamId);
            $this->hub->updateSession($clientId, ['teamId' => null]);
        }

        $this->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'leave'], $msg->requestId));
    }

    /**
     * team:disband（ADR-015 §1.6）：TeamStore::disband → notify disbanded → 全队清理分组 → team:ok。
     * team:disband (ADR-015 §1.6): TeamStore::disband → notify disbanded → whole-team group cleanup → team:ok.
     */
    private function teamDisband(string $clientId, string $uid, Message $msg): void
    {
        $teamId = $this->teamIdOf($msg);
        if ($teamId === null) {
            $this->send($clientId, Message::create('team:error', ['code' => 400, 'message' => 'payload teamId 缺失或格式非法'], $msg->requestId));

            return;
        }

        $result = $this->team->disband($uid, $teamId, self::TEAM_TTL);
        if ($result['code'] !== TeamStoreInterface::CODE_OK) {
            $this->sendTeamError($clientId, $msg, $result['code']);

            return;
        }

        $this->hub->sendToGroup('team:' . $teamId, $this->enc(Message::create('team:notify', [
            'type' => 'disbanded',
            'teamId' => $teamId,
            'uid' => $uid,
        ])));
        $this->leaveGroupAll($result['members'] ?? [], 'team:' . $teamId, 'teamId');
        $this->send($clientId, Message::create('team:ok', ['teamId' => $teamId, 'action' => 'disband'], $msg->requestId));
    }

    /**
     * 全员清理分组：遍历成员 → 各在线连接 leaveGroup + 清 session 指定键（teamId/guildId）。
     * Whole-membership cleanup: iterate members → for each online connection leaveGroup + clear the given session key (teamId/guildId).
     *
     * @param list<string> $members 成员 uid 列表 Member uid list.
     */
    private function leaveGroupAll(array $members, string $group, string $sessionKey): void
    {
        foreach ($members as $memberUid) {
            foreach ($this->hub->getClientIdByUid($memberUid) as $memberClientId) {
                $this->hub->leaveGroup($memberClientId, $group);
                $this->hub->updateSession($memberClientId, [$sessionKey => null]);
            }
        }
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

        return is_string($guildId) && preg_match(self::SERVICE_ID_PATTERN, $guildId) === 1 ? $guildId : null;
    }

    /**
     * 读取需要目标成员的 guild 语义（kick/promote/approve）的 guildId + targetUid 字段并做格式预校验。
     * Read the guildId + targetUid fields of target-member guild semantics (kick/promote/approve) with format pre-validation.
     *
     * @return array{?string, ?string} [guildId, targetUid]（任一非法为 null） [guildId, targetUid] (null when either is illegal).
     */
    private function guildTargetOf(Message $msg): array
    {
        return [$this->guildIdOf($msg), $this->targetUidOf($msg)];
    }

    /**
     * guild:error 下发：GuildStore 返回码 → HTTP 状态码 + message 映射（表驱动语义的统一出口）。
     * guild:error delivery: GuildStore return code → HTTP status + message mapping (the unified exit of table-driven semantics).
     */
    private function sendGuildError(string $clientId, Message $msg, int $code): void
    {
        $this->send($clientId, Message::create('guild:error', [
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

    /**
     * team:error 下发：TeamStore 返回码 → HTTP 状态码 + message 映射（ADR-015 §1.6 PHP 侧动作表）。
     * team:error delivery: TeamStore return code → HTTP status + message mapping (ADR-015 §1.6 PHP-side action table).
     */
    private function sendTeamError(string $clientId, Message $msg, int $code): void
    {
        $this->send($clientId, Message::create('team:error', [
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

    /**
     * 读取 friend:apply/accept/reject/remove 的 targetUid 字段并做 uid 格式预校验。
     * Read the targetUid field for friend:apply/accept/reject/remove and pre-validate the uid format.
     */
    private function targetUidOf(Message $msg): ?string
    {
        $targetUid = $msg->payload['targetUid'] ?? null;

        return is_string($targetUid) && $targetUid !== '' && preg_match(self::UID_PATTERN, $targetUid) === 1
            ? $targetUid
            : null;
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

    /**
     * 选频道（ADR-015 §1.4 ⑤ / §1.7 ② 共用分配过滤）：discover('map') → mapId 过滤 → status !== stopping →
     * 恢复模式优先原频道（未命中/新登录 → 最少在线）。
     * Channel selection (the shared assignment filter of ADR-015 §1.4⑤ / §1.7②): discover('map') → mapId filter →
     * status !== stopping → recovery prefers the original channel (miss / fresh login → least-loaded).
     *
     * @param ?array{mapId: string, channelId: string, x: ?float, y: ?float, updatedAt: float} $location 恢复用位置快照；null = 新登录 Location snapshot for recovery; null = fresh login.
     * @return ?ServiceInstance 选中实例；无可分配 null Selected instance; null when none assignable.
     */
    private function selectChannel(string $mapId, ?array $location): ?ServiceInstance
    {
        // P16 动态扩缩容路由过滤：draining/stopping 实例不再接入新会话；声明了 maxCapacity 的实例
        // 达顶即跳过（auth 侧另有硬守卫兜住 select 与 attach 之间的并发窗口）。
        // The P16 dynamic-scaling routing filter: draining/stopping instances take no new sessions; instances
        // declaring maxCapacity are skipped once at the cap (the auth-side hard guard backstops the concurrent
        // window between select and attach).
        // 注册表读失败归一为「无可用频道」（故障演练 redis-down 的契约确定性）：discover 在 Redis
        // 不可用时可能 throw（phpredis 异常 / 空 hash 读失败 RuntimeException），裸传会落到 dispatch
        // catch-all 的通用 500——与实测观察到的 auth_failed 503 "no available channel" 降级契约不一致。
        // 归一 + 日志归因，保证宕机窗口内登录一律走 503、恢复后心跳重注册即自愈。
        // Registry read failures normalize to "no available channel" (the redis-down drill contract): discover
        // may throw when Redis is unavailable, and letting it propagate lands on the dispatch catch-all's
        // generic 500 — inconsistent with the measured auth_failed 503 "no available channel" degradation.
        // Normalizing (with an attribution log) guarantees 503s during the outage and self-heal on heartbeat
        // re-registration after recovery.
        try {
            $discovered = $this->registry->discover('map');
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] discover failed: mapId=%s err=%s', $mapId, $e->getMessage()));

            return null;
        }
        $channels = array_filter(
            $discovered,
            static fn (ServiceInstance $instance): bool => ($instance->meta['mapId'] ?? null) === $mapId
                && !in_array(($instance->meta['status'] ?? 'serving'), ['stopping', 'draining'], true)
                && !(
                    is_int($instance->meta['maxCapacity'] ?? null)
                    && $instance->meta['maxCapacity'] > 0
                    && (int) ($instance->meta['playerCount'] ?? 0) >= $instance->meta['maxCapacity']
                ),
        );
        if ($channels === []) {
            return null;
        }

        if ($location !== null) {
            $channelId = $location['channelId'];
            foreach ($channels as $instance) {
                if ($this->channelIdOf($instance) === $channelId) {
                    return $instance;
                }
            }
            // 原频道已死/stopping → 最少在线
        }

        return $this->minPlayerCount($channels);
    }

    /**
     * 最少在线选频道：取过滤结果中 playerCount 最小的实例（playerCount 缺失视为满员，避免被误选）。
     * Least-loaded selection: the instance with the smallest playerCount (a missing playerCount counts as fully loaded, never mis-picked).
     *
     * @param array<string, ServiceInstance> $channels 已过滤的存活频道（非空） Filtered live channels (non-empty).
     */
    private function minPlayerCount(array $channels): ServiceInstance
    {
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($channels as $instance) {
            $count = (int) ($instance->meta['playerCount'] ?? PHP_INT_MAX);
            if ($count < $bestCount) {
                $best = $instance;
                $bestCount = $count;
            }
        }

        if ($best === null) {
            // 不可达：调用方已判空过滤结果 Unreachable: the caller already rejected an empty filtered set
            throw new \LogicException('channels must not be empty');
        }

        return $best;
    }

    /**
     * 取实例 channelId：meta.channelId 优先，缺失时从 serviceId 编码解析（{mapId}#{channelId}，最后一个 # 之后）。
     * Resolve an instance's channelId: meta.channelId wins; when absent, parse the serviceId encoding ({mapId}#{channelId}, after the last #).
     */
    private function channelIdOf(ServiceInstance $instance): string
    {
        $channelId = $instance->meta['channelId'] ?? null;
        if (is_string($channelId) && $channelId !== '') {
            return $channelId;
        }

        $hash = strrpos($instance->id, '#');

        return $hash === false ? $instance->id : substr($instance->id, $hash + 1);
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

        return is_string($teamId) && preg_match(self::TEAM_ID_PATTERN, $teamId) === 1 ? $teamId : null;
    }

    /**
     * 读取会话数据（不可见时为空数组）。
     * Read the session data (empty when unavailable).
     *
     * @return array<string, mixed> 会话数据 Session data.
     */
    private function session(string $clientId): array
    {
        return $this->hub->getSession($clientId) ?? [];
    }

    /**
     * consume 五态 → auth_failed reason 映射（Invalid 为兜底分支；与 MapServer 的映射同口径）。
     * Maps a consume verdict to the auth_failed reason (Invalid is the fallback; same mapping as MapServer).
     */
    private function tokenFailureReason(TokenStatus $status): string
    {
        return match ($status) {
            TokenStatus::Expired => 'expired',
            TokenStatus::Replayed => 'replayed',
            TokenStatus::Unauthorized => 'unauthorized',
            default => 'invalid',
        };
    }

    /**
     * 编码消息为帧字节。
     * Encode a message into frame bytes.
     */
    private function enc(Message $message): string
    {
        return $this->serializer->encode($message)->bytes();
    }

    /**
     * 便捷发送：序列化消息并直发指定连接。
     * Convenience send: serialize the message and send it straight to a connection.
     */
    private function send(string $clientId, Message $message): void
    {
        $this->hub->sendToClient($clientId, $this->enc($message));
    }
}
