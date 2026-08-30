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
use Nythros\Framework\Combat\CombatService as FrameworkCombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Inventory;
use Nythros\Framework\Persistence\ArchivePipeline;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Tests\FixedRandomSource;
use Nythros\Network\ServerInterface;
use Nythros\Persistence\InMemoryStorage;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenManagerInterface;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerVitalsRestoreTest - P18 工程债收尾的 MapServer 侧验收：
 * 归档兜底恢复（env 门控：开关关/无归档/坏行回落全新背包；开关开恢复 markDirty 写入口径的背包）与
 * 玩家初始血量基线注入（gameplay 表 player.maxHp → initVitals，快照 hp 导入钳制上界随基线走）。
 * MapServerVitalsRestoreTest - the P18 engineering-debt close-out's MapServer-side acceptance:
 * the archive fallback restore (env-gated: switch off / no archive / malformed rows degrade to a fresh
 * inventory; switch on restores the inventory per markDirty's write convention) and the player's initial
 * vitals baseline injection (gameplay table's player.maxHp -> initVitals, with the snapshot-hp clamp
 * ceiling following the baseline).
 */
final class MapServerVitalsRestoreTest extends TestCase
{
    private const UID = '1001';

    // ── 归档兜底恢复 ──
    // ── The archive fallback restore ──

    public function testArchiveRestoreOffYieldsFreshInventory(): void
    {
        [$map] = $this->buildMapServer(archiveRestore: false, withArchived: true);

        // null = 生产路径回落全新背包（`?? new Inventory()`）
        // null = the production path falls back to a fresh inventory (`?? new Inventory()`).
        self::assertNull($this->restoreFor($map), '开关关闭不恢复（全新背包语义） / switch off restores nothing (fresh-inventory semantics)');
    }

    public function testArchiveRestoreRebuildsInventoryPerMarkDirtyShape(): void
    {
        [$map] = $this->buildMapServer(archiveRestore: true, withArchived: true);
        $inventory = $this->restoreFor($map);

        // 恢复形状与 markDirty 写入口径对称：{inventory: {itemId: count}}
        // The restored shape mirrors markDirty's write convention: {inventory: {itemId: count}}.
        self::assertSame(['potion' => 2, 'gold' => 1], $inventory->all());
    }

    public function testArchiveRestoreWithoutArchiveOrMalformedRowsDegradesToFresh(): void
    {
        [$map] = $this->buildMapServer(archiveRestore: true, withArchived: false);
        self::assertNull($this->restoreFor($map), '无归档记录回落全新背包 / no archived record degrades to a fresh inventory');

        // 坏行（inventory 非数组/条目非法）防御性回落
        // Malformed rows (a non-array inventory / illegal entries) degrade defensively.
        $storage = $this->storageOf($map);
        $storage->save('players', self::UID, ['inventory' => 'oops', 'gold' => 'x']);
        self::assertNull($this->restoreFor($map), '坏行回落全新背包 / a malformed row degrades to a fresh inventory');
    }

    // ── 初始血量基线 ──
    // ── The initial vitals baseline ──

    public function testInitVitalsSetsBaselineAndRestoredHpClampsIntoIt(): void
    {
        [$map] = $this->buildMapServer(archiveRestore: false);
        $actor = new PlayerActor('1001@conn-1');
        $actor->attachConnection('conn-x', self::UID);

        // initVitals：基线覆盖 + 回满（装备未挂载，合成上限即基线）
        // initVitals: the baseline overwrites and hp fills (no equipment mounted, the composed ceiling is the baseline).
        $actor->initVitals(150);
        self::assertSame(150, $actor->hp());
        self::assertSame(150, $actor->maxHp());

        // 快照/迁移 hp 导入的钳制上界随基线走
        // The snapshot/migration hp import's clamp ceiling follows the baseline.
        $actor->importHp(999);
        self::assertSame(150, $actor->hp());
        $actor->importHp(0);
        self::assertSame(1, $actor->hp());

        // 缺省构造（不注入）= 100 基线逐字节等价
        // The default construction (no injection) = the 100 baseline, byte-for-byte equivalent.
        self::assertSame(100, (new PlayerActor('1001@conn-2'))->maxHp());
    }

    public function testMapServerDefaultPlayerMaxHpIsHundred(): void
    {
        $property = new \ReflectionProperty(MapServer::class, 'playerMaxHp');
        $property->setAccessible(true);
        [$map] = $this->buildMapServer(archiveRestore: false);
        self::assertSame(100, $property->getValue($map), '缺省 100 = 接入前逐字节等价 / the default 100 stays byte-for-byte equivalent');
    }

    // ── harness（比照 MapServerTransferTest） ──
    // ── harness (mirroring MapServerTransferTest) ──

    /**
     * @return array{0: MapServer, 1: World}
     */
    private function buildMapServer(bool $archiveRestore, bool $withArchived = false): array
    {
        $addedActors = [];
        $world = new World(new SimpleEntityManager(), $this->recordingActorSystem($addedActors), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $typeIndex = new EntityTypeIndex();
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE));

        // InMemoryStorage 直连管线（读路径不依赖 MySQL；写入口径与 markDirty 一致）
        // An InMemoryStorage-backed pipeline (the read path needs no MySQL; the write convention matches markDirty).
        $storage = new InMemoryStorage();
        $archive = new ArchivePipeline($storage, 'players');
        if ($withArchived) {
            $archive->markDirty(self::UID, ['inventory' => ['potion' => 2, 'gold' => 1]]);
            $archive->flush();
        }

        $map = new MapServer(
            $this->createStub(ServerInterface::class),
            new JsonBatchSerializer(),
            $this->createStub(TokenManagerInterface::class),
            $world,
            new ConnectionRegistry(),
            dropTable: new DropTable(['gold' => 1]),
            typeIndex: $typeIndex,
            archive: $archive,
            skills: $skills,
            random: new FixedRandomSource(1),
            archiveRestore: $archiveRestore,
        );
        $map->attachCombat(new FrameworkCombatService($world, $map, $skills, $items, new FixedRandomSource(100)));

        return [$map, $world];
    }

    /**
     * 经 MapServer 私有恢复路径取背包（auth attach 同一入口）。
     * Takes the inventory through MapServer's private restore path (the same entry as the auth attach).
     */
    private function restoreFor(MapServer $map): ?Inventory
    {
        $method = new \ReflectionMethod(MapServer::class, 'restoreInventoryFromArchive');
        $method->setAccessible(true);
        $inventory = $method->invoke($map, self::UID);
        assert($inventory === null || $inventory instanceof Inventory);

        return $inventory;
    }

    private function storageOf(MapServer $map): InMemoryStorage
    {
        $property = new \ReflectionProperty(MapServer::class, 'archive');
        $property->setAccessible(true);
        $archive = $property->getValue($map);
        assert($archive instanceof ArchivePipeline);
        $storageProperty = new \ReflectionProperty(ArchivePipeline::class, 'storage');
        $storageProperty->setAccessible(true);
        $storage = $storageProperty->getValue($archive);
        assert($storage instanceof InMemoryStorage);

        return $storage;
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
