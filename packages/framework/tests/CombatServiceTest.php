<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Inventory;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * CombatServiceTest - 战斗结算纯业务测试：双向普攻、死亡 entity_dead、技能倍率、非法 itemId 跳过与拾取结算。
 * CombatService pure-business tests: bidirectional normal attacks, death entity_dead, skill multiplier, illegal-item-id skipping and pickup settlement.
 *
 * 组装策略：World 用真实 SimpleEntityManager + SimpleActorSystem + GridAOI + SimpleEventBus + RegionScheduler；
 * 广播/随机/Actor 查找注入调用记录 fake，保证确定性。
 * Assembly strategy: the World is a real SimpleEntityManager + SimpleActorSystem + GridAOI + SimpleEventBus + RegionScheduler stack;
 * broadcasting/random/actor-lookup are call-recording fakes for determinism.
 */
final class CombatServiceTest extends TestCase
{
    public function testAttackDealsBidirectionalDamageAndBroadcastsHit(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $player = new PlayerActor('player-1');
        $monster = $this->buildMonster($world, $combat, 100);

        $combat->attack($player, $monster);
        $combat->attack($monster, $player);

        self::assertSame(90, $monster->hp());
        self::assertSame(90, $player->hp());

        $hits = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:hit',
        ));
        self::assertCount(2, $hits);
        self::assertSame('player-1', $hits[0]['payload']['attackerId']);
        self::assertSame('monster-1', $hits[0]['payload']['targetId']);
        self::assertSame(10, $hits[0]['payload']['damage']);
        self::assertSame(90, $hits[0]['payload']['hp']);
    }

    public function testAttackBroadcastsEntityDeadOnTargetDeathAndSpawnsDrops(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $player = new PlayerActor('player-1');
        // maxHp=10：一次普攻（伤害 10）恰好归零触发死亡结算
        // maxHp=10: one normal attack (10 damage) zeroes hp and triggers the death settlement
        $monster = $this->buildMonster($world, $combat, 10);

        $combat->attack($player, $monster);

        self::assertTrue($monster->isDead());

        // 怪物死亡 entity_dead 恰广播一次（BaseMonster.onDeath 广播，attack 不重复）
        // The monster's entity_dead broadcasts exactly once (BaseMonster.onDeath broadcasts it; attack does not duplicate)
        $deaths = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_dead',
        ));
        self::assertCount(1, $deaths);
        self::assertSame('monster-1', $deaths[0]['payload']['id']);

        // 死亡掉落：dropTable roll 命中 gold，DropEntity 已登记进 world
        // Death drops: the dropTable roll hits gold and the DropEntity is registered into the world
        $drops = self::worldDrops($world);
        self::assertCount(1, $drops);
        self::assertSame('gold', $drops[0]->itemId);
        self::assertSame(['x' => 0, 'y' => 0], $drops[0]->getPosition());
    }

    public function testCastSkillAppliesMultiplierToDamage(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $player = new PlayerActor('player-1');
        $monster = $this->buildMonster($world, $combat, 100);

        $combat->castSkill($player, 'fireball', $monster);

        // 普攻 10 × fireball 倍率 1.5 = 15
        // Base attack 10 × fireball multiplier 1.5 = 15
        self::assertSame(85, $monster->hp());

        $casts = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'skill:cast',
        ));
        self::assertCount(1, $casts);
        self::assertSame('fireball', $casts[0]['payload']['skillId']);
        self::assertSame('monster-1', $casts[0]['payload']['targetId']);

        $hits = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:hit',
        ));
        self::assertSame(15, $hits[0]['payload']['damage']);
    }

    public function testCastSkillWithUnknownSkillIdIsSilent(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $player = new PlayerActor('player-1');
        $monster = $this->buildMonster($world, $combat, 100);

        $combat->castSkill($player, 'unknown-skill', $monster);

        self::assertSame(100, $monster->hp());
        self::assertSame([], $broadcaster->vision);
    }

    public function testSpawnDropsSkipsUnknownItemIds(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [
            ['itemId' => 'unknown-item', 'count' => 1],
            ['itemId' => 'gold', 'count' => 2],
        ]);

        // 未注册 itemId 跳过：只生成 gold 一条掉落
        // Unregistered item ids are skipped: only the gold drop is spawned
        $drops = self::worldDrops($world);
        self::assertCount(1, $drops);
        self::assertSame('gold', $drops[0]->itemId);
        self::assertSame(2, $drops[0]->count);

        $spawns = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:spawned',
        ));
        self::assertCount(1, $spawns);
        self::assertSame('gold', $spawns[0]['payload']['itemId']);
    }

    public function testSpawnDropsBroadcastsEntityEnterToCrossCellNeighbors(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();

        // 已有邻居实体在 (0,0)（cell 0:0），掉落生成在 (10,0)（cell 1:0，跨格）：
        // updateEntity 返回 entered=[邻居]，spawn 后补发 entity_enter（ADR-017 §8.7）
        // A neighbor already exists at (0,0) (cell 0:0); the drop spawns at (10,0) (cell 1:0, cross-cell):
        // updateEntity returns entered=[neighbor], so entity_enter is back-filled after spawn (ADR-017 §8.7)
        $neighbor = new BaseEntity('player-1', new Position(0, 0));
        $world->getEntityManager()->add($neighbor);
        $world->getAOI()->updateEntity($neighbor);

        $combat->spawnDrops('monster-1', ['x' => 10, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);

        // 补发 entity_enter：定向发送给 entered 邻居，附 itemId 与 drop:spawned 信息等价
        // Back-filled entity_enter: directed to the entered neighbor, carrying itemId — informationally equivalent to drop:spawned
        $enters = array_values(array_filter(
            $broadcaster->direct,
            static fn (array $frame): bool => $frame['type'] === 'entity_enter',
        ));
        self::assertCount(1, $enters);
        self::assertSame('player-1', $enters[0]['entity']);
        self::assertSame(self::worldDrops($world)[0]->getId(), $enters[0]['payload']['id']);
        self::assertSame(['x' => 10, 'y' => 0], $enters[0]['payload']['position']);
        self::assertSame('gold', $enters[0]['payload']['itemId']);
    }

    public function testSpawnDropsWithoutNeighborsSkipsEntityEnter(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();

        // 九宫格内无旧邻居：entered 为空，不补发 entity_enter（drop:spawned 承担出生通知，信息等价）
        // No neighbors in the 3x3 neighborhood: entered is empty, no entity_enter back-fill
        // (drop:spawned carries the birth notice, informationally equivalent)
        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);

        $enters = array_values(array_filter(
            $broadcaster->direct,
            static fn (array $frame): bool => $frame['type'] === 'entity_enter',
        ));
        self::assertSame([], $enters);
    }

    public function testPickupRemovesDropAddsToInventoryAndBroadcasts(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $player = new PlayerActor('player-1');
        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        $drop = self::worldDrops($world)[0];
        $inventory = new Inventory();

        self::assertTrue($combat->pickup($player, $drop, $inventory));

        // 摘除：world 实体表与 AOI 均无该掉落
        // Removal: neither the world entity table nor the AOI holds the drop anymore
        self::assertNull($world->getEntityManager()->get($drop->getId()));
        self::assertSame(1, $inventory->count('gold'));
        self::assertSame(['gold' => 1], $inventory->all());

        // 广播：drop:removed（视野）+ item:added（定向拾取者）
        // Broadcasts: drop:removed (view) + item:added (directed to the pickup actor)
        $removed = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:removed',
        ));
        self::assertCount(1, $removed);
        self::assertSame($drop->getId(), $removed[0]['payload']['dropId']);

        $added = array_values(array_filter(
            $broadcaster->direct,
            static fn (array $frame): bool => $frame['type'] === 'item:added',
        ));
        self::assertCount(1, $added);
        self::assertSame('player-1', $added[0]['entity']);
        self::assertSame(['itemId' => 'gold', 'count' => 1], $added[0]['payload']);
    }

    public function testSpawnDropsRegistersTheDropKindIntoTheTypeIndex(): void
    {
        // D5 债务关闭回归：spawnDrops 以登记为准向 typeIndex 登记 KIND_DROP
        // D5 debt-closure regression: spawnDrops registers KIND_DROP into the typeIndex per the registration ruling
        $typeIndex = new EntityTypeIndex();
        [$world, $combat] = $this->buildService($typeIndex);

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        $drop = self::worldDrops($world)[0];

        self::assertSame(EntityTypeIndex::KIND_DROP, $typeIndex->kindOf($drop->getId()));

        $combat->pickup(new PlayerActor('player-1'), $drop, new Inventory());

        self::assertNull($typeIndex->kindOf($drop->getId()), '拾取后同步摘除登记。The registration is removed on pickup.');
    }

    public function testPickupAllowsTheRecordedKiller(): void
    {
        [$world, $combat] = $this->buildService();
        $killer = new PlayerActor('player-1');
        $killer->attachConnection('conn-1', '1001');

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]], '1001');
        $drop = self::worldDrops($world)[0];

        self::assertSame('1001', $drop->ownerUid, '掉落绑定击杀者 uid。The drop binds the killer uid.');
        self::assertTrue($combat->pickup($killer, $drop, new Inventory()), '击杀者本人可拾取。The killer may pick up.');
    }

    public function testPickupRejectsStrangersAndKeepsTheDrop(): void
    {
        [$world, $combat, $broadcaster] = $this->buildService();
        $stranger = new PlayerActor('player-9');
        $stranger->attachConnection('conn-9', '9999');

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]], '1001');
        $drop = self::worldDrops($world)[0];
        $inventory = new Inventory();

        self::assertFalse($combat->pickup($stranger, $drop, $inventory));
        self::assertNotNull($world->getEntityManager()->get($drop->getId()), '归属拒绝后掉落保留在场。A rejected stranger leaves the drop in place.');
        self::assertSame(0, $inventory->count('gold'));

        $errors = array_values(array_filter(
            $broadcaster->direct,
            static fn (array $frame): bool => $frame['type'] === 'combat:error',
        ));
        self::assertCount(1, $errors);
        self::assertSame('not_owner', $errors[0]['payload']['code']);
        self::assertSame('player-9', $errors[0]['entity']);
    }

    public function testPickupAllowsSameTeamMembersOfTheKiller(): void
    {
        [$world, $combat] = $this->buildService(null, new FixedTeamMembership(['1001' => 'team-7', '1002' => 'team-7']));
        $teammate = new PlayerActor('player-2');
        $teammate->attachConnection('conn-2', '1002');

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]], '1001');
        $drop = self::worldDrops($world)[0];

        self::assertSame('team-7', $drop->ownerTeamId, '击杀者队伍随归属绑定。The killer team binds alongside ownership.');
        self::assertTrue($combat->pickup($teammate, $drop, new Inventory()), '同队成员共享拾取权。Same-team members share pickup rights.');
    }

    public function testUnownedDropsAreFreeToPick(): void
    {
        [$world, $combat] = $this->buildService();
        $anyone = new PlayerActor('player-3');
        $anyone->attachConnection('conn-3', '1003');

        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]], null);
        $drop = self::worldDrops($world)[0];

        self::assertNull($drop->ownerUid);
        self::assertTrue($combat->pickup($anyone, $drop, new Inventory()));
    }

    public function testPurgeExpiredDropsReclaimsAndBroadcasts(): void
    {
        $typeIndex = new EntityTypeIndex();
        [$world, $combat, $broadcaster] = $this->buildService($typeIndex, null, 1);
        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        $drop = self::worldDrops($world)[0];

        // 未到期：回收零命中
        // Not yet expired: nothing reclaimed
        self::assertSame(0, $combat->purgeExpiredDrops(microtime(true)));
        self::assertNotNull($world->getEntityManager()->get($drop->getId()));

        // 已过期：摘除实体 + 登记清理 + drop:removed 广播
        // Expired: entity removal + registry cleanup + drop:removed broadcast
        self::assertSame(1, $combat->purgeExpiredDrops(microtime(true) + 2));
        self::assertNull($world->getEntityManager()->get($drop->getId()));
        self::assertNull($typeIndex->kindOf($drop->getId()));

        $removed = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:removed',
        ));
        self::assertCount(1, $removed);
        self::assertSame($drop->getId(), $removed[0]['payload']['dropId']);

        // 幂等：重复扫描不再命中
        // Idempotent: repeated sweeps hit nothing
        self::assertSame(0, $combat->purgeExpiredDrops(microtime(true) + 3));
    }

    public function testZeroLifetimeDisablesExpiry(): void
    {
        [$world, $combat] = $this->buildService(null, null, 0);
        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        $drop = self::worldDrops($world)[0];

        self::assertNull($drop->expiresAt, 'lifetime=0 恒不过期。lifetime=0 never expires.');
        self::assertSame(0, $combat->purgeExpiredDrops(PHP_FLOAT_MAX));
        self::assertNotNull($world->getEntityManager()->get($drop->getId()));
    }

    public function testKillInstrumentationFiresOnSettledDeath(): void
    {
        // D4 缺口补埋回归：击杀路径发布 combat.kill（killerUid/monsterId 口径）。
        // D4-gap instrumentation regression: the kill path publishes combat.kill (the killerUid/monsterId contract).
        $events = new \Nythros\Framework\Event\EventDispatcher();
        $seen = [];
        $events->listen(CombatService::EVENT_KILL, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        [$world, $combat] = $this->buildService(events: $events);
        $player = new PlayerActor('player-1');
        $player->attachConnection('conn-1', '1001');
        $monster = $this->buildMonster($world, $combat, 10);

        // 未致死不触发。 No death, no event.
        $combat->attack($player, $this->buildMonster($world, $combat, 100));
        self::assertSame([], $seen);

        $combat->attack($player, $monster);

        self::assertCount(1, $seen);
        self::assertSame('1001', $seen[0]['killerUid']);
        self::assertSame('monster-1', $seen[0]['monsterId']);
        self::assertSame('monster-1', $seen[0]['victimId']);
        self::assertSame('wolf', $seen[0]['monsterTypeId'], '类型匹配键随击杀埋点发布（P2 收口） the type-matching key rides the kill instrumentation (the P2 close-out)');
    }

    public function testPickupInstrumentationFiresWithThePickerUid(): void
    {
        $events = new \Nythros\Framework\Event\EventDispatcher();
        $seen = [];
        $events->listen(CombatService::EVENT_PICKUP, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        [$world, $combat] = $this->buildService(events: $events);
        $picker = new PlayerActor('player-1');
        $picker->attachConnection('conn-1', '1001');
        $combat->spawnDrops('monster-1', ['x' => 0, 'y' => 0], [['itemId' => 'gold', 'count' => 2]]);

        $combat->pickup($picker, self::worldDrops($world)[0], new Inventory());

        self::assertSame([['uid' => '1001', 'itemId' => 'gold', 'count' => 2]], $seen);
    }

    /**
     * 组装战斗服务与支撑：World（真实引擎栈）+ 技能/物品注册表 + 记录广播器 + 确定性随机。
     * Builds the combat service and its support: a World (real engine stack) + skill/item repositories + a recording broadcaster + a deterministic random source.
     *
     * @param ?EntityTypeIndex $typeIndex 实体类型索引（缺省 null = 不登记） Entity-type index (default null = no registration).
     * @param ?FixedTeamMembership $teams 队伍归属查询（缺省 null = 仅 uid 判定） Team-membership lookup (default null = uid-only verdicts).
     * @param int $dropLifetimeSeconds 掉落存活秒数（0 = 永不过期） Drop lifetime in seconds (0 = never expires).
     * @param ?EventDispatcherInterface $events 事件派发器（缺省 null = 不派发埋点） Event dispatcher (default null = no instrumentation dispatch).
     *
     * @return array{0: WorldInterface, 1: CombatService, 2: RecordingBroadcaster}
     */
    private function buildService(?EntityTypeIndex $typeIndex = null, ?FixedTeamMembership $teams = null, int $dropLifetimeSeconds = 300, ?EventDispatcherInterface $events = null): array
    {
        $world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $broadcaster = new RecordingBroadcaster();
        $skills = new SkillRepository();
        $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE));

        $combat = new CombatService($world, $broadcaster, $skills, $items, new FixedRandomSource(100), typeIndex: $typeIndex, teams: $teams, dropLifetimeSeconds: $dropLifetimeSeconds, events: $events);

        return [$world, $combat, $broadcaster];
    }

    /**
     * 构造怪物 Actor：掉落表命中 gold；怪物自身随机源固定 1（onPatrol 移动与掉落 roll 均可控）。
     * Builds a monster actor: the drop table hits gold; the monster's own random source is fixed at 1 (controlling patrol movement and drop rolls).
     */
    private function buildMonster(WorldInterface $world, CombatService $combat, int $maxHp): MonsterActor
    {
        return new MonsterActor(
            'monster-1',
            $maxHp,
            $world,
            $combat,
            new DropTable(['gold' => 1]),
            new RecordingActorLookup(),
            new EntityTypeIndex(),
            new FixedRandomSource(1),
            new RecordingBroadcaster(),
            typeId: 'wolf',
        );
    }

    /**
     * 从 world 实体表筛出全部 DropEntity。
     * Filters every DropEntity out of the world entity table.
     *
     * @return list<DropEntity>
     */
    private static function worldDrops(WorldInterface $world): array
    {
        return array_values(array_filter(
            $world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
    }
}
