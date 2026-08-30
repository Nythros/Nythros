<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\CircleShape;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Damageable;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * CombatServiceAoETest - AoE 批量命中管线与掉落攒批测试（ADR-024 §D-C/§D-D）：
 * 形状查询命中、空集回执、越界形状、已死实体过滤、施法者豁免、连锁掉落合并帧与顺序、
 * 直接批量掉落 API，以及与既有单体路径（attack/spawnDrops）并存不干扰。
 * CombatServiceAoETest - AoE batch-hit pipeline and drop-batching tests (ADR-024 §D-C/§D-D):
 * shape-query hits, empty-set receipt, out-of-bounds shapes, dead-actor filtering, caster exemption,
 * chained-drop merged frames and ordering, the direct batch-drop API, and coexistence with the existing
 * single-target paths (attack/spawnDrops).
 *
 * 组装策略：World 用真实 SimpleEntityManager + SimpleActorSystem + GridAOI + SimpleEventBus + RegionScheduler；
 * 广播/随机/Actor 查找注入调用记录 fake，保证确定性（FixedRandomSource(100)：普攻伤害恒 10、fireball 恒 15）。
 * Assembly strategy: the World is a real SimpleEntityManager + SimpleActorSystem + GridAOI + SimpleEventBus +
 * RegionScheduler stack; broadcasting/random/actor-lookup are call-recording fakes for determinism
 * (FixedRandomSource(100): normal attack always 10, fireball always 15).
 */
final class CombatServiceAoETest extends TestCase
{
    public function testCastSkillAoeHitsAllAliveTargetsAndBroadcastsSingleMergedFrame(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $m1 = $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);
        $m2 = $this->spawnMonster($world, $combat, $lookup, 'm-2', 100, 0, 8);
        $m3 = $this->spawnMonster($world, $combat, $lookup, 'm-3', 100, -12, 0);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        // 三目标全部命中：fireball = 10 × 1.5 × 100% = 15
        // All three targets hit: fireball = 10 × 1.5 × 100% = 15
        self::assertCount(3, $hits);
        self::assertSame(85, $m1->hp());
        self::assertSame(85, $m2->hp());
        self::assertSame(85, $m3->hp());

        // 合并结果帧恰好一次，杜绝逐目标 N 次 combat:hit（targetIds 顺序不保证，按集合比较）
        // Exactly one merged result frame; per-target combat:hit floods eliminated (targetIds order is
        // unspecified per the queryShape contract — compared as a set)
        $aoes = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:aoe',
        ));
        self::assertCount(1, $aoes);
        self::assertSame('player-1', $aoes[0]['payload']['casterId']);
        self::assertSame('fireball', $aoes[0]['payload']['skillId']);
        self::assertEqualsCanonicalizing(['m-1', 'm-2', 'm-3'], $aoes[0]['payload']['targetIds']);
        self::assertEqualsCanonicalizing([15, 15, 15], $aoes[0]['payload']['damages']);
        self::assertEqualsCanonicalizing([85, 85, 85], $aoes[0]['payload']['hps']);
        self::assertSame([], array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:hit',
        )));
    }

    /**
     * 空集仍发 cast 回执（ADR-024 §5 边界矩阵），且无掉落批量帧（空批静默）。
     * An empty hit set still emits the cast receipt (ADR-024 §5 boundary matrix), with no drop-batch frame (an empty batch stays silent).
     */
    public function testCastSkillAoeEmptyShapeStillBroadcastsCastReceipt(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(500, 500, 5));

        self::assertSame([], $hits);
        $aoes = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:aoe',
        ));
        self::assertCount(1, $aoes);
        self::assertSame([], $aoes[0]['payload']['targetIds']);
        self::assertSame([], array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:spawned_batch',
        )));
    }

    /**
     * 越界形状：形状覆盖外的实体不受影响（GridAOI bounds 格粗筛不漏不误伤）。
     * Out-of-shape: entities outside the shape coverage stay untouched (GridAOI's bounds coarse filter neither misses nor over-hits).
     */
    public function testCastSkillAoeLeavesEntitiesOutsideShapeUntouched(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $inside = $this->spawnMonster($world, $combat, $lookup, 'm-in', 100, 3, 0);
        $outside = $this->spawnMonster($world, $combat, $lookup, 'm-out', 100, 40, 40);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 10));

        self::assertCount(1, $hits);
        self::assertSame('m-in', $hits[0]['targetId']);
        self::assertSame(100, $outside->hp());
        self::assertSame(['m-in'], $this->targetIds($broadcaster));
    }

    /**
     * 命中已死实体过滤（ADR-024 §5 边界矩阵）：已死怪物在形状内也不结算、不出现在结果里。
     * Dead-actor filtering (ADR-024 §5 boundary matrix): a dead monster inside the shape settles nothing and never appears in results.
     */
    public function testCastSkillAoeSkipsDeadActors(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $alive = $this->spawnMonster($world, $combat, $lookup, 'm-alive', 100, 5, 0);
        $dead = $this->spawnMonster($world, $combat, $lookup, 'm-dead', 10, 6, 0);
        $dead->takeDamage(10); // 预置死亡 Pre-kill.

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        self::assertCount(1, $hits);
        self::assertSame('m-alive', $hits[0]['targetId']);
        self::assertTrue($dead->isDead());
    }

    /**
     * 施法者豁免：施法者位于形状内也不受自身 AoE 伤害。
     * Caster exemption: the caster inside the shape never takes its own AoE damage.
     */
    public function testCastSkillAoeExcludesCasterItself(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $casterEntity = new BaseEntity('player-1', new Position(0, 0));
        $world->getEntityManager()->add($casterEntity);
        $world->getAOI()->updateEntity($casterEntity);
        $monster = $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        self::assertCount(1, $hits);
        self::assertSame('m-1', $hits[0]['targetId']);
        self::assertSame(100, $caster->hp());
    }

    public function testCastSkillAoeUnknownSkillIsSilent(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);

        $hits = $combat->castSkillAoE($caster, 'unknown-skill', new CircleShape(0, 0, 15));

        self::assertSame([], $hits);
        self::assertSame([], $broadcaster->vision);
    }

    public function testCastSkillAoeWithoutAssembledActorLookupThrows(): void
    {
        $world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $skills = new SkillRepository();
        $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $combat = new CombatService($world, new RecordingBroadcaster(), $skills, new ItemRepository(), new FixedRandomSource(100));

        $this->expectException(\LogicException::class);

        $combat->castSkillAoE(new PlayerActor('player-1'), 'fireball', new CircleShape(0, 0, 5));
    }

    /**
     * 连锁死亡与掉落双攒批（ADR-024 §9 V5 + §D-D）：AoE 击杀 N 怪 → 逐条 entity_dead 取消，关窗合并为
     * 单条 entity_dead_batch（并行等长列表 ids/positions/types，内容在登记时点捕获）→ 单条 drop:spawned_batch
     * → combat:aoe（同帧 FIFO：死亡批量帧先于掉落批量帧先于结果帧），无逐条 entity_dead/drop:spawned 洪泛。
     * Chained death and drop dual batching (ADR-024 §9 V5 + §D-D): an AoE killing N monsters cancels per-target
     * entity_dead frames, merging on window close into one entity_dead_batch (parallel equal-length lists
     * ids/positions/types, content captured at buffering time) → one drop:spawned_batch → combat:aoe (same-frame
     * FIFO: the death batch precedes the drop batch precedes the result frame), with no per-target
     * entity_dead/drop:spawned flooding.
     */
    public function testAoeKillChainMergesDeathsAndDropsIntoBatchFramesInOrder(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        // maxHp=12 < fireball 15：一次命中必死；DropTable(['gold'=>1]) 无不掉落段：每杀必掉 1
        // maxHp=12 < fireball 15: one hit always kills; DropTable(['gold'=>1]) has no no-drop segment: every kill drops exactly one
        $killed = [
            $this->spawnMonster($world, $combat, $lookup, 'm-1', 12, 5, 0),
            $this->spawnMonster($world, $combat, $lookup, 'm-2', 12, 0, 6),
            $this->spawnMonster($world, $combat, $lookup, 'm-3', 12, -7, 0),
        ];

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        self::assertCount(3, $hits);
        foreach ($killed as $monster) {
            self::assertTrue($monster->isDead());
        }

        $types = array_map(static fn (array $frame): string => $frame['type'], $broadcaster->vision);
        $deathBatchIndexes = array_keys($types, 'entity_dead_batch', true);
        $batchIndexes = array_keys($types, 'drop:spawned_batch', true);
        $aoeIndexes = array_keys($types, 'combat:aoe', true);
        self::assertCount(1, $deathBatchIndexes, 'entity_dead_batch 必须恰好一帧 / entity_dead_batch must be exactly one frame');
        self::assertCount(1, $batchIndexes, 'drop:spawned_batch 必须恰好一帧 / drop:spawned_batch must be exactly one frame');
        self::assertNotContains('entity_dead', $types, '窗口内不得出现逐条 entity_dead / no per-target entity_dead inside the window');
        self::assertNotContains('drop:spawned', $types, '窗口内不得出现逐条 drop:spawned / no per-drop drop:spawned inside the window');
        self::assertLessThan($batchIndexes[0], $deathBatchIndexes[0], '死亡批量帧必须先于掉落批量帧入队 / the death batch must enqueue before the drop batch');
        self::assertLessThan($aoeIndexes[0], $batchIndexes[0], '掉落批量帧必须先于 combat:aoe 结果帧入队 / the drop batch must enqueue before the combat:aoe result');

        // 死亡批量帧内容：并行等长列表，id/位置/种类为登记时点捕获值（死亡自清理后仍完整）；
        // queryShape 顺序不保证，位置/种类按下标与 ids 对齐断言
        // Death-batch content: parallel equal-length lists whose id/position/kind were captured at buffering time
        // (complete even after the death self-cleanup); queryShape order is unspecified, so positions/kinds are
        // asserted per-index aligned with ids
        $deaths = $broadcaster->vision[$deathBatchIndexes[0]]['payload'];
        self::assertEqualsCanonicalizing(['m-1', 'm-2', 'm-3'], $deaths['ids']);
        self::assertCount(count($deaths['ids']), $deaths['positions'], 'positions 与 ids 等长对齐 / positions align in length with ids');
        self::assertCount(count($deaths['ids']), $deaths['types'], 'types 与 ids 等长对齐 / types align in length with ids');
        $positionById = array_combine($deaths['ids'], $deaths['positions']);
        $typeById = array_combine($deaths['ids'], $deaths['types']);
        self::assertSame(['x' => 5, 'y' => 0], $positionById['m-1']);
        self::assertSame(['x' => 0, 'y' => 6], $positionById['m-2']);
        self::assertSame(['x' => -7, 'y' => 0], $positionById['m-3']);
        self::assertSame(['monster' => 3], array_count_values($typeById), 'types 全为 monster（horde 怪） / every kind is monster (the horde)');

        $batch = $broadcaster->vision[$batchIndexes[0]]['payload'];
        self::assertCount(3, $batch['dropIds']);
        self::assertSame(['gold', 'gold', 'gold'], $batch['itemIds']);
        foreach ($batch['positions'] as $position) {
            self::assertSame(['x', 'y'], array_keys($position));
        }

        // 掉落实体已登记进世界（易失不落库，但进程内可拾取）
        // Drop entities registered into the world (volatile, never archived, but pickable in-process)
        $drops = array_values(array_filter(
            $world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
        self::assertCount(3, $drops);
    }

    /**
     * AoE 击杀非怪物目标（reviewer MAJOR-1）：房内玩家被他人 AoE 击杀同样经死亡攒批窗口合并——
     * 单条 entity_dead_batch 的 types 列含 player 条目（与怪物条目同帧并行），无逐条 entity_dead 洪泛，
     * V5「连锁死亡合并为单条 entity_dead_batch」契约对玩家受害者闭环。
     * AoE killing a non-monster target (reviewer MAJOR-1): an in-room player killed by another player's AoE merges
     * through the death-batch window all the same — the single entity_dead_batch's types column carries the player
     * entry in parallel with the monster entry, with no per-target entity_dead flooding; the V5 contract "chained
     * deaths merge into a single entity_dead_batch" closes for player victims too.
     */
    public function testAoeKillingPlayerVictimMergesIntoDeathBatchWithPlayerType(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        // 怪物 maxHp=12 < fireball 15 一次必死；玩家预扣 90（hp=10 < 15）同样一次必死
        // The monster's maxHp=12 < fireball 15 dies in one hit; the player pre-dropped by 90 (hp=10 < 15) dies in one hit too
        $monster = $this->spawnMonster($world, $combat, $lookup, 'm-1', 12, 5, 0);
        $victim = new PlayerActor('player-2');
        $victimEntity = new BaseEntity('player-2', new Position(6, 0));
        $world->getEntityManager()->add($victimEntity);
        $world->getAOI()->updateEntity($victimEntity);
        $lookup->actors['player-2'] = $victim;
        $victim->takeDamage(90);

        $hits = $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));

        self::assertCount(2, $hits);
        self::assertTrue($monster->isDead());
        self::assertTrue($victim->isDead(), 'AoE 命中后玩家受害者必须死亡 / the player victim must be dead after the AoE hit');

        $types = array_map(static fn (array $frame): string => $frame['type'], $broadcaster->vision);
        $deathBatchIndexes = array_keys($types, 'entity_dead_batch', true);
        self::assertCount(1, $deathBatchIndexes, 'entity_dead_batch 必须恰好一帧 / entity_dead_batch must be exactly one frame');
        self::assertNotContains('entity_dead', $types, '窗口内不得出现逐条 entity_dead / no per-target entity_dead inside the window');

        // 玩家条目与怪物条目同帧并行：types 列含 player，id/位置/种类按 ids 下标对齐
        // The player entry rides the same frame as the monster entry: the types column carries player, with id/position/kind aligned per-index with ids
        $deaths = $broadcaster->vision[$deathBatchIndexes[0]]['payload'];
        self::assertEqualsCanonicalizing(['m-1', 'player-2'], $deaths['ids']);
        $typeById = array_combine($deaths['ids'], $deaths['types']);
        self::assertSame('monster', $typeById['m-1']);
        self::assertSame('player', $typeById['player-2'], 'types 列必须含 player 条目 / the types column must carry the player entry');
        $positionById = array_combine($deaths['ids'], $deaths['positions']);
        self::assertSame(['x' => 6, 'y' => 0], $positionById['player-2']);

        // 结果帧照常包含玩家受害者（hp=0）：hps 列与 targetIds 并行对齐，玩家受害者条目必须为 0
        // The result frame still includes the player victim (hp=0): the hps column aligns in parallel with
        // targetIds, and the player victim's entry must be 0
        $aoes = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'combat:aoe',
        ));
        self::assertCount(1, $aoes);
        self::assertEqualsCanonicalizing(['m-1', 'player-2'], $aoes[0]['payload']['targetIds']);
        $hpsById = array_combine($aoes[0]['payload']['targetIds'], $aoes[0]['payload']['hps']);
        self::assertSame(0, $hpsById['player-2'], '玩家受害者 hps 条目必须为 0 / the player victim\'s hps entry must be 0');
        self::assertSame(0, $hpsById['m-1'], '怪物受害者 hps 条目必须为 0 / the monster victim\'s hps entry must be 0');
    }

    /**
     * 直接批量掉落 API：一波两怪掉落 → 单条 drop:spawned_batch 合并帧，内容完整（id/item/位置并行列表），
     * 即使存在跨格旧邻居也不补发 entity_enter（与批量帧信息等价而取消）。
     * Direct batch-drop API: a two-monster wave merges into one drop:spawned_batch frame with complete content
     * (parallel id/item/position lists); no entity_enter back-fill even with a cross-cell neighbor present
     * (cancelled as informationally equivalent to the batch frame).
     */
    public function testSpawnDropsBatchDirectWaveMergesIntoSingleFrame(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();

        // 跨格旧邻居在场：验证窗口内 entity_enter 补发被取消
        // A cross-cell neighbor is present: verifies the entity_enter back-fill is cancelled inside the window
        $neighbor = new BaseEntity('neighbor-1', new Position(14, 0));
        $world->getEntityManager()->add($neighbor);
        $world->getAOI()->updateEntity($neighbor);

        $combat->spawnDropsBatch('caster-1', [
            ['monsterId' => 'm-1', 'position' => ['x' => 10, 'y' => 0], 'drops' => [['itemId' => 'gold', 'count' => 1]]],
            ['monsterId' => 'm-2', 'position' => ['x' => 20, 'y' => 0], 'drops' => [['itemId' => 'potion', 'count' => 2], ['itemId' => 'unknown-item', 'count' => 1]]],
        ]);

        $batches = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:spawned_batch',
        ));
        self::assertCount(1, $batches);
        $payload = $batches[0]['payload'];
        self::assertCount(2, $payload['dropIds']);
        self::assertSame(['gold', 'potion'], $payload['itemIds']);
        self::assertSame(
            [['x' => 10, 'y' => 0], ['x' => 20, 'y' => 0]],
            $payload['positions'],
        );
        self::assertSame([], array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:spawned',
        )));
        self::assertSame([], $broadcaster->direct, '窗口内不得补发 entity_enter / no entity_enter back-fill inside the window');

        // 未注册 itemId 跳过：世界内恰两条掉落
        // Unregistered item ids skipped: exactly two drops in the world
        $drops = array_values(array_filter(
            $world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
        self::assertCount(2, $drops);
    }

    public function testSpawnDropsBatchEmptyWaveAndNoValidDropsStaySilent(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();

        $combat->spawnDropsBatch('caster-1', []);
        $combat->spawnDropsBatch('caster-1', [
            ['monsterId' => 'm-1', 'position' => ['x' => 0, 'y' => 0], 'drops' => [['itemId' => 'unknown-item', 'count' => 1]]],
        ]);

        self::assertSame([], $broadcaster->vision);
        self::assertSame([], array_values(array_filter(
            $world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        )));
    }

    /**
     * 攒批窗口异常泄漏防护（R2 审查 MINOR-3）：结算链路中途抛非 Argument/Logic 异常时，
     * try/finally 必须关窗复位（掉落与死亡双窗口）——异常后 spawnDrops 恢复逐条 drop:spawned、
     * broadcastDeath 恢复逐条 entity_dead，出生/死亡通知不再被吞入缓冲。
     * Batch-window leak guard (R2 review MINOR-3): when the settlement chain throws a non-Argument/Logic
     * exception midway, try/finally must close and reset both windows (drop and death) — afterwards spawnDrops
     * resumes per-drop drop:spawned and broadcastDeath resumes per-target entity_dead, so birth/death notices are
     * no longer swallowed into the buffers.
     */
    public function testCastSkillAoeSettlementExceptionResetsDropBatchWindow(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        // 索引内实体 + lookup 解析为 takeDamage 即爆的战斗体：模拟结算链路中途抛异常
        // An indexed entity whose lookup resolves to a takeDamage-exploding combatant: simulates a mid-settlement exception
        $victimEntity = new BaseEntity('boom-1', new Position(5, 0));
        $world->getEntityManager()->add($victimEntity);
        $world->getAOI()->updateEntity($victimEntity);
        $lookup->actors['boom-1'] = new ExplodingDamageable();

        try {
            $combat->castSkillAoE($caster, 'fireball', new CircleShape(0, 0, 15));
            self::fail('结算异常必须上抛 / the settlement exception must propagate');
        } catch (\RuntimeException) {
            // 预期路径 expected path
        }

        $dropWindow = new \ReflectionProperty(CombatService::class, 'dropBatch');
        self::assertNull($dropWindow->getValue($combat), '异常后掉落攒批窗口必须已关闭 / the drop-batch window must be closed after the exception');
        $deathWindow = new \ReflectionProperty(CombatService::class, 'deathBatch');
        self::assertNull($deathWindow->getValue($combat), '异常后死亡攒批窗口必须已关闭 / the death-batch window must be closed after the exception');

        // 窗口复位后：窗口外 spawnDrops 恢复逐条 drop:spawned 广播（泄漏场景下会静默入缓冲永不广播）
        // After the reset: out-of-window spawnDrops resumes per-drop drop:spawned broadcasts (a leaked window would silently buffer them forever)
        $combat->spawnDrops('m-x', ['x' => 5, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        self::assertNotSame([], array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'drop:spawned',
        )), '异常后 spawnDrops 必须正常广播 / spawnDrops must broadcast normally after the exception');
    }

    /**
     * 单体击杀并存策略（ADR-024 §9 V5）：AoE 窗口关闭后，单体路径击杀仍逐条出帧——
     * 怪物死亡经 onDeath → broadcastDeath 出单条 entity_dead；非怪物目标死亡由 attack 结算直接广播。
     * Single-target coexistence policy (ADR-024 §9 V5): with the AoE window closed, single-target kills keep
     * per-frame output — a monster death walks onDeath → broadcastDeath as one entity_dead frame; a non-monster
     * target's death is broadcast directly by the attack settlement.
     */
    public function testSingleTargetKillsKeepPerFrameEntityDeadAfterAoeWindow(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $monster = $this->spawnMonster($world, $combat, $lookup, 'm-1', 12, 5, 0);
        $player = new PlayerActor('player-2');
        $lookup->actors['player-2'] = $player;

        // 先跑一次空集 AoE 开关窗（不产生任何死亡帧），再走单体路径
        // One empty-set AoE first (opens and closes the windows without any deaths), then the single-target paths
        $combat->castSkillAoE($caster, 'fireball', new CircleShape(500, 500, 5));
        $combat->attack($caster, $monster); // 10 < 12 hp：不死，仅掉血 10 < 12 hp: survives at 2.
        self::assertSame(2, $monster->hp());
        $combat->attack($caster, $monster); // 补刀至死 → onDeath → broadcastDeath（窗口外逐条） the finishing blow → onDeath → broadcastDeath (per-frame outside the window)
        $player->takeDamage(95); // 预置残血（普攻 10 不足以击杀满血玩家） pre-drop to low hp (one 10-damage swing cannot kill a full-hp player)
        $combat->attack($monster, $player); // 怪物反杀玩家目标 → attack 直接广播 the monster kills the player target → attack broadcasts directly

        $deaths = array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_dead',
        ));
        self::assertCount(2, $deaths, '单体击杀保持逐条 entity_dead / single-target kills keep per-frame entity_dead');
        self::assertSame('m-1', $deaths[0]['payload']['id']);
        self::assertSame('player-2', $deaths[1]['payload']['id']);
        self::assertSame([], array_values(array_filter(
            $broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_dead_batch',
        )), '窗口外不得出现 entity_dead_batch / no entity_dead_batch outside the window');
    }

    /**
     * 与既有单体路径并存不干扰：AoE 之后普攻仍逐目标出 combat:hit、窗口外 spawnDrops 仍逐条 drop:spawned。
     * Coexistence with existing single-target paths: after an AoE, attacks still emit per-target combat:hit and
     * single-target spawnDrops still emits per-drop drop:spawned.
     */
    public function testSingleTargetPathsCoexistWithAoePipeline(): void
    {
        [$world, $combat, $broadcaster, $lookup] = $this->buildService();
        $caster = new PlayerActor('player-1');
        $survivor = $this->spawnMonster($world, $combat, $lookup, 'm-1', 100, 5, 0);

        $combat->castSkillAoE($caster, 'fireball', new CircleShape(500, 500, 5)); // 空集 AoE Empty-set AoE.
        $combat->attack($caster, $survivor);
        $combat->spawnDrops('m-1', ['x' => 5, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);

        $types = array_map(static fn (array $frame): string => $frame['type'], $broadcaster->vision);
        self::assertContains('combat:aoe', $types);
        self::assertContains('combat:hit', $types, '单体普攻路径不受 AoE 管线影响 / the single-target attack path is unaffected by the AoE pipeline');
        self::assertContains('drop:spawned', $types, '窗口外逐条掉落路径保持原样 / the out-of-window per-drop path stays as-is');
        self::assertSame(90, $survivor->hp());
    }

    /**
     * 从视野广播记录提取 combat:aoe 的 targetIds。
     * Extracts combat:aoe's targetIds from the vision-broadcast records.
     *
     * @return list<string>
     */
    private static function targetIds(RecordingBroadcaster $broadcaster): array
    {
        foreach ($broadcaster->vision as $frame) {
            if ($frame['type'] === 'combat:aoe') {
                return $frame['payload']['targetIds'];
            }
        }

        return [];
    }

    /**
     * 组装战斗服务与支撑：World（真实引擎栈）+ 技能/物品注册表 + 记录广播器 + 确定性随机 + Actor 查找表。
     * Builds the combat service and its support: a World (real engine stack) + skill/item repositories + a recording
     * broadcaster + a deterministic random source + the actor lookup table.
     *
     * @return array{0: WorldInterface, 1: CombatService, 2: RecordingBroadcaster, 3: RecordingActorLookup}
     */
    private function buildService(): array
    {
        $world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $broadcaster = new RecordingBroadcaster();
        $lookup = new RecordingActorLookup();
        $skills = new SkillRepository();
        $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $items->register(new ItemDefinition('potion', '药水', ItemDefinition::TYPE_CONSUMABLE));

        $combat = new CombatService($world, $broadcaster, $skills, $items, new FixedRandomSource(100), $lookup);

        return [$world, $combat, $broadcaster, $lookup];
    }

    /**
     * 构造并登记一只房间内怪物：BaseEntity 空间实体入 EM/AOI，MonsterActor 入 Actor 查找表。
     * Builds and registers one monster: the BaseEntity spatial entity into EM/AOI, the MonsterActor into the actor lookup.
     */
    private function spawnMonster(WorldInterface $world, CombatService $combat, RecordingActorLookup $lookup, string $id, int $maxHp, int $x, int $y): MonsterActor
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
        );
        $monster->bindEntity($entity);
        $lookup->actors[$id] = $monster;

        return $monster;
    }
}

/**
 * ExplodingDamageable - takeDamage 即抛 RuntimeException 的战斗体 fake：模拟结算链路中途的意外异常
 * （非 Argument/Logic 类），驱动攒批窗口异常泄漏场景。
 * ExplodingDamageable - a combatant fake whose takeDamage throws a RuntimeException: simulates an unexpected
 * mid-settlement exception (non-Argument/Logic) driving the drop-batch window leak scenario.
 */
final class ExplodingDamageable implements Damageable, ActorInterface
{
    public function update(): void
    {
    }

    public function hp(): int
    {
        return 100;
    }

    public function maxHp(): int
    {
        return 100;
    }

    public function takeDamage(int $amount): void
    {
        throw new \RuntimeException('settlement exploded');
    }

    public function heal(int $amount): void
    {
    }

    public function isDead(): bool
    {
        return false;
    }
}
