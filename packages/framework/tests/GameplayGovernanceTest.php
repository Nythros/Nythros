<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Combat\SeededRandomSource;
use Nythros\Framework\Game\Mmorpg\DeathDropPolicy;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * GameplayGovernanceTest - P13 死亡与对抗治理 + P14 随机性注入的框架侧验收：
 * DeathDropPolicy/MmorpgConfig 新参数不变量、BaseMonster 伤害账本聚合与归属裁决、
 * CombatService AoE PVP 门与 damage_leader 击杀归属、SeededRandomSource 确定性。
 * GameplayGovernanceTest - the P13 death & combat governance + P14 randomness injection's framework-side
 * acceptance: DeathDropPolicy/MmorpgConfig new-parameter invariants, the BaseMonster damage-ledger
 * aggregation and attribution ruling, CombatService's AoE PVP gate and damage_leader kill credit, and
 * SeededRandomSource determinism.
 */
final class GameplayGovernanceTest extends TestCase
{
    // ── P13 参数不变量 ──
    // ── P13 parameter invariants ──

    public function testDeathDropPolicyInvariantsFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeathDropPolicy(101, 60, 8);
    }

    public function testDeathDropPolicyWindowAndMaxMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeathDropPolicy(30, 0, 8);
    }

    public function testMmorpgConfigRejectsUnknownKillCredit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MmorpgConfig(killCredit: 'first_blood');
    }

    public function testMmorpgConfigDefaultsKeepGovernanceOff(): void
    {
        $config = MmorpgConfig::default();

        self::assertNull($config->deathDrop, '死亡掉落缺省关闭（零破坏口径） / death drops default off (the zero-breakage baseline)');
        self::assertFalse($config->pvpEnabled, 'PVP 缺省关闭（治理裁决） / PVP defaults off (the governance ruling)');
        self::assertSame(MmorpgConfig::KILL_CREDIT_LAST_HIT, $config->killCredit);
    }

    // ── P13 伤害账本 ──
    // ── P13 damage ledger ──

    public function testDamageLedgerAggregatesPerAttackerAndRanks(): void
    {
        [$monster] = $this->spawnLedgerMonster();

        $monster->noteDamage('a', 10);
        $monster->noteDamage('b', 15);
        $monster->noteDamage('a', 20);
        $monster->noteDamage('c', 0); // 零伤害不入账 Zero damage books nothing.

        self::assertSame([
            ['attackerId' => 'a', 'damage' => 30],
            ['attackerId' => 'b', 'damage' => 15],
        ], $monster->damageContributors());
        self::assertSame('a', $monster->damageLeader(), '账本最高者 = 归属候选 / the ledger leader is the attribution candidate');
    }

    public function testDamageLeaderTieTakesFirstReached(): void
    {
        [$monster] = $this->spawnLedgerMonster();

        $monster->noteDamage('first', 10);
        $monster->noteDamage('second', 10);

        self::assertSame('first', $monster->damageLeader());
    }

    // ── P13 CombatService：AoE PVP 门 + damage_leader 归属 ──
    // ── P13 CombatService: the AoE PVP gate + damage_leader attribution ──

    public function testAoePvpGateSkipsRejectedTargetsSilently(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildServiceWithGate(
            static fn ($attacker, $target): ?string => $target instanceof PlayerActor ? 'pvp_disabled' : null,
        );
        $caster = new PlayerActor('player-1');
        $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);
        $this->spawnPlayerEntity($world, $lookup, 'player-2', 0, 6);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        self::assertCount(1, $hits, '被门挡下的玩家目标不出现在命中列表 / the gate-rejected player target never enters the hit list');
        self::assertSame('m-1', $hits[0]['targetId']);
        $aoes = array_values(array_filter($broadcaster->vision, static fn (array $f): bool => $f['type'] === 'combat:aoe'));
        self::assertEqualsCanonicalizing(['m-1'], $aoes[0]['payload']['targetIds']);
    }

    public function testDamageLeaderKillCreditAttributesTheLedgerLeader(): void
    {
        $events = new \Nythros\Framework\Event\EventDispatcher();
        $seen = [];
        $events->listen(CombatService::EVENT_KILL, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        [$world, $combat, $broadcaster, $lookup] = $this->buildServiceWithGate(null, killCredit: MmorpgConfig::KILL_CREDIT_DAMAGE_LEADER, events: $events);
        $p1 = new PlayerActor('player-1');
        $p1->attachConnection('conn-1', '1001');
        $p2 = new PlayerActor('player-2');
        $p2->attachConnection('conn-2', '1002');
        // 归属解析依赖 actorLookup（生产即 MapServer 全量 $actors 表）——两名玩家都登记
        // Credit resolution rides the actorLookup (production: MapServer's full $actors table) — register both players.
        $lookup->actors['player-1'] = $p1;
        $lookup->actors['player-2'] = $p2;
        $this->spawnMonster($world, $combat, $lookup, 'm-1', 30, 5, 0);

        // p1 fireball 15 + 普攻 10 = 账本 25；p2 普攻 10 收最后一击 → last_hit = p2，账本最高 = p1
        // p1's fireball 15 + normal attack 10 = ledger 25; p2's normal attack 10 lands the last hit →
        // last_hit = p2 while the ledger leader = p1
        $combat->castSkill($p1, 'fireball', $lookup->actors['m-1']);
        $combat->attack($p1, $lookup->actors['m-1']);
        $combat->attack($p2, $lookup->actors['m-1']);

        self::assertCount(1, $seen);
        self::assertSame('1001', $seen[0]['killerUid'], 'damage_leader 裁决归属账本最高者 / damage_leader credits the ledger leader');
        self::assertSame('m-1', $seen[0]['victimId']);
        self::assertSame('wolf', $seen[0]['monsterTypeId']);
        $contributors = array_column($seen[0]['contributors'], 'attackerId');
        self::assertSame(['player-1', 'player-2'], $contributors, '多源统计随击杀埋点发布 / multi-source statistics ride the kill instrumentation');
    }

    public function testLastHitCreditKeepsThePreIntegrationAttribution(): void
    {
        $events = new \Nythros\Framework\Event\EventDispatcher();
        $seen = [];
        $events->listen(CombatService::EVENT_KILL, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        [$world, $combat, $broadcaster, $lookup] = $this->buildServiceWithGate(null, killCredit: MmorpgConfig::KILL_CREDIT_LAST_HIT, events: $events);
        $p1 = new PlayerActor('player-1');
        $p1->attachConnection('conn-1', '1001');
        $p2 = new PlayerActor('player-2');
        $p2->attachConnection('conn-2', '1002');
        $lookup->actors['player-1'] = $p1;
        $lookup->actors['player-2'] = $p2;
        $this->spawnMonster($world, $combat, $lookup, 'm-1', 30, 5, 0);

        $combat->castSkill($p1, 'fireball', $lookup->actors['m-1']);
        $combat->attack($p1, $lookup->actors['m-1']);
        $combat->attack($p2, $lookup->actors['m-1']);

        self::assertSame('1002', $seen[0]['killerUid'], 'last_hit 缺省维持接入前归属 / last_hit keeps the pre-integration attribution by default');
    }

    // ── P14 SeededRandomSource ──
    // ── P14 SeededRandomSource ──

    public function testSeededRandomSourceReproducesTheSameSequencePerSeed(): void
    {
        $a = new SeededRandomSource(42);
        $b = new SeededRandomSource(42);
        $c = new SeededRandomSource(43);
        $sequenceA = [];
        $sequenceB = [];
        $sequenceC = [];
        for ($i = 0; $i < 20; $i++) {
            $sequenceA[] = $a->randomInt(80, 120);
            $sequenceB[] = $b->randomInt(80, 120);
            $sequenceC[] = $c->randomInt(80, 120);
        }

        self::assertSame($sequenceA, $sequenceB, '同种子同序列 / the same seed yields the same sequence');
        self::assertNotEquals($sequenceA, $sequenceC, '异种子异序列 / different seeds diverge');
        foreach ($sequenceA as $value) {
            self::assertGreaterThanOrEqual(80, $value);
            self::assertLessThanOrEqual(120, $value);
        }
    }

    // ── harness ──

    /**
     * @return array{0: \Nythros\Framework\BaseMonster}
     */
    private function spawnLedgerMonster(): array
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildServiceWithGate(null);

        return [$this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 0, 0)];
    }

    /**
     * @param (callable(\Nythros\Framework\Damageable, \Nythros\Framework\Damageable): (string|null))|null $gate
     * @return array{0: \Nythros\Contracts\WorldInterface, 1: CombatService, 2: RecordingBroadcaster, 3: RecordingActorLookup}
     */
    private function buildServiceWithGate(?callable $gate, string $killCredit = MmorpgConfig::KILL_CREDIT_LAST_HIT, ?\Nythros\Framework\Event\EventDispatcherInterface $events = null): array
    {
        $world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $broadcaster = new RecordingBroadcaster();
        $lookup = new RecordingActorLookup();
        $skills = new \Nythros\Framework\Plugin\Skill\SkillRepository();
        $skills->register(new \Nythros\Framework\Plugin\Skill\SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $items = new \Nythros\Framework\Plugin\Item\ItemRepository();
        $items->register(new \Nythros\Framework\Plugin\Item\ItemDefinition('gold', '金币', \Nythros\Framework\Plugin\Item\ItemDefinition::TYPE_CURRENCY));
        $items->register(new \Nythros\Framework\Plugin\Item\ItemDefinition('potion', '药水', \Nythros\Framework\Plugin\Item\ItemDefinition::TYPE_CONSUMABLE));

        $combat = new CombatService($world, $broadcaster, $skills, $items, new FixedRandomSource(100), $lookup, killCredit: $killCredit, pvpGate: $gate === null ? null : \Closure::fromCallable($gate), events: $events);

        return [$world, $combat, $broadcaster, $lookup];
    }

    private function spawnMonster(\Nythros\Contracts\WorldInterface $world, CombatService $combat, RecordingActorLookup $lookup, string $id, int $maxHp, int $x, int $y): MonsterActor
    {
        $entity = new BaseEntity($id, new Position($x, $y));
        $world->getEntityManager()->add($entity);
        $world->getAOI()->updateEntity($entity);

        $monster = new MonsterActor(
            $id,
            $maxHp,
            $world,
            $combat,
            new DropTable(['gold' => 1]),
            $lookup,
            new EntityTypeIndex(),
            new FixedRandomSource(1),
            new RecordingBroadcaster(),
            typeId: 'wolf',
        );
        $monster->bindEntity($entity);
        $lookup->actors[$id] = $monster;

        return $monster;
    }

    private function spawnPlayerEntity(\Nythros\Contracts\WorldInterface $world, RecordingActorLookup $lookup, string $id, int $x, int $y): void
    {
        $entity = new BaseEntity($id, new Position($x, $y));
        $world->getEntityManager()->add($entity);
        $world->getAOI()->updateEntity($entity);
        $lookup->actors[$id] = new PlayerActor($id);
    }
}
