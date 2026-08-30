<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Contracts\WorldInterface;
use Nythros\Framework\Leaderboard\LeaderboardStoreInterface;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\RealtimeServer;
use Nythros\Framework\Social\ConnectionHubInterface;
use Nythros\Framework\Social\InMemoryConnectionHub;
use Nythros\Framework\Social\SocialService;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\Message;

/**
 * 社交运行时入口（ADR-021：gateway-worker 移除后的自研单栈承载）：继承 framework RealtimeServer 骨架
 * （解码/认证态路由/异常兜底/清理模板），只写社交路由——帧到 SocialService 的 handleAuth/handleTokenAuth/
 * handleChat/handleTeam/handleMapEnter/handleMapJoin/handleGuild/handleFriend 分发，leaderboard:top/rank
 * 查询帧经可选注入的 LeaderboardStoreInterface 就地应答；连接生命周期与 ConnectionHub 索引同步。
 * gateway/chat/team 三角色共用本类、以 --service 区分部署身份，各角色进程连接表独立（对称直连）；
 * chat/team 角色声明 token 消费 scope（auth 帧带 token → handleTokenAuth），gateway 角色走完整握手签发新 token。
 * Social runtime entry (ADR-021: the self-built single stack after removing gateway-worker): inherits the framework
 * RealtimeServer skeleton (decode/auth-state routing/exception fallback/cleanup template) and only writes social
 * routing — frames dispatch to SocialService's handleAuth/handleTokenAuth/handleChat/handleTeam/handleMapEnter/
 * handleMapJoin/handleGuild/handleFriend, while the leaderboard:top/rank query frames are answered in place through
 * an optionally injected LeaderboardStoreInterface; connection lifecycle stays in sync with the ConnectionHub indexes.
 * The gateway/chat/team roles share this class and differ only by their --service deployment identity, each with an
 * independent per-role connection table (symmetric direct connections); the chat/team roles declare their consumed
 * token scope (an auth frame carrying a token routes to handleTokenAuth), while the gateway role keeps the full
 * handshake issuing fresh tokens.
 */
final class SocialServer extends RealtimeServer
{
    /**
     * 组装社交服务依赖：网络服务、序列化、空 World（骨架构造依赖，社交层无实体/AOI 消费）、连接注册表、
     * 社交业务与进程内连接注册表；并追加 hub 索引的连接生命周期回调。
     * （$hub 取具体类 InMemoryConnectionHub：attach/detach 是连接生命周期能力，不属于 SocialService 消费的
     * ConnectionHubInterface 业务面。）
     * Wires the social dependencies: networking, serializer, an empty World (a skeleton constructor dependency — the
     * social tier consumes no entities/AOI), the connection registry, the social business core and the in-process
     * connection hub; also appends the connection-lifecycle callbacks keeping the hub indexes in sync.
     * ($hub takes the concrete InMemoryConnectionHub: attach/detach are connection-lifecycle capabilities, not part of
     * the ConnectionHubInterface business surface consumed by SocialService.)
     *
     * @param ?string $tokenAuthScope 本角色 token 消费登录的授权域（ADR-021 §3.2 多 scope 兑现）：chat 角色传 'chat'、
     *                                team 角色传 'team'——auth 帧携带 token 字段时走 SocialService::handleTokenAuth
     *                                消费该 scope；null（gateway 角色/缺省）= 不启用 token 路径，auth 帧一律完整握手。
     *                                The scope this role consumes for token login (fulfilling ADR-021 §3.2's multi-scope
     *                                promise): 'chat' for the chat role, 'team' for the team role — an auth frame carrying
     *                                a token field routes to SocialService::handleTokenAuth consuming that scope;
     *                                null (the gateway role / default) disables the token path, so every auth frame takes
     *                                the full handshake.
     */
    public function __construct(
        ServerInterface $server,
        BatchSerializerInterface $serializer,
        WorldInterface $world,
        ConnectionRegistry $registry,
        private readonly SocialService $social,
        private readonly InMemoryConnectionHub $hub,
        private readonly ?string $tokenAuthScope = null,
        private readonly ?LeaderboardStoreInterface $leaderboard = null,
    ) {
        parent::__construct($server, $serializer, $world, $registry);

        // 连接建立登记进 hub（sendToAll 广播全集来源）；断开兜底摘除全部索引——未认证连接不走
        // closeConnection 清理模板，必须在此对齐 gateway-worker 的自动解绑承诺（detach 幂等）
        // Connections register into the hub on establishment (the sendToAll broadcast universe); on close every index
        // is removed as a fallback — unauthenticated connections never reach the closeConnection cleanup template, so
        // this aligns gateway-worker's auto-unbind promise here (detach is idempotent)
        $server->onConnect(function (ConnectionInterface $conn): void {
            $this->hub->attachConnection($conn->getId());
        });
        $server->onClose(function (ConnectionInterface $conn): void {
            $this->hub->detachConnection($conn->getId());
        });
    }

    /**
     * 认证握手：payload 携带 token 字段且本角色声明了消费 scope → 委托 SocialService::handleTokenAuth
     * （chat/team 角色 token 消费登录）；否则委托完整握手 SocialService::handleAuth（gateway 角色签发多 scope
     * token）。成功时其内部已 bindUid + setSession；以 hub 会话中的 uid 判定成功并挂 registry 认证态
     * （entityId = uid），后续路由与关闭清理都经它取回。
     * Auth handshake: an auth frame carrying a token field on a role that declares its consumed scope delegates to
     * SocialService::handleTokenAuth (the chat/team roles' token-consume login); otherwise it delegates to the full
     * handshake SocialService::handleAuth (the gateway role issues the multi-scope token). On success the callee has
     * already boundUid + setSession; success is judged by the uid in the hub session and mounts the registry auth state
     * (entityId = uid), which later routing and close cleanup read back.
     */
    protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void
    {
        if ($this->tokenAuthScope !== null && array_key_exists('token', $message->payload)
            && is_string($message->payload['token']) && $message->payload['token'] !== ''
        ) {
            $this->social->handleTokenAuth($conn->getId(), $message, $this->tokenAuthScope);
        } else {
            $this->social->handleAuth($conn->getId(), $message);
        }

        $uid = $this->hub->getSession($conn->getId())['uid'] ?? null;
        if (is_string($uid)) {
            $this->registry->attach($conn->getId(), $uid);
        }
    }

    /** 已认证路由：chat:send / team:* / map:enter / map:join / guild:* / friend:* / leaderboard:top|rank，其余 404。 Authenticated routing: chat:send / team:* / map:enter / map:join / guild:* / friend:* / leaderboard:top|rank, anything else 404. */
    protected function handleAuthenticated(ConnectionInterface $conn, Message $message): void
    {
        $uid = (string) $this->registry->getEntityId($conn->getId());

        switch ($message->type) {
            case 'chat:send':
                $this->social->handleChat($conn->getId(), $uid, $message);

                return;
            case 'team:invite':
            case 'team:accept':
            case 'team:reject':
            case 'team:leave':
            case 'team:disband':
                $this->social->handleTeam($conn->getId(), $uid, $message);

                return;
            case 'map:enter':
                $this->social->handleMapEnter($conn->getId(), $uid, $message);

                return;
            case 'map:join':
                $this->social->handleMapJoin($conn->getId(), $uid, $message);

                return;
            case 'guild:create':
            case 'guild:disband':
            case 'guild:kick':
            case 'guild:promote':
            case 'guild:notice':
            case 'guild:apply':
            case 'guild:approve':
            case 'guild:join':
            case 'guild:leave':
                $this->social->handleGuild($conn->getId(), $uid, $message);

                return;
            case 'friend:apply':
            case 'friend:accept':
            case 'friend:reject':
            case 'friend:remove':
            case 'friend:list':
                $this->social->handleFriend($conn->getId(), $uid, $message);

                return;
            case 'leaderboard:top':
            case 'leaderboard:rank':
                $this->handleLeaderboard($conn, $uid, $message);

                return;
        }

        $this->unknownType($conn, $message);
    }

    /**
     * 排行榜查询帧（R3 社交批）：leaderboard:top → leaderboard:rows（平行列表 ranks/uids/scores）；
     * leaderboard:rank → leaderboard:ranked（未上榜 rank 为 null）。未装配存储时 501。
     * 下行统一经 ConnectionHub 单帧投递（社交层线约定，与 SocialService 回执同口径），不走骨架批量信封。
     * Leaderboard query frames (the R3 social batch): leaderboard:top → leaderboard:rows (parallel
     * ranks/uids/scores lists); leaderboard:rank → leaderboard:ranked (a null rank when unranked). Without a wired
     * store everything answers 501. Downstream always rides the ConnectionHub as a single frame (the social-tier
     * wire convention, same as SocialService receipts) — never the skeleton's batch envelope.
     */
    private function handleLeaderboard(ConnectionInterface $conn, string $uid, Message $message): void
    {
        if ($this->leaderboard === null) {
            $this->replyViaHub($conn, Message::create('error', ['code' => 501, 'message' => 'leaderboard not wired'], $message->requestId));

            return;
        }

        $boardId = $message->payload['boardId'] ?? null;
        if (!is_string($boardId) || $boardId === '') {
            $this->replyViaHub($conn, Message::create('error', ['code' => 400, 'message' => 'payload 缺少 boardId 字段'], $message->requestId));

            return;
        }

        if ($message->type === 'leaderboard:top') {
            $n = $message->payload['n'] ?? null;
            $offset = $message->payload['offset'] ?? 0;
            if (!is_int($n) || $n < 1 || !is_int($offset) || $offset < 0) {
                $this->replyViaHub($conn, Message::create('error', ['code' => 400, 'message' => 'payload n 必须为正整数且 offset 非负'], $message->requestId));

                return;
            }

            $ranks = [];
            $uids = [];
            $scores = [];
            foreach ($this->leaderboard->top($boardId, $n, $offset) as $row) {
                $ranks[] = $row['rank'];
                $uids[] = $row['uid'];
                $scores[] = $row['score'];
            }
            $this->replyViaHub($conn, Message::create('leaderboard:rows', [
                'boardId' => $boardId,
                'ranks' => $ranks,
                'uids' => $uids,
                'scores' => $scores,
            ], $message->requestId));

            return;
        }

        // leaderboard:rank：单 uid 排名查询（本人）
        // leaderboard:rank: a single uid's ranking (oneself)
        $ranked = $this->leaderboard->rankOf($boardId, $uid);
        $this->replyViaHub($conn, Message::create('leaderboard:ranked', [
            'boardId' => $boardId,
            'uid' => $uid,
            'rank' => $ranked['rank'] ?? null,
            'score' => $ranked['score'] ?? 0.0,
        ], $message->requestId));
    }

    /**
     * 经 hub 的单帧直发（社交层下行约定；与 RealtimeServer::send 的批量信封区分）。
     * Hub-routed single-frame reply (the social-tier downstream convention; distinct from RealtimeServer::send's batch envelope).
     */
    private function replyViaHub(ConnectionInterface $conn, Message $message): void
    {
        $this->hub->sendToClient($conn->getId(), $this->serializer->encode($message)->bytes());
    }

    /**
     * 未认证兜底：任何非 auth 帧 404 宽容不关闭（对齐原 Events 薄壳行为——社交层无 move 语义，
     * 不沿用骨架「move 401 断开」缺省）。
     * Guest fallback: any non-auth frame gets a tolerated 404 without closing (aligned with the former Events shell —
     * the social tier has no move semantics, so the skeleton's "move → 401 + close" default does not apply).
     */
    protected function handleGuestFallback(ConnectionInterface $conn, Message $message): void
    {
        $this->unknownType($conn, $message);
    }

    /**
     * 已认证连接清理后钩子：写掉线标记（ADR-015 §1.8）。hub 索引已由 onClose 兜底回调摘除。
     * Post-cleanup hook for authenticated connections: writes the offline marker (ADR-015 §1.8). Hub indexes were
     * already removed by the onClose fallback callback.
     */
    protected function onEntityCleanedUp(ConnectionInterface $conn, string $entityId): void
    {
        // entityId = uid（handleAuthMessage 成功路径挂载）
        // entityId = uid (mounted by handleAuthMessage's success path)
        $this->social->handleClose($entityId);
    }
}
