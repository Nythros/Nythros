<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Demo\Gameplay\GameplayConfig;
use Nythros\Demo\Gameplay\MonsterSpawn;
use Nythros\Demo\MapServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
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
 * MapServerGameplayReloadTest - 覆盖 P11 玩法数据外置的热载应用面：gameplay 表 diff
 * （已登记参数热更 / 新增行 spawn / 删除行摘登记 / 在场怪物不驱逐）与掉落表原子换入。
 * MapServerGameplayReloadTest - covers the P11 externalization's hot-reload application surface: the gameplay
 * table diff (registered params hot-updated / new rows spawn / deleted rows unregistered / live monsters never
 * evicted) and the drop table's atomic swap.
 */
final class MapServerGameplayReloadTest extends TestCase
{
    public function testApplyGameplayConfigDiffRegistrySpawnsAndRemoves(): void
    {
        [$map, $world] = $this->buildMapServer(withMmorpg: true);
        $map->spawnMonster('monster-1', 100, ['x' => 15, 'y' => 15], 'slime', patrolRadius: 4);

        // 热载：monster-1 参数热更 + monster-2 新增（立即 spawn）+ monster-3 删除（未在场无效果）
        // Hot reload: monster-1's params hot-updated + monster-2 added (spawned immediately) + monster-3 removed (absent, no effect)
        $map->applyGameplayConfig(new GameplayConfig(['x' => 3, 'y' => 4], [
            new MonsterSpawn('monster-1', 'slime', 200, ['x' => 20, 'y' => 20], 6, 9000),
            new MonsterSpawn('monster-2', 'wolf', 150, ['x' => -6, 'y' => -6], 4, null),
        ]));

        // ① 出生点换入（spawnPoint() 读取口径）
        // ① The spawn point swapped in (the spawnPoint() read convention)
        self::assertSame(['x' => 3, 'y' => 4], $map->spawnPoint());

        // ② 新增行立即 spawn：实体登记 + monster:spawned 广播路径（EM 可查即视为 spawn 完成）
        // ② New rows spawn immediately: the entity registered (an EM hit means the spawn completed)
        self::assertNotNull($world->getEntityManager()->get('monster-2'));

        // ③ 已登记参数热更（重生侧生效）：spawnRegistry 快照 = 新锚/新血/新巡逻域/逐怪重生延迟
        // ③ Registered params hot-updated (effective on the respawn side): the spawnRegistry snapshot = new anchor/hp/patrol/per-monster delay
        $registry = $this->spawnRegistry($map);
        self::assertSame(200, $registry['monster-1']['maxHp']);
        self::assertSame(['x' => 20, 'y' => 20], $registry['monster-1']['position']);
        self::assertSame(6, $registry['monster-1']['patrolRadius']);
        self::assertSame(9000, $registry['monster-1']['respawnMs']);
        self::assertNull($registry['monster-2']['respawnMs']);

        // ④ 删除行摘登记：不再有 monster-3（未在场场景）→ registry 无该键
        // ④ Deleted rows unregistered: monster-3 never existed here → no registry key
        self::assertArrayNotHasKey('monster-3', $registry);

        // ⑤ 删除已登记且在场的行：登记摘除但实体不驱逐
        // ⑤ Deleting a registered live row: the registration is removed but the entity is never evicted
        $map->applyGameplayConfig(new GameplayConfig(['x' => 3, 'y' => 4], [
            new MonsterSpawn('monster-1', 'slime', 200, ['x' => 20, 'y' => 20], 6, 9000),
        ]));
        $registry = $this->spawnRegistry($map);
        self::assertArrayNotHasKey('monster-2', $registry);
        self::assertNotNull($world->getEntityManager()->get('monster-2'), '删除行不驱逐在场怪物。A deleted row never evicts a live monster.');
    }

    public function testApplyGameplayConfigWithoutMmorpgSpawnsNewRows(): void
    {
        [$map, $world] = $this->buildMapServer(withMmorpg: false);

        // mmorpg 关闭（spawnRegistry 恒空）：新增行走实体存在性判定，同样立即 spawn
        // With mmorpg off (the spawnRegistry stays empty): new rows judge by entity existence and still spawn immediately.
        $map->applyGameplayConfig(new GameplayConfig(['x' => 0, 'y' => 0], [
            new MonsterSpawn('monster-x', 'orc', 80, ['x' => 1, 'y' => 1], null, null),
        ]));

        self::assertNotNull($world->getEntityManager()->get('monster-x'));
        self::assertSame(['x' => 1, 'y' => 1], $world->getEntityManager()->get('monster-x')?->getPosition());
    }

    public function testReplaceDropTableSwapsAtomically(): void
    {
        [$map] = $this->buildMapServer(withMmorpg: false);
        $replacement = new DropTable(['gold' => 1]);

        $map->replaceDropTable($replacement);

        $property = new \ReflectionProperty(MapServer::class, 'dropTable');
        $property->setAccessible(true);
        self::assertSame($replacement, $property->getValue($map));
    }

    /**
     * 组装 MapServer + 战斗依赖（比照 MonsterSpawnTest 的 harness）。
     * Builds a MapServer + combat dependencies (mirroring MonsterSpawnTest's harness).
     *
     * @return array{0: MapServer, 1: \Nythros\Contracts\WorldInterface}
     */
    private function buildMapServer(bool $withMmorpg): array
    {
        $addedActors = [];
        $world = new World(new SimpleEntityManager(), $this->recordingActorSystem($addedActors), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $typeIndex = new EntityTypeIndex();
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));

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
        );
        $map->attachCombat(new CombatService($world, $map, $skills, $items, new FixedRandomSource(100)));
        if ($withMmorpg) {
            $map->attachMmorpg(MmorpgConfig::default(), new EventDispatcher());
        }

        return [$map, $world];
    }

    private function recordingActorSystem(array &$addedActors): ActorSystemInterface
    {
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('add')->willReturnCallback(static function (ActorInterface $actor) use (&$addedActors): void {
            $addedActors[] = $actor;
        });

        return $actorSystem;
    }

    /**
     * 读 spawnRegistry 快照（私有状态的测试观察口，比照 bindEntity 的反射先例）。
     * Reads the spawnRegistry snapshot (a test observation port over private state, mirroring the bindEntity reflection precedent).
     *
     * @return array<string, array{maxHp: int, position: array{x: int, y: int}, typeId: string, patrolRadius: ?int, respawnMs: ?int}>
     */
    private function spawnRegistry(MapServer $map): array
    {
        $property = new \ReflectionProperty(MapServer::class, 'spawnRegistry');
        $property->setAccessible(true);

        return $property->getValue($map);
    }
}
