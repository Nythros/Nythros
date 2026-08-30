<?php

declare(strict_types=1);

namespace Nythros\Framework\Server;

use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\Position;
use Nythros\Network\ConnectionClosedException;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Message;

/**
 * 实时服务器运行时（抽象基类）：把「基于 World 的实时游戏服务器」的通用骨架收拢到这里，
 * 子类只写游戏逻辑（认证协议、消息路由、业务处理）。覆盖：
 * - 连接生命周期：onConnect/onMessage/onClose 挂载、消息分发模板（解码单帧/认证态路由/兜底 400·401·404·500）、
 *   断连清理模板（entity_leave 广播 → AOI/实体管理器摘除 → onEntityCleanedUp 钩子；世界 EM 查空时经可选注入的
 *   跨容器清理回调兜底，ADR-024 §9 V3）；
 * - 出站管道：直接回复 send + 帧末批量发送 flushOutbox（事件总线 flush → 慢客户端软/硬阈值 → FrameMerger drain → sendBatch）；
 * - 视野统一路径：broadcastToView/broadcastEntityEnter/enqueueVisionSnapshot/resyncVision——GridAOI 与 UniversalAOI 零分支；
 * - 通用处理：move 移动广播模板、isNeighbor 视野判定、mountPlayer 挂载帮手、世界视野事件（AOI enter/leave → 帧转发）。
 *
 * Realtime server runtime (abstract base): the common skeleton of a real-time game server built on the World
 * facade, so subclasses only write game logic (auth protocol, message routing, business handling). Covers:
 * - Connection lifecycle: onConnect/onMessage/onClose mounting, a message-dispatch template (single-frame decode /
 *   authenticated-state routing / 400·401·404·500 fallbacks), and a disconnect-cleanup template (entity_leave
 *   broadcast → AOI/entity-manager removal → onEntityCleanedUp hook; when the world EM lookup misses, the optionally
 *   injected cross-container cleanup callback takes over, ADR-024 §9 V3);
 * - Outbound pipeline: direct replies via send plus the frame-end batch flushOutbox (event-bus flush → slow-client
 *   soft/hard thresholds → FrameMerger drain → sendBatch);
 * - Unified view path: broadcastToView/broadcastEntityEnter/enqueueVisionSnapshot/resyncVision — GridAOI and
 *   UniversalAOI share one path with no branches;
 * - Generic handlers: the move-broadcast template, isNeighbor view checks, the mountPlayer helper, and world
 *   view-event forwarding (AOI enter/leave → frames).
 */
abstract class RealtimeServer
{
    protected readonly EntityManagerInterface $entityManager;

    protected readonly ActorSystemInterface $actorSystem;

    /** @var AOIProviderInterface 视野提供者：GridAOI 或 UniversalAOI，恒非空（全量广播 = 全世界即视野） View provider: GridAOI or UniversalAOI; never null. */
    protected readonly AOIProviderInterface $aoi;

    protected readonly FrameMerger $frameMerger;

    /** @var array<string, ConnectionInterface> connectionId => 连接对象 connectionId => connection object */
    protected array $connections = [];

    /**
     * @var null|callable(string $entityId): bool 跨容器断连清理回调（ADR-024 §9 V3）：世界 EM 查空时兜底调用
     * （实体可能经 transfer 进了房间），返回是否实际摘除；缺省 null = 行为与旧模板完全一致（查空即静默跳过）。
     * The cross-container disconnect-cleanup callback (ADR-024 §9 V3): invoked as a fallback when the world EM
     * lookup misses (the entity may have transferred into a room); returns whether an eviction actually happened.
     * Default null = behavior identical to the legacy template (a miss is silently skipped).
     */
    private $crossContainerCleanup = null;

    /**
     * @var null|MovementValidator 移动校验器（R3 反作弊基线，可选注入）：缺省 null = handleMove 行为逐字节不变；
     * 注入后在实体坐标变更前做 O(1) 校验，失败回 403 error 帧（对齐既有错误回执风格，不静默吞帧）。
     * protected：房间容器化移动路径（MapServer::handleMoveRouted 房内分支）复用同一实例——阈值与窗口状态
     * 单一来源，勿另建第二校验器（R4 债务关闭）。
     * The movement validator (the R3 anti-cheat baseline, optional injection): default null = handleMove behavior
     * byte-for-byte unchanged; when injected, an O(1) validation runs before the coordinate mutation and a failure
     * replies a 403 error frame (aligned with the existing error-receipt style, never silently swallowed).
     * protected: the room-containerized move path (MapServer::handleMoveRouted's in-room branch) reuses this same
     * instance — thresholds and window state keep a single source of truth, never a second validator (the R4 debt closed).
     */
    protected ?MovementValidator $movementValidator = null;

    /**
     * @param ServerInterface $server WebSocket 服务器 WebSocket server.
     * @param BatchSerializerInterface $serializer 批量序列化器（请求解码/响应编码） Batch serializer (request decode / response encode).
     * @param WorldInterface $world 世界门面（实体/AOI/Actor/事件总线/调度） World facade (entities/AOI/actors/event bus/scheduler).
     * @param ConnectionRegistry $registry 连接-实体双向映射 Connection-entity bidirectional mapping.
     * @param int $sendBufferSoftLimitBytes 慢客户端发送缓冲软阈值（字节）；到达后本帧低优先级帧被过滤 Soft send-buffer backlog threshold in bytes; once reached, this frame's low-priority frames are shed.
     * @param int $sendBufferHardLimitBytes 慢客户端发送缓冲硬阈值（字节）；到达后直接断开 Hard send-buffer backlog threshold in bytes; the connection is closed when reached.
     * @param int $maxFrameBytesPerConnection 单帧字节配额（每连接每帧） Per-connection per-frame byte quota.
     */
    public function __construct(
        protected readonly ServerInterface $server,
        protected readonly BatchSerializerInterface $serializer,
        protected readonly WorldInterface $world,
        protected readonly ConnectionRegistry $registry,
        protected readonly int $sendBufferSoftLimitBytes = 2 * 1024 * 1024,
        protected readonly int $sendBufferHardLimitBytes = 10 * 1024 * 1024,
        protected readonly int $maxFrameBytesPerConnection = 512 * 1024,
    ) {
        $this->frameMerger = new FrameMerger($serializer);
        $this->entityManager = $world->getEntityManager();
        $this->actorSystem = $world->getActorSystem();
        $this->aoi = $world->getAOI();
    }

    // ── 生命周期模板 ──

    /**
     * 注册事件处理器（不触发 runAll）：单进程多服务组装时先 register 再统一启动。
     * Registers event handlers without triggering runAll: multi-service assemblies call register() first and start once later.
     */
    final public function register(): void
    {
        $this->server->onWorkerStart(function (): void {
            $this->onStart();
        });

        $this->server->onWorkerStop(function (): void {
            $this->onStop();
        });

        $this->server->onConnect(function (ConnectionInterface $conn): void {
            $this->connections[$conn->getId()] = $conn;
        });

        $this->server->onMessage(function (ConnectionInterface $conn, string $data): void {
            $this->dispatch($conn, $data);
        });

        $this->server->onClose(function (ConnectionInterface $conn): void {
            $this->closeConnection($conn);
        });

        // 世界视野事件：信封由 world->update 发布入队、帧末 flushOutbox 内事件总线 flush 分发 → 转成帧
        // World view events: envelopes are published by world->update, dispatched by the event-bus flush inside
        // the frame-end flushOutbox → forwarded as frames
        $this->world->getEventBus()->subscribe(EventEnvelope::TYPE_AOI_ENTER, $this->handleAoiEnter(...));
        $this->world->getEventBus()->subscribe(EventEnvelope::TYPE_AOI_LEAVE, $this->handleAoiLeave(...));
    }

    /** 启动服务器：注册处理器后进入阻塞事件循环。 Starts the server: registers handlers, then enters the blocking event loop. */
    final public function start(): void
    {
        $this->register();
        $this->server->start();
    }

    /** worker 启动钩子（注册/心跳/世界 tick/周期任务）。 Worker-start hook (registration/heartbeat/world tick/periodic tasks). */
    protected function onStart(): void
    {
    }

    /** worker 退出钩子（注销/落库兜底）。 Worker-stop hook (unregistration/persistence backstop). */
    protected function onStop(): void
    {
    }

    /** 帧末 flush 前置钩子：默认冲刷世界事件总线（分发视野信封）。 Frame-end flush pre-hook: the default flushes the world event bus (dispatching view envelopes). */
    protected function beforeFlush(): void
    {
        $this->world->getEventBus()->flush();
    }

    // ── 消息分发模板 ──

    /**
     * 消息分发兜底：捕获一切异常记日志，并尽力回一个 500 error 帧（发送失败只记日志不抛出）。
     * Dispatch fallback: catches any throwable, logs it, and best-effort replies a 500 error frame (send failures are logged only, never re-thrown).
     */
    final public function dispatch(ConnectionInterface $conn, string $data): void
    {
        try {
            $this->dispatchSafe($conn, $data);
        } catch (\Throwable $e) {
            error_log(sprintf('[%s] dispatch failed: %s', static::class, $e->getMessage()));

            try {
                $this->send($conn, Message::create('error', ['code' => 500, 'message' => 'internal error']));
            } catch (\Throwable $inner) {
                error_log(sprintf('[%s] failed to send error frame: %s', static::class, $inner->getMessage()));
            }
        }
    }

    /**
     * 按认证状态路由消息：解码失败 400；未认证只接受认证消息（auth），其余按兜底策略（move 401 断开、未知 404）。
     * Routes by auth state: 400 on decode failure; unauthenticated connections may only send the auth message —
     * everything else follows the fallback policy (move → 401 + close, unknown → 404).
     */
    private function dispatchSafe(ConnectionInterface $conn, string $data): void
    {
        try {
            // 客户端请求以「批量包含 1 帧」的格式发送（与服务端一致性：出站是批量包、入站也按批量包解）
            // Client requests travel as a batch packet holding exactly one frame (consistent with outbound batches)
            $messages = $this->serializer->decodeBatch($data);
            if (count($messages) !== 1) {
                throw new DecodeException('请求包必须恰好包含 1 帧。A request packet must contain exactly one frame.');
            }
            $message = $messages[0];
        } catch (DecodeException $e) {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => $e->getMessage()]));

            return;
        }

        if ($this->registry->has($conn->getId())) {
            $this->handleAuthenticated($conn, $message);

            return;
        }

        if ($this->isAuthMessage($message)) {
            $this->handleAuthMessage($conn, $message);

            return;
        }

        $this->handleGuestFallback($conn, $message);
    }

    /** 已认证消息路由：子类按 type 分发业务处理（未知类型经 unknownType 给 404）。 Authenticated routing: subclasses dispatch by type (unknown types get 404 via unknownType). */
    abstract protected function handleAuthenticated(ConnectionInterface $conn, Message $message): void;

    /** 认证判定：缺省 auth 帧即认证消息。 Auth-message discrimination: defaults to the auth frame. */
    protected function isAuthMessage(Message $message): bool
    {
        return $message->type === 'auth';
    }

    /** 认证消息处理：子类实现握手（token/uid 校验、实体挂载、auth_ok 回执）。 Auth-message handling: subclasses implement the handshake (token/uid checks, entity mount, auth_ok reply). */
    abstract protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void;

    /**
     * 未认证兜底：缺省 move 401 并断开、其余 404（宽容不关闭）。
     * Guest fallback: the default answers move with 401 + close, everything else 404 (tolerated, no close).
     */
    protected function handleGuestFallback(ConnectionInterface $conn, Message $message): void
    {
        if ($message->type === 'move') {
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        $this->unknownType($conn, $message);
    }

    /** 未知类型回执：404 不关闭。 Unknown-type reply: 404 without closing. */
    final protected function unknownType(ConnectionInterface $conn, Message $message): void
    {
        $this->send($conn, Message::create('error', [
            'code' => 404,
            'message' => sprintf('unknown type: %s', $message->type),
        ], $message->requestId));
    }

    // ── 发送 ──

    /** 直接回复：单条消息编码为「批量含 1 帧」的包立即写入（认证回执/错误帧）。 Direct reply: one message as a one-frame batch written immediately (auth receipts / error frames). */
    final protected function send(ConnectionInterface $conn, Message $message): void
    {
        $conn->send($this->serializer->encodeBatch([$message]));
    }

    /**
     * 入 outbox：帧末 flushOutbox 统一批量发送。
     * Enqueues into the outbox, batch-sent at the frame-end flushOutbox.
     *
     * @param array<string|int, mixed> $payload 帧负载 Frame payload.
     */
    final protected function enqueue(ConnectionInterface $conn, string $type, array $payload): void
    {
        $this->frameMerger->enqueue($conn, $type, $payload);
    }

    /**
     * 定向发送：经 registry 反查 entityId 对应连接并入 outbox（帧末批量发送）。
     * Directed send: resolves the connection of an entityId via the registry and enqueues (batch-sent at frame end).
     *
     * @param array<string|int, mixed> $payload 帧负载 Frame payload.
     */
    final public function sendToEntity(string $entityId, string $type, array $payload): void
    {
        $connId = $this->registry->getConnectionId($entityId);
        if ($connId === null) {
            return;
        }

        $conn = $this->connections[$connId] ?? null;
        if ($conn === null) {
            return;
        }

        $this->frameMerger->enqueue($conn, $type, $payload);
    }

    // ── 视野统一路径（GridAOI 与 UniversalAOI 零分支） ──
    // Unified view path (GridAOI and UniversalAOI share one code path)

    /**
     * 视野广播：向 center 视野内全部实体对应连接入 outbox（帧末批量发送）。
     * 视野来源 = AOI 查询：GridAOI 给九宫格邻居，UniversalAOI（全量广播）给全世界。
     * skipSelf 语义分叉：状态帧（entity_moved，客户端只看他人）默认跳过自身；事件帧（combat:hit /
     * monster:spawned 等以视野中心实体为通告对象）需包含自身——query 含自身（与 GridAOI 口径一致），
     * 由调用方按帧类别选择。
     * View broadcast: enqueues one frame to the connections of every entity inside center's view (batch-sent at frame end).
     * The view comes from the AOI query: GridAOI yields the 3x3 neighbors, UniversalAOI (full broadcast) yields the whole world.
     * skipSelf semantics split: STATE frames (entity_moved — clients watch others only) default to skipping self;
     * EVENT frames (combat:hit / monster:spawned, which announce to the center entity itself) must include self —
     * the query includes self (consistent with GridAOI); callers pick per frame kind.
     *
     * @param array<string, mixed> $payload 帧负载 Frame payload.
     * @param bool $skipSelf 是否跳过中心实体自己的连接（状态帧 true；事件帧 false） Whether to skip the center entity's own connection (true for STATE frames; false for EVENT frames).
     */
    final protected function broadcastToView(EntityInterface $center, string $type, array $payload, bool $skipSelf = true): void
    {
        foreach ($this->aoi->query($center) as $other) {
            if ($skipSelf && $other->getId() === $center->getId()) {
                continue;
            }

            $connId = $this->registry->getConnectionId($other->getId());
            if ($connId === null) {
                continue;
            }

            $conn = $this->connections[$connId] ?? null;
            if ($conn === null) {
                continue;
            }

            $this->frameMerger->enqueue($conn, $type, $payload);
        }
    }

    /**
     * 向给定邻居连接广播「sourceId 进入视野」entity_enter 帧（入 outbox，帧末批量发送）；邻居无连接/连接已摘除静默跳过。
     * Broadcasts entity_enter ("sourceId entered view") to the given neighbor connections (into the outbox, batch-sent
     * at frame end); neighbors without a connection are silently skipped.
     *
     * @param list<EntityInterface> $neighbors 目标邻居实体列表 Target neighbor entity list.
     * @param string $sourceId 进入视野的实体 id The entity id that entered the view.
     * @param array{x: int, y: int} $position 进入实体的坐标 The entering entity's position.
     */
    final protected function broadcastEntityEnter(array $neighbors, string $sourceId, array $position): void
    {
        foreach ($neighbors as $neighbor) {
            $connId = $this->registry->getConnectionId($neighbor->getId());
            if ($connId === null) {
                continue;
            }

            $conn = $this->connections[$connId] ?? null;
            if ($conn === null) {
                continue;
            }

            $this->frameMerger->enqueue($conn, 'entity_enter', [
                'id' => $sourceId,
                'position' => $position,
            ]);
        }
    }

    /**
     * 视野快照入队：以 aoi->query 为权威视野源（GridAOI 九宫格 / UniversalAOI 全世界），把全部邻居的 entity_enter
     * 帧并入 outbox（帧末批量发送）；query 含自身，跳过。供 auth 的 join 快照与 resyncVision 周期重同步共用。
     * Enqueues a vision snapshot: the authoritative view is the aoi->query (GridAOI 3x3 / UniversalAOI whole world),
     * whose entity_enter frames for every neighbor enter the outbox (batch-sent at frame end); query includes self,
     * which is skipped. Shared by the auth join snapshot and the periodic resyncVision.
     */
    final protected function enqueueVisionSnapshot(EntityInterface $entity, ConnectionInterface $conn): void
    {
        foreach ($this->aoi->query($entity) as $neighbor) {
            if ($neighbor->getId() === $entity->getId()) {
                continue;
            }

            $this->frameMerger->enqueue($conn, 'entity_enter', [
                'id' => $neighbor->getId(),
                'position' => $neighbor->getPosition(),
            ]);
        }
    }

    /**
     * 周期视野快照重同步：遍历全部连接，经 registry 过滤已认证者，取实体后重发视野全量快照（入 outbox，帧末批量发送）。
     * Periodic vision-snapshot resync: iterates every connection, filters authenticated ones via the registry, resolves
     * the entity and re-enqueues the full view snapshot (into the outbox, batch-sent at frame end).
     */
    final protected function resyncVision(): void
    {
        foreach ($this->connections as $connId => $conn) {
            if (!$this->registry->has($connId)) {
                continue;
            }

            $entityId = $this->registry->getEntityId($connId);
            if ($entityId === null) {
                continue;
            }

            $entity = $this->entityManager->get($entityId);
            if ($entity === null) {
                continue;
            }

            $this->enqueueVisionSnapshot($entity, $conn);
        }
    }

    /**
     * 目标是否在中心实体视野内（统一走视野查询：GridAOI 查九宫格，UniversalAOI 查全表——目标在视野内即命中）。
     * Whether the target id is inside the center entity's view (a unified view query: GridAOI scans the 3x3, UniversalAOI
     * scans the whole table — a target inside the view hits).
     */
    final protected function isNeighbor(EntityInterface $center, string $targetId): bool
    {
        foreach ($this->aoi->query($center) as $other) {
            if ($other->getId() === $targetId) {
                return true;
            }
        }

        return false;
    }

    // ── 通用处理模板 ──

    /**
     * 移动处理模板：只改实体坐标，不更新 AOI——视野 enter/leave 差分由 World::update 全量刷新统一发布（单一来源）；
     * 随后按新坐标查询视野广播移动帧（跳过自己；无回执，客户端以广播为准）。
     * Move-handling template: only mutates entity coordinates and never updates the AOI — view enter/leave deltas are
     * published exclusively by World::update's full sweep (single source); then broadcasts the move frame across the view
     * queried by the new position (skipping self; no ack — clients rely on the broadcast).
     *
     * @param string $frameType 移动帧类型（缺省 entity_moved） Move frame type (default entity_moved).
     */
    final protected function handleMove(ConnectionInterface $conn, Message $message, string $frameType = 'entity_moved'): void
    {
        $entityId = $this->registry->getEntityId($conn->getId());
        if ($entityId === null) {
            // 注册表无此连接的实体映射：视为未认证，401 后断开（registry 是认证挂载的唯一来源）
            // No entity mapping for this connection: treat as unauthenticated, reply 401 and close (registry is the only source of auth mounting)
            $this->send($conn, Message::create('error', ['code' => 401, 'message' => 'unauthorized'], $message->requestId));
            $conn->close();

            return;
        }

        // dx/dy 严格校验为 int：JSON 解码出的 float/string 会污染坐标，直接 400 拒绝
        // dx/dy must be strictly int: floats or strings decoded from JSON would corrupt coordinates, so reject with 400
        $dx = $message->payload['dx'] ?? 0;
        $dy = $message->payload['dy'] ?? 0;
        if (!is_int($dx) || !is_int($dy)) {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => 'dx/dy 必须是整数'], $message->requestId));

            return;
        }

        // 实体意外缺失（理论不可达）按 500 处理并断开，避免僵尸连接继续发包
        // A missing entity (theoretically unreachable) is treated as 500 and closed, so a zombie connection cannot keep sending
        $entity = $this->entityManager->get($entityId);
        if ($entity === null) {
            $this->send($conn, Message::create('error', ['code' => 500, 'message' => 'entity not found'], $message->requestId));
            $conn->close();

            return;
        }

        // 反作弊钩子（可选注入）：缺省 null 完全跳过；注入后校验失败回 403 并保留实体坐标（拒绝即无副作用）
        // Anti-cheat hook (optional injection): default null skips entirely; on injection a failed validation replies
        // 403 and keeps the entity coordinates untouched (a rejection has no side effects)
        if ($this->movementValidator !== null) {
            $position = $entity->getPosition();
            $reason = $this->movementValidator->validate($entityId, $dx, $dy, $position['x'], $position['y'], microtime(true));
            if ($reason !== null) {
                $this->send($conn, Message::create('error', ['code' => 403, 'message' => sprintf('move rejected: %s', $reason)], $message->requestId));

                return;
            }
        }

        // 先改世界状态再广播：邻居拿到的 position 才是最新值；AOI 索引不动，位置更新交给 World::update 全量刷新。
        // 广播前过子类节流钩子（P9b：热区分频下移动广播跳帧，位置仍照常应用，最终位置由视野重同步/后续移动补发）。
        // Mutate world state before broadcasting so neighbours always see the latest position; the AOI index is untouched
        // here. The broadcast passes the subclass throttle hook first (the P9b: with hot-cell cadence, move broadcasts
        // skip frames while positions still apply; the final position is reconciled by the vision resync or a later move).
        $entity->move($dx, $dy);
        if (!$this->shouldBroadcastMove($entityId)) {
            return;
        }
        $this->broadcastToView($entity, $frameType, [
            'id' => $entityId,
            'position' => $entity->getPosition(),
        ]);
    }

    /**
     * 移动广播节流钩子（P9b）：缺省恒广播；子类可按负载策略跳帧（如热区分频）。
     * The move-broadcast throttle hook (the P9b): broadcasts always by default; subclasses may skip frames
     * per their load policy (e.g. hot-cell cadence).
     */
    protected function shouldBroadcastMove(string $entityId): bool
    {
        return true;
    }

    /**
     * 玩家挂载帮手：注册实体进入实体管理器（坐标原点）、绑定 Actor、登记 ActorSystem、挂 registry 双向映射并标记已认证。
     * 实体/AOI 的登记（与视野差分的时序）由子类自行编排：需要 join 差分的场景先 aoi->updateEntity 再调本方法。
     * Player-mount helper: registers the entity into the entity manager (origin position), binds the actor, registers the
     * ActorSystem, attaches the registry bidirectional mapping and marks the connection authenticated. Entity/AOI
     * registration order (with the vision delta) is orchestrated by subclasses: join-delta scenarios call aoi->updateEntity first.
     */
    final protected function mountPlayer(ConnectionInterface $conn, EntityInterface $entity, ActorInterface $actor): void
    {
        $this->actorSystem->add($actor);
        $this->registry->attach($conn->getId(), $entity->getId());
        $conn->markAuthenticated();
    }

    // ── 清理模板 ──

    /**
     * 注入跨容器断连清理回调（ADR-024 §9 V3）：closeConnection 模板在世界 EM 查空时兜底调用，
     * 吸收「查世界 → 查不到 → 问容器编排」的通用序列；缺省 null 时行为完全不变（向后兼容）。
     * 回调签名 (string $entityId): bool——典型实现为 RoomManagerInterface::evictFromAny 的适配器。
     * Sets the cross-container disconnect-cleanup callback (ADR-024 §9 V3): the closeConnection template invokes
     * it as a fallback when the world EM lookup misses, absorbing the generic "query world → miss → ask container
     * orchestration" sequence; default null keeps behavior completely unchanged (backward compatible).
     * Callback signature (string $entityId): bool — typically an adapter over RoomManagerInterface::evictFromAny.
     */
    public function setCrossContainerCleanup(?callable $cleanup): void
    {
        $this->crossContainerCleanup = $cleanup;
    }

    /**
     * 注入移动校验器（R3 反作弊基线）：handleMove 模板在坐标变更前调用其 O(1) 校验，失败回
     * 403 error 帧（携带 requestId）；缺省 null = 行为与旧模板完全一致（向后兼容）。
     * 世界侧 handleMove 模板与房间容器化移动路径（MapServer::handleMoveRouted 房内分支）共用本实例
     * （R4 债务关闭：阈值与窗口状态单一来源）。
     * Sets the movement validator (the R3 anti-cheat baseline): the handleMove template runs its O(1) validation
     * before the coordinate mutation and replies a 403 error frame (carrying the requestId) on failure; default
     * null = behavior identical to the legacy template (backward compatible). The world-side handleMove template
     * and the room-containerized move path (MapServer::handleMoveRouted's in-room branch) share this same instance
     * (the R4 debt closed: thresholds and window state keep a single source of truth).
     */
    public function setMovementValidator(?MovementValidator $validator): void
    {
        $this->movementValidator = $validator;
    }

    /**
     * 连接清理：先摘 registry 双向映射与连接表，再向视野邻居广播 entity_leave（必须在摘除前查询），
     * 然后摘 AOI 与实体管理器；世界 EM 查空（实体已 transfer 进房间等跨容器场景）时调用可选注入的
     * 跨容器清理回调兜底，不再静默跳过；最后调用 onEntityCleanedUp 钩子（子类清理 actors/类型索引/
     * 持久化/统计——两条路径共用，持久化冲刷由此覆盖）。
     * Connection cleanup: detaches the registry mapping and the connection entry first, broadcasts entity_leave to the
     * view neighbors (the query must run before removal), removes the AOI entry and the entity; when the world EM
     * lookup misses (a cross-container case such as having transferred into a room), the optionally injected
     * cross-container cleanup callback is invoked as the fallback instead of silently skipping; finally the
     * onEntityCleanedUp hook runs (subclass cleanup: actors/type index/persistence/statistics — shared by both paths,
     * so persistence flushing is covered there).
     */
    final protected function closeConnection(ConnectionInterface $conn): void
    {
        $connId = $conn->getId();
        $entityId = $this->registry->detachByConnection($connId);
        unset($this->connections[$connId]);

        if ($entityId === null) {
            return;
        }

        // 反作弊校验窗口随断连清理：窗口行按 entityId 无界增长且无 TTL，不摘除则长驻进程内存泄漏
        // The anti-cheat window is dropped on disconnect: rows grow unbounded by entityId with no TTL and leak in a long-lived process without this
        $this->movementValidator?->forget($entityId);

        $entity = $this->entityManager->get($entityId);
        if ($entity !== null) {
            $this->broadcastToView($entity, 'entity_leave', [
                'id' => $entityId,
                'position' => $entity->getPosition(),
            ]);
            // UniversalAOI 的 remove 为空操作（全量世界无索引）；GridAOI 侧真正摘除
            // UniversalAOI::remove is a no-op (the full world has no index); GridAOI actually removes
            $this->aoi->remove($entity);
            $this->entityManager->remove($entityId);
        } elseif ($this->crossContainerCleanup !== null) {
            ($this->crossContainerCleanup)($entityId);
        }

        $this->onEntityCleanedUp($conn, $entityId);
    }

    /** 实体清理后钩子：子类清理 actors / 类型索引 / 持久化 / 在线统计。 Post-entity-cleanup hook: subclasses clean up actors / type indices / persistence / online stats. */
    protected function onEntityCleanedUp(ConnectionInterface $conn, string $entityId): void
    {
    }

    // ── 世界视野事件 → 帧转发 ──
    // World view events → frame forwarding

    private function handleAoiEnter(EventEnvelope $envelope): void
    {
        $this->handleAoiVisibility($envelope, 'entity_enter');
    }

    private function handleAoiLeave(EventEnvelope $envelope): void
    {
        $this->handleAoiVisibility($envelope, 'entity_leave');
    }

    /**
     * 视野事件公共路径：targetScope 经 registry 反查连接，编码通知帧入 outbox；连接已摘除静默跳过。
     * Shared view-event path: the targetScope is resolved to a connection via the registry and the notification frame
     * is enqueued; connections already removed are silently skipped.
     */
    private function handleAoiVisibility(EventEnvelope $envelope, string $frameType): void
    {
        if ($envelope->targetScope === null) {
            return;
        }

        $targetConnId = $this->registry->getConnectionId($envelope->targetScope);
        if ($targetConnId === null) {
            return;
        }

        $targetConn = $this->connections[$targetConnId] ?? null;
        if ($targetConn === null) {
            return;
        }

        $payload = $this->decorateViewPayload($envelope->source, [
            'id' => $envelope->source,
            'position' => $envelope->payload['position'] ?? null,
        ]);

        $this->frameMerger->enqueue($targetConn, $frameType, $payload);
    }

    /**
     * 视野帧负载装饰钩子：子类可按 source 实体的业务类型附加字段（如掉落物附 itemId）。
     * View-frame payload decoration hook: subclasses may append business fields by the source entity's kind (e.g. drops carry itemId).
     *
     * @param array<string, mixed> $payload 待装饰负载 Payload to decorate.
     * @return array<string, mixed> 装饰后的负载 The decorated payload.
     */
    protected function decorateViewPayload(string $sourceEntityId, array $payload): array
    {
        return $payload;
    }

    // ── 帧末出站冲刷 ──

    /**
     * 帧末出站冲刷：① beforeFlush 前置钩子（缺省冲刷事件总线，分发视野信封 → listener 把通知帧写入 frameMerger），
     * ② 慢客户端策略先行（硬阈值断开、软阈值标记低优先级过滤），③ frameMerger drain 应用软过滤与单帧字节配额后
     * 按连接 sendBatch；对端已断但 close 事件未触达时捕获 ConnectionClosedException 走 closeConnection。
     * Frame-end outbound flush: ① the beforeFlush hook (default: flush the event bus, dispatching view envelopes whose
     * listeners write notification frames into the frameMerger), ② slow-client policy first (hard threshold closes, soft
     * threshold flags low-priority filtering), ③ frameMerger drain applies the soft filter and per-frame byte quota, then
     * sendBatch per connection; a peer that died before its close event arrived is caught by the ConnectionClosedException fallback.
     */
    final protected function flushOutbox(): void
    {
        $this->beforeFlush();

        // 慢客户端策略：硬阈值断开（closeConnection 会向邻居广播 entity_leave，并入本帧 drain），软阈值标记低优先级过滤
        // Slow-client policy: hard threshold closes (closeConnection broadcasts entity_leave to neighbors, included in this frame's drain); soft threshold flags low-priority filtering
        $softFilter = [];
        foreach ($this->connections as $connId => $conn) {
            $buffered = $conn->getSendBufferQueueSize();
            if ($buffered >= $this->sendBufferHardLimitBytes) {
                error_log(sprintf('[%s] send buffer over hard limit [%s]: %d bytes, closing', static::class, $connId, $buffered));
                $this->closeConnection($conn);
                continue;
            }
            if ($buffered >= $this->sendBufferSoftLimitBytes) {
                $softFilter[$connId] = true;
            }
        }

        foreach ($this->frameMerger->drain($this->maxFrameBytesPerConnection, $softFilter) as $connId => $frames) {
            $conn = $this->connections[$connId] ?? null;
            if ($conn === null) {
                continue;
            }

            try {
                $conn->sendBatch($frames);
            } catch (ConnectionClosedException $e) {
                // 对端已断但 close 事件尚未触达：主动 closeConnection 防止残留 registry/实体脏数据
                // Peer died before its close event arrived: clean up eagerly to avoid stale registry/entity entries
                error_log(sprintf('[%s] flush to closed connection [%s]: %s', static::class, $connId, $e->getMessage()));
                $this->closeConnection($conn);
            }
        }
    }
}
