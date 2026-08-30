<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomManagerInterface;
use Nythros\Contracts\RoomState;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Combat\RandomSourceInterface;
use Nythros\Framework\Game\Horde\HordeConfig;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;

/**
 * 房间编排中枢（ADR-024 §D-A/§D-B starter-kit 接线）：房间创建、玩家 transfer 进出、horde 刷怪、
 * AoE 施法与 settle/close 生命周期的 demo 路由层。组装逻辑只在此处（starter-kit 唯一组装点铁律）：
 * 每房间独立 CombatService（以房间自身为 WorldInterface 门面）+ RoomVisionBroadcaster（房间自有 AOI 定视野、
 * 宿主 MapServer 出站管道投递）。请求回执统一为定向 room:ok{op, roomId, count}，失败为定向 error{code, message}。
 * 房间归属权限（R2 review MINOR-6）：settle/close 仅创建者可操作（非创建者 403）；创建者断连/被 evict
 * 标记无主，无主房任意玩家可接管管理（防僵尸房泄漏）。房间成员闸门（reviewer MAJOR-2）：spawn/aoe 仅
 * 房内成员可操作（非成员定向 403）——堵住任意已认证玩家对任意已知 roomId 刷怪/施法的跨房滥用面。
 * Room orchestration hub (ADR-024 §D-A/§D-B starter-kit wiring): the demo routing layer for room creation, player
 * transfer in/out, horde spawning, AoE casts and the settle/close lifecycle. Assembly lives only here (the
 * starter-kit single-assembly-point rule): each room gets its own CombatService (with the room itself as the
 * WorldInterface facade) plus a RoomVisionBroadcaster (vision from the room's own AOI, delivery over the host
 * MapServer's outbound pipeline). Success receipts are directed room:ok{op, roomId, count}; failures are directed error{code, message}.
 * Room-ownership permission (R2 review MINOR-6): settle/close are creator-only operations (403 for others); a
 * creator disconnect/eviction marks the room ownerless, and ownerless rooms accept management takeover from any
 * player (preventing zombie-room leaks). The room-membership gate (reviewer MAJOR-2): spawn/aoe are member-only
 * operations (403 for non-members) — closing the cross-room abuse surface where any authenticated player could
 * flood any known roomId with spawns/casts.
 */
final class RoomHub
{
    /** @var array<string, array{room: RoomInstanceInterface, combat: CombatService}> roomId => 房间上下文 roomId => room context. */
    private array $contexts = [];

    /**
     * 房间归属表（R2 review MINOR-6 债务关闭）：roomId => 创建者 entityId；null = 无主
     * （创建者断连/被 evict 后标记无主——不自动转移：继任者选择是任意的策略问题，horde 房短生命周期
     * 且匹配开房本就无天然房主；无主房允许任意玩家 settle/close 接管，防创建者失联后房间变僵尸泄漏）。
     * Room-ownership table (closing the R2 review MINOR-6 debt): roomId => creator entityId; null = ownerless
     * (marked when the creator disconnects or is evicted — no automatic transfer: picking a successor is an
     * arbitrary policy, horde rooms are short-lived and matching-built rooms have no natural owner anyway;
     * an ownerless room accepts settle/close from any player so a lost creator cannot leak a zombie room).
     *
     * @var array<string, ?string>
     */
    private array $owners = [];

    /** @var int 房间内刷怪序号（保证 monster id 唯一） In-room spawn sequence (keeps monster ids unique). */
    private int $spawnSequence = 0;

    /** horde 玩法参数（R4 类型模块试点）：framework 提供参数与规则，本中枢装配消费；缺省 default() 与迁移前常量逐值一致。 The horde gameplay parameters (the R4 type-module pilot): the framework provides parameters and rules, assembled and consumed by this hub; the default() aligns value-for-value with the pre-migration constants. */
    private readonly HordeConfig $horde;

    private ?MapServer $map = null;

    /**
     * @param RoomManagerInterface $manager 房间管理器（创建/归属校验/到期驱动） The room manager (creation/ownership/due-driven ticking).
     * @param WorldInterface $world 宿主大世界（join 时摘除玩家登记，ADR-024 §4 调用方编排责任） The host world (player registration removal on join, the caller-orchestrated duty per ADR-024 §4).
     * @param SkillRepository $skills 技能注册表（AoE 技能校验） Skill repository (AoE skill validation).
     * @param ItemRepository $items 物品注册表（掉落校验） Item repository (drop validation).
     * @param RandomSourceInterface $random 随机源（伤害浮动/掉落 roll） Random source (damage variance / drop rolls).
     * @param DropTable $dropTable 掉落表（horde 死亡掉落） Drop table (horde death drops).
     * @param EntityTypeIndex $typeIndex 实体类型索引（怪物种类登记，玩家感知依赖） Entity type index (monster kind registration, relied on by perception).
     * @param null|HordeConfig $horde horde 玩法配置（R4 类型模块试点）；缺省 null = HordeConfig::default() The horde gameplay config (the R4 type-module pilot); default null = HordeConfig::default().
     */
    public function __construct(
        private readonly RoomManagerInterface $manager,
        private readonly WorldInterface $world,
        private readonly SkillRepository $skills,
        private readonly ItemRepository $items,
        private readonly RandomSourceInterface $random,
        // 掉落表（P11 玩法数据外置热载）：非 readonly——drops 表 config.changed 时经 replaceDropTable 换入，
        // 之后 spawnWave 构造的新怪物用新表（在场怪物持有旧引用自然耗尽）。
        // The drop table (the P11 hot reload): non-readonly — swapped in via replaceDropTable on a drops
        // config.changed; monsters built by later spawnWave calls use the new table (live ones keep draining the old reference).
        private DropTable $dropTable,
        private readonly EntityTypeIndex $typeIndex,
        ?HordeConfig $horde = null,
    ) {
        $this->horde = $horde ?? HordeConfig::default();
    }

    /**
     * 回填宿主 MapServer（依赖循环规避：MapServer 构造时注入本中枢，中枢随后回填宿主引用，比照 attachCombat）。
     * Back-fills the host map server (circular-dependency avoidance: MapServer receives this hub at construction,
     * then the hub is back-filled with the host reference, mirroring attachCombat).
     */
    public function attach(MapServer $map): void
    {
        $this->map = $map;
    }

    /**
     * 掉落表热载换入（P11 玩法数据外置）：drops 表 config.changed 时由装配层同步本中枢（horde 波次
     * 刷怪的掉落源与 MapServer 同源换新）。
     * Swaps a drop table in for hot reload (the P11 data externalization): the assembly layer syncs this hub on a
     * drops config.changed (the horde wave-spawn drop source renews in step with MapServer's).
     */
    public function replaceDropTable(DropTable $dropTable): void
    {
        $this->dropTable = $dropTable;
    }

    /**
     * 创建房间并装配其战斗上下文：GridAOI 工厂（每房间独立实例）+ 以房间为世界门面的 CombatService；
     * tick 周期/成员上限/掉落寿命取自 horde 配置（R4 类型模块试点：framework 提供参数，starter-kit 装配）。
     * Creates a room and assembles its combat context: a GridAOI factory (independent instance per room) plus a
     * CombatService facing the room as its world; the tick period/member cap/drop lifetime come from the horde
     * config (the R4 type-module pilot: the framework provides parameters, the starter kit assembles).
     *
     * @param string $roomId 房间唯一标识 Unique room id.
     * @param int|null $periodMs 房间 tick 周期覆盖；null = 取 horde 配置 Period override; null = from the horde config.
     * @param int|null $maxMembers 成员上限覆盖；null = 取 horde 配置 Member-cap override; null = from the horde config.
     */
    public function createRoom(string $roomId, ?int $periodMs = null, ?int $maxMembers = null): RoomInstanceInterface
    {
        $room = $this->manager->create(new RoomConfig(
            $roomId,
            $periodMs ?? $this->horde->periodMs,
            $maxMembers ?? $this->horde->maxMembers,
            static fn (): GridAOI => new GridAOI(10),
        ));

        $broadcaster = new RoomVisionBroadcaster($room, $this->requireMap());
        $this->contexts[$roomId] = [
            'room' => $room,
            'combat' => new CombatService(
                $room,
                $broadcaster,
                $this->skills,
                $this->items,
                $this->random,
                $this->requireMap(),
                dropLifetimeSeconds: $this->horde->dropStorm->dropLifetimeSeconds,
            ),
        ];

        return $room;
    }

    /**
     * room:* 请求路由入口（由 MapServer 在已认证态前置分发；entityId 已由宿主经 registry 解析，
     * connId 供 join 成功后的连接容器维度标记使用，ADR-024 §9 V6）。
     * The room:* request routing entry (pre-dispatched by MapServer in the authenticated state; entityId already
     * resolved via the host registry, connId feeds the post-join connection-container marking, ADR-024 §9 V6).
     *
     * @param array<string|int, mixed> $payload 请求负载 Request payload.
     */
    public function handle(string $connId, string $entityId, string $type, array $payload): void
    {
        try {
            match ($type) {
                'room:create' => $this->handleCreate($entityId, $payload),
                // join 的容器维度标记已统一走 admitPlayer 内的 moveEntityToContainer（按 entityId 解析连接），
                // connId 保留在入口签名供路由契约完整
                // join's container marking now rides admitPlayer's moveEntityToContainer uniformly (the connection
                // resolves by entityId); connId stays in the entry signature for a complete routing contract
                'room:join' => $this->handleJoin($entityId, $payload),
                'room:spawn' => $this->handleSpawn($entityId, $payload),
                'room:aoe' => $this->handleAoe($entityId, $payload),
                'room:settle' => $this->handleSettle($entityId, $payload),
                'room:close' => $this->handleClose($entityId, $payload),
                default => $this->replyError($entityId, 404, sprintf('unknown room op: %s', $type)),
            };
        } catch (\OverflowException $e) {
            // 准入上限（P9c）：进程房间数触顶——定向 busy 回执（507，连接不断），客户端可稍后重试或
            // 由路由层避开本进程（registry 指标已暴露 rooms/deferred）。
            // The admission cap (the P9c): the process room count is full — a directed busy receipt (507,
            // connection kept); the client may retry or the routing layer avoids this process (registry
            // metrics already expose rooms/deferred).
            $this->replyError($entityId, 507, $e->getMessage());
        } catch (\InvalidArgumentException|\LogicException $e) {
            // 状态机/参数类失败转定向错误回执（连接不断），其余异常交由 dispatch 兜底 500
            // State-machine/argument failures become directed error receipts (connection kept); other exceptions fall through to dispatch's 500 fallback
            $this->replyError($entityId, 400, $e->getMessage());
        }
    }

    /**
     * 取容器内战斗服务（ADR-024 §9 V6：结算上下文跟随攻击方所在容器）：未知容器返回 null。
     * 房间 CombatService 以房间为 WorldInterface 门面 + RoomVisionBroadcaster——伤害帧走房内视野、
     * 死亡掉落入房内 EM、拾取从房内 EM 摘除。
     * Returns the container's combat service (ADR-024 §9 V6: the settlement context follows the attacker's
     * container); unknown containers return null. A room's CombatService faces the room as its WorldInterface
     * facade plus a RoomVisionBroadcaster — damage frames ride the in-room view, death drops land in the room EM,
     * pickups are removed from the room EM.
     */
    public function combatInContainer(RoomInstanceInterface $container): ?CombatService
    {
        return $this->contexts[$container->getRoomId()]['combat'] ?? null;
    }

    /**
     * room:create：建房并装配战斗上下文；创建者 entityId 记入归属表（room:settle/close 的权限基准）。
     * room:create: builds the room and assembles its combat context; the creator's entityId enters the
     * ownership table (the permission baseline of room:settle/close).
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleCreate(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        if (isset($this->contexts[$roomId])) {
            throw new \InvalidArgumentException(sprintf('房间 %s 已存在 / room %s already exists', $roomId, $roomId));
        }

        $this->createRoom($roomId);
        $this->owners[$roomId] = $entityId;
        $this->replyOk($entityId, 'create', $roomId, 0);
    }

    /**
     * room:join：玩家进房——走 transfer 约定路径（ADR-024 §4）：先预检目标房可入，再向世界视野邻居广播
     * entity_leave（G1：镜像 closeConnection 的"先广播后摘除"时序，摘除后世界 AOI/EM 无此实体无从补发），
     * 然后摘除宿主世界 EM/AOI 登记（$fromRoomId=null 的调用方编排责任），最后 manager->transfer 原子入房；
     * 失败回滚宿主世界登记（不产生额外 leave——摘除前那次广播是全流程唯一一次）。快照/成员进入信封经共享总线
     * 转发（见 MapChannelFactory 订阅）。transfer 成功后把连接容器维度标记到房间（ADR-024 §9 V6）：置于
     * transfer 成功之后，回滚路径容器零触碰；此后该连接的指令路由与视野判定走房间 EM/AOI。
     * room:join: player admission via the transfer convention (ADR-024 §4): pre-check target admissibility, then
     * broadcast entity_leave to the world-view neighbors (G1: mirroring closeConnection's "broadcast before removal"
     * ordering — once removed, the world AOI/EM has no such entity to broadcast from), then remove the host-world
     * EM/AOI registration (the caller-orchestrated duty when $fromRoomId=null), and finally an atomic
     * manager->transfer into the room; failure rolls the host-world registration back (no extra leave — the
     * pre-removal broadcast is the only one in the whole flow). Snapshot/member-enter envelopes forward over the
     * shared bus (see the MapChannelFactory subscriptions). After a successful transfer the connection's container
     * dimension is marked to the room (ADR-024 §9 V6): placed strictly after transfer success, so rollback paths
     * never touch the container; from then on the connection's instruction routing and view checks run against the
     * room's EM/AOI.
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleJoin(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        $this->admitPlayer($entityId, $roomId);

        $this->replyOk($entityId, 'join', $roomId, 0);
    }

    /**
     * 玩家入房公共编排（room:join 与匹配开房共用，R3 玩法批提取）：校验目标房存在且可入 → 世界侧
     * entity_leave 广播 + EM/AOI 摘除 → manager->transfer 原子入房（失败回滚世界登记）→ 连接容器维度
     * 标记到房间（经 moveEntityToContainer 按 entityId 解析连接，与 connId 路径等价——在线玩家必有连接）。
     * The shared player-admission orchestration (used by both room:join and match-built rooms, extracted in the R3
     * gameplay batch): validates the target room exists and admits → world-side entity_leave broadcast + EM/AOI
     * removal → atomic manager->transfer (rolling the world registration back on failure) → the connection's
     * container dimension marked to the room (via moveEntityToContainer resolving the connection by entityId,
     * equivalent to the connId path — an online player always has a connection).
     *
     * @throws \InvalidArgumentException 房间不存在 Room does not exist.
     * @throws \LogicException 房间状态不可入 / 实体或 Actor 缺失 / transfer 失败（满员或归属冲突）
     *   The room state rejects admission / the entity or actor is missing / the transfer failed (full or ownership conflict).
     */
    public function admitPlayer(string $entityId, string $roomId): void
    {
        $target = $this->manager->get($roomId);
        if ($target === null) {
            throw new \InvalidArgumentException(sprintf('房间 %s 不存在 / room %s does not exist', $roomId, $roomId));
        }
        $state = $target->getState();
        if ($state !== RoomState::Created && $state !== RoomState::Running) {
            throw new \LogicException(sprintf('房间 %s 状态 %s 不可加入 / room %s is %s, admissions stopped', $roomId, $state->name, $roomId, $state->name));
        }

        $entity = $this->world->getEntityManager()->get($entityId);
        $actor = $this->requireMap()->getActor($entityId);
        if ($entity === null || !$actor instanceof PlayerActor) {
            throw new \LogicException('玩家实体或 Actor 缺失 / player entity or actor missing');
        }

        // 先广播 entity_leave 给世界视野邻居（镜像 closeConnection 的"先广播后摘除"时序，G1），
        // 再摘除世界登记，随后原子 transfer 入房
        // Broadcast entity_leave to the world-view neighbors first (mirroring closeConnection's
        // "broadcast before removal" ordering, G1), then remove the world registration before the atomic transfer
        $this->requireMap()->broadcastEntityLeave($entityId);
        $this->world->getAOI()->remove($entity);
        $this->world->getEntityManager()->remove($entityId);

        if (!$this->manager->transfer(null, $roomId, $entity, $actor)) {
            // 回滚宿主世界登记（满员等失败路径）；EM add 即 markMoved，下帧自动重进 AOI 索引；
            // 回滚路径不触碰容器维度——连接仍指宿主世界
            // Roll the host-world registration back (full-room etc.); EM add marks moved, re-entering the AOI index next frame;
            // rollback never touches the container dimension — the connection still points at the host world
            $this->world->getEntityManager()->add($entity);
            $this->world->getAOI()->updateEntity($entity);
            throw new \LogicException(sprintf('进入房间 %s 失败（满员或归属冲突）/ joining room %s failed (full or ownership conflict)', $roomId, $roomId));
        }

        // V6 激活：连接容器维度标记到房间（仅 transfer 成功后触达；按 entityId 解析连接与 connId 路径等价）
        // V6 activation: the connection's container dimension is marked to the room (reached only after a successful
        // transfer; resolving the connection by entityId is equivalent to the connId path)
        $this->requireMap()->moveEntityToContainer($entityId, $target);

        // CHASE 卡滞修复（R4）：玩家已离开世界容器——通知感知方放弃以该实体为目标的追击/攻击
        // （目标 Actor 仍在共享 $actors 表，不通知则世界怪 CHASE 原地卡滞）
        // The CHASE-stall fix (R4): the player has left the world container — perceivers drop their chase/attack
        // targeting this entity (the target actor stays in the shared actors table; without the notice world
        // monsters stall in CHASE in place)
        $this->requireMap()->notifyTargetLeft($entityId);
    }

    /**
     * room:spawn：房内直入刷怪（ADR-024 成员模型验证载体）——实体仅经房间 EM.add 登记（不入管理器归属表，
     * 也不走 join 的双向通知），首帧由房间 update 的 drainMoved 自动进 AOI 索引；MonsterActor 绑定房间门面，
     * 战斗结算全部在房间上下文闭环。
     * room:spawn: direct in-room spawning (the ADR-024 membership-model verification vehicle) — entities register
     * only via the room's EM.add (never entering the manager's ownership table nor join's bidirectional notices);
     * the room update's drainMoved indexes them into the AOI on their first frame; MonsterActors bind the room facade,
     * closing all combat settlement inside the room context.
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleSpawn(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        if ($this->requireMembership($roomId, $entityId)) {
            return;
        }
        $context = $this->requireContext($roomId);
        $count = $payload['count'] ?? 0;
        if (!is_int($count) || $count < 1 || $count > 500) {
            throw new \InvalidArgumentException('count 必须是 1~500 的整数 / count must be an integer within 1..500');
        }

        // 波次刷怪定义（R4 类型模块试点）：网格布局与怪物血量取自 horde 配置首波
        // The wave spawn definition (the R4 type-module pilot): grid layout and monster hp come from the horde config's first wave
        $wave = $this->horde->waves[0];

        $map = $this->requireMap();
        $combat = $context['combat'];
        $room = $context['room'];
        $entityManager = $room->getEntityManager();

        for ($i = 0; $i < $count; $i++) {
            $this->spawnSequence++;
            $monsterId = sprintf('%s-horde-%d', $roomId, $this->spawnSequence);
            $position = $wave->positionAt($i);

            // 直入路径：EM.add（即 markMoved，首帧 drainMoved 进索引）+ ActorSystem 注册；不经 join、不入归属表
            // Direct path: EM.add (marks moved; first-frame drainMoved enters the index) + ActorSystem registration;
            // never via join, never into the ownership table
            $entity = new BaseEntity($monsterId, new Position($position['x'], $position['y']));
            $entityManager->add($entity);

            $monster = new MonsterActor(
                $monsterId,
                $wave->monsterMaxHp,
                $room,
                $combat,
                $this->dropTable,
                $map,
                $this->typeIndex,
                $this->random,
                new RoomVisionBroadcaster($room, $map),
                patrolAnchor: $position,
                patrolRadius: 2,
            );
            $monster->bindEntity($entity);
            $room->getActorSystem()->add($monster);
            // Actor 查找表登记：AoE 命中结算经 ActorLookupInterface 解析目标依赖此表
            // (死亡自清理经 removeActor 对称摘除)
            // The actor-lookup registration: AoE hit settlement resolves targets through the ActorLookupInterface
            // table (death self-cleanup removes it symmetrically via removeActor)
            $map->registerActor($monsterId, $monster);
            $this->typeIndex->set($monsterId, EntityTypeIndex::KIND_MONSTER);
        }

        $this->replyOk($entityId, 'spawn', $roomId, $count);
    }

    /**
     * room:aoe：以请求方玩家为施法者，在房间上下文执行 castSkillAoE（形状查询/结算/合并广播全在房间内）。
     * room:aoe: casts castSkillAoE in the room context with the requesting player as caster (shape query, settlement
     * and merged broadcast all inside the room).
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleAoe(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        if ($this->requireMembership($roomId, $entityId)) {
            return;
        }
        $context = $this->requireContext($roomId);

        $skillId = $payload['skillId'] ?? null;
        if (!is_string($skillId) || $skillId === '' || $this->skills->get($skillId) === null) {
            throw new \InvalidArgumentException('技能不存在或未注册 / unknown or unregistered skill');
        }

        $cx = $payload['cx'] ?? null;
        $cy = $payload['cy'] ?? null;
        $r = $payload['r'] ?? null;
        if (!is_int($cx) || !is_int($cy) || !is_int($r) || $r < 0) {
            throw new \InvalidArgumentException('cx/cy/r 必须是整数且 r 非负 / cx/cy/r must be integers with non-negative r');
        }

        // 半径业务上限（horde 配置，R2 审查 MAJOR-1）：超限直接拒绝（定向 error 回执），不触达 queryShape
        // The radius business cap (from the horde config, R2 review MAJOR-1): over-cap requests are rejected outright (a directed error receipt), never reaching queryShape
        $aoeMaxRadius = $this->horde->aoeMaxRadius;
        if ($r > $aoeMaxRadius) {
            throw new \InvalidArgumentException(sprintf('半径超过上限 %d / radius exceeds the cap of %d', $aoeMaxRadius, $aoeMaxRadius));
        }

        $caster = $this->requireMap()->getActor($entityId);
        if (!$caster instanceof PlayerActor || $caster->isDead()) {
            throw new \LogicException('施法者无效或已死亡 / invalid or dead caster');
        }

        $hits = $context['combat']->castSkillAoE($caster, $skillId, new CircleShape($cx, $cy, $r));

        $this->replyOk($entityId, 'aoe', $roomId, count($hits));
    }

    /**
     * room:settle：Running→Settled，向存活成员发 room.closed 信封（经订阅转发为 room:closed 帧）。
     * 归属校验（R2 review MINOR-6）：仅创建者可结算；创建者失联后的无主房任意玩家可接管。
     * room:settle: Running→Settled, sending room.closed envelopes to surviving members (forwarded as room:closed
     * frames via subscriptions). Ownership check (R2 review MINOR-6): only the creator may settle; an ownerless
     * room (creator lost) accepts any player's takeover.
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleSettle(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        if ($this->requireOwnership($roomId, $entityId)) {
            return;
        }
        $this->requireContext($roomId)['room']->settle();
        $this->replyOk($entityId, 'settle', $roomId, 0);
    }

    /**
     * room:close：清空成员与索引并经 manager->destroy 移除房间与归属表记录
     * （否则已离房玩家的归属残留会拒绝其后续跨房转移）。
     * 归属校验（R2 review MINOR-6）：仅创建者可关闭；创建者失联后的无主房任意玩家可接管。
     * 与 manager->destroy 同口径的状态容错：Created/Running 先补 settle（验收脚本对上一轮残留房的
     * 幂等前置依赖此路径），Settled 直接 close；Closed 抛非法迁移交由 replyError 兜底。
     * destroy 僵尸处置（跨容器编排批记录 #2）：close 清空成员后受管玩家会陷入「连接活着但无实体」
     * ——既不在世界也不在任何房。close 前把房内受管玩家（PlayerActor）回填宿主世界 EM/AOI 并把其
     * 连接容器维度回落 null；直入怪物/掉落非受管成员，随 close 销毁不回填。
     * room:close: clears members and indexes, then manager->destroy removes the room and its ownership-table
     * records (otherwise departed players' stale ownership would reject later transfers). State tolerance matching
     * manager->destroy: Created/Running settle first (the acceptance scripts' idempotent pre-step against a previous
     * run's leftover room relies on this path), Settled closes directly; Closed throws the illegal transition caught
     * by replyError. Destroy-zombie disposal (cross-container batch record #2): after close clears the members,
     * managed players would be stuck as "alive connection, no entity" — in neither the world nor any room. Before
     * close, in-room managed players (PlayerActors) are back-filled into the host-world EM/AOI with their
     * connections' container dimension reset to null; directly-spawned monsters/drops are unmanaged members,
     * destroyed with the room and never back-filled.
     *
     * @param array<string|int, mixed> $payload
     */
    private function handleClose(string $entityId, array $payload): void
    {
        $roomId = $this->requireRoomId($payload);
        if ($this->requireOwnership($roomId, $entityId)) {
            return;
        }
        $room = $this->requireContext($roomId)['room'];
        $state = $room->getState();
        if ($state === RoomState::Created || $state === RoomState::Running) {
            $room->settle();
        }

        // 受管玩家回填宿主世界（必须在 close 清空房内 EM 之前遍历）：EM add 即 markMoved，
        // 手动 updateEntity 立即进世界 AOI 索引（比照 join 回滚路径）；容器维度同步回落 null。
        // Managed-player world back-fill (iterating before close clears the room EM): EM add marks moved, and the
        // manual updateEntity indexes into the world AOI immediately (mirroring the join rollback path); the container
        // dimension resets to null in step.
        $map = $this->requireMap();
        $worldEm = $this->world->getEntityManager();
        foreach ($room->getEntityManager()->all() as $member) {
            if (!$map->getActor($member->getId()) instanceof PlayerActor) {
                continue;
            }
            if ($worldEm->get($member->getId()) !== null) {
                continue;
            }
            $worldEm->add($member);
            $this->world->getAOI()->updateEntity($member);
            $map->moveEntityToContainer($member->getId(), null);
        }

        $room->close();
        $this->manager->destroy($roomId);
        unset($this->contexts[$roomId], $this->owners[$roomId]);

        $this->replyOk($entityId, 'close', $roomId, 0);
    }

    /**
     * 创建者断连/被 evict 后的归属处置（R2 review MINOR-6 裁决：标记无主，不自动转移）：
     * 由宿主 onEntityCleanedUp 钩子调用（断连与跨容器 evict 两条清理路径的公共汇点）；
     * 无主房允许任意玩家 settle/close 接管——创建者失联不能把房间变成无人能关的僵尸。
     * Ownership disposal after the creator disconnects or is evicted (the R2 review MINOR-6 ruling: mark
     * ownerless, never auto-transfer): invoked from the host's onEntityCleanedUp hook (the common sink of both
     * cleanup paths, disconnect and cross-container eviction); an ownerless room accepts settle/close takeover
     * from any player — a lost creator must not leave a zombie room nobody can close.
     */
    public function handleCreatorDisconnected(string $entityId): void
    {
        foreach ($this->owners as $roomId => $owner) {
            if ($owner === $entityId) {
                $this->owners[$roomId] = null;
            }
        }
    }

    /**
     * 定向成功回执 room:ok{op, roomId, count}。
     * Emits the directed success receipt room:ok{op, roomId, count}.
     */
    private function replyOk(string $entityId, string $op, string $roomId, int $count): void
    {
        $this->requireMap()->sendToEntity($entityId, 'room:ok', [
            'op' => $op,
            'roomId' => $roomId,
            'count' => $count,
        ]);
    }

    /**
     * 定向失败回执 error{code, message}（连接不断，比照 combat:error 口径）。
     * Emits the directed failure receipt error{code, message} (connection kept, matching the combat:error convention).
     */
    private function replyError(string $entityId, int $code, string $message): void
    {
        $this->requireMap()->sendToEntity($entityId, 'error', [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * 校验并提取 roomId 负载字段。
     * Validates and extracts the roomId payload field.
     *
     * @param array<string|int, mixed> $payload
     */
    private function requireRoomId(array $payload): string
    {
        $roomId = $payload['roomId'] ?? null;
        if (!is_string($roomId) || $roomId === '') {
            throw new \InvalidArgumentException('payload 缺少 roomId 字段 / payload lacks the roomId field');
        }

        return $roomId;
    }

    /**
     * 取房间战斗上下文；未知 roomId 抛 InvalidArgumentException。
     * Returns the room's combat context; unknown roomIds throw.
     *
     * @return array{room: RoomInstanceInterface, combat: CombatService}
     */
    private function requireContext(string $roomId): array
    {
        $context = $this->contexts[$roomId] ?? null;
        if ($context === null) {
            throw new \InvalidArgumentException(sprintf('房间 %s 不存在 / room %s does not exist', $roomId, $roomId));
        }

        return $context;
    }

    /**
     * 房间管理操作归属校验（R2 review MINOR-6）：创建者在线时仅创建者可 settle/close——非创建者定向
     * 403 error 回执（连接不断，比照 combat:error 口径；权限拒绝与状态机 400 分流）；无主房（创建者
     * 断连/被 evict）任意玩家可接管。返回 true = 已拒绝并回执，调用方直接返回。
     * Room-management ownership check (R2 review MINOR-6): while the creator is online only they may
     * settle/close — non-creators get a directed 403 error receipt (connection kept, matching the combat:error
     * convention; permission denials split from state-machine 400s); an ownerless room (creator
     * disconnected/evicted) accepts any player's takeover. Returns true when rejected-and-replied, so callers
     * simply return.
     */
    private function requireOwnership(string $roomId, string $entityId): bool
    {
        if (!array_key_exists($roomId, $this->owners)) {
            return false; // 未知房间：交由后续 requireContext 报存在性错误 Unknown room: existence is reported by the later requireContext
        }

        $owner = $this->owners[$roomId];
        if ($owner === null || $owner === $entityId) {
            return false;
        }

        $this->replyError($entityId, 403, sprintf('房间 %s 归属其他玩家 / room %s is owned by another player', $roomId, $roomId));

        return true;
    }

    /**
     * 房间成员闸门（reviewer MAJOR-2）：spawn/aoe 仅房内成员可操作——成员事实来源即房内 EM
     * （join/admitPlayer 的 transfer 与直入刷怪都登记于此，匹配开房路径同源兼容）；非成员定向 403 error
     * 回执（连接不断，比照 requireOwnership 口径；权限拒绝与存在性 400 分流）。返回 true = 已拒绝并回执，
     * 调用方直接返回。边界：死亡玩家的实体不摘除、留在房内 EM（死亡路径只清 Actor/类型索引并广播，
     * 不动房内 EM 登记），因此死亡玩家仍算成员、成员闸门对其放行——成员资格随实体登记生死，不随 hp
     * 归零失效（死亡后的 spawn/aoe 由各自后续校验另行拦截，非本闸门职责）。
     * The room-membership gate (reviewer MAJOR-2): spawn/aoe are member-only operations — the room's EM is the
     * membership source of truth (both the join/admitPlayer transfer and direct spawning register there, so the
     * match-built-room path is compatible by construction); non-members get a directed 403 error receipt
     * (connection kept, matching the requireOwnership convention; permission denials split from existence 400s).
     * Returns true when rejected-and-replied, so callers simply return. Boundary: a dead player's entity is never
     * removed from the room EM (the death path only clears the actor/type index and broadcasts; the room EM
     * registration is untouched), so dead players still count as members and this gate lets them through —
     * membership follows entity registration, not the hp-at-zero state (post-death spawn/aoe are intercepted by
     * their own later validity checks, which is not this gate's job).
     */
    private function requireMembership(string $roomId, string $entityId): bool
    {
        $context = $this->contexts[$roomId] ?? null;
        if ($context === null) {
            return false; // 未知房间：交由后续 requireContext 报存在性错误 Unknown room: existence is reported by the later requireContext
        }

        if ($context['room']->getEntityManager()->get($entityId) !== null) {
            return false;
        }

        $this->replyError($entityId, 403, sprintf('房间 %s 拒绝非成员操作 / room %s rejects non-member operations', $roomId, $roomId));

        return true;
    }

    /**
     * 取宿主 MapServer（attach 前调用属装配顺序错误）。
     * Returns the host map server (calling before attach is an assembly-order bug).
     */
    private function requireMap(): MapServer
    {
        if ($this->map === null) {
            throw new \LogicException('RoomHub 尚未 attach 宿主 MapServer / RoomHub is not attached to a host MapServer yet');
        }

        return $this->map;
    }
}
