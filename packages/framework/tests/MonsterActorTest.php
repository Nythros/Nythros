<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MonsterActorTest - 怪物 AI 钩子测试：巡逻感知→追击、追击→攻击、攻击对已死目标短路、死亡掉落与自清理。
 * MonsterActor AI-hook tests: patrol perception→chase, chase→attack, attack short-circuit on a dead target, death drops and self-cleanup.
 *
 * 组装策略：World 用真实 EntityManager/AOI/EventBus/Scheduler，ActorSystem 用 stub 记录 remove（供自清理断言）。
 * Assembly strategy: the World uses a real EntityManager/AOI/EventBus/Scheduler; the ActorSystem is a stub recording remove (for the self-cleanup assertions).
 */
final class MonsterActorTest extends TestCase
{
    public function testPatrolPerceivesPlayerAndEntersChase(): void
    {
        $h = $this->buildHarness();
        $this->registerPlayer($h, 'player-1', 0, 0);
        $this->registerMonster($h, $h->monster, 0, 0);

        $h->monster->update();

        self::assertSame(BaseMonster::STATE_CHASE, $h->monster->aiState());
        self::assertSame('player-1', $h->monster->targetId());
    }

    public function testPatrolMovesRandomlyWhenNoPlayerPerceived(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        // 视野内无玩家：随机移动一格（random 固定 1 → move(1,1)）
        // No player in view: one random move (random fixed at 1 → move(1,1))
        $h->monster->update();

        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
        self::assertNull($h->monster->targetId());
        self::assertSame(['x' => 1, 'y' => 1], $h->world->getEntityManager()->get('monster-1')?->getPosition());
    }

    public function testChaseTransitionsToAttackWhenTargetInRange(): void
    {
        $h = $this->buildHarness();
        $this->registerPlayer($h, 'player-1', 0, 0);
        $this->registerMonster($h, $h->monster, 0, 0);
        $h->lookup->actors['player-1'] = new PlayerActor('player-1');

        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->update();

        // 目标与怪物同格（九宫格内）：进入 ATTACK
        // The target shares the cell (inside the 3x3): enter ATTACK
        self::assertSame(BaseMonster::STATE_ATTACK, $h->monster->aiState());
    }

    public function testChaseReturnsToPatrolWhenTargetLost(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        $h->monster->setTarget('player-ghost');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->update();

        // 目标 Actor 不存在（丢失）：清目标回 PATROL
        // The target actor is gone (lost): clear the target and return to PATROL
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
        self::assertNull($h->monster->targetId());
    }

    public function testAttackShortCircuitsOnDeadTargetAndReturnsToPatrol(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        $deadPlayer = new PlayerActor('player-1');
        $deadPlayer->takeDamage(999);
        $h->lookup->actors['player-1'] = $deadPlayer;

        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_ATTACK);
        $h->monster->update();

        // reviewer 细节 1：目标 instanceof Damageable && !isDead() 前置——已死目标短路，回到 PATROL
        // Reviewer detail 1: the instanceof Damageable && !isDead() precondition — a dead target short-circuits back to PATROL
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
        self::assertNull($h->monster->targetId());
    }

    public function testPatrolMoveBroadcastsEntityMoved(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        // 视野内无玩家：随机移动一格（random 固定 1 → move(1,1)）后广播 entity_moved（含 id + 新位置）
        // No player in view: one random move (random fixed at 1 → move(1,1)) then broadcast entity_moved (id + new position)
        $h->monster->update();

        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(1, $moves, 'PATROL 随机移动必须广播一次 entity_moved。');
        self::assertSame('monster-1', $moves[0]['center']);
        self::assertSame('monster-1', $moves[0]['payload']['id']);
        self::assertSame(['x' => 1, 'y' => 1], $moves[0]['payload']['position']);
    }

    public function testPatrolZeroMoveSkipsEntityMovedBroadcast(): void
    {
        $h = $this->buildHarness(monsterRandom: 0);
        $this->registerMonster($h, $h->monster, 0, 0);

        // random 固定 0 → dx=0, dy=0：无位移，跳过 entity_moved 广播
        // random fixed at 0 → dx=0, dy=0: no displacement, so entity_moved is skipped
        $h->monster->update();

        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(0, $moves, '无位移的 PATROL 帧不应广播 entity_moved。');
        self::assertSame(['x' => 0, 'y' => 0], $h->world->getEntityManager()->get('monster-1')?->getPosition());
    }

    public function testPatrolMoveIsBoundedToSpawnAnchor(): void
    {
        $h = $this->buildHarness(monsterRandom: 1); // random 固定 1 → 试 move(1,1) Random fixed at 1 → tries move(1,1)
        // 怪物放在出生锚点外缘 (35,0)（默认 anchor (0,0), radius 30）：任何位移动会把 |x| 拉到 36 > 30 → 拒绝
        // Place the monster at the anchor's edge (35,0) (default anchor (0,0), radius 30): any move yields |x|=36 > 30 → rejected
        $this->registerMonster($h, $h->monster, 35, 0);

        $h->monster->update();

        self::assertSame(['x' => 35, 'y' => 0], $h->world->getEntityManager()->get('monster-1')?->getPosition(), '巡逻位移超出出生点半径时保持原位。Out-of-radius patrol moves are rejected (stay put).');
        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(0, $moves, '被拒绝的越界位移不应广播 entity_moved。A rejected out-of-bound patrol move must not broadcast entity_moved.');
    }

    public function testChaseMoveBroadcastsEntityMoved(): void
    {
        $h = $this->buildHarness();
        // 玩家在 (25,0)：九宫格（cellSize=10）之外，chase 走 moveTowardTarget 而非 ATTACK
        // The player at (25,0) is outside the 3x3 AOI (cellSize=10): chase takes moveTowardTarget instead of ATTACK
        $this->registerPlayer($h, 'player-1', 25, 0);
        $this->registerMonster($h, $h->monster, 0, 0);

        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->update();

        // 朝目标方向移动一格 → move(1,0) → 广播 entity_moved（含 id + 新位置）
        // Moves one cell toward the target → move(1,0) → broadcasts entity_moved (id + new position)
        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(1, $moves, 'CHASE 朝目标移动必须广播一次 entity_moved。');
        self::assertSame('monster-1', $moves[0]['center']);
        self::assertSame('monster-1', $moves[0]['payload']['id']);
        self::assertSame(['x' => 1, 'y' => 0], $moves[0]['payload']['position']);
        self::assertSame(BaseMonster::STATE_CHASE, $h->monster->aiState());
    }

    public function testChaseMoveIsBoundedToSpawnAnchor(): void
    {
        $h = $this->buildHarness();
        // 玩家在 (30,0)（cell 3）：与怪物 (10,0)（cell 1）相隔两格，视野外 → chase 尝试接近；
        // 但怪物已在锚 (0,0) 半径外缘（默认 radius 10），再进一步 x=11 > 10 越界 → 本步被拒。
        // The player at (30,0) (cell 3) is two cells from the monster at (10,0) (cell 1), outside vision → chase closes in;
        // but the monster already stands at the anchor-(0,0) radius edge (default radius 10): the next step x=11 > 10 is rejected.
        $this->registerPlayer($h, 'player-1', 30, 0);
        $this->registerMonster($h, $h->monster, 10, 0);

        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->update();

        self::assertSame(['x' => 10, 'y' => 0], $h->world->getEntityManager()->get('monster-1')?->getPosition(), '追击位移不得越出出生锚活动域。Chase moves must not leave the spawn-anchor roam domain.');
        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(0, $moves, '被拒绝的越界追击位移不应广播 entity_moved。A rejected out-of-bound chase move must not broadcast entity_moved.');
        self::assertSame(BaseMonster::STATE_CHASE, $h->monster->aiState(), '目标仍在（只是不可达）：保持 CHASE 而非回 PATROL。The target still exists (just unreachable): stay in CHASE rather than falling back to PATROL.');
    }

    public function testPatrolOutsideAnchorWalksBackHome(): void
    {
        $h = $this->buildHarness(monsterRandom: -1); // random 固定 -1 → 试 move(-1,-1) Random fixed at -1 → tries move(-1,-1)
        // 怪物滞留在锚 (0,0) 活动域外 (15,5)（默认 radius 10）：越界自愈放行「朝锚回归」的单步移动
        // （曼哈顿距离 20 → 18 严格减小），否则界外怪物连回家路径都被拒、永久滞留视野外。
        // The monster is stranded outside the anchor-(0,0) domain at (15,5) (default radius 10): the out-of-bound
        // self-heal allows the anchorward step (Manhattan distance strictly decreases 20 → 18); otherwise a stranded
        // monster could never even walk home and stays outside vision forever.
        $this->registerMonster($h, $h->monster, 15, 5);

        $h->monster->update();

        self::assertSame(['x' => 14, 'y' => 4], $h->world->getEntityManager()->get('monster-1')?->getPosition(), '界外怪物允许朝锚回归单步。An out-of-bound monster may take one anchorward step home.');
        $moves = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_moved',
        ));
        self::assertCount(1, $moves, '回归位移正常广播 entity_moved。The homeward step broadcasts entity_moved as usual.');
    }

    /**
     * R1 e2e 缺陷回归：demo 出生配置（锚 (5,5)/半径 10 与锚 (-5,0)/半径 5）下，怪物无论朝哪个方向
     * 持续随机游走都不得越出活动域，且恒保持在原点（玩家出生格）九宫格视野内——否则攻击随机
     * out_of_range、死亡/掉落广播无人可收。
     * R1 e2e-defect regression: under the demo spawn configuration (anchor (5,5)/radius 10 and anchor (-5,0)/radius 5),
     * sustained random roaming in any direction must never leave the roam domain, and the monster must remain inside the
     * origin's (player-spawn cell) 3x3 view — otherwise attacks randomly go out_of_range and death/drop broadcasts reach nobody.
     */
    public function testPatrolRoamStaysInsideOriginVisionForDemoAnchors(): void
    {
        foreach ([[5, 5, 10], [-5, 0, 5]] as [$anchorX, $anchorY, $radius]) {
            foreach ([1, -1] as $direction) {
                $h = $this->buildHarness(monsterRandom: $direction);
                $monster = new MonsterActor(
                    'monster-roam',
                    100,
                    $h->world,
                    new CombatService($h->world, $h->broadcaster, new SkillRepository(), new ItemRepository(), new FixedRandomSource(100)),
                    new DropTable([]),
                    $h->lookup,
                    $h->typeIndex,
                    new FixedRandomSource($direction),
                    $h->broadcaster,
                    patrolAnchor: ['x' => $anchorX, 'y' => $anchorY],
                    patrolRadius: $radius,
                );
                $this->registerMonster($h, $monster, $anchorX, $anchorY);

                for ($i = 0; $i < 60; $i++) {
                    $monster->update();
                    $pos = $h->world->getEntityManager()->get('monster-roam')?->getPosition();
                    self::assertNotNull($pos);
                    self::assertLessThanOrEqual($radius, abs($pos['x'] - $anchorX), "持续 {$direction} 游走不得越出锚活动域（x）。Sustained {$direction} roaming must stay inside the anchor domain (x).");
                    self::assertLessThanOrEqual($radius, abs($pos['y'] - $anchorY), "持续 {$direction} 游走不得越出锚活动域（y）。Sustained {$direction} roaming must stay inside the anchor domain (y).");
                    // 原点九宫格可见性：floor(pos/cellSize) 必须落在 {-1,0,1}（cellSize=10）。
                    // Origin 3x3 visibility: floor(pos/cellSize) must stay within {-1,0,1} (cellSize=10).
                    self::assertContains((int) floor($pos['x'] / 10), [-1, 0, 1], '怪物 x 必须恒在原点九宫格视野内。Monster x must remain inside the origin 3x3 view.');
                    self::assertContains((int) floor($pos['y'] / 10), [-1, 0, 1], '怪物 y 必须恒在原点九宫格视野内。Monster y must remain inside the origin 3x3 view.');
                }
            }
        }
    }

    public function testOnDeathSpawnsDropsAndSelfCleans(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 2, 3);
        $h->typeIndex->set('monster-1', EntityTypeIndex::KIND_MONSTER);

        $h->monster->takeDamage(100);
        self::assertTrue($h->monster->isDead());
        self::assertSame(BaseMonster::STATE_DEAD, $h->monster->aiState());

        // 掉落：dropTable roll 命中 gold，DropEntity 生成在怪物实体位置
        // Drops: the dropTable roll hits gold, spawning a DropEntity at the monster entity's position
        $drops = array_values(array_filter(
            $h->world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
        self::assertCount(1, $drops);
        self::assertSame('gold', $drops[0]->itemId);
        self::assertSame(['x' => 2, 'y' => 3], $drops[0]->getPosition());

        // 自清理：actorSystem + actorLookup($actors) + entityManager + AOI + typeIndex 五处摘除（entity_dead 由 onDeath 广播）
        // Self-cleanup: actorSystem + actorLookup($actors) + entityManager + AOI + typeIndex are all removed (entity_dead is broadcast by onDeath)
        self::assertSame([$h->monster], $h->removedActors);
        self::assertSame(['monster-1'], $h->lookup->removedActorIds);
        self::assertArrayNotHasKey('monster-1', $h->lookup->actors);
        self::assertNull($h->world->getEntityManager()->get('monster-1'));
        self::assertNull($h->typeIndex->kindOf('monster-1'));

        $probeIds = array_map(
            static fn ($entity): string => $entity->getId(),
            $h->world->getAOI()->query(new BaseEntity('probe', new Position(2, 3))),
        );
        self::assertNotContains('monster-1', $probeIds, '怪物实体应从 AOI 摘除。The monster entity must leave the AOI.');

        $deaths = array_values(array_filter(
            $h->broadcaster->vision,
            static fn (array $frame): bool => $frame['type'] === 'entity_dead',
        ));
        self::assertCount(1, $deaths);
        self::assertSame('monster-1', $deaths[0]['payload']['id']);
    }

    public function testOnDeathBindsTheKillerUidOntoDrops(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 2, 3);
        $killer = new PlayerActor('player-1');
        $killer->attachConnection('conn-1', '1001');
        $h->lookup->actors['player-1'] = $killer;

        // 经 CombatService.attack 结算至死：noteAttacker 记录最后伤害来源，死亡掉落绑定击杀者 uid
        // Settled to death via CombatService.attack: noteAttacker records the last damage source and death drops bind the killer's uid
        for ($i = 0; $i < 10; $i++) {
            $h->combat->attack($killer, $h->monster);
        }

        self::assertTrue($h->monster->isDead());
        $drops = array_values(array_filter(
            $h->world->getEntityManager()->all(),
            static fn ($entity): bool => $entity instanceof DropEntity,
        ));
        self::assertCount(1, $drops);
        self::assertSame('1001', $drops[0]->ownerUid, '死亡掉落必须绑定击杀者 uid。Death drops must bind the killer uid.');
    }

    public function testOnDeathFullyCleansEntityFromWorld(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 2, 3);
        $h->typeIndex->set('monster-1', EntityTypeIndex::KIND_MONSTER);
        $h->lookup->actors['monster-1'] = $h->monster;

        $h->monster->takeDamage(100);

        // 死亡完整清理（修复 MINOR-2）：$actors / actorSystem / entityManager / AOI / typeIndex 五处无残留。
        // Full death cleanup (fixes MINOR-2): no residue in $actors / actorSystem / entityManager / AOI / typeIndex.
        self::assertTrue($h->monster->isDead());
        self::assertSame([$h->monster], $h->removedActors, 'actorSystem 移除已登记的 MonsterActor');
        self::assertSame(['monster-1'], $h->lookup->removedActorIds, '$actors 表经 actorLookup->removeActor 摘除');
        self::assertArrayNotHasKey('monster-1', $h->lookup->actors);
        self::assertNull($h->world->getEntityManager()->get('monster-1'), 'entityManager 中怪物实体已移除');

        $probeIds = array_map(
            static fn ($entity): string => $entity->getId(),
            $h->world->getAOI()->query(new BaseEntity('probe', new Position(2, 3))),
        );
        self::assertNotContains('monster-1', $probeIds, 'AOI 索引中怪物实体已移除');

        self::assertNull($h->typeIndex->kindOf('monster-1'), 'typeIndex 中怪物种类登记已移除');
    }

    /**
     * 出生保护（R4 出生保护批）——攻击侧：保护期内怪物 ATTACK 不结算伤害、不消耗冷却；
     * 窗口倒数归零后自动恢复攻击（hp 开始下降）。
     * Spawn protection (the R4 spawn-protection batch) — attack side: while protected, the monster's ATTACK
     * settles no damage and consumes no cooldown; once the countdown zeroes, attacks resume automatically (hp drops).
     */
    public function testAttackSkipsSpawnProtectedTargetAndResumesAfterWindow(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        $player = new PlayerActor('player-1');
        $player->enableSpawnProtection();
        $h->lookup->actors['player-1'] = $player;

        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_ATTACK);

        // 保护期内：连续两帧 update，无伤害结算且攻击冷却未被置位（第三帧仍可立即结算）
        // While protected: two update frames settle nothing and never start the attack cooldown (a third frame could settle immediately)
        $h->monster->update();
        $h->monster->update();
        self::assertSame(100, $player->hp(), '保护期内不结算伤害 no damage settles while protected');
        self::assertSame(BaseMonster::STATE_ATTACK, $h->monster->aiState(), '保持 ATTACK 态等待窗口结束 stays in ATTACK until the window ends');

        // 窗口结束：保护倒数 60 帧走 PlayerActor::update 归零后，怪物恢复结算
        // After the window: the player's protection counts down to zero via PlayerActor::update and the monster resumes settling
        for ($i = 0; $i < PlayerActor::SPAWN_PROTECTION_FRAMES; $i++) {
            $player->update();
        }
        self::assertFalse($player->isSpawnProtected());
        $h->monster->update();
        self::assertSame(90, $player->hp(), '窗口结束后攻击恢复 attacks resume after the window');
    }

    /**
     * 出生保护（R4 出生保护批）——感知侧：保护期内玩家对巡逻感知不可见（不进 CHASE、无目标）；
     * 窗口结束后恢复感知进入 CHASE。保护语义是「出生不被打扰」而非仅「免伤」。
     * Spawn protection (the R4 spawn-protection batch) — perception side: a protected player is invisible to
     * patrol perception (no CHASE, no target); perception resumes into CHASE after the window. Protection means
     * "undisturbed spawn", not merely "damage immunity".
     */
    public function testPatrolDoesNotPerceiveSpawnProtectedPlayerUntilWindowEnds(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        $player = new PlayerActor('player-1');
        $player->enableSpawnProtection();
        $this->registerPlayerActor($h, 'player-1', $player, 0, 0);

        $h->monster->update();
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState(), '保护期内不被感知 never perceived while protected');
        self::assertNull($h->monster->targetId());

        for ($i = 0; $i < PlayerActor::SPAWN_PROTECTION_FRAMES; $i++) {
            $player->update();
        }
        $h->monster->update();
        self::assertSame(BaseMonster::STATE_CHASE, $h->monster->aiState(), '窗口结束后恢复感知 perception resumes after the window');
        self::assertSame('player-1', $h->monster->targetId());
    }

    /**
     * 目标离场钩子（R4 CHASE 卡滞修复）：CHASE/ATTACK 怪物在目标离场时清目标回 PATROL；
     * 非当前目标幂等无操作；PATROL 态不受影响。
     * Target-left hook (the R4 CHASE-stall fix): a CHASE/ATTACK monster clears its target back to PATROL when the
     * target leaves; other targets are an idempotent no-op; PATROL is untouched.
     */
    public function testOnTargetLeftAbandonsChaseAndAttack(): void
    {
        $h = $this->buildHarness();
        $this->registerMonster($h, $h->monster, 0, 0);

        // CHASE → 目标离场 → PATROL + 清目标
        // CHASE → target left → PATROL with the target cleared
        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->onTargetLeft('player-1');
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
        self::assertNull($h->monster->targetId());

        // ATTACK → 目标离场 → PATROL（防跨容器继续结算伤害）
        // ATTACK → target left → PATROL (prevents cross-container damage settlement)
        $h->monster->setTarget('player-1');
        $h->monster->enterState(BaseMonster::STATE_ATTACK);
        $h->monster->onTargetLeft('player-1');
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
        self::assertNull($h->monster->targetId());

        // 非当前目标：状态与目标均不变
        // A different entity: neither state nor target changes
        $h->monster->setTarget('player-2');
        $h->monster->enterState(BaseMonster::STATE_CHASE);
        $h->monster->onTargetLeft('player-1');
        self::assertSame(BaseMonster::STATE_CHASE, $h->monster->aiState());
        self::assertSame('player-2', $h->monster->targetId());

        // PATROL 态收到通知：保持 PATROL
        // A notice while in PATROL: stays PATROL
        $h->monster->onTargetLeft('player-2');
        self::assertSame(BaseMonster::STATE_PATROL, $h->monster->aiState());
    }

    /**
     * 组装测试线束：真实 World（stub ActorSystem 记录 remove）+ 战斗服务 + 掉落表（gold）+ 记录 fake。
     * Builds the test harness: a real World (with a stub ActorSystem recording remove) + combat service + drop table (gold) + recording fakes.
     *
     * @param int $monsterRandom 怪物随机源固定值（巡逻移动方向与掉落 roll；默认 1 = 每次 move(1,1)） Fixed value of the monster random source (patrol direction and drop rolls; 1 = always move(1,1)).
     *
     * @return object{world: WorldInterface, monster: MonsterActor, combat: CombatService, broadcaster: RecordingBroadcaster, lookup: RecordingActorLookup, typeIndex: EntityTypeIndex, removedActors: list<MonsterActor>}
     */
    private function buildHarness(int $monsterRandom = 1): object
    {
        $removedActors = [];
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('remove')->willReturnCallback(static function ($actor) use (&$removedActors): void {
            $removedActors[] = $actor;
        });
        $world = new World(new SimpleEntityManager(), $actorSystem, new GridAOI(10), new SimpleEventBus(), new RegionScheduler());

        $broadcaster = new RecordingBroadcaster();
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $combat = new CombatService($world, $broadcaster, $skills, $items, new FixedRandomSource(100));

        $typeIndex = new EntityTypeIndex();
        $lookup = new RecordingActorLookup();
        $monster = new MonsterActor(
            'monster-1',
            100,
            $world,
            $combat,
            new DropTable(['gold' => 1]),
            $lookup,
            $typeIndex,
            new FixedRandomSource($monsterRandom),
            $broadcaster,
        );

        return (object) [
            'world' => $world,
            'monster' => $monster,
            'combat' => $combat,
            'broadcaster' => $broadcaster,
            'lookup' => $lookup,
            'typeIndex' => $typeIndex,
            'removedActors' => &$removedActors,
        ];
    }

    /**
     * 登记一个玩家实体（BaseEntity + AOI + typeIndex），并记录其 PlayerActor 到查找表。
     * Registers a player entity (BaseEntity + AOI + typeIndex) and records its PlayerActor into the lookup table.
     */
    private function registerPlayer(object $h, string $entityId, int $x, int $y): void
    {
        $this->registerPlayerActor($h, $entityId, new PlayerActor($entityId), $x, $y);
    }

    /**
     * 登记一个给定 Actor 的玩家实体（出生保护测试注入受保护 Actor 用）。
     * Registers a player entity with the given actor (lets spawn-protection tests inject a protected actor).
     */
    private function registerPlayerActor(object $h, string $entityId, PlayerActor $actor, int $x, int $y): void
    {
        $entity = new BaseEntity($entityId, new Position($x, $y));
        $h->world->getEntityManager()->add($entity);
        $h->world->getAOI()->updateEntity($entity);
        $h->typeIndex->set($entityId, EntityTypeIndex::KIND_PLAYER);
        $h->lookup->actors[$entityId] = $actor;
    }

    /**
     * 给怪物绑定实体并登记进 world（AOI 感知依赖绑定实体）。
     * Binds the monster's entity and registers it into the world (AOI perception relies on the bound entity).
     */
    private function registerMonster(object $h, MonsterActor $monster, int $x, int $y): void
    {
        $entity = new BaseEntity($monster->monsterId(), new Position($x, $y));
        $monster->bindEntity($entity);
        $h->world->getEntityManager()->add($entity);
        $h->world->getAOI()->updateEntity($entity);
    }
}
