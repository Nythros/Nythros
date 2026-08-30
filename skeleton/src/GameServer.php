<?php

declare(strict_types=1);

namespace Nythros\Skeleton;

use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\RealtimeServer;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\Message;
use Nythros\Skeleton\Actor\PlayerActor;
use Nythros\Skeleton\Actor\WanderingNpc;

/**
 * 游戏服务器本体（入门套件核心）：认证（uid 直通）/移动/清理 + NPC 出生与巡游。
 * 服务器运行时骨架继承 framework 的 RealtimeServer——连接生命周期、消息分发模板、慢客户端与帧末批量发送、
 * 视野统一广播路径（GridAOI 与 UniversalAOI 零分支）与清理模板都在基类，这里只写游戏逻辑。
 *
 * Game server body (the starter-kit core): auth (uid passthrough) / move / cleanup + NPC spawning and wandering.
 * The server runtime skeleton is inherited from the framework's RealtimeServer — connection lifecycle, the message
 * dispatch template, slow-client handling with frame-end batching, the unified view path (GridAOI and UniversalAOI
 * share one code path) and the cleanup template all live in the base; this class only writes game logic.
 */
final class GameServer extends RealtimeServer
{
    /** 世界 tick 间隔（秒）：50ms 一帧，驱动时钟与 world 更新。World tick interval in seconds: 50ms per frame. */
    private const TICK_INTERVAL_SECONDS = 0.05;

    /** @var list<array{id: string, typeId: string, x: int, y: int}> NPC 出生描述 NPC spawn descriptions. */
    private readonly array $npcSeeds;

    /** @var array<string, ActorInterface> entityId => Actor（玩家与 NPC 都登记） entityId => actor (players and NPCs are both registered). */
    private array $actors = [];

    /**
     * @param ServerInterface $server WebSocket 服务器 WebSocket server.
     * @param BatchSerializerInterface $serializer 批量序列化器（JSON） Batch serializer (JSON).
     * @param WorldInterface $world 世界（实体/视野/Actor 门面） World facade (entities/view/actors).
     * @param list<array{id: string, typeId: string, x: int, y: int}> $npcSeeds NPC 出生描述 NPC spawn descriptions.
     * @param ?ClockInterface $clock 世界帧时钟；与 $timer 同时注入时启动 50ms 世界 tick World frame clock; with $timer injects it starts the 50ms world tick.
     * @param ?TimerInterface $timer 定时器；缺省 null = 不启动世界 tick（纯消息模式） Timer; default null = no world tick (message-only mode).
     */
    public function __construct(
        ServerInterface $server,
        BatchSerializerInterface $serializer,
        WorldInterface $world,
        array $npcSeeds,
        private readonly ?ClockInterface $clock = null,
        private readonly ?TimerInterface $timer = null,
    ) {
        parent::__construct($server, $serializer, $world, new ConnectionRegistry());
        $this->npcSeeds = $npcSeeds;
    }

    /** worker 启动：出生 NPC + 50ms 世界 tick（时钟推进 → world 更新 → 帧末 flushOutbox：事件总线 flush + 批量发送）。 Worker start: spawn NPCs + the 50ms world tick (clock advance → world update → frame-end flushOutbox: event-bus flush + batch send). */
    protected function onStart(): void
    {
        $this->spawnNpcs();

        $clock = $this->clock;
        $timer = $this->timer;
        if ($timer !== null && $clock !== null) {
            $timer->add(self::TICK_INTERVAL_SECONDS, function () use ($clock): void {
                $clock->tick();
                $this->world->update();
                $this->flushOutbox();
            }, true);
        }
    }

    /** 已认证路由：move / ping，其余 404。 Authenticated routing: move / ping, anything else 404. */
    protected function handleAuthenticated(ConnectionInterface $conn, Message $message): void
    {
        switch ($message->type) {
            case 'move':
                $this->handleMove($conn, $message);

                return;
            case 'ping':
                $this->send($conn, Message::create('pong', [], $message->requestId));

                return;
        }

        $this->unknownType($conn, $message);
    }

    /**
     * 认证：uid 直通（入门套件不带 token/Redis——生产请走引擎 Security：TokenManager + RedisTokenStore）。
     * 挂载实体 + PlayerActor + 双向映射（mountPlayer）；随后补发 NPC 快照（新客户端立刻知道世界里有谁）。
     * Auth: uid passthrough (the kit ships no token/Redis — production uses the engine Security tier:
     * TokenManager + RedisTokenStore). Mounts the entity + a PlayerActor + the bidirectional mapping (mountPlayer);
     * then back-fills the NPC snapshot so the new client immediately knows who is in the world.
     */
    protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void
    {
        $uid = $message->payload['uid'] ?? null;
        if (!is_string($uid) || $uid === '') {
            $this->send($conn, Message::create('error', ['code' => 400, 'message' => 'payload 缺少 uid 字段'], $message->requestId));
            $conn->close();

            return;
        }

        // entityId = uid@connectionId：同账号多开互不干扰，断连清理互不影响
        // entityId = uid@connectionId: multiple logins of the same account stay independent; disconnect cleanup never collides
        $entityId = sprintf('%s@%s', $uid, $conn->getId());
        $entity = new BaseEntity($entityId, new Position(0, 0));
        $this->entityManager->add($entity);
        $this->aoi->updateEntity($entity);

        $actor = new PlayerActor();
        $actor->bindEntity($entity);
        $actor->attachConnection($conn->getId(), $uid);
        $this->actors[$entityId] = $actor;
        $this->mountPlayer($conn, $entity, $actor);

        $this->send($conn, Message::create('auth_ok', ['id' => $entityId], $message->requestId));

        // NPC 快照：AOI 型 World 的视野进入事件在后续帧自然补发，全量型 World 无事件——
        // 快照是两类 World 共用的「世界现状」下发途径（frame 类型不同避免语义混淆：spawned = 出生，enter = 可见）。
        // NPC snapshot: for AOI Worlds the view-enter events naturally follow in later frames; full-broadcast
        // Worlds have no such events — the snapshot is the shared "current world state" delivery (frame types stay
        // distinct: spawned = birth, enter = visibility).
        foreach ($this->npcSeeds as $seed) {
            $npcEntity = $this->entityManager->get($seed['id']);
            if ($npcEntity === null) {
                continue;
            }
            $this->send($conn, Message::create('npc:spawned', [
                'id' => $seed['id'],
                'typeId' => $seed['typeId'],
                'position' => $npcEntity->getPosition(),
            ]));
        }
    }

    /** 实体清理后钩子：玩家/NPC Actor 从 actorSystem 与登记表摘除。 Post-entity-cleanup hook: removes the player/NPC actor from the actor system and the registry table. */
    protected function onEntityCleanedUp(ConnectionInterface $conn, string $entityId): void
    {
        if (isset($this->actors[$entityId])) {
            $this->actorSystem->remove($this->actors[$entityId]);
            unset($this->actors[$entityId]);
        }
    }

    // ── NPC ──

    /** 出生 NPC（worker 启动后执行；无连接时的出生通知在客户端 auth 时以快照补发）。 NPC spawning (after the worker starts; spawn notices before any client connects are back-filled by the auth snapshot). */
    private function spawnNpcs(): void
    {
        foreach ($this->npcSeeds as $seed) {
            $entity = new BaseEntity($seed['id'], new Position($seed['x'], $seed['y']));
            $this->entityManager->add($entity);
            $this->aoi->updateEntity($entity);

            $npc = new WanderingNpc(
                $seed['id'],
                function (string $id, array $position): void {
                    $this->broadcastNpcMoved($id, $position);
                },
                patrolAnchor: ['x' => $seed['x'], 'y' => $seed['y']],
                patrolRadius: 8,
            );
            $npc->bindEntity($entity);
            $this->world->getActorSystem()->add($npc);
            $this->actors[$seed['id']] = $npc;
        }
    }

    /** NPC 移动广播：走与玩家 move 相同的视野路径。 NPC move broadcast: the same view path as player moves. */
    private function broadcastNpcMoved(string $npcId, array $position): void
    {
        $entity = $this->entityManager->get($npcId);
        if ($entity === null) {
            return;
        }
        $this->broadcastToView($entity, 'entity_moved', [
            'id' => $npcId,
            'position' => $position,
        ]);
    }
}
