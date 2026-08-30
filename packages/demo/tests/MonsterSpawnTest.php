<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';

use Nythros\Actor\BaseActor;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\MapServer;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
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
 * MonsterSpawnTest - spawnMonster 组装测试：entityManager/AOI 登记、Actor 系统登记、typeIndex 登记与 bindEntity（reviewer 细节 3）。
 * MonsterSpawnTest - spawnMonster assembly tests: entityManager/AOI registration, actor-system registration, typeIndex registration and bindEntity (reviewer detail 3).
 */
final class MonsterSpawnTest extends TestCase
{
    public function testSpawnMonsterRegistersEntityActorAndTypeIndex(): void
    {
        $addedActors = [];
        [$mapServer, $world, $typeIndex] = $this->buildMapServer($addedActors);

        $mapServer->spawnMonster('monster-1', 100, ['x' => 1, 'y' => 2], 'slime');

        // ① entityManager/AOI 登记：实体可查且坐标正确
        // ① entityManager/AOI registration: the entity is queryable with the correct coordinates
        $entity = $world->getEntityManager()->get('monster-1');
        self::assertNotNull($entity);
        self::assertSame(['x' => 1, 'y' => 2], $entity->getPosition());

        // ② actorSystem 登记 + $actors 登记（getActor 可查）
        // ② actor-system registration + $actors registration (getActor resolves it)
        self::assertCount(1, $addedActors);
        self::assertInstanceOf(MonsterActor::class, $addedActors[0]);
        $monster = $mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);

        // ③ typeIndex 登记：怪物种类可判定（感知侧依赖，修复 MAJOR-4）
        // ③ typeIndex registration: the monster kind is discriminable (relied on by perception, fixing MAJOR-4)
        self::assertSame(EntityTypeIndex::KIND_MONSTER, $typeIndex->kindOf('monster-1'));

        // bindEntity（reviewer 细节 3）：怪物 Actor 绑定的是同一实体实例
        // bindEntity (reviewer detail 3): the monster actor is bound to the very same entity instance
        $bound = new \ReflectionProperty(BaseActor::class, 'entity');
        $bound->setAccessible(true);
        self::assertSame($entity, $bound->getValue($monster));
    }

    public function testSpawnedMonsterPerceivesNearbyPlayerAndChases(): void
    {
        $addedActors = [];
        [$mapServer, $world, $typeIndex] = $this->buildMapServer($addedActors);
        $mapServer->spawnMonster('monster-1', 100, ['x' => 0, 'y' => 0], 'slime');

        // 玩家实体登记（BaseEntity + AOI + typeIndex），与怪物同格
        // Register a player entity (BaseEntity + AOI + typeIndex) sharing the monster's cell
        $playerEntity = new BaseEntity('player-1', new Position(0, 0));
        $world->getEntityManager()->add($playerEntity);
        $world->getAOI()->updateEntity($playerEntity);
        $typeIndex->set('player-1', EntityTypeIndex::KIND_PLAYER);

        // 行为断言（间接验证 bindEntity + AOI 感知链完整）：update 后怪物进入 CHASE
        // Behavioural assertion (indirectly verifying the bindEntity + AOI perception chain): update moves the monster into CHASE
        $monster = $mapServer->getActor('monster-1');
        $monster->update();

        self::assertSame(BaseMonster::STATE_CHASE, $monster->aiState());
        self::assertSame('player-1', $monster->targetId());
    }

    /**
     * 组装 MapServer + 战斗依赖（CombatService 以 $mapServer 本身构造后 attachCombat 回填，依赖循环规避）。
     * Builds a MapServer + combat dependencies (CombatService is built against $mapServer itself and back-filled via attachCombat, avoiding the circular dependency).
     *
     * @param list<ActorInterface> $addedActors 由引用接收 actorSystem->add 的调用记录 Receives the actorSystem->add call records by reference.
     * @return array{0: MapServer, 1: WorldInterface, 2: EntityTypeIndex}
     */
    private function buildMapServer(array &$addedActors): array
    {
        $world = new World(new SimpleEntityManager(), $this->recordingActorSystem($addedActors), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $typeIndex = new EntityTypeIndex();

        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));

        $mapServer = new MapServer(
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
        $mapServer->attachCombat(new CombatService($world, $mapServer, $skills, $items, new FixedRandomSource(100)));

        return [$mapServer, $world, $typeIndex];
    }

    /**
     * 构造记录 add 调用的 ActorSystem stub。
     * Builds an ActorSystem stub recording add calls.
     *
     * @param list<ActorInterface> $addedActors 调用记录接收引用 The call-record reference.
     */
    private function recordingActorSystem(array &$addedActors): ActorSystemInterface
    {
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('add')->willReturnCallback(static function (ActorInterface $actor) use (&$addedActors): void {
            $addedActors[] = $actor;
        });

        return $actorSystem;
    }
}
