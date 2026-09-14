<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

use Nythros\Cluster\ServiceRegistryInterface;
use Nythros\Protocol\Message;
use Nythros\Protocol\SerializerInterface;
use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenStatus;

/**
 * 社交业务门面：auth（完整握手 + token 消费登录）/ map:enter / map:join 与 chat 五语义 / team 状态机 /
 * guild（最小 join/leave + 正式化面）/ friend 五语义的统一入口。后四个域竖切为 @internal 响应器
 * （Chat/Team/Guild/FriendResponder），共享投递/会话底座（SocialContext）与选频道器（ChannelSelector）；
 * 本类保留 gateway 侧的握手、凭证续签与位置上报（跨域状态机，不归属单一玩法域）。
 * The social business facade: the unified entry for auth (full handshake plus token-consume login) / map:enter /
 * map:join and the five chat semantics / the team state machine / guild (minimal join/leave plus the formalized
 * surface) / the five friend semantics. The latter four domains are vertically sliced into @internal responders
 * (Chat/Team/Guild/FriendResponder) over the shared delivery/session base (SocialContext) and the channel assigner
 * (ChannelSelector); this class keeps the gateway-side handshake, credential renewal and location reporting
 * (cross-domain state machines belonging to no single gameplay domain).
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
    /** token 有效秒数（auth 初始凭证与 map:enter 续签共用） Token TTL in seconds (shared by the auth initial credential and the map:enter renewal). */
    private const TOKEN_TTL = 30;

    private readonly SocialContext $ctx;

    private readonly ChannelSelector $channels;

    private readonly ChatResponder $chat;

    private readonly TeamResponder $teamResponder;

    private readonly GuildResponder $guildResponder;

    private readonly FriendResponder $friendResponder;

    /**
     * 组装社交业务依赖（域响应器在构造期装配，公开签名保持稳定）。
     * Wire the social service dependencies (domain responders are assembled at construction; the public signature stays stable).
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
        ServiceRegistryInterface $registry,
        private readonly AuthenticatorInterface $authenticator,
        private readonly LocationStoreInterface $location,
        private readonly GuildStoreInterface $guild,
        private readonly TeamStoreInterface $team,
        SerializerInterface $serializer,
        /** @var list<string> 合法 mapId 白名单 Allowed mapId whitelist */
        private readonly array $mapIds,
        private readonly array $endpointAddresses = [],
        ?FriendStoreInterface $friend = null,
        /** @var int|null 最低客户端协议版本（null = 版本守卫不启用；见 handleAuth ⓪）。 The minimum client protocol version (null = the guard is off; see handleAuth's step ⓪). */
        private readonly ?int $minClientVersion = null,
        /** @var int 清单版本（ADR-030）：auth_ok 回传供客户端与编译期生成物比对,0 = 装配层未注入（不校验）。
         *  Manifest version (ADR-030): echoed in auth_ok for client-side comparison against its build-time tables; 0 = not injected (no check). */
        private readonly int $manifestVersion = 0,
    ) {
        $this->ctx = new SocialContext($hub, $serializer);
        $this->channels = new ChannelSelector($registry);
        $this->chat = new ChatResponder($this->ctx, $team, $guild);
        $this->teamResponder = new TeamResponder($this->ctx, $team);
        $this->guildResponder = new GuildResponder($this->ctx, $guild);
        $this->friendResponder = new FriendResponder($this->ctx, $friend);
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
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => 'client_version_too_old'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ① authenticate → uid；失败 auth_failed{401} + closeClient
        try {
            $identity = $this->authenticator->authenticate($msg->payload);
        } catch (AuthenticationException $e) {
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 401, 'message' => $e->getMessage()], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        $uid = $identity->getUserId();
        if (preg_match(SocialContext::UID_PATTERN, $uid) !== 1) {
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => '非法 uid 格式'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ② mapId 白名单校验（未知/缺失 → 400）
        $mapId = $msg->payload['mapId'] ?? null;
        if (!is_string($mapId) || !in_array($mapId, $this->mapIds, true)) {
            $this->ctx->send($clientId, Message::create('auth_failed', [
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
        $channel = $this->channels->select($mapId, $location);
        if ($channel === null) {
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 503, 'message' => 'no available channel'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }
        $channelId = $this->channels->channelIdOf($channel);

        // ⑥ 签发 token（先选频道后签发，频道失败不产生孤儿 token）；多 scope：map/chat/team 各服务消费自己的 scope（ADR-021 §3.2）
        try {
            $token = $this->tokenManager->issue($uid, $mapId, ['map', 'chat', 'team'], self::TOKEN_TTL);
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] token issue failed: uid=%s err=%s', $uid, $e->getMessage()));
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 500, 'message' => 'token issue failed'], $msg->requestId));
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
        // 协商结果回传（ADR-027/037 地基）：version=客户端自报并对齐的协议版本,manifestVersion=服务端清单指纹,
        // 客户端据此与编译期码表比对,不一致即断开升级（A 模型：拒绝而非适配）。
        // Negotiation echo-back: the agreed protocol version + the server manifest fingerprint; the client compares
        // the fingerprint against its build-time tables and disconnects on mismatch (Model A: reject, never adapt).
        if ($this->manifestVersion > 0) {
            $payload['version'] = is_int($version) ? $version : 0;
            $payload['manifestVersion'] = $this->manifestVersion;
        }
        if ($endpoints !== []) {
            $payload['endpoints'] = $endpoints;
        }
        $this->ctx->send($clientId, Message::create('auth_ok', $payload, $msg->requestId));
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
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => 'payload 缺少 token 字段'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ② peek 只读预检：不可见（格式非法/不存在/已过期）时以 consume 归因五态——expired/replayed 态在服务端链路可见
        //    （对齐 MapServer 的归因模式；peek null 一律拒绝，consume 在此仅用于 reason 归因）
        $record = $this->tokenManager->peek($token);
        if ($record === null) {
            $status = $this->tokenManager->consume($token, $scope);
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 403, 'reason' => $this->tokenFailureReason($status)], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ③ consume 该角色 scope（per-scope 墓碑一次性；Unauthorized = token 有效但未授权该 scope，不写墓碑）
        $status = $this->tokenManager->consume($token, $scope);
        if ($status !== TokenStatus::Valid) {
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 403, 'reason' => $this->tokenFailureReason($status)], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ④ uid 白名单（uid 进入 hub 键构造，与 handleAuth 同规则）
        $uid = $record->uid;
        if (preg_match(SocialContext::UID_PATTERN, $uid) !== 1) {
            $this->ctx->send($clientId, Message::create('auth_failed', ['code' => 400, 'message' => '非法 uid 格式'], $msg->requestId));
            $this->hub->closeClient($clientId);

            return;
        }

        // ⑤ bindUid + session（仅 uid——chat/team 连接不承担位置状态机，loc/分组恢复仍归 gateway 完整握手）
        $this->hub->bindUid($clientId, $uid);
        $this->hub->setSession($clientId, ['uid' => $uid]);

        // ⑥ auth_ok{uid}：凭证已在 gateway 签发，此处只消费不再签发
        $this->ctx->send($clientId, Message::create('auth_ok', ['uid' => $uid], $msg->requestId));
    }

    /**
     * 聊天五语义（ADR-015 §1.5）：world/channel/team/guild/private，委托 ChatResponder。
     * The five chat semantics (ADR-015 §1.5): world/channel/team/guild/private, delegated to ChatResponder.
     */
    public function handleChat(string $clientId, string $uid, Message $msg): void
    {
        $this->chat->handle($clientId, $uid, $msg);
    }

    /**
     * 组队状态机（ADR-015 §1.6）：invite/accept/reject/leave/disband，委托 TeamResponder。
     * The team state machine (ADR-015 §1.6): invite/accept/reject/leave/disband, delegated to TeamResponder.
     */
    public function handleTeam(string $clientId, string $uid, Message $msg): void
    {
        $this->teamResponder->handle($clientId, $uid, $msg);
    }

    /**
     * map:enter 进图/重连凭证续签（ADR-015 §1.7）：mapId 白名单 → 选频道 → issue(['map']) → map:entered。
     * map:enter map-entry/reconnect credential renewal (ADR-015 §1.7): mapId whitelist → channel selection → issue(['map']) → map:entered.
     */
    public function handleMapEnter(string $clientId, string $uid, Message $msg): void
    {
        $mapId = $msg->payload['mapId'] ?? null;
        if (!is_string($mapId) || !in_array($mapId, $this->mapIds, true)) {
            $this->ctx->send($clientId, Message::create('map:error', [
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
        $loc = $this->ctx->session($clientId)['loc'] ?? null;
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
        $channel = $this->channels->select($mapId, $preferred);
        if ($channel === null) {
            $this->ctx->send($clientId, Message::create('map:error', ['code' => 503, 'message' => 'no available channel'], $msg->requestId));

            return;
        }
        $channelId = $this->channels->channelIdOf($channel);

        // ③ 签发一次性凭证（先选频道后签发）
        try {
            $token = $this->tokenManager->issue($uid, $mapId, ['map'], self::TOKEN_TTL);
        } catch (\Throwable $e) {
            error_log(sprintf('[Social] token issue failed: uid=%s err=%s', $uid, $e->getMessage()));
            $this->ctx->send($clientId, Message::create('map:error', ['code' => 500, 'message' => 'token issue failed'], $msg->requestId));

            return;
        }

        // ④ map:entered 下发凭证；不写位置快照、不 joinGroup（等 map:join 上报确认）
        $this->ctx->send($clientId, Message::create('map:entered', [
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
            || preg_match(SocialContext::SERVICE_ID_PATTERN, $channelId) !== 1
        ) {
            $this->ctx->send($clientId, Message::create('map:error', ['code' => 400, 'message' => 'payload mapId/channelId 非法'], $msg->requestId));

            return;
        }

        // ② 读旧 loc：新旧不同 → leaveGroup 旧频道组
        $loc = $this->ctx->session($clientId)['loc'] ?? null;
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
        $this->ctx->send($clientId, Message::create('map:joined', ['mapId' => $mapId, 'channelId' => $channelId], $msg->requestId));
    }

    /**
     * 帮派语义（ADR-015 §1.9 最小面 + R3 正式化面），委托 GuildResponder。
     * The guild semantics (the ADR-015 §1.9 minimal surface plus the R3 formalized surface), delegated to GuildResponder.
     */
    public function handleGuild(string $clientId, string $uid, Message $msg): void
    {
        $this->guildResponder->handle($clientId, $uid, $msg);
    }

    /**
     * 好友五语义（R3 社交批），委托 FriendResponder。
     * The five friend semantics (the R3 social batch), delegated to FriendResponder.
     */
    public function handleFriend(string $clientId, string $uid, Message $msg): void
    {
        $this->friendResponder->handle($clientId, $uid, $msg);
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
}
