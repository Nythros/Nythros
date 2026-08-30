<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Server;

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\WorldInterface;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\RoomInstanceManager;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * ConnectionRegistryTest - 覆盖 ConnectionRegistry 连接与实体的双向映射、映射覆盖与幂等摘除契约，
 * 以及容器维度（ADR-024 §9 V6）：moveToContainer/getContainer/resolveContainerContext 的标记、
 * null 回退宿主世界与 attach 重挂/detach 摘除的防御语义。
 * Tests covering ConnectionRegistry bidirectional connection/entity mapping, mapping overwrite, and idempotent
 * detach contracts, plus the container dimension (ADR-024 §9 V6): moveToContainer/getContainer/
 * resolveContainerContext marking, the null host-world fallback, and the defensive semantics of re-attach/detach.
 */
final class ConnectionRegistryTest extends TestCase
{
    public function testAttachMakesBothDirectionsQueryable(): void
    {
        $registry = new ConnectionRegistry();

        $registry->attach('conn-1', 'entity-1');

        self::assertSame('entity-1', $registry->getEntityId('conn-1'));
        self::assertSame('conn-1', $registry->getConnectionId('entity-1'));
        self::assertTrue($registry->has('conn-1'));
    }

    public function testDetachByConnectionRemovesBothDirections(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');

        $removed = $registry->detachByConnection('conn-1');

        self::assertSame('entity-1', $removed);
        self::assertNull($registry->getEntityId('conn-1'));
        self::assertNull($registry->getConnectionId('entity-1'));
        self::assertFalse($registry->has('conn-1'));
    }

    public function testDetachByEntityRemovesBothDirections(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');

        $removed = $registry->detachByEntity('entity-1');

        self::assertSame('conn-1', $removed);
        self::assertNull($registry->getEntityId('conn-1'));
        self::assertNull($registry->getConnectionId('entity-1'));
    }

    public function testRepeatedAttachOverwritesOldMapping(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');
        $registry->attach('conn-1', 'entity-2');

        self::assertSame('entity-2', $registry->getEntityId('conn-1'));
        self::assertNull($registry->getConnectionId('entity-1'));
        self::assertSame('conn-1', $registry->getConnectionId('entity-2'));

        // 反向覆盖：同一 entity 换新 connection
        $registry->attach('conn-2', 'entity-2');
        self::assertSame('entity-2', $registry->getEntityId('conn-2'));
        self::assertNull($registry->getEntityId('conn-1'));
        self::assertSame('conn-2', $registry->getConnectionId('entity-2'));
    }

    public function testUnknownKeysReturnNullAndDetachIsNoop(): void
    {
        $registry = new ConnectionRegistry();

        self::assertNull($registry->getEntityId('missing'));
        self::assertNull($registry->getConnectionId('missing'));
        self::assertNull($registry->detachByConnection('missing'));
        self::assertNull($registry->detachByEntity('missing'));
        self::assertFalse($registry->has('missing'));
    }

    public function testDetachIsIdempotent(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');

        self::assertSame('entity-1', $registry->detachByConnection('conn-1'));
        self::assertNull($registry->detachByConnection('conn-1'));
        self::assertNull($registry->detachByEntity('entity-1'));
    }

    // ── 容器维度（ADR-024 §9 V6） ──

    /**
     * moveToContainer 标记后 getContainer 可查；null 移动清除记录回落宿主世界（唯一合法的「无容器」语义）。
     * After a moveToContainer mark getContainer resolves it; a null move clears the record back to the host world
     * (the only legitimate "no container" semantics).
     */
    public function testMoveToContainerMarksAndNullResetsToHostFallback(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');

        self::assertNull($registry->getContainer('conn-1'), '未标记连接无容器记录 an unmarked connection holds no container record');

        $room = $this->makeRoom('r-1');
        $registry->moveToContainer('conn-1', $room);
        self::assertSame($room, $registry->getContainer('conn-1'));

        $registry->moveToContainer('conn-1', null);
        self::assertNull($registry->getContainer('conn-1'), 'null 移动清除容器记录（回落宿主世界） a null move clears the container record (host-world fallback)');
    }

    /**
     * 未挂载实体映射的连接 moveToContainer 静默忽略：容器维度从属于实体映射。
     * moveToContainer on a connection without an entity mapping is silently ignored: the container dimension is subordinate to the entity mapping.
     */
    public function testMoveToContainerIgnoresUnattachedConnections(): void
    {
        $registry = new ConnectionRegistry();
        $room = $this->makeRoom('r-x');

        $registry->moveToContainer('missing', $room);

        self::assertNull($registry->getContainer('missing'));
    }

    /**
     * attach 重挂清容器（防御语义）：同 connId 换实体重挂不得继承上一实体的容器引用。
     * Re-attach clears the container (defensive semantics): re-attaching the same connId with another entity must not inherit the previous entity's container reference.
     */
    public function testReAttachClearsStaleContainer(): void
    {
        $registry = new ConnectionRegistry();
        $registry->attach('conn-1', 'entity-1');
        $registry->moveToContainer('conn-1', $this->makeRoom('r-old'));

        $registry->attach('conn-1', 'entity-2');

        self::assertSame('entity-2', $registry->getEntityId('conn-1'));
        self::assertNull($registry->getContainer('conn-1'), '重挂后容器必须从宿主世界起算 the container must restart from the host world after re-attach');
    }

    /**
     * detach 双向摘除对称清理容器维度，连接表无残留。
     * Both detach directions clear the container dimension symmetrically, leaving no stale entries.
     */
    public function testDetachClearsContainerDimension(): void
    {
        $room = $this->makeRoom('r-dc');
        $byConnection = new ConnectionRegistry();
        $byConnection->attach('conn-1', 'entity-1');
        $byConnection->moveToContainer('conn-1', $room);
        $byConnection->detachByConnection('conn-1');
        self::assertNull($byConnection->getContainer('conn-1'));

        $byEntity = new ConnectionRegistry();
        $byEntity->attach('conn-2', 'entity-2');
        $byEntity->moveToContainer('conn-2', $room);
        $byEntity->detachByEntity('entity-2');
        self::assertNull($byEntity->getContainer('conn-2'));
    }

    /**
     * resolveContainerContext：有容器记录时 EM/AOI 取自容器本身；无记录时整体回落宿主世界，
     * 且 container 键区分两种来源（null = 宿主世界）。
     * resolveContainerContext: with a record the EM/AOI come from the container itself; without one everything
     * falls back to the host world, and the container key distinguishes both sources (null = host world).
     */
    public function testResolveContainerContextFallsBackToHostWithoutRecord(): void
    {
        $registry = new ConnectionRegistry();

        $hostEm = new SimpleEntityManager();
        $hostAoi = new GridAOI(10);
        $host = new World($hostEm, new \Nythros\Actor\SimpleActorSystem(), $hostAoi, new SimpleEventBus(), new RegionScheduler());
        $registry->attach('conn-w', 'entity-w');

        $worldContext = $registry->resolveContainerContext('conn-w', $host);
        self::assertNull($worldContext['container'], '无记录连接的 container 为 null（宿主世界） a recordless connection resolves container null (host world)');
        self::assertSame($hostEm, $worldContext['entityManager']);
        self::assertSame($hostAoi, $worldContext['aoi']);

        $room = $this->makeRoom('r-ctx');
        $registry->attach('conn-r', 'entity-r');
        $registry->moveToContainer('conn-r', $room);

        $roomContext = $registry->resolveContainerContext('conn-r', $host);
        self::assertSame($room, $roomContext['container']);
        self::assertSame($room->getEntityManager(), $roomContext['entityManager'], '有记录连接的 EM 取自容器 a recorded connection resolves the EM from the container');
        self::assertSame($room->getAOI(), $roomContext['aoi'], '有记录连接的 AOI 取自容器 a recorded connection resolves the AOI from the container');
    }

    /**
     * 构造一个真实 RoomInstance 作为容器引用（经 manager->create，与生产装配同构）。
     * Builds a real RoomInstance as the container reference (via manager->create, isomorphic to production assembly).
     */
    private function makeRoom(string $roomId): WorldInterface
    {
        $manager = new RoomInstanceManager();

        return $manager->create(new \Nythros\Contracts\RoomConfig($roomId, 50, 8, static fn (): GridAOI => new GridAOI(10)));
    }
}
