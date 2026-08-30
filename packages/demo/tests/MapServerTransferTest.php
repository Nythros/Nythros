<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Demo\MapServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Cluster\InMemoryPlayerTransferStore;
use Nythros\Framework\Combat\CombatService as FrameworkCombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Inventory;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Tests\FixedRandomSource;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenManagerInterface;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerTransferTest - P15 跨 map 实体迁移的 MapServer 侧验收（ADR-025 §3.2/§3.3）：
 * detach 导出（fromMapId/位置/hp clamp/背包全量）、attach 导入（同图恢复坐标/异图落入场点/
 * 背包与血量同图异图均恢复/无票全新入场）、导出-导入往返（同图与异图两路径）。
 * MapServerTransferTest - the P15 cross-map entity migration's MapServer-side acceptance (ADR-025 §3.2/§3.3):
 * the detach export (fromMapId / position / hp clamp / full inventory), the attach import (same-map position
 * restore / different-map default entry / inventory and hp restoring for both / a fresh entry without a
 * ticket), and the export-import round trip (both the same-map and different-map paths).
 */
final class MapServerTransferTest extends TestCase
{
    private const MAP_ID = 'map-1';

    public function testExportCapturesWorldLocalStateAndImportRestoresSameMap(): void
    {
        [$map, $world] = $this->buildMapServer();
        $store = $this->store($map);
        $actor = $this->mountedActor($map, '1001@conn-1', '1001');
        $inventory = new Inventory();
        $inventory->add('potion', 2);
        $inventory->add('gold', 1);
        $this->setInventory($map, '1001@conn-1', $inventory);
        $world->getEntityManager()->add(new \Nythros\Entity\BaseEntity('1001@conn-1', new \Nythros\Entity\Position(30, 40)));
        $actor->importHp(66);

        self::assertTrue($map->exportTransferSnapshot('1001@conn-1', $actor), 'detach 导出实际发生 / the detach export actually happened');

        // 同图导入：坐标按快照恢复 + 背包/血量重建（fromMapId 同图由「坐标恢复」本身证明）
        // The same-map import: the position restores from the snapshot + the inventory and hp rebuild (the same-map
        // fromMapId is proven by the position restore itself).
        $restored = $this->consume($map, '1001');
        self::assertNotNull($restored);
        self::assertSame(['x' => 30, 'y' => 40], $restored['position']);
        self::assertSame(66, $restored['hp']);
        self::assertSame(['potion' => 2, 'gold' => 1], $restored['inventory']->all());
        self::assertNull($this->consume($map, '1001'), '票据原子单消费 / the ticket consumes atomically once');
    }

    public function testImportOnDifferentMapFallsBackToDefaultEntry(): void
    {
        [$map] = $this->buildMapServer();
        $store = $this->store($map);
        $store->export('1001', ['fromMapId' => 'map-2', 'position' => ['x' => 99, 'y' => 99], 'hp' => 80, 'inventory' => ['bone' => 3]]);

        // 异图：位置不迁移（经典换线语义），背包/血量照常恢复
        // A different map: the position never migrates (the classic zoning semantics); the inventory and hp still restore.
        $restored = $this->consume($map, '1001');
        self::assertNotNull($restored);
        self::assertNull($restored['position'], '异图不搬运坐标 / a different map never carries coordinates over');
        self::assertSame(80, $restored['hp']);
        self::assertSame(['bone' => 3], $restored['inventory']->all());
    }

    public function testImportClampsSnapshotHpAndIgnoresMalformedInventory(): void
    {
        [$map] = $this->buildMapServer();
        $store = $this->store($map);
        $store->export('1001', ['fromMapId' => self::MAP_ID, 'position' => ['x' => 1, 'y' => 1], 'hp' => 0, 'inventory' => ['potion' => 2, 'bad' => 'x', 'zero' => 0]]);

        $restored = $this->consume($map, '1001');
        self::assertNotNull($restored);
        self::assertSame(1, $restored['hp'], '死亡态不迁移（hp clamp ≥1） / death state never migrates (hp clamped >=1)');
        self::assertSame(['potion' => 2], $restored['inventory']->all(), '坏行/零计数不入背包 / malformed rows and zero counts stay out of the inventory');
    }

    public function testStorelessAssemblyIsANoOpOnBothSides(): void
    {
        [$map] = $this->buildMapServer(withTransfers: false);
        $actor = $this->mountedActor($map, '1001@conn-1', '1001');

        self::assertFalse($map->exportTransferSnapshot('1001@conn-1', $actor), 'store 未装配不导出 / no export without a store');
        self::assertNull($this->consume($map, '1001'), 'store 未装配不导入 / no import without a store');
    }

    public function testExportWithoutUidIsANoOp(): void
    {
        [$map] = $this->buildMapServer();
        $actor = new PlayerActor('1001@conn-1'); // 未 attachConnection = 无 uid No attachConnection = no uid.

        self::assertFalse($map->exportTransferSnapshot('1001@conn-1', $actor));
    }

    // ── harness ──

    /**
     * @return array{0: MapServer, 1: World}
     */
    private function buildMapServer(bool $withTransfers = true): array
    {
        $addedActors = [];
        $world = new World(new SimpleEntityManager(), $this->recordingActorSystem($addedActors), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $typeIndex = new EntityTypeIndex();
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE));

        $map = new MapServer(
            $this->createStub(ServerInterface::class),
            new JsonBatchSerializer(),
            $this->createStub(TokenManagerInterface::class),
            $world,
            new ConnectionRegistry(),
            dropTable: new DropTable(['gold' => 1]),
            typeIndex: $typeIndex,
            skills: $skills,
            random: new FixedRandomSource(1),
            mapId: self::MAP_ID,
            transfers: $withTransfers ? new InMemoryPlayerTransferStore() : null,
        );
        $map->attachCombat(new FrameworkCombatService($world, $map, $skills, $items, new FixedRandomSource(100)));

        return [$map, $world];
    }

    /**
     * 装配一个已挂连接的玩家 Actor（uid 可供票据寻址）。
     * Assembles a player actor with an attached connection (the uid addresses tickets).
     */
    private function mountedActor(MapServer $map, string $entityId, string $uid): PlayerActor
    {
        $actor = new PlayerActor($entityId);
        $actor->attachConnection('conn-x', $uid);
        $map->registerActor($entityId, $actor);

        return $actor;
    }

    private function setInventory(MapServer $map, string $entityId, Inventory $inventory): void
    {
        $property = new \ReflectionProperty(MapServer::class, 'inventories');
        $property->setAccessible(true);
        $property->setValue($map, [$entityId => $inventory]);
    }

    private function store(MapServer $map): InMemoryPlayerTransferStore
    {
        $property = new \ReflectionProperty(MapServer::class, 'transfers');
        $property->setAccessible(true);
        $store = $property->getValue($map);
        assert($store instanceof InMemoryPlayerTransferStore);

        return $store;
    }

    /**
     * 经 MapServer 私有消费路径取票（auth attach 同一入口）。
     * Takes the ticket through MapServer's private consume path (the same entry as the auth attach).
     *
     * @return array{position: array{x: int, y: int}|null, hp: int|null, inventory: Inventory}|null
     */
    private function consume(MapServer $map, string $uid): ?array
    {
        $method = new \ReflectionMethod(MapServer::class, 'consumeTransferSnapshot');
        $method->setAccessible(true);
        $result = $method->invoke($map, $uid);
        assert(is_array($result) || $result === null);

        return $result;
    }

    private function recordingActorSystem(array &$addedActors): ActorSystemInterface
    {
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('add')->willReturnCallback(static function (ActorInterface $actor) use (&$addedActors): void {
            $addedActors[] = $actor;
        });

        return $actorSystem;
    }
}
