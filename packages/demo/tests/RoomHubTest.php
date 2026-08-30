<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\MapServer;
use Nythros\Demo\RoomHub;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\MovementValidator;
use Nythros\Framework\Tests\FakeServiceRegistry;
use Nythros\Framework\Tests\FakeTokenManager;
use Nythros\Framework\Tests\FixedRandomSource;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenRecord;
use Nythros\World\RoomInstanceManager;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * RoomHubTest - room:aoe 半径防线测试（R2 审查 MAJOR-1）：超业务上限的半径被定向 error 回执拒绝
 * （不触达 queryShape，防认证后 DoS），合法大半径（如 300）正常结算不被误伤。
 * RoomHubTest - the room:aoe radius defense tests (R2 review MAJOR-1): over-cap radii are rejected with a
 * directed error receipt (never reaching queryShape, preventing post-auth DoS), while legitimate large radii
 * (e.g. 300) settle normally without false rejections.
 *
 * 组装策略：与 MapServerCombatTest 一致（stub 连接/Server、真实 World 栈、Fake 时钟定时器），
 * 额外装配 RoomInstanceManager + RoomHub 并注入 MapServer rooms: 参数（starter-kit 接线）。
 * Assembly strategy: same as MapServerCombatTest (stub connections/Server, real World stack, fake clock/timer),
 * plus a RoomInstanceManager + RoomHub wired into the MapServer's rooms: parameter (the starter-kit wiring).
 */
final class RoomHubTest extends TestCase
{
    /**
     * 超业务上限的半径被拒绝：定向 error{code:400} 回执、无 combat:aoe 结算帧；
     * PHP_INT_MAX 的恶意极端输入走同一拦截路径。
     * An over-cap radius is rejected: a directed error{code:400} receipt and no combat:aoe settlement frame;
     * the malicious extreme input PHP_INT_MAX takes the same interception path.
     */
    public function testAoeRadiusAboveBusinessCapIsRejectedWithErrorReceipt(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-cap']);
        // 成员闸门（reviewer MAJOR-2）先行：aoe 为成员操作，先 join 再验参（权限拒绝与参数校验分流）
        // The membership gate (reviewer MAJOR-2) runs first: aoe is a member operation, so join before parameter validation (permission denials split from argument checks)
        $h->send('room:join', ['roomId' => 'r-cap']);
        $h->flush();

        foreach ([301, PHP_INT_MAX] as $r) {
            $h->batchedA = [];
            $h->send('room:aoe', ['roomId' => 'r-cap', 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => $r]);
            $h->flush();

            $messages = RoomHubHarness::decodeFrames($h->batchedA);
            $errors = self::messagesOfType($messages, 'error');
            self::assertCount(1, $errors, sprintf('r=%d 必须恰好一条 error 回执 / r=%d must yield exactly one error receipt', $r, $r));
            self::assertSame(400, $errors[0]->payload['code']);
            self::assertSame('半径超过上限 300 / radius exceeds the cap of 300', $errors[0]->payload['message']);
            self::assertSame([], self::messagesOfType($messages, 'combat:aoe'), '超限请求不得产生 AoE 结算帧 / an over-cap request must never emit an AoE settlement frame');
        }
    }

    /**
     * 合法大半径不被误伤：r=300（上限边界值）正常走 castSkillAoE 结算，
     * 房内 3 只 horde 怪全命中并回 room:ok{op=aoe, count=3}。
     * A legitimate large radius is not falsely rejected: r=300 (the cap boundary) settles through castSkillAoE
     * normally, hitting all three in-room horde monsters and answering room:ok{op=aoe, count=3}.
     */
    public function testAoeAcceptsLargeLegalRadiusAtCapBoundary(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-big']);
        $h->send('room:join', ['roomId' => 'r-big']);
        $h->send('room:spawn', ['roomId' => 'r-big', 'count' => 3]);
        $h->flush();

        // 房间跑一帧：drainMoved 把直入刷怪的怪物登记进房间 AOI 索引
        // One room frame: drainMoved registers the directly-spawned monsters into the room's AOI index
        $room = $h->rooms->get('r-big');
        self::assertNotNull($room);
        $room->update();

        $h->batchedA = [];
        $h->send('room:aoe', ['roomId' => 'r-big', 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => 300]);
        $h->flush();

        $oks = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok');
        self::assertCount(1, $oks);
        self::assertSame('aoe', $oks[0]->payload['op']);
        self::assertSame('r-big', $oks[0]->payload['roomId']);
        self::assertSame(3, $oks[0]->payload['count'], 'r=300 圆必须命中全部 3 只怪 / the r=300 circle must hit all three monsters');
    }

    /**
     * V3（ADR-024 §9）断连跨容器清理闭环：玩家 transfer 进房后断连——世界 EM 查空触发跨容器清理回调，
     * evictFromAny 复用房间 leave 全链把房内成员摘除（幽灵成员不残留），宿主侧 Actor/在线计数同步回收。
     * V3 (ADR-024 §9) the cross-container disconnect-cleanup closed loop: a player disconnects after transferring
     * into a room — the world EM miss triggers the cross-container cleanup callback, and evictFromAny reuses the
     * room's full leave chain to remove the in-room member (no ghost members linger); the host-side actor and online
     * count are reclaimed in step.
     */
    public function testDisconnectAfterJoinEvictsMemberFromRoom(): void
    {
        $h = $this->buildHarness();
        // 桥接与 MapChannelFactory 的 NYTHROS_ROOMS=1 分支一致：注入 evictFromAny 适配回调
        // The bridge mirrors MapChannelFactory's NYTHROS_ROOMS=1 branch: inject the evictFromAny adapter callback
        $h->mapServer->setCrossContainerCleanup(static fn (string $entityId): bool => $h->rooms->evictFromAny($entityId));

        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-dc']);
        $h->send('room:join', ['roomId' => 'r-dc']);
        $h->flush();

        $roomId = 'r-dc';
        $entityId = '1001@conn-a';
        $room = $h->rooms->get($roomId);
        self::assertNotNull($room);
        self::assertNotNull($room->getEntityManager()->get($entityId), 'join 后玩家应在房内 the player is in the room after join');
        self::assertNull($h->world->getEntityManager()->get($entityId), 'join 后世界 EM 已摘除 the world EM entry is gone after join');

        // 断连：世界 EM 查空 → 跨容器清理回调 → 房间 leave 全链
        // Disconnect: world EM miss → cross-container cleanup callback → the room's full leave chain
        ($h->onClose)($h->connA);

        self::assertNull($room->getEntityManager()->get($entityId), '断连后房内成员必须被摘除（V3 幽灵成员修复） the in-room member must be evicted on disconnect (the V3 ghost-member fix)');
        self::assertNull($h->mapServer->getActor($entityId), '宿主 Actor 表同步回收 the host actor table is reclaimed');
        // 归属已清除：同 id 实体可再次从大世界入册新房间（无双房拒绝）
        // Ownership purged: an entity with the same id can re-register into a fresh room from the world (no double-housing rejection)
        $fresh = new BaseEntity($entityId, new Position(0, 0));
        $h->rooms->create(new RoomConfig('r-fresh', 50, 8, static fn (): GridAOI => new GridAOI(10)));
        self::assertTrue($h->rooms->transfer(null, 'r-fresh', $fresh), 'evict 后归属已清，同 id 可重新入册 ownership purged after eviction, the same id re-registers');
    }

    /**
     * room:close 状态容错（与 manager->destroy 同口径）：Created 态房间直接 close 成功
     * （内部补 settle），验收脚本对上一轮残留房的幂等前置依赖此路径。
     * The room:close state tolerance (matching manager->destroy): a Created room closes directly (settle is
     * filled in internally); the acceptance scripts' idempotent pre-step against a previous run's leftover room
     * relies on this path.
     */
    public function testCloseAcceptsCreatedRoomWithImplicitSettle(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-created']);
        $h->flush();

        $room = $h->rooms->get('r-created');
        self::assertNotNull($room);
        self::assertSame(\Nythros\Contracts\RoomState::Created, $room->getState());

        $h->batchedA = [];
        $h->send('room:close', ['roomId' => 'r-created']);
        $h->flush();

        $oks = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok');
        self::assertCount(1, $oks, 'Created 房直接 close 必须成功 a Created room must close directly');
        self::assertSame('close', $oks[0]->payload['op']);
        self::assertNull($h->rooms->get('r-created'), 'close 后房间销毁 the room is destroyed after close');
    }

    /**
     * V6（ADR-024 §9）跨容器误伤防护锁定：世界玩家攻击房内怪——目标 Actor 经共享 $actors 表可跨容器
     * 命中，但 isNeighbor 用攻击方（世界）AOI 查不到房内实体 → out_of_range 天然拒绝，怪血量不变。
     * 这是正确语义，锁死防后续误改（如改成按目标所在容器判定视野会造成跨容器远程打击）。
     * V6 (ADR-024 §9) the cross-container friendly-fire guard, locked: a world player attacks an in-room monster —
     * the target actor resolves through the shared actors table across containers, but isNeighbor misses the in-room
     * entity via the attacker's (world) AOI → out_of_range rejects naturally with the monster's hp untouched. This is
     * the correct semantics, locked against future regressions (judging vision by the target's container would enable
     * cross-container ranged strikes).
     */
    public function testWorldPlayerAttackingInRoomMonsterIsRejectedOutOfRange(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB(); // B 留守大世界 B stays in the host world
        $h->send('room:create', ['roomId' => 'r-ff']);
        $h->send('room:join', ['roomId' => 'r-ff']);
        $h->send('room:spawn', ['roomId' => 'r-ff', 'count' => 1]);
        $h->flush();

        // 房间跑一帧：drainMoved 把房内怪索引进房间 AOI（攻击方容器解析的前置事实）
        // One room frame: drainMoved indexes the in-room monster into the room's AOI (the precondition fact of attacker-side container resolution)
        $room = $h->rooms->get('r-ff');
        self::assertNotNull($room);
        $room->update();

        $h->batchedB = [];
        $h->sendFrom($h->connB, 'attack', ['targetId' => 'r-ff-horde-1']);
        $h->flush();

        $errors = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedB), 'combat:error');
        self::assertCount(1, $errors, '跨容器攻击必须被拒绝 cross-container attacks must be rejected');
        self::assertSame('out_of_range', $errors[0]->payload['code'], '世界 AOI 查不到房内实体 → out_of_range the world AOI misses the in-room entity → out_of_range');
        self::assertSame(12, $h->mapServer->getActor('r-ff-horde-1')->hp(), '怪血量不变（未结算伤害） the monster hp is untouched (no damage settled)');
    }

    /**
     * G1（跨容器编排批）：B 留守世界、A join 进房——handleJoin 摘除世界登记前先向世界视野邻居
     * 广播 entity_leave（镜像 closeConnection 时序），B 必须收到 entity_leave{id=1001@conn-a}。
     * 顺序断言依据：broadcastEntityLeave 经世界 EM 解析实体，若实现为"先摘除后广播"则查空静默跳过、
     * B 零帧——恰好 1 条 leave 即"先广播后摘除"的证明。
     * G1 (cross-container batch): B stays in the world while A joins a room — handleJoin broadcasts
     * entity_leave to the world-view neighbors before removing the world registration (mirroring the
     * closeConnection ordering), so B must receive entity_leave{id=1001@conn-a}. Ordering-proof basis:
     * broadcastEntityLeave resolves the entity via the world EM, so a wrong "remove-then-broadcast" ordering
     * would miss silently and deliver zero frames to B — exactly one leave proves "broadcast before removal".
     */
    public function testJoinBroadcastsEntityLeaveToWorldNeighbors(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB(); // B 留守大世界 B stays in the host world
        $h->flush(); // 清空 A/B 同格互见的 entity_enter 帧 drain the mutual entity_enter frames

        $h->batchedA = [];
        $h->batchedB = [];
        $h->send('room:create', ['roomId' => 'r-g1']);
        $h->send('room:join', ['roomId' => 'r-g1']);
        $h->flush();

        $leaves = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedB), 'entity_leave');
        self::assertCount(1, $leaves, 'B 必须收到 A 的 entity_leave B must receive A\'s entity_leave');
        self::assertSame('1001@conn-a', $leaves[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $leaves[0]->payload['position']);

        // A 已进房：世界 EM 无 A、房内 EM 有 A
        $room = $h->rooms->get('r-g1');
        self::assertNotNull($room);
        self::assertNull($h->world->getEntityManager()->get('1001@conn-a'), 'join 后世界 EM 已摘除 the world EM entry is gone after join');
        self::assertNotNull($room->getEntityManager()->get('1001@conn-a'), 'join 后玩家在房内 the player is in the room after join');
    }

    /**
     * G1 回滚路径：满员房 join 失败——B 收到的 entity_leave 恰好 1 条（摘除前那次广播是全流程唯一一次，
     * 回滚路径自身不产生多余 leave），且回滚后实体仍在世界（EM 登记恢复 + AOI 索引可查询）。
     * G1 rollback path: a full-room join failure — B receives exactly one entity_leave (the pre-removal broadcast
     * is the only one in the whole flow; the rollback path itself emits no extra leave), and after the rollback the
     * entity stays in the world (EM registration restored plus AOI-index queryable).
     */
    public function testJoinFailureRollbackEmitsNoExtraLeaveAndKeepsEntityInWorld(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB(); // B 留守大世界，与 A 同格互见 B stays in the host world, same cell as A
        $h->flush();

        // 手工建满员房（maxMembers=1 已被占位成员占满）：handleJoin 的 manager->transfer 必然失败
        // Builds a full room by hand (maxMembers=1 already taken): handleJoin's manager->transfer must fail
        $full = $h->rooms->create(new RoomConfig('r-g1-full', 50, 1, static fn (): GridAOI => new GridAOI(10)));
        self::assertTrue($full->join(new BaseEntity('occupier-1', new Position(0, 0))), '占位成员入房 the placeholder member joins');

        $h->batchedA = [];
        $h->batchedB = [];
        $h->send('room:join', ['roomId' => 'r-g1-full']);
        $h->flush();

        // 失败回执（定向 error{400}）
        // The directed failure receipt (error{400})
        $errors = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'error');
        self::assertCount(1, $errors, '满员 join 必须恰好一条 error 回执 a full-room join must yield exactly one error receipt');
        self::assertSame(400, $errors[0]->payload['code']);

        // 无多余 leave：B 恰好 1 条（摘除前那次），回滚未追加任何重复广播
        // No extra leave: B holds exactly one (the pre-removal one); the rollback appended no repeated broadcast
        $leaves = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedB), 'entity_leave');
        self::assertCount(1, $leaves, '回滚路径不得产生多余 entity_leave the rollback path must not emit an extra entity_leave');
        self::assertSame('1001@conn-a', $leaves[0]->payload['id']);

        // 回滚后实体仍在世界：EM 登记恢复 + AOI 索引可查询（query 含自身）
        // After the rollback the entity stays in the world: EM restored plus AOI-index queryable (query includes self)
        $entity = $h->world->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($entity, '回滚后玩家仍在世界 EM the player stays in the world EM after rollback');
        $indexedIds = array_map(static fn (\Nythros\Contracts\EntityInterface $e): string => $e->getId(), $h->world->getAOI()->query($entity));
        self::assertContains('1001@conn-a', $indexedIds, '回滚后实体已重进世界 AOI 索引 the entity is re-indexed into the world AOI after rollback');
        self::assertNull($h->registry->getContainer('conn-a'), '回滚路径容器零触碰（仍指宿主世界） rollback never touches the container (still the host world)');
    }

    /**
     * V6 激活：join 后连接容器维度标记到房间，move 在房间上下文结算——房内 EM 含新坐标、世界 EM 无此人、
     * 无 error{500}（激活前 move 走世界 EM 查无此人必 500+断连）。
     * V6 activation: after join the connection's container dimension marks to the room and move settles in the room
     * context — the room EM holds the new coordinates, the world EM has no such entity, and no error{500} appears
     * (pre-activation move missed in the world EM and always died with 500+disconnect).
     */
    public function testMoveAfterJoinUpdatesRoomEntityCoordinates(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-move']);
        $h->send('room:join', ['roomId' => 'r-move']);
        $h->flush();

        $room = $h->rooms->get('r-move');
        self::assertNotNull($room);
        self::assertSame($room, $h->registry->getContainer('conn-a'), 'join 后连接容器标记到房间 the connection container marks to the room after join');

        $h->batchedA = [];
        $h->send('move', ['dx' => 5, 'dy' => -3]);
        $h->flush();

        $moved = $room->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($moved, 'move 后实体在房内 EM the entity lives in the room EM after move');
        self::assertSame(5, $moved->getPosition()['x']);
        self::assertSame(-3, $moved->getPosition()['y']);
        self::assertNull($h->world->getEntityManager()->get('1001@conn-a'), '世界 EM 无此人 the world EM has no such entity');
        self::assertSame([], self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'error'), 'join 后 move 不得产生 error{500} a post-join move must never emit error{500}');
    }

    /**
     * V6 激活：join 后 attack 在房间上下文结算——玩家 move 进房内怪九宫格后普攻命中
     * （horde 怪 maxHp=12，普攻伤害 10 → hp=2），combat:hit 帧正常广播。
     * V6 activation: after join attack settles in the room context — the player moves into the in-room monster's
     * 3x3 view and the normal attack lands (horde monster maxHp=12, base damage 10 → hp=2), with the combat:hit
     * frame broadcast normally.
     */
    public function testAttackAfterJoinSettlesInRoomContext(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-atk']);
        $h->send('room:join', ['roomId' => 'r-atk']);
        $h->send('room:spawn', ['roomId' => 'r-atk', 'count' => 1]);
        $h->flush();

        $room = $h->rooms->get('r-atk');
        self::assertNotNull($room);
        $room->update(); // 怪进房内 AOI the monster enters the room AOI

        // 玩家 move 进怪所在格（锚点 (24,-24)，有界巡逻 ±2 恒在同格），再跑一帧让新位进索引；
        // 本帧怪仅感知→CHASE 不攻击（状态机当帧不结算），随后立即普攻不会被反击污染断言。
        // The player moves onto the monster's cell (anchor (24,-24); bounded patrol ±2 stays in-cell), then one more
        // frame indexes the new position; this frame the monster only perceives → CHASE without attacking (the state
        // machine settles nothing on the transition frame), so the immediate attack is never polluted by a counter-hit.
        $h->send('move', ['dx' => 24, 'dy' => -24]);
        $h->flush();
        $room->update();

        $h->batchedA = [];
        $h->send('attack', ['targetId' => 'r-atk-horde-1']);
        $h->flush();

        $hits = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'combat:hit');
        self::assertCount(1, $hits);
        self::assertSame('1001@conn-a', $hits[0]->payload['attackerId']);
        self::assertSame('r-atk-horde-1', $hits[0]->payload['targetId']);
        self::assertSame(10, $hits[0]->payload['damage']);
        self::assertSame(2, $h->mapServer->getActor('r-atk-horde-1')->hp(), '房内怪被命中掉血 the in-room monster takes damage');
    }

    /**
     * V6 激活：join 后 skill:cast 与 pickup 在房间上下文闭环——fireball（15 ≥ maxHp 12）击杀房内怪，
     * 死亡掉落生成在房内 EM；pickup 同容器解析掉落并入包（item:added + 背包更新）。
     * V6 activation: after join skill:cast and pickup close the loop in the room context — fireball (15 ≥ maxHp 12)
     * kills the in-room monster whose death drop lands in the room EM; pickup resolves the drop in the same container
     * into the inventory (item:added plus the inventory update).
     */
    public function testSkillCastAndPickupAfterJoinSettleInRoomContext(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-skill']);
        $h->send('room:join', ['roomId' => 'r-skill']);
        $h->send('room:spawn', ['roomId' => 'r-skill', 'count' => 1]);
        $h->flush();

        $room = $h->rooms->get('r-skill');
        self::assertNotNull($room);
        $room->update();

        $h->send('move', ['dx' => 24, 'dy' => -24]);
        $h->flush();
        $room->update();

        $h->batchedA = [];
        $h->send('skill:cast', ['skillId' => 'fireball', 'targetId' => 'r-skill-horde-1']);
        $h->flush();

        $hits = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'combat:hit');
        self::assertCount(1, $hits);
        self::assertSame(15, $hits[0]->payload['damage'], 'fireball 倍率 1.5：10 × 1.5 = 15 fireball multiplier 1.5: 10 × 1.5 = 15');
        self::assertNull($h->mapServer->getActor('r-skill-horde-1'), '15 ≥ maxHp 12 击杀并自清理 15 ≥ maxHp 12 kills with self-cleanup');

        // 死亡掉落在房内 EM（战斗以房间为门面）
        // The death drop lands in the room EM (combat faces the room)
        $drop = null;
        foreach ($room->getEntityManager()->all() as $entity) {
            if ($entity instanceof \Nythros\Framework\Combat\DropEntity) {
                $drop = $entity;
            }
        }
        self::assertNotNull($drop, '击杀掉落生成在房内 EM the kill drop spawns inside the room EM');

        $h->batchedA = [];
        $h->send('pickup', ['dropId' => $drop->getId()]);
        $h->flush();

        $added = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'item:added');
        self::assertCount(1, $added, 'pickup 在房间上下文结算入包 pickup settles into the inventory in the room context');
        self::assertSame(['itemId' => 'gold', 'count' => 1], $added[0]->payload);
    }

    /**
     * join 失败回滚后容器仍指宿主世界：满员房 transfer 失败走回滚路径，容器维度零触碰，
     * 玩家实体留在世界 EM。
     * After a failed join's rollback the container still points at the host world: a full-room transfer failure takes
     * the rollback path, the container dimension is never touched, and the player entity stays in the world EM.
     */
    public function testJoinFailureRollbackKeepsContainerAtHostWorld(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();

        // 手工建满员房（maxMembers=1 已被占位成员占满）：handleJoin 的 manager->transfer 必然失败
        // Builds a full room by hand (maxMembers=1 already taken): handleJoin's manager->transfer must fail
        $full = $h->rooms->create(new RoomConfig('r-full', 50, 1, static fn (): GridAOI => new GridAOI(10)));
        self::assertTrue($full->join(new BaseEntity('occupier-1', new Position(0, 0))), '占位成员入房 the placeholder member joins');

        $h->batchedA = [];
        $h->send('room:join', ['roomId' => 'r-full']);
        $h->flush();

        $errors = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'error');
        self::assertCount(1, $errors);
        self::assertSame(400, $errors[0]->payload['code']);
        self::assertNull($h->registry->getContainer('conn-a'), '回滚路径容器零触碰（仍指宿主世界） rollback never touches the container (still the host world)');
        self::assertNotNull($h->world->getEntityManager()->get('1001@conn-a'), '回滚后玩家仍在世界 EM the player stays in the world EM after rollback');
    }

    /**
     * destroy 僵尸处置（跨容器编排批记录 #2）：close 回填受管玩家到宿主世界——世界 EM/AOI 恢复登记、
     * 容器维度回落 null；回填后 move 正常结算（无 500、实体仍在世界）；归属表已清可再次入册新房。
     * Destroy-zombie disposal (cross-container batch record #2): close back-fills managed players into the host
     * world — world EM/AOI registration restored, container dimension reset to null; a post-back-fill move settles
     * normally (no 500, entity still in the world); ownership purged, re-admission into a fresh room works.
     */
    public function testCloseBackFillsManagedPlayersToWorldAndResetsContainer(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-bf']);
        $h->send('room:join', ['roomId' => 'r-bf']);
        $h->flush();
        self::assertNotNull($h->rooms->get('r-bf'));

        $h->send('room:close', ['roomId' => 'r-bf']);
        $h->flush();

        self::assertNull($h->rooms->get('r-bf'), 'close 销毁房间 close destroys the room');
        $player = $h->world->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($player, '受管玩家回填世界 EM（僵尸消除） the managed player is back-filled into the world EM (zombie eliminated)');
        self::assertNull($h->registry->getContainer('conn-a'), '容器维度回落宿主世界 the container dimension resets to the host world');

        // 回填后 move 正常结算：无 error{500} 且实体仍在世界（连接不再是「活着但无实体」）
        // A post-back-fill move settles normally: no error{500}, entity still in the world (no more "alive connection, no entity")
        $h->batchedA = [];
        $h->send('move', ['dx' => 1, 'dy' => 0]);
        $h->flush();
        $errors500 = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'error'),
            static fn (Message $message): bool => $message->payload['code'] === 500,
        ));
        self::assertSame([], $errors500);
        self::assertNotNull($h->world->getEntityManager()->get('1001@conn-a'));

        // 归属表已清：同 id 可再次从大世界入册新房间
        // Ownership purged: the same id re-registers into a fresh room from the world
        $h->send('room:create', ['roomId' => 'r-bf2']);
        $h->send('room:join', ['roomId' => 'r-bf2']);
        $h->flush();
        $oks = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok');
        $joins = array_values(array_filter($oks, static fn (Message $message): bool => $message->payload['op'] === 'join'));
        self::assertCount(1, $joins, '回填后可再次 join the back-filled player can join again');
        self::assertNotNull($h->rooms->get('r-bf2')?->getEntityManager()->get('1001@conn-a'));
    }

    /**
     * 房间成员闸门（reviewer MAJOR-2）：未入房的已认证玩家对已知 roomId 的 spawn/aoe 均被定向
     * 403 error 拒绝——spawn 零刷怪、aoe 不触达结算（无 combat:aoe 帧）；房内成员操作不受影响。
     * The room-membership gate (reviewer MAJOR-2): an authenticated player who never joined a known roomId is
     * rejected with a directed 403 error for both spawn and aoe — zero monsters spawned, no settlement reached
     * (no combat:aoe frame); in-room members stay unaffected.
     */
    public function testSpawnAndAoeRejectNonMembersWith403(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB(); // B 留守大世界，从未 join B stays in the host world, never joins
        $h->send('room:create', ['roomId' => 'r-gate']);
        // 创建者同样先 join 才成为成员（spawn/aoe 是成员操作，与 E2E 的 create→join→spawn 流程一致）
        // The creator becomes a member only after joining too (spawn/aoe are member operations, matching the E2E create→join→spawn flow)
        $h->send('room:join', ['roomId' => 'r-gate']);
        $h->flush();

        // 非成员 spawn：恰好一条 403 回执且零刷怪
        // Non-member spawn: exactly one 403 receipt and zero spawns
        $h->batchedB = [];
        $h->sendFrom($h->connB, 'room:spawn', ['roomId' => 'r-gate', 'count' => 3]);
        $h->flush();

        $errors = self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedB), 'error');
        self::assertCount(1, $errors, '非成员 spawn 必须恰好一条 error 回执 / a non-member spawn must yield exactly one error receipt');
        self::assertSame(403, $errors[0]->payload['code']);
        self::assertSame('房间 r-gate 拒绝非成员操作 / room r-gate rejects non-member operations', $errors[0]->payload['message']);
        $room = $h->rooms->get('r-gate');
        self::assertNotNull($room);
        // 房内不得出现直入刷怪的 horde 怪（EM 中仅剩已 join 的创建者本人）
        // No directly-spawned horde monster may appear in the room (the EM holds only the joined creator)
        $monsterIds = array_map(
            static fn (\Nythros\Contracts\EntityInterface $entity): string => $entity->getId(),
            array_values(array_filter(
                $room->getEntityManager()->all(),
                static fn (\Nythros\Contracts\EntityInterface $entity): bool => str_contains($entity->getId(), '-horde-'),
            )),
        );
        self::assertSame([], $monsterIds, '非成员 spawn 不得刷怪 / a non-member spawn must not spawn anything');

        // 非成员 aoe：恰好一条 403 回执且不触达 AoE 结算
        // Non-member aoe: exactly one 403 receipt with no AoE settlement reached
        $h->batchedB = [];
        $h->sendFrom($h->connB, 'room:aoe', ['roomId' => 'r-gate', 'skillId' => 'fireball', 'cx' => 0, 'cy' => 0, 'r' => 10]);
        $h->flush();

        $messages = RoomHubHarness::decodeFrames($h->batchedB);
        $errors = self::messagesOfType($messages, 'error');
        self::assertCount(1, $errors, '非成员 aoe 必须恰好一条 error 回执 / a non-member aoe must yield exactly one error receipt');
        self::assertSame(403, $errors[0]->payload['code']);
        self::assertSame([], self::messagesOfType($messages, 'combat:aoe'), '非成员 aoe 不得产生结算帧 / a non-member aoe must never emit a settlement frame');

        // 房内成员不受影响：创建者 spawn 正常出 room:ok
        // In-room members are unaffected: the creator's spawn answers room:ok normally
        $h->batchedA = [];
        $h->send('room:spawn', ['roomId' => 'r-gate', 'count' => 1]);
        $h->flush();

        $oks = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'spawn',
        ));
        self::assertCount(1, $oks, '成员 spawn 正常放行 / a member\'s spawn passes the gate');
        // 成员 spawn 刷怪成功：房内恰 1 只直入 horde 怪（另有创建者本人的成员实体）
        // The member's spawn created its monster: exactly one directly-spawned horde monster in the room (plus the creator's own member entity)
        $memberSpawnedIds = array_map(
            static fn (\Nythros\Contracts\EntityInterface $entity): string => $entity->getId(),
            array_values(array_filter(
                $room->getEntityManager()->all(),
                static fn (\Nythros\Contracts\EntityInterface $entity): bool => str_contains($entity->getId(), '-horde-'),
            )),
        );
        self::assertCount(1, $memberSpawnedIds, '成员 spawn 刷怪成功 / the member\'s spawn created its monster');
    }

    /**
     * 匹配开房路径兼容（reviewer MAJOR-2）：无主房（owners 表无记录——MatchingService 开房不经
     * handleCreate，归属表天然缺失）的成员判定只看房内 EM——经 hub->createRoom 装配战斗上下文 +
     * admitPlayer 入房后，成员 spawn/aoe 正常放行（无 403 误伤）。真实 MatchingService 直建房的
     * spawn/aoe 本就因无 RoomHub 战斗上下文走 requireContext 存在性 400（既有行为），与本闸门正交。
     * Match-built-room compatibility (reviewer MAJOR-2): an ownerless room (no owners record — MatchingService
     * builds rooms without handleCreate, so the ownership table is naturally empty) judges membership solely by
     * the room's EM — after hub->createRoom assembles the combat context and admitPlayer admits the member,
     * the member's spawn/aoe pass normally (no false 403). A real MatchingService-built room's spawn/aoe already
     * hits the requireContext existence-400 for lacking a RoomHub combat context (pre-existing behavior),
     * orthogonal to this gate.
     */
    public function testMatchBuiltOwnerlessRoomPassesMembershipGate(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();

        // 匹配开房同构：hub->createRoom 建房（不写 owners 表 = 无主）+ admitPlayer 直接入房
        // Mirroring match-built rooms: hub->createRoom (never writes the owners table = ownerless) plus direct admission via admitPlayer
        $h->hub->createRoom('r-match');
        $h->hub->admitPlayer('1001@conn-a', 'r-match');

        $h->batchedA = [];
        $h->send('room:spawn', ['roomId' => 'r-match', 'count' => 1]);
        $h->flush();

        $spawnOks = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'spawn',
        ));
        self::assertCount(1, $spawnOks, '匹配开房成员 spawn 通过闸门 / the match-built room member\'s spawn passes the gate');

        // 房间跑一帧让直入怪进 AOI，随后成员 aoe 正常结算
        // One room frame indexes the directly-spawned monster into the AOI; the member's aoe then settles normally
        $room = $h->rooms->get('r-match');
        self::assertNotNull($room);
        $room->update();

        $h->batchedA = [];
        $h->send('room:aoe', ['roomId' => 'r-match', 'skillId' => 'fireball', 'cx' => 24, 'cy' => -24, 'r' => 300]);
        $h->flush();

        $messages = RoomHubHarness::decodeFrames($h->batchedA);
        $aoeOks = array_values(array_filter(
            self::messagesOfType($messages, 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'aoe',
        ));
        self::assertCount(1, $aoeOks, '匹配开房成员 aoe 通过闸门 / the match-built room member\'s aoe passes the gate');
        self::assertSame(1, $aoeOks[0]->payload['count'], 'AoE 命中唯一 horde 怪 / the AoE hits the single horde monster');
        self::assertSame([], self::messagesOfType($messages, 'error'), '成员操作不得产生任何错误回执 / member operations must never emit any error receipt');
    }

    /**
     * 房间归属权限（R2 review MINOR-6）：创建者 settle/close 通过；非创建者（即使在房成员）被定向
     * 403 error 拒绝且房间状态不受影响——settle 后房间仍可由创建者正常 close。
     * Room-ownership permission (R2 review MINOR-6): the creator's settle/close pass; a non-creator (even an
     * in-room member) is rejected with a directed 403 error and the room state stays untouched — after the
     * rejected attempt the creator can still close the room normally.
     */
    public function testSettleAndCloseAreCreatorOnly(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB();
        $h->send('room:create', ['roomId' => 'r-own']);
        $h->send('room:join', ['roomId' => 'r-own']);
        // B 也入房：在房成员身份不授予管理权
        // B joins too: in-room membership grants no management rights
        $h->sendFrom($h->connB, 'room:join', ['roomId' => 'r-own']);
        $h->flush();

        // 非创建者 settle/close 均被 403 拒绝
        // Non-creator settle/close are both rejected with 403
        $h->batchedB = [];
        $h->sendFrom($h->connB, 'room:settle', ['roomId' => 'r-own']);
        $h->sendFrom($h->connB, 'room:close', ['roomId' => 'r-own']);
        $h->flush();

        $messages = RoomHubHarness::decodeFrames($h->batchedB);
        $errors = self::messagesOfType($messages, 'error');
        self::assertCount(2, $errors, '非创建者两次管理操作各一条 error / one error per non-creator management op');
        self::assertSame(403, $errors[0]->payload['code']);
        self::assertSame(403, $errors[1]->payload['code']);
        self::assertSame(\Nythros\Contracts\RoomState::Running, $h->rooms->get('r-own')?->getState(), '被拒操作不改变房间状态 rejected ops never touch the room state');

        // 创建者操作通过：settle → close 全链成功
        // Creator ops pass: settle → close both succeed
        $h->batchedA = [];
        $h->send('room:settle', ['roomId' => 'r-own']);
        $h->flush();
        $oksA = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'settle',
        ));
        self::assertCount(1, $oksA, '创建者 settle 通过 the creator settles through');

        $h->batchedA = [];
        $h->send('room:close', ['roomId' => 'r-own']);
        $h->flush();
        $oksClose = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'close',
        ));
        self::assertCount(1, $oksClose, '创建者 close 通过 the creator closes through');
        self::assertNull($h->rooms->get('r-own'), 'close 后房间销毁 the room is destroyed after close');
    }

    /**
     * 创建者断连后的归属裁决（R2 review MINOR-6：标记无主，不自动转移）：断连触发 onEntityCleanedUp
     * 钩子 → 房间标记无主 → 留守玩家 B 可接管 close（防僵尸房泄漏）；接管后归属表记录随房间销毁清除。
     * The post-disconnect ownership ruling (R2 review MINOR-6: mark ownerless, never auto-transfer): the
     * disconnect fires the onEntityCleanedUp hook → the room turns ownerless → the remaining player B takes over
     * and closes it (no zombie-room leak); the ownership record dies with the room on takeover.
     */
    public function testCreatorDisconnectMarksRoomOwnerlessAndAnyPlayerTakesOver(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->authPlayerB();
        $h->send('room:create', ['roomId' => 'r-orphan']);
        $h->sendFrom($h->connB, 'room:join', ['roomId' => 'r-orphan']);
        $h->flush();

        // 创建者 A 断连（世界侧路径，onEntityCleanedUp 汇点）
        // The creator A disconnects (the world-side path; the onEntityCleanedUp sink)
        ($h->onClose)($h->connA);

        // 无主房：B 接管 close 成功
        // The ownerless room: B's takeover close succeeds
        $h->batchedB = [];
        $h->sendFrom($h->connB, 'room:close', ['roomId' => 'r-orphan']);
        $h->flush();
        $oks = array_values(array_filter(
            self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedB), 'room:ok'),
            static fn (Message $message): bool => $message->payload['op'] === 'close',
        ));
        self::assertCount(1, $oks, '无主房任意玩家可接管 close an ownerless room accepts any player\'s takeover close');
        self::assertNull($h->rooms->get('r-orphan'));
    }

    /**
     * CHASE 卡滞修复（R4）：世界怪追击玩家 → 玩家 admitPlayer transfer 进房 →
     * 怪物收到目标离场通知，放弃追击回 PATROL（修复前：目标 Actor 仍在共享 $actors 表可解析，
     * 世界 EM 查空使 moveTowardTarget 原地 no-op，怪物永久卡 CHASE）。
     * The CHASE-stall fix (R4): a world monster chases the player → the player admits into a room via the
     * transfer chain → the monster receives the target-left notice and drops back to PATROL (before the fix: the
     * target actor stayed resolvable via the shared actors table while the world EM missed, so moveTowardTarget
     * no-oped in place and the monster stalled in CHASE forever).
     */
    public function testAdmitPlayerNotifiesWorldMonstersToAbandonTarget(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();

        // 先耗尽出生保护窗口（auth 激活 60 帧）：否则感知跳过受保护玩家，怪不会锁定
        // Exhaust the spawn-protection window first (60 frames activated at auth): otherwise perception skips the
        // protected player and the monster never locks on
        $player = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $player);
        for ($i = 0; $i < PlayerActor::SPAWN_PROTECTION_FRAMES; $i++) {
            $player->update();
        }

        // 世界侧刷一只怪并与玩家同格，驱动一帧感知进入 CHASE
        // Spawn a world monster in the player's cell and drive one perception frame into CHASE
        $h->mapServer->spawnMonster('world-mon-1', 100, ['x' => 0, 'y' => 0], 'slime');
        $monster = $h->mapServer->getActor('world-mon-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $monster->update();
        self::assertSame(BaseMonster::STATE_CHASE, $monster->aiState(), '前置事实：怪已锁定玩家 precondition: the monster locked onto the player');
        self::assertSame('1001@conn-a', $monster->targetId());

        // 玩家 transfer 进房：admitPlayer 通知感知方放弃目标
        // The player transfers into a room: admitPlayer notifies perceivers to drop the target
        $h->send('room:create', ['roomId' => 'r-chase']);
        $h->send('room:join', ['roomId' => 'r-chase']);
        $h->flush();

        self::assertSame(BaseMonster::STATE_PATROL, $monster->aiState(), '目标离场后怪回 PATROL the monster returns to PATROL after the target left');
        self::assertNull($monster->targetId(), '追击目标已清 the chase target is cleared');
    }

    /**
     * 房内反作弊（R4 MINOR-7 债务关闭）：注入 MovementValidator 后，房内超速 move 被定向
     * error{403, move rejected: overspeed} 拒绝——与世界模板同一实例、同一拒绝口径；坐标零副作用
     * （拒绝后合法步从原坐标起算），连接不断（后续 move 正常结算）。
     * In-room anti-cheat (the R4 MINOR-7 debt closed): with a MovementValidator injected, an in-room overspeed
     * move is rejected with a directed error{403, move rejected: overspeed} — the same instance and rejection
     * contract as the world template; zero side effects on coordinates (a legal step after rejection starts from
     * the untouched position) and the connection stays open (later moves settle normally).
     */
    public function testInRoomOverspeedMoveIsRejectedBySharedValidator(): void
    {
        $h = $this->buildHarness();
        // 与世界路径同一实例：setMovementValidator 注入后世界与房内两条移动路径共享
        // The same instance as the world path: after setMovementValidator both move paths share it
        $validator = new MovementValidator();
        $h->mapServer->setMovementValidator($validator);

        $h->authPlayer();
        $h->send('room:create', ['roomId' => 'r-ac']);
        $h->send('room:join', ['roomId' => 'r-ac']);
        $h->flush();

        // 房内超速：dx=3 超出缺省单步轴上限 2 → overspeed（403 走 send 直发路径，捕获于 sentA）
        // In-room overspeed: dx=3 exceeds the default per-axis step cap of 2 → overspeed (the 403 rides the direct
        // send path, captured in sentA)
        $h->batchedA = [];
        $h->sentA = [];
        $h->send('move', ['dx' => 3, 'dy' => 0]);
        $h->flush();

        $errors = self::messagesOfType(RoomHubHarness::decodeFrames(array_merge($h->sentA, $h->batchedA)), 'error');
        self::assertCount(1, $errors, '房内超速必须恰好一条 error 回执 an in-room overspeed must yield exactly one error receipt');
        self::assertSame(403, $errors[0]->payload['code']);
        self::assertSame('move rejected: overspeed', $errors[0]->payload['message']);

        // 坐标零副作用：拒绝后合法步 {1,0} 从原点 (0,0) 起算落 (1,0)
        // Zero side effects: the legal step {1,0} after the rejection lands at (1,0) counted from the origin
        $h->batchedA = [];
        $h->send('move', ['dx' => 1, 'dy' => 0]);
        $h->flush();
        $moved = $h->rooms->get('r-ac')?->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($moved);
        self::assertSame(['x' => 1, 'y' => 0], $moved->getPosition(), '拒绝无副作用，后续合法步正常结算 the rejection has no side effects; the later legal step settles normally');
        self::assertSame([], self::messagesOfType(RoomHubHarness::decodeFrames($h->batchedA), 'error'), '连接不断，合法步无错误回执 the connection stays open; no error for the legal step');

        // 同一实例证据：世界侧窗口状态被房内校验共享——validator 已记录该实体窗口行
        // Same-instance evidence: the world-side window state is shared with in-room checks — the validator holds this entity's window row
        $prop = new \ReflectionProperty(MovementValidator::class, 'windows');
        self::assertArrayHasKey('1001@conn-a', $prop->getValue($validator), '房内校验写入的是共享 validator 的窗口行 the in-room check wrote into the shared validator\'s window row');
    }

    /**
     * 组装 RoomHub 测试线束。
     * Builds the RoomHub test harness.
     */
    private function buildHarness(): RoomHubHarness
    {
        $h = new RoomHubHarness();

        $h->connA = $this->createStub(ConnectionInterface::class);
        $h->connA->method('getId')->willReturn('conn-a');
        $h->connA->method('getSendBufferQueueSize')->willReturn(0);
        $h->connA->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentA[] = $payload;
        });
        $h->connA->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedA = array_merge($h->batchedA, $payloads);
        });

        // 第二客户端（uid 1002 → entityId 1002@conn-b）：跨容器误伤防护等双连接场景用
        // The second client (uid 1002 → entityId 1002@conn-b): for dual-connection scenarios such as the cross-container friendly-fire guard
        $h->connB = $this->createStub(ConnectionInterface::class);
        $h->connB->method('getId')->willReturn('conn-b');
        $h->connB->method('getSendBufferQueueSize')->willReturn(0);
        $h->connB->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedB = array_merge($h->batchedB, $payloads);
        });

        $server = $this->createStub(ServerInterface::class);
        $server->method('onWorkerStart')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onWorkerStart = $handler;
        });
        $server->method('onConnect')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onConnect = $handler;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onMessage = $handler;
        });
        $server->method('onClose')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onClose = $handler;
        });

        $h->tokens = new FakeTokenManager();
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);
        $h->tokens->records['token-b'] = new TokenRecord('1002', 'map-1', ['map'], 0.0, 999.0);

        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $h->registry = new ConnectionRegistry();
        $h->world = new World(new SimpleEntityManager(), $actorSystem, new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $h->timer = new RoomHubFakeTimer();
        $h->clock = new RoomHubFakeClock();

        $skills = new SkillRepository();
        $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));

        $dropTable = new DropTable(['gold' => 1]);
        $typeIndex = new EntityTypeIndex();
        $random = new FixedRandomSource(100);

        $h->rooms = new RoomInstanceManager();
        $h->hub = new RoomHub($h->rooms, $h->world, $skills, $items, $random, $dropTable, $typeIndex);

        $h->mapServer = new MapServer(
            $server,
            new JsonBatchSerializer(),
            $h->tokens,
            $h->world,
            $h->registry,
            clock: $h->clock,
            timer: $h->timer,
            serviceId: 'map-1#ch-1',
            mapId: 'map-1',
            serviceRegistry: new FakeServiceRegistry(),
            dropTable: $dropTable,
            typeIndex: $typeIndex,
            skills: $skills,
            random: $random,
            rooms: $h->hub,
        );
        $h->hub->attach($h->mapServer);
        $h->mapServer->attachCombat(new \Nythros\Framework\Combat\CombatService($h->world, $h->mapServer, $skills, $items, $random));
        $h->mapServer->register();

        ($h->onWorkerStart)();

        return $h;
    }

    /**
     * 按消息类型过滤并返回全部匹配消息。
     * Filters messages by type and returns all matches.
     *
     * @param list<Message> $messages 已解码消息列表 Decoded messages.
     * @return list<Message> 匹配消息列表 Matching messages.
     */
    private static function messagesOfType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->type === $type,
        ));
    }
}

/**
 * RoomHubTest 测试线束：持有房间依赖与消息驱动工具。
 * The RoomHubTest harness: holds the room dependencies and message-driving helpers.
 */
final class RoomHubHarness
{
    public ConnectionInterface $connA;
    public ConnectionInterface $connB;
    public WorldInterface $world;
    public ConnectionRegistry $registry;
    public RoomInstanceManager $rooms;
    public RoomHub $hub;
    public MapServer $mapServer;
    public FakeTokenManager $tokens;
    public RoomHubFakeTimer $timer;
    public RoomHubFakeClock $clock;

    /** @var null|callable worker start / connect / message / close 回调 Worker-start / connect / message / close callbacks. */
    public $onWorkerStart = null;
    public $onConnect = null;
    public $onMessage = null;
    public $onClose = null;

    /** @var list<string> connA 经 sendBatch 批量发送的帧 Frames batch-sent to connA via sendBatch. */
    public array $batchedA = [];

    /** @var list<string> connB 经 sendBatch 批量发送的帧 Frames batch-sent to connB via sendBatch. */
    public array $batchedB = [];

    /** @var list<string> connA 经 send 直接发送的帧（500 兜底等直发路径） Frames sent directly to connA via send (the 500-fallback direct path). */
    public array $sentA = [];

    /** 认证玩家（uid 1001 → entityId 1001@conn-a，位置 (0,0)）。Authenticates the player (uid 1001 → entityId 1001@conn-a at (0,0)). */
    public function authPlayer(): void
    {
        ($this->onConnect)($this->connA);
        ($this->onMessage)($this->connA, self::frame('auth', ['token' => 'token-a'], 'auth-1'));
    }

    /** 认证第二玩家（uid 1002 → entityId 1002@conn-b，位置 (0,0)，留守大世界）。Authenticates the second player (uid 1002 → entityId 1002@conn-b at (0,0), staying in the host world). */
    public function authPlayerB(): void
    {
        ($this->onConnect)($this->connB);
        ($this->onMessage)($this->connB, self::frame('auth', ['token' => 'token-b'], 'auth-2'));
    }

    /** 发送一条已认证消息。Sends one authenticated message. */
    public function send(string $type, array $payload): void
    {
        ($this->onMessage)($this->connA, self::frame($type, $payload));
    }

    /** 从指定连接发送一条已认证消息。Sends one authenticated message from the given connection. */
    public function sendFrom(ConnectionInterface $conn, string $type, array $payload): void
    {
        ($this->onMessage)($conn, self::frame($type, $payload));
    }

    /** 驱动两帧世界 tick：帧 N 投递 flush 任务，帧 N+1 的 runFrame 执行 flush。Drives two world ticks: frame N submits the flush task, frame N+1's runFrame executes it. */
    public function flush(): void
    {
        $this->timer->trigger();
        $this->timer->trigger();
    }

    /** 构造一条合法协议帧字节。Builds a valid protocol frame payload. */
    public static function frame(string $type, array $payload, ?string $requestId = null): string
    {
        return json_encode([
            'type' => $type,
            'requestId' => $requestId,
            'timestamp' => 123.0,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    /** 把帧字节列表解码为消息列表。Decodes frame bytes into messages. */
    public static function decodeFrames(array $frames): array
    {
        $serializer = new JsonBatchSerializer();
        $out = [];
        foreach ($frames as $frame) {
            foreach ($serializer->decodeBatch($frame) as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }
}

/**
 * RoomHubFakeTimer - 测试定时器：只记录回调不真正定时，由测试经 trigger 手动驱动（类名唯一，避免与其他测试线束冲突）。
 * RoomHubFakeTimer - test timer: records callbacks without real timing, driven manually by tests via trigger (unique class name, avoiding clashes with other harnesses).
 */
final class RoomHubFakeTimer implements TimerInterface
{
    /** @var list<callable> 已登记的回调 Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
        // 测试不需要取消语义，空操作 No cancellation semantics needed in tests; no-op
    }

    public function trigger(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }
}

/**
 * RoomHubFakeClock - 测试时钟：每次 tick 推进固定 50ms（类名唯一）。
 * RoomHubFakeClock - test clock: advances a fixed 50ms per tick (unique class name).
 */
final class RoomHubFakeClock implements ClockInterface
{
    /** @var float 当前时钟时间（秒） Current clock time in seconds. */
    private float $current = 0.0;

    public function tick(): void
    {
        $this->current += 0.05;
    }

    public function now(): float
    {
        return $this->current;
    }

    public function deltaTime(): float
    {
        return 0.05;
    }
}
