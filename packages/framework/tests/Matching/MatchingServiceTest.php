<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Contracts\RoomConfig;
use Nythros\Contracts\RoomInstanceInterface;
use Nythros\Contracts\RoomManagerInterface;
use Nythros\Framework\Matching\MatchCriteria;
use Nythros\Framework\Matching\MatchingService;
use Nythros\Framework\Matching\MatchJoinHandlerInterface;
use PHPUnit\Framework\TestCase;

/**
 * MatchingServiceTest - 覆盖匹配服务：队列入队准入、撮合开房、条件不满足等待、取消、离线清理与
 * 编排失败（满员）回滚重排。
 * Tests covering the matching service: queue admission, match-and-build, conditions-unmet waiting, cancellation,
 * offline cleanup and orchestration-failure (full-room) rollback re-queueing.
 */
final class MatchingServiceTest extends TestCase
{
    public function testEnqueueRejectsUnregisteredCriteriaOutOfRangeLevelAndDuplicates(): void
    {
        $service = $this->makeService()[0];

        self::assertFalse($service->enqueue('horde-6', '1001', 'e1', 10, 100.0), '未注册队列拒绝入队。Unregistered queues reject enqueue.');
        $service->registerCriteria(new MatchCriteria('horde-6', 2, 5, 20));

        self::assertFalse($service->enqueue('horde-6', '1001', 'e1', 4, 100.0), '等级越界（低于下界）拒绝。Out-of-range level (below the floor) is rejected.');
        self::assertFalse($service->enqueue('horde-6', '1001', 'e1', 21, 100.0), '等级越界（高于上界）拒绝。Out-of-range level (above the ceiling) is rejected.');

        self::assertTrue($service->enqueue('horde-6', '1001', 'e1', 10, 100.0));
        self::assertFalse($service->enqueue('horde-6', '1001', 'e1', 10, 101.0), '一人一票：重复入队拒绝。One ticket per candidate: duplicate enqueue is rejected.');
        self::assertFalse($service->enqueue('horde-6', '1001', 'other-e', 10, 102.0), '跨 entityId 的同 uid 重复同样拒绝。The same uid behind another entityId is rejected too.');
    }

    public function testTickWaitsUntilTheQueueFillsThenBuildsARoom(): void
    {
        [$service, $rooms, $handler] = $this->makeService();
        $service->registerCriteria(new MatchCriteria('horde-6', 2, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        self::assertSame([], $service->tick(101.0), '人未满保持等待。An unfilled queue keeps waiting.');
        self::assertSame([], $rooms->created, '等待期间不开房。No room is built while waiting.');

        $service->enqueue('horde-6', '1002', 'e2', 12, 102.0);
        $built = $service->tick(103.0);

        self::assertCount(1, $built, '凑满 teamSize 即开房。A full queue builds exactly one room.');
        self::assertSame('match-horde-6-1', $built[0]['roomId']);
        self::assertSame(['1001', '1002'], $built[0]['uids'], 'FIFO 入队序即入房序。FIFO enqueue order is the join order.');
        self::assertSame([['e1', 'match-horde-6-1'], ['e2', 'match-horde-6-1']], $handler->calls, '逐候选者委托 join 编排。Join orchestration is delegated per candidate.');
        self::assertSame(0, $service->queueDepth('horde-6'), '撮合后队列清空。The queue drains after matching.');
        self::assertNull($service->ticketOf('1001'));
    }

    public function testBuiltRoomCarriesCriteriaParameters(): void
    {
        [$service, $rooms] = $this->makeService();
        $service->registerCriteria(new MatchCriteria('arena-2', 1, 1, 99, 30, 8));

        $service->enqueue('arena-2', '1001', 'e1', 50, 100.0);
        $service->tick(101.0);

        self::assertCount(1, $rooms->configs);
        $config = $rooms->configs[0];
        self::assertSame('match-arena-2-1', $config->roomId);
        self::assertSame(30, $config->periodMs, '开房周期透传条件声明。The built-room period passes the declared criteria through.');
        self::assertSame(8, $config->maxMembers, '开房成员上限透传条件声明。The built-room member cap passes the declared criteria through.');
    }

    public function testCancelRemovesTheTicketSoTheQueueNeverFills(): void
    {
        [$service, $rooms] = $this->makeService();
        $service->registerCriteria(new MatchCriteria('horde-6', 2, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        self::assertTrue($service->cancel('1001'), '在队取消返回 true。Cancelling a queued candidate returns true.');
        self::assertFalse($service->cancel('1001'), '重复取消静默 false。A repeated cancel stays silently false.');

        $service->enqueue('horde-6', '1002', 'e2', 12, 101.0);
        self::assertSame([], $service->tick(102.0), '取消后凑不满不开房。After the cancel the queue never fills, so no room is built.');
        self::assertSame([], $rooms->created);
    }

    public function testPurgeOfflineDropsTicketsByEntityId(): void
    {
        $service = $this->makeService()[0];
        $service->registerCriteria(new MatchCriteria('horde-6', 3, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        $service->enqueue('horde-6', '1002', 'e2', 11, 100.0);
        $service->enqueue('horde-6', '1003', 'e3', 12, 100.0);

        self::assertSame(2, $service->purgeOffline(['e1', 'e3']), '按 entityId 摘票并计数。Tickets drop by entityId with a count.');
        self::assertSame(1, $service->queueDepth('horde-6'));
        self::assertNull($service->ticketOf('1001'), '离线候选者的票已摘除。The offline candidate\'s ticket is gone.');
        self::assertNotNull($service->ticketOf('1002'), '在线候选者不受影响。Online candidates stay untouched.');
    }

    public function testFailedOrchestrationRequeuesWhileOthersStayInTheRoom(): void
    {
        [$service, , $handler] = $this->makeService(failEntityIds: ['e2']);
        $service->registerCriteria(new MatchCriteria('horde-6', 2, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        $service->enqueue('horde-6', '1002', 'e2', 12, 100.0);

        $built = $service->tick(101.0);

        self::assertCount(1, $built, '部分成功语义：其余成员留在新房。Partial-success semantics: the rest stay in the new room.');
        self::assertSame(['1001'], $built[0]['uids']);
        self::assertNotNull($service->ticketOf('1002'), '编排失败者重新入队。A failed candidate re-queues.');
        self::assertSame(1, $service->queueDepth('horde-6'));

        // 失败者回队首后，下一拍与新候选者撮合。
        // The failed candidate sits at the head and matches a fresh candidate next tick.
        $handler->failEntityIds = [];
        $service->enqueue('horde-6', '1003', 'e3', 15, 102.0);
        $built = $service->tick(103.0);

        self::assertCount(1, $built);
        self::assertSame(['1002', '1003'], $built[0]['uids'], '失败者保留原位（队首优先）。The failed candidate keeps its place (head-first).');
    }

    public function testWholeBatchFailureStopsThisTicksLoopWithoutSpinning(): void
    {
        [$service, $rooms] = $this->makeService(failAll: true);
        $service->registerCriteria(new MatchCriteria('horde-6', 1, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        $built = $service->tick(101.0);

        self::assertSame([], $built, '整批失败无开房记录。A whole-batch failure records no built room.');
        self::assertCount(1, $rooms->created, '只尝试一次开房（防死循环）。Exactly one build attempt (no spin).');
        self::assertNotNull($service->ticketOf('1001'), '失败者重新入队。The failed candidate re-queues.');
    }

    public function testPoisonTicketIsEvictedAfterConsecutiveFailuresWithoutBurningInnocents(): void
    {
        // 毒票场景：e2 永久 joinRoom 失败——部分成功语义下若不设上限，它每拍回队首再次配对，
        // 每次消耗一张无辜票进部分空房直至队列耗尽；连续失败达上限（3 次）必须移出
        // Poison scenario: e2 fails joinRoom forever — under partial success without a cap it would re-pair at the
        // head every tick, burning one innocent ticket into a half-empty room each time until the queue drains;
        // at the consecutive-failure cap (3) it must be evicted
        [$service, $rooms, $handler] = $this->makeService(failEntityIds: ['e2']);
        $service->registerCriteria(new MatchCriteria('horde-6', 2, 5, 20));

        $service->enqueue('horde-6', '1001', 'e1', 10, 100.0);
        $service->enqueue('horde-6', '1002', 'e2', 12, 100.0);

        // 第 1 拍：e1 入房，e2 失败回队首（计数 1）
        // Tick 1: e1 joins, e2 fails and re-queues at the head (count 1)
        $built = $service->tick(101.0);
        self::assertSame(['1001'], $built[0]['uids']);
        self::assertNotNull($service->ticketOf('1002'));

        // 第 2 拍：e2 + e3 凑满开房，e2 再败（计数 2），e3 入房
        // Tick 2: e2 + e3 fill a room; e2 fails again (count 2), e3 joins
        $service->enqueue('horde-6', '1003', 'e3', 13, 102.0);
        $built = $service->tick(103.0);
        self::assertSame(['1003'], $built[0]['uids']);
        self::assertNotNull($service->ticketOf('1002'));

        // 第 3 拍：e2 + e4 凑满开房，e2 连续第 3 次失败 → 达上限移出，e4 照常入房
        // Tick 3: e2 + e4 fill a room; e2's third consecutive failure → evicted at the cap, e4 joins as usual
        $service->enqueue('horde-6', '1004', 'e4', 14, 104.0);
        $built = $service->tick(105.0);
        self::assertSame(['1004'], $built[0]['uids']);
        self::assertNull($service->ticketOf('1002'), '连续失败达上限的毒票必须被移出队列。A poison ticket hitting the cap must be evicted.');

        // 毒票移出后无辜票不再被消耗：队列已空，后续拍不开任何部分空房
        // After eviction no innocent ticket is burned anymore: the queue is empty and later ticks build nothing
        self::assertSame(0, $service->queueDepth('horde-6'));
        $roomCount = count($rooms->created);
        self::assertSame([], $service->tick(106.0));
        self::assertCount($roomCount, $rooms->created, '毒票移出后不再为它开部分空房。No more half-empty rooms are built for the evicted ticket.');

        // 移出时计数同步清除：同 uid 可重新入队（重新开始计数）
        // Eviction clears the count in sync: the same uid may re-queue (counting restarts)
        self::assertTrue($service->enqueue('horde-6', '1002', 'e2', 12, 107.0));

        // 编排恢复后毒票照常撮合（计数从零开始）
        // With orchestration recovered the re-enqueued ticket matches normally (counting from zero)
        $handler->failEntityIds = [];
        $service->enqueue('horde-6', '1005', 'e5', 15, 108.0);
        $built = $service->tick(109.0);
        self::assertSame(['1002', '1005'], $built[0]['uids']);
    }

    /**
     * 构造服务组：内存房间管理器 + 可配置失败的 join 委托。
     * Builds the service group: an in-memory room manager plus a join delegate with configurable failures.
     *
     * @param list<string> $failEntityIds 编排必失败的 entityId 列表 EntityIds whose orchestration always fails.
     * @param bool $failAll 全部编排失败 All orchestrations fail.
     * @return array{0: MatchingService, 1: FakeRoomManager, 2: FakeJoinHandler}
     */
    private function makeService(array $failEntityIds = [], bool $failAll = false): array
    {
        $rooms = new FakeRoomManager();
        $handler = new FakeJoinHandler($failEntityIds, $failAll);

        // AOI 工厂产出合法 UniversalAOI（RoomInstance 构造契约）：framework 侧 MatchingService 只透传闭包。
        // The AOI factory yields a valid UniversalAOI (the RoomInstance construction contract): the framework-side
        // MatchingService only passes the closure through.
        return [new MatchingService($rooms, $handler, static fn (\Nythros\Contracts\EntityManagerInterface $em): \Nythros\Contracts\AOIProviderInterface => new \Nythros\Aoi\UniversalAOI($em)), $rooms, $handler];
    }
}

/**
 * FakeRoomManager - 内存房间管理器：记录 create 调用与配置，供断言。
 * FakeRoomManager - an in-memory room manager recording create calls and configs for assertions.
 */
final class FakeRoomManager implements RoomManagerInterface
{
    /** @var list<string> create 调用的 roomId 记录 Recorded create call roomIds. */
    public array $created = [];

    /** @var list<RoomConfig> create 调用的配置记录 Recorded create call configs. */
    public array $configs = [];

    public function create(RoomConfig $config): RoomInstanceInterface
    {
        if (in_array($config->roomId, $this->created, true)) {
            throw new \InvalidArgumentException(sprintf('房间 %s 已存在 / room %s already exists', $config->roomId, $config->roomId));
        }
        $this->created[] = $config->roomId;
        $this->configs[] = $config;

        // 复用引擎 RoomInstance 真实实现（tests 不受 @internal 门禁约束）：create 成功路径返回可用实例。
        // Reuses the engine's real RoomInstance (tests are outside the @internal gate): a successful create returns a usable instance.
        return new \Nythros\World\RoomInstance($config, new \Nythros\Event\SimpleEventBus());
    }

    public function get(string $roomId): ?RoomInstanceInterface
    {
        return null;
    }

    public function all(): array
    {
        return [];
    }

    public function tick(float $now): array
    {
        return ['updated' => 0, 'deferred' => 0];
    }

    public function transfer(?string $fromRoomId, string $toRoomId, \Nythros\Contracts\EntityInterface $entity, ?\Nythros\Contracts\ActorInterface $actor = null): bool
    {
        return false;
    }

    public function destroy(string $roomId): void
    {
    }

    public function evictFromAny(string $entityId): bool
    {
        return false;
    }
}

/**
 * FakeJoinHandler - 记录 joinRoom 调用并可配置失败的编排委托。
 * FakeJoinHandler - a join delegate recording calls with configurable failures.
 */
final class FakeJoinHandler implements MatchJoinHandlerInterface
{
    /** @var list<array{0: string, 1: string}> joinRoom 调用记录 [entityId, roomId] joinRoom call records as [entityId, roomId]. */
    public array $calls = [];

    /**
     * @param list<string> $failEntityIds 编排必失败的 entityId 列表 EntityIds whose orchestration always fails.
     */
    public function __construct(
        public array $failEntityIds = [],
        public bool $failAll = false,
    ) {
    }

    public function joinRoom(string $roomId, string $entityId): bool
    {
        $this->calls[] = [$entityId, $roomId];
        if ($this->failAll || in_array($entityId, $this->failEntityIds, true)) {
            return false;
        }

        return true;
    }
}
