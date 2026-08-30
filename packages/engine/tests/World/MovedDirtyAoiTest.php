<?php

declare(strict_types=1);

namespace Nythros\World\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EventBusInterface;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\SchedulerInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MovedDirtyAoiTest - AOI moved-dirty 增量刷新的五条正确性红线专项：
 * ① 实体首次加入世界必须视为 dirty；② 同帧多次移动只算一次；③ 传送/强制位置变更走同一标记路径；
 * ④ 视野事件双向生成语义不变（entered/left 成对）；⑤ 同格 fast path 行为不变。
 * 附带核心性能语义：静止实体过帧零 AOI updateEntity 调用。
 * MovedDirtyAoiTest - the five correctness red lines of the AOI moved-dirty incremental refresh:
 * (1) a newly added entity must count as dirty; (2) multiple moves within one frame count once;
 * (3) teleports / forced repositioning ride the same marking path; (4) bidirectional vision-event semantics
 * unchanged (entered/left in pairs); (5) same-cell fast-path behavior unchanged.
 * Plus the core performance semantic: stationary entities cross a frame with zero AOI updateEntity calls.
 */
final class MovedDirtyAoiTest extends TestCase
{
    public function testNewlyAddedEntityIsDirtyOnFirstFrame(): void
    {
        // 红线①：add 即 markMoved —— 新实体首帧必进 drainMoved，保证必进 AOI 索引
        // Red line 1: add implies markMoved — a new entity enters the first drainMoved, so it always enters the AOI index
        $manager = new SimpleEntityManager();
        $entity = new BaseEntity('a', new Position(0, 0));

        $manager->add($entity);

        self::assertSame([$entity], $manager->drainMoved());
        // 取走即清空：下一帧不再重复刷新 the drain clears: no repeat refresh on the next frame
        self::assertSame([], $manager->drainMoved());
    }

    public function testMultipleMovesInSameFrameCountOnce(): void
    {
        // 红线②：同帧多次 move 只置一次位 —— drainMoved 去重，AOI 每帧至多刷新一次
        // Red line 2: multiple moves within one frame set the flag once — drainMoved dedupes, the AOI refreshes at most once per frame
        $manager = new SimpleEntityManager();
        $entity = new BaseEntity('a', new Position(0, 0));
        $manager->add($entity);
        $manager->drainMoved(); // 清掉登记 dirty clear the registration dirty

        $entity->move(5, 0);
        $entity->move(5, 0);
        $entity->move(5, 0);

        self::assertSame([$entity], $manager->drainMoved(), '同一实体同帧多次移动只出现一次');
        self::assertSame(['x' => 15, 'y' => 0], $entity->getPosition(), '三次增量全部生效');
    }

    public function testForcedRepositioningRidesTheSameMarkingPath(): void
    {
        // 红线③：传送/强制位置变更没有旁路 —— BaseEntity 的位置变更唯一入口是 move()，
        // 外部强制标记走 markMoved()，两者都汇入 drainMoved 同一收集路径
        // Red line 3: teleports / forced repositioning have no bypass — BaseEntity's single position-change entry is
        // move(), external force-marking goes through markMoved(), and both converge into the same drainMoved collection
        $manager = new SimpleEntityManager();
        $teleported = new BaseEntity('t', new Position(0, 0));
        $forced = new BaseEntity('f', new Position(0, 0));
        $manager->add($teleported);
        $manager->add($forced);
        $manager->drainMoved();

        $teleported->move(100, 100); // 传送 = 大步长 move teleport = a long-range move
        $forced->markMoved();        // 外部强制标记（如服务端权威位置回写） external force-mark (e.g. server-authoritative position write-back)

        $drained = $manager->drainMoved();
        self::assertCount(2, $drained);
        self::assertContains($teleported, $drained);
        self::assertContains($forced, $drained);
    }

    public function testBidirectionalVisionEventsUnchangedUnderMovedDirty(): void
    {
        // 红线④：moved-dirty 下视野事件双向生成语义不变 —— 静止 B + 移动 A：
        // A 跨格离开/进入时 entered/left 成对（邻居视角 + 自身视角），与全量扫描时代完全一致
        // Red line 4: bidirectional vision-event semantics unchanged under moved-dirty — stationary B + moving A:
        // when A crosses cells, leave/enter pairs (neighbor view + self view) match the full-scan era exactly
        $a = new BaseEntity('a', new Position(0, 0)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(0, 0)); // 0:0 cell 0:0

        $entityManager = new SimpleEntityManager();
        $entityManager->add($a);
        $entityManager->add($b);

        $published = [];
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('publishEnvelope')->willReturnCallback(
            static function (EventEnvelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        );

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            new GridAOI(10),
            $eventBus,
            $this->createStub(SchedulerInterface::class),
        );

        // 首帧：两实体登记 dirty → 双向初始 enter 成对 first frame: both entities registered dirty → paired bidirectional initial enters
        $world->update();
        self::assertCount(2, $published);
        self::assertSame('b', $published[0]->source);
        self::assertSame('a', $published[0]->targetScope);
        self::assertSame('a', $published[1]->source);
        self::assertSame('b', $published[1]->targetScope);
        $published = [];

        // A 跨格移出：一对双向 leave（邻居视角 source=a + 自身视角 source=b）；静止 B 不产生任何事件
        // A crosses out: one pair of bidirectional leaves (neighbor view source=a + self view source=b); stationary B emits nothing
        $a->move(24, 0);
        $world->update();
        self::assertCount(2, $published);
        self::assertSame(EventEnvelope::TYPE_AOI_LEAVE, $published[0]->type);
        self::assertSame('a', $published[0]->source);
        self::assertSame('b', $published[0]->targetScope);
        self::assertSame(EventEnvelope::TYPE_AOI_LEAVE, $published[1]->type);
        self::assertSame('b', $published[1]->source);
        self::assertSame('a', $published[1]->targetScope);
        $published = [];

        // A 移回：一对双向 enter，成对语义保持 A moves back: one pair of bidirectional enters, pairing preserved
        $a->move(-24, 0);
        $world->update();
        self::assertCount(2, $published);
        self::assertSame(EventEnvelope::TYPE_AOI_ENTER, $published[0]->type);
        self::assertSame('a', $published[0]->source);
        self::assertSame('b', $published[0]->targetScope);
        self::assertSame(EventEnvelope::TYPE_AOI_ENTER, $published[1]->type);
        self::assertSame('b', $published[1]->source);
        self::assertSame('a', $published[1]->targetScope);
    }

    public function testSameCellFastPathBehaviorUnchanged(): void
    {
        // 红线⑤：同格 fast path 行为不变 —— moved 实体同格移动仍走 updateEntity（fast path 空 diff），无事件发布
        // Red line 5: same-cell fast-path behavior unchanged — a moved entity's same-cell move still goes through
        // updateEntity (fast path, empty diff), publishing no events
        $a = new BaseEntity('a', new Position(0, 0)); // 0:0 cell 0:0
        $b = new BaseEntity('b', new Position(0, 0)); // 0:0 cell 0:0

        $entityManager = new SimpleEntityManager();
        $entityManager->add($a);
        $entityManager->add($b);

        /** @var list<int> $updateCalls updateEntity 收到的实体 id 序列 entity ids seen by updateEntity */
        $updateCalls = [];
        $aoi = $this->createStub(AOIProviderInterface::class);
        $aoi->method('updateEntity')->willReturnCallback(static function ($entity) use (&$updateCalls): array {
            $updateCalls[] = $entity->getId();

            return ['entered' => [], 'left' => []];
        });

        $published = [];
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('publishEnvelope')->willReturnCallback(
            static function (EventEnvelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        );

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            $aoi,
            $eventBus,
            $this->createStub(SchedulerInterface::class),
        );

        $world->update();
        $published = [];
        $updateCalls = []; // 登记帧不计入 fast path 断言 the registration frame is excluded from the fast-path assertion

        // A 同格移动（(1,0) 仍在 0:0）：仍被 updateEntity 刷新（fast path），但不发布任何 enter/leave
        // A's same-cell move ((1,0) stays in 0:0): still refreshed by updateEntity (fast path), but publishes nothing
        $a->move(1, 0);
        $world->update();
        self::assertSame(['a'], $updateCalls, '仅 moved 实体被刷新');
        self::assertSame([], $published, '同格 fast path 无事件');
    }

    public function testStationaryEntitiesCrossFramesWithZeroAoiCost(): void
    {
        // 核心性能语义：全员静止帧 —— drainMoved 为空，updateEntity 一次都不被调用（静止实体零成本过帧）
        // Core performance semantic: an all-stationary frame — drainMoved is empty and updateEntity is never called
        // (stationary entities cross the frame at zero AOI cost)
        $entityManager = new SimpleEntityManager();
        for ($i = 0; $i < 10; $i++) {
            $entityManager->add(new BaseEntity('e' . $i, new Position($i * 30, 0)));
        }

        /** @var list<int> $updateCalls updateEntity 收到的实体 id 序列 entity ids seen by updateEntity */
        $updateCalls = [];
        $aoi = $this->createStub(AOIProviderInterface::class);
        $aoi->method('updateEntity')->willReturnCallback(static function ($entity) use (&$updateCalls): array {
            $updateCalls[] = $entity->getId();

            return ['entered' => [], 'left' => []];
        });

        $world = new World(
            $entityManager,
            $this->createStub(ActorSystemInterface::class),
            $aoi,
            $this->createStub(EventBusInterface::class),
            $this->createStub(SchedulerInterface::class),
        );

        $world->update(); // 登记帧：10 个新实体全部 dirty registration frame: all 10 new entities dirty
        self::assertCount(10, $updateCalls);
        $updateCalls = [];

        $world->update(); // 静止帧：无人移动 → 零 AOI 调用 stationary frame: nobody moved → zero AOI calls
        self::assertSame([], $updateCalls);

        $world->update(); // 继续静止 still stationary
        self::assertSame([], $updateCalls);
    }
}
