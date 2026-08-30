<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Demo\MapServer;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Combat\CombatService as FrameworkCombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Damageable;
use Nythros\Framework\Game\Mmorpg\DeathDropPolicy;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
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
 * MapServerGovernanceTest - P13 死亡与对抗治理的 MapServer 侧验收：
 * 死亡掉落（逐单位 roll/绑定不掉/条目上限/归属绑定/同帧扣包）与 PVP 对抗门
 * （pvp_disabled/in_safe_zone/spawn_protected/mmorpg 未装配零治理）。
 * MapServerGovernanceTest - the P13 death & combat governance's MapServer-side acceptance:
 * death drops (per-unit rolls / bound items never drop / the per-death cap / ownership binding / same-beat
 * deduction) and the PVP combat gate (pvp_disabled / in_safe_zone / spawn_protected / zero governance
 * without mmorpg).
 */
final class MapServerGovernanceTest extends TestCase
{
    // ── 死亡掉落 ──
    // ── Death drops ──

    public function testDeathDropRollsPerUnitDeductsSameBeatAndBindsOwnership(): void
    {
        [$map, $world] = $this->buildMapServer(new DeathDropPolicy(100, 60, 8, ['gold']));

        $inventory = new Inventory();
        $inventory->add('potion', 2);
        $inventory->add('gold', 1);
        $map = $this->withInventory($map, '1001@conn-1', $inventory);

        $dropped = $map->dropInventoryOnDeath('1001@conn-1', '1002');

        // 比例 100%：potion 两单位全掉；gold 绑定不掉
        // At a 100% ratio: both potion units drop; gold is bound and never drops.
        self::assertSame(1, $dropped);
        self::assertSame(['gold' => 1], $inventory->all(), '掉落单位同帧扣包（绑定物保留） / dropped units deduct in the same beat (bound items stay)');

        $drops = $this->worldDrops($world);
        self::assertCount(1, $drops);
        self::assertSame('potion', $drops[0]->itemId);
        self::assertSame(2, $drops[0]->count);
        self::assertSame('1002', $drops[0]->ownerUid, '击杀者归属绑定（not_owner 拾取保护复用） / the killer-ownership binding (reusing the not_owner pickup protection)');
        self::assertNotNull($drops[0]->expiresAt, '归属窗口写入 expiresAt / the ownership window writes expiresAt');
    }

    public function testDeathDropCapsEntriesAndZeroRatioDropsNothing(): void
    {
        [$map, $world] = $this->buildMapServer(new DeathDropPolicy(0, 60, 1, []));

        $inventory = new Inventory();
        $inventory->add('potion', 3);
        $inventory->add('sword', 1);
        $map = $this->withInventory($map, '1001@conn-1', $inventory);

        self::assertSame(0, $map->dropInventoryOnDeath('1001@conn-1', '1002'), '0% 比例不掉落 / a 0% ratio drops nothing');
        self::assertSame(['potion' => 3, 'sword' => 1], $inventory->all());

        $dropped = $map->dropInventoryOnDeath('1001@conn-1', '1002');
        self::assertSame(0, $dropped);
        self::assertCount(0, $this->worldDrops($world));
    }

    public function testDeathDropIsNoOpWithoutPolicy(): void
    {
        [$map, $world] = $this->buildMapServer(null);

        $inventory = new Inventory();
        $inventory->add('potion', 2);
        $map = $this->withInventory($map, '1001@conn-1', $inventory);

        self::assertSame(0, $map->dropInventoryOnDeath('1001@conn-1', '1002'), '策略缺省关闭零操作 / the default-off policy is a no-op');
        self::assertSame(['potion' => 2], $inventory->all());
        self::assertCount(0, $this->worldDrops($world));
    }

    // ── PVP 对抗门 ──
    // ── The PVP combat gate ──

    public function testPvpGateRejectsWhenDisabledAndAllowsPvePairs(): void
    {
        [$map] = $this->buildMapServer(new DeathDropPolicy(30, 60, 8), pvpEnabled: false);

        $attacker = new PlayerActor('player-1');
        $target = new PlayerActor('player-2');
        $monster = $this->createStub(Damageable::class);

        self::assertSame('pvp_disabled', $map->pvpRejection($attacker, $target), 'PVP 缺省拒绝（治理裁决） / PVP rejects by default (the governance ruling)');
        self::assertNull($map->pvpRejection($attacker, $monster), 'PVE 不治理 / PVE stays ungoverned');
    }

    public function testPvpGateRejectsInsideTheSafeZoneAndSpawnProtection(): void
    {
        [$map, $world] = $this->buildMapServer(null, pvpEnabled: true, safeZone: ['x' => 0, 'y' => 0, 'radius' => 50]);

        // 玩家实体入 EM（pvpRejection 读世界权威位置）；player-2 放 (60,60)（距圆心 84.9 > 50，区外）
        // Player entities into the EM (pvpRejection reads the world's authoritative positions); player-2 at
        // (60,60) (84.9 from the center > 50, outside the zone).
        $world->getEntityManager()->add(new BaseEntity('player-1', new Position(0, 0)));
        $world->getEntityManager()->add(new BaseEntity('player-2', new Position(60, 60)));

        $attacker = new PlayerActor('player-1');
        $target = new PlayerActor('player-2');

        self::assertSame('in_safe_zone', $map->pvpRejection($attacker, $target), '攻击方在安全区内即拒绝 / the attacker inside the zone rejects');

        // 双方都在区外：攻击方挪 (70,0)（距离 70 > 50）
        // Both outside the zone: the attacker moves to (70,0) (distance 70 > 50).
        $world->getEntityManager()->add(new BaseEntity('player-3', new Position(70, 0)));
        $farAttacker = new PlayerActor('player-3');
        self::assertNull($map->pvpRejection($farAttacker, $target), '区外 PVP 放行（开关已开） / out-of-zone PVP passes (with the switch on)');

        $protected = new PlayerActor('player-4');
        $protected->enableSpawnProtection();
        $world->getEntityManager()->add(new BaseEntity('player-4', new Position(70, 70)));
        self::assertSame('spawn_protected', $map->pvpRejection($farAttacker, $protected), '出生保护期免 PVP / spawn protection shields from PVP');
    }

    public function testPvpGateIsInertWithoutMmorpg(): void
    {
        [$map] = $this->buildMapServer(null, withMmorpg: false);

        self::assertNull($map->pvpRejection(new PlayerActor('player-1'), new PlayerActor('player-2')), 'mmorpg 未装配 = 零治理 / without mmorpg = zero governance');
    }

    // ── harness（比照 MapServerGameplayReloadTest） ──
    // ── harness (mirroring MapServerGameplayReloadTest) ──

    private function buildMapServer(?DeathDropPolicy $deathDrop, bool $pvpEnabled = false, array $safeZone = null, bool $withMmorpg = true): array
    {
        $addedActors = [];
        $world = new World(new SimpleEntityManager(), $this->recordingActorSystem($addedActors), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $typeIndex = new EntityTypeIndex();
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE));
        $items->register(new ItemDefinition('sword', '铁剑', ItemDefinition::TYPE_EQUIPMENT));

        $config = null;
        if ($withMmorpg) {
            $config = new MmorpgConfig(deathDrop: $deathDrop, pvpEnabled: $pvpEnabled, safeZone: $safeZone);
        }

        $map = new MapServer(
            $this->createStub(ServerInterface::class),
            new JsonBatchSerializer(),
            $this->createStub(TokenManagerInterface::class),
            $world,
            new ConnectionRegistry(),
            dropTable: new DropTable(['gold' => 1]),
            typeIndex: $typeIndex,
            inventories: [],
            skills: $skills,
            random: new FixedRandomSource(1),
            mmorpg: $config,
        );
        $map->attachCombat(new FrameworkCombatService($world, $map, $skills, $items, new FixedRandomSource(100)));

        return [$map, $world];
    }

    /**
     * 以替换 inventories 表的方式注入死者背包（MapServer 构造参数为只读快照，测试经反射覆写）。
     * Injects the victim's inventory by replacing the inventories table (the constructor arg is a readonly
     * snapshot; the test overwrites it via reflection).
     */
    private function withInventory(MapServer $map, string $entityId, Inventory $inventory): MapServer
    {
        $property = new \ReflectionProperty(MapServer::class, 'inventories');
        $property->setAccessible(true);
        $property->setValue($map, [$entityId => $inventory]);

        return $map;
    }

    /**
     * @return list<DropEntity>
     */
    private function worldDrops(World $world): array
    {
        return array_values(array_filter(
            $world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
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
