<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomState;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\World\RoomInstanceManager;
use PHPUnit\Framework\TestCase;

/**
 * RoomInstanceManagerTest - 覆盖到期驱动调度（假时钟）：到期判定、追帧跳帧上限、预算截断顺延、
 * 跨房转移原子语义与回滚、归属表双房拒绝、destroy 强制结算清理。
 * RoomInstanceManagerTest - covers due-driven scheduling under a fake clock: due judgment, catch-up/skip-frame
 * cap, budget truncation with deferral, atomic cross-room transfer semantics with rollback, ownership-table
 * double-housing rejection, and destroy's forced settle/clear.
 */
final class RoomInstanceManagerTest extends TestCase
{
    public function testCreateRegistersRoomWithLazyDue(): void
    {
        $manager = $this->createManager();

        $room = $manager->create($this->config('r1'));

        self::assertSame('r1', $room->getRoomId());
        self::assertSame(RoomState::Created, $room->getState());
        self::assertSame($room, $manager->get('r1'));
        self::assertNull($manager->get('nope'));
        self::assertSame([$room], $manager->all());
    }

    public function testCreateDuplicateRoomIdThrows(): void
    {
        $manager = $this->createManager();
        $manager->create($this->config('r1'));

        $this->expectException(\InvalidArgumentException::class);

        $manager->create($this->config('r1'));
    }

    /**
     * 到期判定：首次观察即到期跑一帧；未到周期不驱动。
     * Due judgment: the first observation is immediately due and runs one frame; within the period nothing is driven.
     */
    public function testTickDrivesOnlyDueRooms(): void
    {
        $manager = $this->createManager();
        $actor = new ManagerCountingActor();
        $room = $manager->create($this->config('r1', periodMs: 50));
        $room->join(new BaseEntity('p1', new Position(0, 0)), $actor);

        // 首次观察：立即到期执行一帧 first observation: immediately due, one frame
        self::assertSame(['updated' => 1, 'deferred' => 0], $manager->tick(0.0));
        self::assertSame(1, $actor->updates);

        // 未到期：不驱动 not yet due: not driven
        self::assertSame(['updated' => 0, 'deferred' => 0], $manager->tick(0.010));
        self::assertSame(1, $actor->updates);

        // 恰好到期：一帧 exactly due: one frame
        self::assertSame(['updated' => 1, 'deferred' => 0], $manager->tick(0.050));
        self::assertSame(2, $actor->updates);
    }

    /**
     * 追帧跳帧：落后恰为 maxCatchUpTicks 个周期走逐帧追帧（追满全部欠帧）；
     * 落后超过上限则跳帧对齐——只执行一帧并把 nextDueAt 对齐当前时刻，防死亡螺旋。
     * Catch-up/skip-frame: being behind by exactly maxCatchUpTicks periods takes the frame-by-frame path
     * (all owed frames run); beyond the cap a single frame runs and nextDueAt aligns to now,
     * preventing the death spiral.
     */
    public function testTickCatchUpThenSkipFrameBeyondCap(): void
    {
        $manager = $this->createManager();
        $actor = new ManagerCountingActor();
        // 周期 10ms、上限 4：落后 40ms = 恰 4 周期 → 逐帧追帧 period 10ms cap 4: 40ms behind = exactly 4 periods → frame-by-frame
        $room = $manager->create($this->config('r1', periodMs: 10, maxCatchUpTicks: 4));
        $room->join(new BaseEntity('p1', new Position(0, 0)), $actor);

        $manager->tick(0.000); // 初始化 + 1 帧 init + 1 frame
        self::assertSame(1, $actor->updates);

        // 落后 40ms（nextDue=10ms，now=50ms，behind=4 未超上限）：逐帧追满 10/20/30/40/50 共 5 帧
        // 40ms behind (nextDue=10ms, now=50ms, behind=4, within cap): catches up all of 10/20/30/40/50 = 5 frames
        self::assertSame(['updated' => 5, 'deferred' => 0], $manager->tick(0.050));
        self::assertSame(6, $actor->updates);

        // 再落后 90ms（nextDue=60ms，now=150ms，behind=9 > 4）：跳帧只跑 1 帧并对齐 now+period
        // 90ms behind (nextDue=60ms, now=150ms, behind=9 > 4): skip-frame runs exactly 1 frame and aligns to now+period
        self::assertSame(['updated' => 1, 'deferred' => 0], $manager->tick(0.150));
        self::assertSame(7, $actor->updates);

        // 对齐后下一周期恢复正常节奏 next period resumes normal cadence after alignment
        self::assertSame(['updated' => 1, 'deferred' => 0], $manager->tick(0.160));
        self::assertSame(8, $actor->updates);
    }

    /**
     * 预算截断：单次 tick 处理中实测耗时达预算即止，剩余到期房间计 deferred；
     * 被顺延房间 nextDueAt 不变，下一心跳仍到期即补跑。
     * Budget truncation: once measured elapsed time reaches the budget within one tick, stop; remaining due rooms
     * count as deferred; a deferred room keeps its nextDueAt and is picked up on the next heartbeat.
     */
    public function testTickBudgetTruncationDefersRemainingRooms(): void
    {
        // 受控时钟：调用序列 [tick 起点, 房间 A 前, 房间 B 前] = [0, 0, 100]，预算 30ms
        // controlled clock: call sequence [tick start, before room A, before room B] = [0, 0, 100] with a 30ms budget
        $sequence = [0.0, 0.0, 100.0];
        $index = 0;
        $clock = static function () use ($sequence, &$index): float {
            return $sequence[$index++] ?? 100.0;
        };

        $manager = new RoomInstanceManager(clock: $clock, budgetMs: 0.030);
        $actorA = new ManagerCountingActor();
        $actorB = new ManagerCountingActor();

        $roomA = $manager->create($this->config('a', periodMs: 10));
        $roomB = $manager->create($this->config('b', periodMs: 10));
        $roomA->join(new BaseEntity('pa', new Position(0, 0)), $actorA);
        $roomB->join(new BaseEntity('pb', new Position(0, 0)), $actorB);

        self::assertSame(['updated' => 1, 'deferred' => 1], $manager->tick(0.0));
        self::assertSame(1, $actorA->updates, '预算内房间应完成本帧 the in-budget room completes its frame');
        self::assertSame(0, $actorB->updates, '超预算房间应被顺延 the over-budget room is deferred');

        // 下一心跳：被顺延的 b 补跑一帧 next heartbeat: the deferred room b catches up one frame
        self::assertSame(['updated' => 1, 'deferred' => 0], $manager->tick(0.001));
        self::assertSame(1, $actorB->updates);
    }

    public function testTransferFromWorldIntoRoomRegistersOwnership(): void
    {
        $manager = $this->createManager();
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));

        self::assertTrue($manager->transfer(null, 'r1', $entity));
        self::assertSame($entity, $room->getEntityManager()->get('p1'));

        // 双房拒绝：已属房间 r1 的实体不得再从「大世界」进入另一房 double-housing rejection: an entity owned by r1 cannot enter another room "from the world"
        $other = $manager->create($this->config('r2'));
        self::assertFalse($manager->transfer(null, 'r2', $entity));
        self::assertNull($other->getEntityManager()->get('p1'));
    }

    public function testTransferBetweenRoomsMovesMembershipAndActor(): void
    {
        $manager = $this->createManager();
        $source = $manager->create($this->config('r1'));
        $target = $manager->create($this->config('r2'));
        $actor = new ManagerCountingActor();
        $entity = new BaseEntity('p1', new Position(1, 1));

        self::assertTrue($manager->transfer(null, 'r1', $entity, $actor));
        $before = $actor->updates;
        $source->update();
        self::assertSame($before + 1, $actor->updates, '转移前 Actor 由源房驱动 the actor is driven by the source room before transfer');

        self::assertTrue($manager->transfer('r1', 'r2', $entity, $actor));
        self::assertNull($source->getEntityManager()->get('p1'), '源房摘除 removed from the source room');
        self::assertSame($entity, $target->getEntityManager()->get('p1'), '目标房登记 registered in the target room');

        $target->update();
        self::assertSame($before + 2, $actor->updates, '转移后 Actor 只由目标房驱动一次 the actor is driven exactly once by the target room after transfer');
        $source->update();
        self::assertSame($before + 2, $actor->updates, '源房不再驱动该 Actor the source room no longer drives the actor');
    }

    public function testTransferRejectsOwnershipMismatch(): void
    {
        $manager = $this->createManager();
        $manager->create($this->config('r1'));
        $owner = $manager->create($this->config('r3'));
        $target = $manager->create($this->config('r2'));

        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r3', $entity);

        // 归属表记录为 r3：声称从 r1 转移应被拒 ownership says r3: claiming a transfer from r1 must be rejected
        self::assertFalse($manager->transfer('r1', 'r2', $entity));
        self::assertNull($target->getEntityManager()->get('p1'));

        // 从未入册的实体也不能凭空从房间转出 an unregistered entity cannot transfer out of a room either
        $stranger = new BaseEntity('px', new Position(2, 2));
        self::assertFalse($manager->transfer('r1', 'r2', $stranger));
    }

    public function testTransferUnknownRoomReturnsFalse(): void
    {
        $manager = $this->createManager();
        $entity = new BaseEntity('p1', new Position(1, 1));

        self::assertFalse($manager->transfer(null, 'ghost', $entity), '未知目标房返回 false unknown target room returns false');

        $manager->create($this->config('r1'));
        self::assertTrue($manager->transfer(null, 'r1', $entity));
        self::assertFalse($manager->transfer('ghost', 'r1', $entity), '未知源房返回 false unknown source room returns false');
    }

    public function testTransferRollbackWhenTargetFull(): void
    {
        $manager = $this->createManager();
        $source = $manager->create($this->config('r1'));
        $target = $manager->create($this->config('r2', maxMembers: 1));

        $occupant = new BaseEntity('occ', new Position(9, 9));
        $mover = new BaseEntity('mv', new Position(1, 1));
        $manager->transfer(null, 'r1', $mover);
        $manager->transfer(null, 'r2', $occupant); // 占满 r2 fills r2

        self::assertFalse($manager->transfer('r1', 'r2', $mover), '满员转移失败 full target fails the transfer');
        // 回滚：实体仍在源房、归属表不变 rollback: the entity is still in the source room, ownership unchanged
        self::assertSame($mover, $source->getEntityManager()->get('mv'), '回滚后实体仍在源房 the entity is back in the source room after rollback');
        self::assertNull($target->getEntityManager()->get('mv'));
        self::assertTrue($manager->transfer('r1', $manager->create($this->config('r4'))->getRoomId(), $mover), '回滚后仍可正常转移 transfers still work after the rollback');
    }

    public function testTransferToSettledRoomRejectedWithoutMutation(): void
    {
        $manager = $this->createManager();
        $source = $manager->create($this->config('r1'));
        $settled = $manager->create($this->config('r2'));
        $settled->settle();

        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);

        self::assertFalse($manager->transfer('r1', 'r2', $entity), '向已结算房间转移被拒 transferring into a settled room is rejected');
        self::assertSame($entity, $source->getEntityManager()->get('p1'), '源房未被扰动 the source room is untouched');
    }

    public function testSameRoomTransferRejected(): void
    {
        $manager = $this->createManager();
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);

        self::assertFalse($manager->transfer('r1', 'r1', $entity));
        self::assertSame($entity, $room->getEntityManager()->get('p1'));
    }

    public function testDestroyForcesSettleCloseRemovalAndPurgesOwnership(): void
    {
        $bus = new SimpleEventBus();
        $closed = [];
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_CLOSED, static function (EventEnvelope $envelope) use (&$closed): void {
            $closed[] = $envelope;
        });

        // 注入测试总线为共享宿主总线，验证 destroy 的 room.closed 信封可达订阅方
        // inject the test bus as the shared host bus to verify destroy's room.closed envelopes reach subscribers
        $manager = new RoomInstanceManager(clock: static fn (): float => 0.0, budgetMs: 30.0, eventBus: $bus);
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);

        $manager->destroy('r1');

        self::assertNull($manager->get('r1'), '销毁后房间移除 the room is gone after destroy');
        self::assertSame(RoomState::Closed, $room->getState(), '强制走完 settle→close forced through settle→close');
        self::assertSame([], $room->getEntityManager()->all(), '成员清空 members cleared');

        // 归属表同步清除：实体可再次进入其他房间 ownership purged: the entity may enter another room again
        $fresh = $manager->create($this->config('r2'));
        self::assertTrue($manager->transfer(null, 'r2', $entity), '销毁后原成员可重新进入 the former member can re-enter after destroy');
        self::assertSame($entity, $fresh->getEntityManager()->get('p1'));

        // 存活成员收到 room.closed 信封 surviving members received room.closed envelopes
        $bus->flush();
        self::assertCount(1, $closed);
        self::assertSame('p1', $closed[0]->targetScope);
    }

    public function testDestroyUnknownOrRepeatedIsSilent(): void
    {
        $manager = $this->createManager();

        $manager->destroy('ghost');
        $manager->create($this->config('r1'));
        $manager->destroy('r1');
        $manager->destroy('r1');

        self::assertNull($manager->get('r1'));
        $this->addToAssertionCount(1);
    }

    public function testTickSkipsTerminalRoomsEvenWhenDue(): void
    {
        $manager = $this->createManager();
        $actor = new ManagerCountingActor();
        $room = $manager->create($this->config('r1', periodMs: 10));
        $room->join(new BaseEntity('p1', new Position(0, 0)), $actor);

        $manager->tick(0.0);
        self::assertSame(1, $actor->updates);

        $room->settle();
        $room->close();

        // 终态房间即使到期也不驱动 terminal rooms are not driven even when due
        self::assertSame(['updated' => 0, 'deferred' => 0], $manager->tick(1.0));
        self::assertSame(1, $actor->updates);
    }

    /**
     * V3（ADR-024 §9）：evictFromAny 命中——按归属表定位房间并复用 leave 全链：
     * 摘 EM/AOI/ActorSystem、留守成员收 member_leave、离开者收 room.left、归属记录清除
     * （清除后实体可再次从大世界进入其他房间）。
     * V3 (ADR-024 §9): an evictFromAny hit — locates the room via the ownership table and reuses the full leave
     * chain: EM/AOI/ActorSystem removal, remaining members get member_leave, the departer gets room.left, and the
     * ownership record is purged (the entity may enter another room from the world again afterwards).
     */
    public function testEvictFromAnyRemovesMemberAndBroadcastsLeave(): void
    {
        $bus = new SimpleEventBus();
        $memberLeaves = [];
        $roomLefts = [];
        $bus->subscribe(RoomInstanceInterface::EVENT_MEMBER_LEAVE, static function (EventEnvelope $envelope) use (&$memberLeaves): void {
            $memberLeaves[] = $envelope;
        });
        $bus->subscribe(RoomInstanceInterface::EVENT_ROOM_LEFT, static function (EventEnvelope $envelope) use (&$roomLefts): void {
            $roomLefts[] = $envelope;
        });

        $manager = new RoomInstanceManager(clock: static fn (): float => 0.0, budgetMs: 30.0, eventBus: $bus);
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);
        // 第二个成员留守，验证其收到 member_leave a second member stays behind to receive member_leave
        $manager->transfer(null, 'r1', new BaseEntity('p2', new Position(2, 2)));

        self::assertTrue($manager->evictFromAny('p1'), '房内成员必须被摘除 an in-room member must be evicted');
        self::assertNull($room->getEntityManager()->get('p1'), '房间 EM 已摘除 removed from the room EM');
        self::assertFalse($manager->evictFromAny('p1'), '重复清理幂等返回 false repeated cleanup is idempotently false');

        // 归属已清除：实体可再次从大世界进入新房间 ownership purged: the entity may enter another room from the world again
        $fresh = $manager->create($this->config('r2'));
        self::assertTrue($manager->transfer(null, 'r2', $entity), '摘除后可重新入房 re-entry works after eviction');

        $bus->flush();
        self::assertCount(1, $memberLeaves);
        self::assertSame('p1', $memberLeaves[0]->source, 'member_leave.source 为离开者 the leaver is member_leave\'s source');
        self::assertSame('p2', $memberLeaves[0]->targetScope, '留守成员收到 member_leave the remaining member receives member_leave');
        self::assertCount(1, $roomLefts);
        self::assertSame('p1', $roomLefts[0]->targetScope, '离开者收到 room.left 回执 the leaver receives the room.left receipt');
    }

    /**
     * V3：evictFromAny 未命中——从未入册的实体返回 false（大世界成员由调用方模板清理，
     * 引擎侧静默幂等，不抛错不扰动）。
     * V3: an evictFromAny miss — an entity never registered returns false (world members are cleaned by the
     * caller's template; the engine side stays silently idempotent, no throw, no disturbance).
     */
    public function testEvictFromAnyUnknownEntityReturnsFalse(): void
    {
        $manager = $this->createManager();
        $room = $manager->create($this->config('r1'));

        self::assertFalse($manager->evictFromAny('ghost'), '未知实体返回 false unknown entity returns false');
        self::assertSame([], $room->getEntityManager()->all(), '房间未被扰动 the room is untouched');

        // 大世界成员（未经 transfer 入册）同样不在职责内 a world member (never transferred in) is equally out of scope
        $worldMember = new BaseEntity('pw', new Position(0, 0));
        $room->join($worldMember);
        self::assertFalse($manager->evictFromAny('pw'), 'join 直入成员不入归属表，不归 evictFromAny 管 a join-admitted member never enters the ownership table');
        self::assertSame($worldMember, $room->getEntityManager()->get('pw'), 'join 直入成员不被误摘 a join-admitted member is not falsely evicted');
    }

    /**
     * V3：已 settle 房仍可摘除断连成员——settle 只停收新成员，存量成员的断连清理照常走 leave 全链；
     * close 清空成员后 evictFromAny 静默幂等。
     * V3: a settled room still evicts disconnecting members — settle only stops admissions, existing members'
     * disconnect cleanup takes the full leave chain as usual; after close clears the members, evictFromAny is silently idempotent.
     */
    public function testEvictFromAnySettledRoomStillEvicts(): void
    {
        $manager = $this->createManager();
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);

        $room->settle();
        self::assertTrue($manager->evictFromAny('p1'), 'settle 后断连成员仍须摘除（防幽灵滞留） a post-settle disconnecting member must still be evicted (no ghost lingering)');
        self::assertNull($room->getEntityManager()->get('p1'));

        // close 清空后再次清理：false 幂等 after close clears everything, another eviction is idempotently false
        $room->close();
        self::assertFalse($manager->evictFromAny('p1'));
    }

    /**
     * V3：destroy 后 evictFromAny 安全——归属表已被 destroy 清除，返回 false 且不抛错
     * （与 destroy 的「未知 roomId 静默」口径一致）。
     * V3: evictFromAny after destroy is safe — the ownership table was purged by destroy, so it returns false
     * without throwing (matching destroy's "unknown roomId is silent" convention).
     */
    public function testEvictFromAnyAfterDestroyIsSilentlyFalse(): void
    {
        $manager = $this->createManager();
        $room = $manager->create($this->config('r1'));
        $entity = new BaseEntity('p1', new Position(1, 1));
        $manager->transfer(null, 'r1', $entity);
        $manager->destroy('r1');

        self::assertFalse($manager->evictFromAny('p1'), 'destroy 后清理安全幂等 cleanup after destroy is safely idempotent');
        $this->addToAssertionCount(1);
    }

    /**
     * 构造管理器（缺省时钟恒 0，预算充足）。
     * Creates a manager (default clock frozen at 0 with ample budget).
     */
    /**
     * P9c 预算压力自调：连续顺延的房间抬高动态周期（降档），预算余量恢复后逐步回落到配置周期。
     * The P9c budget-pressure self-tuning: a room deferred repeatedly raises its dynamic period (downgrades)
     * and steps back down to the configured period once budget headroom recovers.
     */
    public function testDeferralPressureInflatesPeriodAndRecovers(): void
    {
        // 受控时钟：压力档下每次调用都单调越过本 tick 的预算线（deadline = 本 tick 首调 + 30ms）——
        // 此时仅 B 到期（A 的周期 50ms 未到，不触发预算检查），故只有 B 被顺延。
        // Controlled clock: under pressure every call monotonically overshoots this tick's budget line
        // (deadline = this tick's first call + 30ms) — only B is due then (A's 50ms period is not, so its
        // budget check never fires), hence only B gets deferred.
        $now = 0.0;
        $overBudget = false;
        $calls = 0;
        $clock = static function () use (&$now, &$overBudget, &$calls): float {
            $calls++;

            return $overBudget ? $now + $calls * 1.0 : $now;
        };

        // 错峰周期：A 500ms（压力档内不到期、预算检查不触发）、B 5ms（每 tick 到期）——
        // 压力只落在 B 上，A 作为不膨胀的对照。
        // Staggered periods: A at 500ms (never due under pressure, its budget check never fires) and B at
        // 5ms (due every tick) — pressure lands on B only, with A as the non-inflating control.
        $manager = new RoomInstanceManager(clock: $clock, budgetMs: 0.030, maxDynamicPeriodMs: 80);
        $roomA = $manager->create($this->config('a', periodMs: 500));
        $roomB = $manager->create($this->config('b', periodMs: 5));

        // 预热拍：两房惰性初始化各跑一帧（A nextDueAt=0.5、B nextDueAt=0.005）
        // The warm-up tick: both rooms lazily run one frame (A nextDueAt=0.5, B nextDueAt=0.005).
        $manager->tick($now);

        // 压力档：B 连续两轮被顺延 → 周期膨胀 5 → ceil(5*1.5)=8；A 未被顺延不膨胀
        // Pressure: B deferred twice in a row → its period inflates 5 → ceil(5*1.5)=8; A never deferred, no inflation.
        $overBudget = true;
        // 压力拍取 0.006/0.007：B 周期 5ms，0.001 时还不到期（预热后 nextDueAt=0.005）
        // Pressure ticks at 0.006/0.007: B's period is 5ms — at 0.001 it isn't due yet (nextDueAt=0.005 after warm-up).
        $manager->tick($now + 0.006);
        $manager->tick($now + 0.007);
        self::assertSame(8, $manager->periodMap()['b'], '连续顺延 → 房间周期膨胀（降档）');
        self::assertSame(500, $manager->periodMap()['a'], '如期房间不膨胀');

        // 余量档：零顺延 → 周期逐步回落到配置值（8 → 7 → 6 → 5）
        // Headroom: zero deferrals → the period steps back down to the configured value (8 → 7 → 6 → 5).
        $overBudget = false;
        $manager->tick($now + 0.003);
        self::assertSame(7, $manager->periodMap()['b'], '余量回升第一拍');
        $manager->tick($now + 0.003);
        $manager->tick($now + 0.003);
        $manager->tick($now + 0.003);
        self::assertSame(5, $manager->periodMap()['b'], '余量回升到配置周期（下限）');
        self::assertSame(0, $manager->lastDeferred(), '余量档零顺延（指标）');
        self::assertNotNull($roomA);
        self::assertNotNull($roomB);
    }

    /**
     * P9c 准入上限：进程房间数触顶 → create 抛 OverflowException（RoomHub/Matching 转译 busy）。
     * The P9c admission cap: a full process throws an OverflowException from create (translated into
     * busy by RoomHub/Matching).
     */
    public function testAdmissionCapThrowsWhenFull(): void
    {
        $manager = new RoomInstanceManager(clock: static fn (): float => 0.0, maxRooms: 1);
        $manager->create($this->config('r1'));

        $this->expectException(\OverflowException::class);
        $manager->create($this->config('r2'));
    }

    private function createManager(): RoomInstanceManager
    {
        return new RoomInstanceManager(clock: static fn (): float => 0.0);
    }

    /**
     * 构造房间配置（GridAOI 工厂）。
     * Creates a room config (GridAOI factory).
     */
    private function config(string $roomId, int $periodMs = 50, int $maxMembers = 8, int $maxCatchUpTicks = 4): RoomConfig
    {
        return new RoomConfig(
            roomId: $roomId,
            periodMs: $periodMs,
            maxMembers: $maxMembers,
            aoiFactory: static fn (): GridAOI => new GridAOI(10),
            maxCatchUpTicks: $maxCatchUpTicks,
        );
    }
}

/**
 * 计数 Actor 测试桩（Manager 测试专用，避免跨测试文件共享类）。
 * Counting actor test stub (manager-test-local to avoid cross-file class sharing).
 */
final class ManagerCountingActor implements ActorInterface
{
    public int $updates = 0;

    public function update(): void
    {
        $this->updates++;
    }
}
