<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\MapServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Mmorpg\HotCellPolicy;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Game\Mmorpg\Respawner;
use Nythros\Framework\Game\Mmorpg\ThreatTable;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Tests\FakeServiceRegistry;
use Nythros\Framework\Tests\FakeTokenManager;
use Nythros\Framework\Tests\FixedRandomSource;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenRecord;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerMmorpgTest - mmorpg 世界侧接线测试（R4 试点，NYTHROS_MMORPG 开关）：
 * ① 开关关闭时 spawnMonster 不注入威胁表（行为不变回归——缺省路径零改动）；
 * ② 开关开启后威胁切换（受击方记录攻击者威胁，aggro 选择最高威胁者切换目标）；
 * ③ 开关开启后重生（怪物死亡登记 → respawnMs 后重生回锚点）。
 * MapServerMmorpgTest - the mmorpg world-side wiring tests (the R4 pilot, the NYTHROS_MMORPG switch):
 * ① with the switch off spawnMonster injects no threat table (the unchanged-behavior regression — zero change on
 * the default path); ② with it on, threat switching (the hit side records the attacker's threat and aggro picks
 * the highest threat to switch targets); ③ with it on, respawn (a monster death registers → respawns back to the
 * anchor after respawnMs).
 */
final class MapServerMmorpgTest extends TestCase
{
    public function testWithoutMmorpgMonsterHasNoThreatTable(): void
    {
        $h = $this->buildHarness(null);
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();

        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertNull($threatTable, 'mmorpg 关闭时怪物无威胁表（行为不变回归） without mmorpg the monster carries no threat table (the unchanged-behavior regression)');
    }

    public function testThreatSwitchToHighestThreat(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();

        // 1001 攻击一次（威胁 10）；1002 攻击三次（威胁 30），中间驱动冷却递减
        // 1001 attacks once (threat 10); 1002 attacks three times (threat 30), driving cooldown decay in between
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        $h->send($h->connB, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 5; $i++) {
            $h->flush();
        }
        $h->send($h->connB, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 5; $i++) {
            $h->flush();
        }
        $h->send($h->connB, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        // 受击方威胁表记录：1001=10、1002=30（真实攻击路径驱动）
        // The hit side's threat table records: 1001=10, 1002=30 (driven by the real attack path)
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertSame(10.0, $threatTable->threatOf('1001@conn-a'));
        self::assertSame(30.0, $threatTable->threatOf('1002@conn-b'));

        // 驱动怪物 AI：感知 → CHASE → ATTACK → aggro 切换目标到最高威胁者 1002
        // Drive the monster AI: perceive → CHASE → ATTACK → aggro switches to the highest threat 1002
        for ($i = 0; $i < 10; $i++) {
            $h->flush();
        }
        $targetId = (new \ReflectionProperty(BaseMonster::class, 'targetId'))->getValue($monster);
        self::assertSame('1002@conn-b', $targetId, 'aggro 选择最高威胁者切换目标 aggro picks the highest threat and switches the target');
    }

    public function testTauntSkillSwitchesAggroToTaunter(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)
        $h->spawnMonster('monster-1', 100, 0, 0);
        // 注册嘲讽技能（P4b 接入，关闭 P1 预留）：tauntThreat 1000 × tauntMultiplier 1.0 = 1000，
        // 远超伤害威胁（BaseMonster 10/击）——确定性切换
        // Register the taunt skill (the P4b wiring, closing the P1 reservation): tauntThreat 1000 ×
        // tauntMultiplier 1.0 = 1000, far above the damage threat (10 per BaseMonster hit) — a deterministic switch.
        $h->skills->register(new SkillDefinition('taunt', '嘲讽', 0.6, 6.0, 3, tauntThreat: 1000.0));
        $h->flush();

        // 1001 攻击两次（威胁 20）；1002 攻击一次（威胁 10）——当前最高威胁是 1001
        // 1001 attacks twice (threat 20); 1002 attacks once (threat 10) — 1001 currently holds the highest threat.
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 5; $i++) {
            $h->flush();
        }
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 5; $i++) {
            $h->flush();
        }
        $h->send($h->connB, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        // 攻击冷却耗尽（resolveCombatant 在 isAttackReady 为假时拒绝施法）
        // Let the attack cooldown expire (resolveCombatant rejects the cast while isAttackReady is false).
        for ($i = 0; $i < 10; $i++) {
            $h->flush();
        }

        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertGreaterThan($threatTable->threatOf('1002@conn-b'), $threatTable->threatOf('1001@conn-a'), '嘲讽前 1001 威胁最高 before the taunt 1001 holds the highest threat');

        // 1002 施放嘲讽：伤害 6 记威胁 6，applyTaunt 把威胁抬到 max(6, 1000)=1000 → aggro 切换目标到 1002
        // 1002 casts the taunt: the 6 damage records threat 6, applyTaunt raises it to max(6, 1000)=1000 → aggro
        // switches to 1002.
        $h->send($h->connB, 'skill:cast', ['skillId' => 'taunt', 'targetId' => 'monster-1']);
        $h->flush();
        self::assertSame(1000.0, $threatTable->threatOf('1002@conn-b'), '嘲讽威胁量写入目标威胁表（tauntMultiplier 裁决） the taunt amount lands in the target threat table (the tauntMultiplier adjudication)');

        for ($i = 0; $i < 15; $i++) {
            $h->flush();
        }
        $targetId = (new \ReflectionProperty(BaseMonster::class, 'targetId'))->getValue($monster);
        self::assertSame('1002@conn-b', $targetId, '嘲讽后 aggro 切换到嘲讽者 after the taunt, aggro switches to the taunter');
    }

    public function testMonsterReleasesDeadChaseTarget(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->skills->register(new SkillDefinition('taunt', '嘲讽', 0.6, 6.0, 3, tauntThreat: 1000.0));
        $h->flush();

        // 1001 攻击一次（威胁 10）→ 怪物 aggro 目标 1001
        // 1001 attacks once (threat 10) → the monster's aggro targets 1001.
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 15; $i++) {
            $h->flush();
        }
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $targetId = (new \ReflectionProperty(BaseMonster::class, 'targetId'))->getValue($monster);
        self::assertSame('1001@conn-a', $targetId, '怪物追击 1001（唯一威胁者） the monster chases 1001 (the only threat source)');

        // 1001 远移 + 击杀：实体留 (100,100)（Actor 持久——死亡仅 awaitingRevive 标记）。P4b 修复前怪物
        // 会永远追着尸体（目标 Actor 可解析 → 不判丢失 → 被巡逻边界卡死）；修复后首个 CHASE tick 即释放。
        // 1001 teleports far away and dies: the entity stays at (100,100) (the actor persists — death only sets the
        // awaitingRevive marker). Before the P4b fix the monster would chase the corpse forever (the target actor
        // stays resolvable → never judged lost → frozen against the patrol boundary); after the fix the first CHASE
        // tick releases it.
        $h->world->getEntityManager()->get('1001@conn-a')->setPosition(100, 100);
        $h->mapServer->getActor('1001@conn-a')->takeDamage(100);
        $h->flush();
        for ($i = 0; $i < 15; $i++) {
            $h->flush();
        }

        // 1002 移到怪物旁（(2,2) 巡逻域边界角、距怪物 2.8 ≤ taunt range 3，P7a 距离门内）施放嘲讽
        // （威胁 1000）→ 下一个攻击 tick 的 aggro 切换（死目标已释放）把目标切到 1002
        // 1002 moves next to the monster (the (2,2) patrol-boundary corner, 2.8 from the monster — inside the
        // P7a taunt range 3) and casts the taunt (threat 1000) → the next attack tick's aggro switch (the dead
        // target already released) retargets 1002.
        $h->world->getEntityManager()->get('1002@conn-b')->setPosition(2, 2);
        $h->send($h->connB, 'skill:cast', ['skillId' => 'taunt', 'targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 20; $i++) {
            $h->flush();
        }
        $targetId = (new \ReflectionProperty(BaseMonster::class, 'targetId'))->getValue($monster);
        self::assertSame('1002@conn-b', $targetId, '死目标释放后 aggro 切换到嘲讽者 1002（P4b 修复回归） after the dead target releases, aggro switches to the taunter 1002 (the P4b regression)');
    }

    public function testPlayerReviveRestoresHpAndTeleportsToSpawn(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->flush();

        $actor = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $actor);

        // 未死复活 → not_ready（幂等拒绝，不重复回血）。sendToEntity 走帧末批量（batchedA），
        // 每项是批量编码串（JSON 数组包一帧）——先按批展平再取末帧。
        // A revive while alive → not_ready (idempotent rejection, no repeated healing). sendToEntity rides the
        // frame-end batch (batchedA), each entry being an encoded batch (a JSON array wrapping one frame) — flatten
        // per batch first, then take the last frame.
        $h->send($h->connA, 'player:revive', []);
        $h->flush();
        $frames = [];
        foreach ($h->batchedA as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                $frames[] = $f;
            }
        }
        $last = $frames[array_key_last($frames)] ?? [];
        self::assertSame('player:revive', $last['type'] ?? null, '未死复活应回执 not_ready（末帧=' . json_encode($last, JSON_UNESCAPED_UNICODE) . '）');
        self::assertSame('not_ready', $last['payload']['code'] ?? null);

        // 击杀 → 待复活标记 + hp 0；远移实体验证复活传送回出生点
        // Kill → the awaiting-revive marker + hp 0; teleport the entity away to verify the revive returns it to the spawn.
        $actor->takeDamage(100);
        $h->flush();
        self::assertTrue($actor->isAwaitingRevive(), '死亡置待复活标记');
        self::assertSame(0, $actor->hp());
        $h->world->getEntityManager()->get('1001@conn-a')->setPosition(50, 50);

        $h->send($h->connA, 'player:revive', []);
        $h->flush();

        self::assertFalse($actor->isAwaitingRevive(), '复活清除待复活标记');
        self::assertSame($actor->maxHp(), $actor->hp(), '复活满血（合成上限）');
        self::assertSame(['x' => 0, 'y' => 0], $h->world->getEntityManager()->get('1001@conn-a')->getPosition(), '复活传送回出生点');

        // 回执帧断言：player:revive ok + 落点 (0,0)（player:stats 满血帧由 onDamaged 路径之外直发；均走帧末批量）
        // Receipt-frame assertions: player:revive ok + the landing (0,0) (the full-hp player:stats frame is sent directly, outside the onDamaged path; both ride the frame-end batch).
        $revive = null;
        foreach ($h->batchedA as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) === 'player:revive' && ($f['payload']['code'] ?? null) === 'ok') {
                    $revive = $f;
                }
            }
        }
        self::assertNotNull($revive, '复活回执 player:revive ok');
        self::assertSame(['x' => 0, 'y' => 0], $revive['payload']['position'] ?? null);
    }

    /**
     * P6a 自动复活：playerRespawnMs > 0 时玩家死亡入队（combat.kill 埋点消费），到期世界 tick 服务端
     * 直接复活——满血回生 + 清标记 + 落点出生点 + 主动 player:revive ok 回执（客户端未发送 revive）。
     * The P6a auto-revive: with playerRespawnMs > 0 a player death joins the queue (consumed from the combat.kill
     * instrumentation), and the due world tick revives server-side — full restore + the marker cleared + landing on
     * the spawn + a proactive player:revive ok receipt (the client never sent revive).
     */
    public function testAutoReviveAfterRespawnDelay(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(playerRespawnMs: 1000));
        $h->authPlayer();
        $h->flush();

        $actor = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $actor);

        // 击杀 → combat.kill 埋点 → 玩家死亡登记自动复活队列（与怪物重生同一埋点，按 victim 分流）
        // Kill → the combat.kill instrumentation → the player death joins the auto-revive queue (the same
        // instrumentation as the monster respawn, demuxed by the victim).
        $actor->takeDamage(100);
        $h->combatEvents->dispatch(CombatService::EVENT_KILL, ['killerUid' => null, 'victimId' => '1001@conn-a', 'monsterId' => null, 'monsterTypeId' => null]);
        $h->flush();
        self::assertTrue($actor->isAwaitingRevive(), '死亡置待复活标记');

        $respawner = (new \ReflectionProperty(MapServer::class, 'playerRespawner'))->getValue($h->mapServer);
        self::assertInstanceOf(Respawner::class, $respawner, 'playerRespawnMs > 0 创建玩家复活调度器');
        $queue = (new \ReflectionProperty(Respawner::class, 'queue'))->getValue($respawner);
        foreach ($queue as $playerId => $at) {
            $queue[$playerId] = microtime(true) - 1.0;
        }
        (new \ReflectionProperty(Respawner::class, 'queue'))->setValue($respawner, $queue);

        // 世界 tick 消费到期登记 → 服务端自动复活（客户端未发送 player:revive）
        // The world tick consumes the due registration → the server-side auto-revive (the client never sent player:revive).
        $h->flush();

        self::assertFalse($actor->isAwaitingRevive(), '自动复活清除待复活标记');
        self::assertSame($actor->maxHp(), $actor->hp(), '自动复活满血回生');
        self::assertSame(['x' => 0, 'y' => 0], $h->world->getEntityManager()->get('1001@conn-a')->getPosition(), '自动复活落点出生点');
        $revive = null;
        foreach ($h->batchedA as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) === 'player:revive' && ($f['payload']['code'] ?? null) === 'ok') {
                    $revive = $f;
                }
            }
        }
        self::assertNotNull($revive, '自动复活主动下发 player:revive ok（客户端未请求）');
        self::assertSame(['x' => 0, 'y' => 0], $revive['payload']['position'] ?? null);
    }

    /**
     * P6b 即时视野差分：复活传送跨 AOI 单元时 enter/leave 立即补发（不等 World::update 下一帧）——
     * 进入方向：新邻居收到 entity_enter{me}、我收到新邻居 entity_enter；离开方向：旧邻居收到
     * entity_leave{me}、我收到旧邻居 entity_leave。
     * The P6b immediate vision diff: a revive teleport crossing AOI cells backfills enter/leave right away (not
     * waiting for the next World::update frame) — the enter direction: a new neighbor gets entity_enter{me} while
     * I get its entity_enter; the leave direction: a departed neighbor gets entity_leave{me} while I get its
     * entity_leave.
     */
    public function testReviveTeleportEmitsImmediateVisionDiff(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer();  // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)
        $h->flush();
        $actorA = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $actorA);
        $entityA = $h->world->getEntityManager()->get('1001@conn-a');
        $entityB = $h->world->getEntityManager()->get('1002@conn-b');
        self::assertNotNull($entityA);
        self::assertNotNull($entityB);

        // 进入方向：1001 死亡 → 尸体远移 (50,50)（世界 tick 刷新索引至 cell(5,5)）→ 复活传送回 (0,0)——
        // 1002（cell(0,0)）是新邻居：双向 entity_enter 立即入箱
        // The enter direction: 1001 dies → the corpse moves far to (50,50) (the world tick refreshes the index to
        // cell(5,5)) → the revive teleports back to (0,0) — 1002 (cell(0,0)) is a new neighbor: the paired
        // entity_enter frames land immediately.
        $actorA->takeDamage(100);
        $h->flush();
        $entityA->move(50, 50);
        $h->flush();

        $h->send($h->connA, 'player:revive', []);
        $h->flush();

        self::assertTrue(self::hasFrame($h->batchedA, 'entity_enter', static fn (array $p): bool => ($p['id'] ?? null) === '1002@conn-b'), '复活者立即收到新邻居 entity_enter（1002）');
        self::assertTrue(self::hasFrame($h->batchedB, 'entity_enter', static fn (array $p): bool => ($p['id'] ?? null) === '1001@conn-a'), '新邻居立即收到复活者 entity_enter（1001）');

        // 离开方向：1002 移到尸体域 (45,50)（旧视野邻居）→ 1001 再死再复活传送回出生点——
        // 1002 不在新视野：双向 entity_leave 立即入箱
        // The leave direction: 1002 moves into the corpse's domain (45,50) (an old-view neighbor) → 1001 dies and
        // revives back to the spawn — 1002 is outside the new view: the paired entity_leave frames land immediately.
        $entityB->move(45, 50);
        $h->flush();
        $actorA->takeDamage(100);
        $entityA->move(50, 50);
        $h->flush();

        $h->send($h->connA, 'player:revive', []);
        $h->flush();

        self::assertTrue(self::hasFrame($h->batchedA, 'entity_leave', static fn (array $p): bool => ($p['id'] ?? null) === '1002@conn-b'), '复活者立即收到离开邻居 entity_leave（1002）');
        self::assertTrue(self::hasFrame($h->batchedB, 'entity_leave', static fn (array $p): bool => ($p['id'] ?? null) === '1001@conn-a'), '离开邻居立即收到复活者 entity_leave（1001）');
    }

    /** P6c AoE 施法距离门：形状中心距施法者超 definition->range → out_of_range 拒绝（无副作用）。 The P6c AoE cast-distance gate: a shape center beyond definition->range from the caster → an out_of_range rejection (side-effect-free). */
    public function testAoECastBeyondRangeRejected(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->skills->register(new SkillDefinition('taunt_aoe', '嘲讽风暴', 0.3, 8.0, 3, ['shape' => SkillDefinition::SHAPE_CIRCLE, 'radius' => 10], tauntThreat: 1000.0));
        $h->flush();

        // 施法者 (0,0)，形状中心 (50,0)——距离 50 > range 3 → out_of_range
        // The caster at (0,0), the shape center at (50,0) — distance 50 > range 3 → out_of_range.
        $h->send($h->connA, 'skill:cast_aoe', ['skillId' => 'taunt_aoe', 'cx' => 50, 'cy' => 0, 'r' => 10]);
        $h->flush();

        $rejected = false;
        foreach ($h->batchedA as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) === 'combat:error' && ($f['payload']['code'] ?? null) === 'out_of_range') {
                    $rejected = true;
                }
            }
        }
        self::assertTrue($rejected, '形状中心超技能 range 的 AoE 施法被 out_of_range 拒绝');
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertSame(0.0, $threatTable->threatOf('1001@conn-a'), '拒绝无副作用：威胁表未写入');
    }

    /** 按批展平连接收到的帧并匹配类型/负载。 Flattens the batches a connection received and matches by type/payload. */
    private static function hasFrame(array $batches, string $type, ?callable $pred = null): bool
    {
        foreach ($batches as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) !== $type) {
                    continue;
                }
                if ($pred === null || $pred($f['payload'] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * P9a 区域降频接线：热区策略启用时，tickMmorpg 按格子密度给实体指派分频——同格聚集触发分频、
     * 冷格恒逐帧；玩家冷却门控（降档下攻击率降载）而出生保护不门控（安全窗口不延长）。
     * The P9a region-downgrade wiring: with a hot-cell policy on, tickMmorpg assigns divisors by cell
     * density — same-cell clustering triggers the tier while cold cells stay per-frame; the player's
     * cooldown is gated (attack rate sheds on a downgrade) while spawn protection is not (the safety
     * window never stretches).
     */
    public function testHotCellAssignsDivisorsAndGatesCooldown(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(hotCell: new HotCellPolicy(tiers: [['untilPlayers' => 1, 'divisor' => 1], ['untilPlayers' => 0, 'divisor' => 4]], hysteresisSeconds: 5)));
        $h->authPlayer();  // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)——同格 2 人 → 兜底档 divisor 4
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();

        $player = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $player);
        self::assertSame(4, $player->tickDivisor(), '同格聚集（密度 2 > 1）→ 热区分频 4');
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        self::assertSame(4, $monster->tickDivisor(), '热区内怪物同档分频');

        // 冷却门控：攻击置 5 帧冷却，分频 4 下两帧内不递减（非到期帧跳过）
        // The cooldown gate: an attack sets a 5-frame cooldown; at divisor 4 two frames don't decrement it
        // (non-due frames skip).
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        self::assertSame(5, $player->attackCooldown(), '分频下非到期帧冷却不递减（降档降攻击率）');

        // 出生保护不门控：保护已耗尽路径与 divisor 无关——auth 满窗后置 1 帧验证递减仍在走
        // Spawn protection is never gated: the exhaust path is divisor-independent — assert the countdown
        // still advances (auth protection was already consumed by earlier flushes here; drive one more).
        self::assertFalse($player->isSpawnProtected(), '多帧驱动后保护已耗尽（保护不随分频延长）');
    }

    /**
     * P9b 移动广播节流与速率帧：热区分频下玩家移动广播只在到期 tick 发（O(N²) 聚团流量主砍口），
     * 分频变化即下发 world:tick_rate（客户端插值窗口依据）。
     * The P9b move-broadcast throttle and rate frame: under hot-cell cadence a player's move broadcasts go
     * out only on due ticks (the main cut for O(N²) clustering traffic), and a divisor change immediately
     * sends world:tick_rate (the client's interpolation-window signal).
     */
    public function testHotCellThrottlesMoveBroadcastAndSendsRateFrame(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(hotCell: new HotCellPolicy(tiers: [['untilPlayers' => 1, 'divisor' => 1], ['untilPlayers' => 0, 'divisor' => 4]], hysteresisSeconds: 5)));
        $h->authPlayer();  // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)——同格 2 人 → divisor 4
        $h->flush();

        // 速率帧：分频 1→4 变化即下发（A 与 B 各一份）
        // The rate frame: the 1→4 divisor change sends one to each of A and B.
        self::assertTrue(self::hasFrame($h->batchedA, 'world:tick_rate', static fn (array $p): bool => ($p['divisor'] ?? null) === 4), 'A 收到 world:tick_rate divisor=4');
        self::assertTrue(self::hasFrame($h->batchedB, 'world:tick_rate', static fn (array $p): bool => ($p['divisor'] ?? null) === 4), 'B 收到 world:tick_rate divisor=4');

        // 节流：A 连续移动 4 次（每 flush 2 个 base tick；auth 已耗 2 tick，4 次移动落在 tick 3..10，
        // 到期 tick 为 4 与 8）——仅到期 tick 广播（4 次移动 → 2 次广播，节流一半），负载为到期时最新位置
        // Throttle: A moves 4 times in a row (each flush = 2 base ticks; auth consumed 2, so the moves land
        // on ticks 3..10 with due ticks 4 and 8) — only due ticks broadcast (4 moves → 2 broadcasts, half
        // throttled), carrying the position as of each due tick.
        $h->batchedB = [];
        $movedFrames = 0;
        for ($i = 1; $i <= 4; $i++) {
            $h->send($h->connA, 'move', ['dx' => 1, 'dy' => 0]);
            $h->flush();
        }
        foreach ($h->batchedB as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) === 'entity_moved' && ($f['payload']['id'] ?? null) === '1001@conn-a') {
                    $movedFrames++;
                }
            }
        }
        self::assertSame(2, $movedFrames, '4 次移动仅到期 tick 广播 2 次（其余帧跳发）');
        $entity = $h->world->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($entity);
        self::assertSame(['x' => 4, 'y' => 0], $entity->getPosition(), '位置照常应用（节流只砍广播不砍状态）');
    }

    /**
     * P8a 安全区/出生点同源校验：safeZone 圆心与 spawnPoint 偏离 → attachMmorpg 抛 LogicException（装配期 fail-fast）。
     * The P8a safe-zone/spawn-point alignment check: a safeZone center off the spawnPoint → attachMmorpg throws
     * a LogicException (assembly-time fail-fast).
     */
    public function testSafeZoneCenterMismatchFailsFast(): void
    {
        // 先装配不带安全区的 harness（错配配置在 attachMmorpg 内即抛——buildHarness 阶段就需规避）
        // Assemble the harness without a safe zone first (the mismatched config throws inside attachMmorpg —
        // the buildHarness stage must avoid it).
        $h = $this->buildHarness(null, ['x' => 3, 'y' => 0]);

        $this->expectException(\LogicException::class);
        $h->mapServer->attachMmorpg(new MmorpgConfig(safeZone: ['x' => 3, 'y' => 3, 'radius' => 5]), $h->combatEvents);
    }

    /**
     * P8b AoE 矩形形状：aoe.shape='rect' 消费 width/height，payload cx/cy 为几何中心——形状内怪物受击、
     * 形状外怪物不受影响（命中判定归引擎 queryShape，与圆形同路径）。
     * The P8b rect AoE shape: aoe.shape='rect' consumes width/height with payload cx/cy as the geometric center —
     * monsters inside the shape take damage while outside ones are untouched (hit judgment belongs to the engine's
     * queryShape, the same path as circles).
     */
    public function testAoERectShapeConsumesDeclaration(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer();
        // 矩形 6×4 几何中心 (0,0)：x∈[-3,2]、y∈[-2,1]（anchor = center - half，整除向下）
        // The 6x4 rect centered at (0,0): x in [-3,2], y in [-2,1] (anchor = center - half, floor-divided).
        $inside = $h->spawnMonsterReturning('monster-1', 100, 0, 0);
        $corner = $h->spawnMonsterReturning('monster-2', 100, -3, -2); // 角点（含边） corner (edges included)
        $outside = $h->spawnMonsterReturning('monster-3', 100, 5, 0);
        $h->skills->register(new SkillDefinition('slash_rect', '矩形斩击', 1.0, 5.0, 6, ['shape' => SkillDefinition::SHAPE_RECT, 'width' => 6, 'height' => 4]));
        $h->flush();

        $h->send($h->connA, 'skill:cast_aoe', ['skillId' => 'slash_rect', 'cx' => 0, 'cy' => 0, 'w' => 6, 'h' => 4]);
        $h->flush();

        self::assertLessThan(100, $inside->hp(), '形状内怪物受击');
        self::assertLessThan(100, $corner->hp(), '矩形含边：角点怪物受击');
        self::assertSame(100, $outside->hp(), '形状外怪物不受影响');
    }

    /**
     * P8c AI 攻击距离参数化：attackRange > 0 时在视野命中之上叠加欧氏距离上限；0 = 视野口径不变。
     * The P8c AI attack-range parameterization: with attackRange > 0 a Euclidean cap stacks on the view hit;
     * 0 keeps the view convention unchanged.
     */
    public function testAttackRangeCapsAiTargeting(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(attackRange: 3));
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $entity = $h->world->getEntityManager()->get('1001@conn-a');
        self::assertNotNull($entity);

        $inRange = \Closure::bind(static fn (MonsterActor $m): bool => $m->isTargetInRange('1001@conn-a'), null, MonsterActor::class);

        // (2,0)：视野内且距 2 ≤ 3 → 命中（不 flush：驱动世界 tick 会让怪物逼近进入射程，污染判定）
        // (2,0): inside the view and 2 ≤ 3 → a hit (no flush: driving world ticks would let the monster close
        // into range, polluting the judgment).
        $entity->setPosition(2, 0);
        self::assertTrue($inRange($monster), '攻击距离内（2 ≤ 3）视野命中即命中');

        // (5,0)：视野内（AOI 索引未刷新，仍同格）但距 5 > 3 → 距离门拦截
        // (5,0): inside the view (the AOI index is unrefreshed, still the same cell) yet 5 > 3 → the distance
        // gate blocks it.
        $entity->setPosition(5, 0);
        self::assertFalse($inRange($monster), '超攻击距离（5 > 3）被距离门拦截');

        // 对照：attackRange = 0（缺省口径，独立 harness）→ 视野命中即命中（readonly 参数不可反射改写）
        // Control: attackRange = 0 (the default convention, a separate harness) → a view hit is a hit (readonly
        // parameters resist reflection writes).
        $h0 = $this->buildHarness(new MmorpgConfig());
        $h0->authPlayer();
        $h0->spawnMonster('monster-1', 100, 0, 0);
        $monster0 = $h0->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster0);
        $inRange0 = \Closure::bind(static fn (MonsterActor $m): bool => $m->isTargetInRange('1001@conn-a'), null, MonsterActor::class);
        self::assertTrue($inRange0($monster0), '缺省口径（0）下视野命中即命中（距离 5 不拦截）');
    }

    /**
     * P7a 单体技能距离门：施法者到目标超 definition->range → out_of_range 拒绝（无副作用：威胁表零写入）。
     * The P7a single-target skill-distance gate: a caster beyond the target's definition->range → an
     * out_of_range rejection (side-effect-free: zero threat-table writes).
     */
    public function testSingleSkillCastBeyondRangeRejected(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 5, 0); // (5,0)：视野内（单体路径可解析），但超 range 3
        $h->skills->register(new SkillDefinition('taunt', '嘲讽', 0.6, 6.0, 3, tauntThreat: 1000.0));
        $h->flush();

        $h->send($h->connA, 'skill:cast', ['skillId' => 'taunt', 'targetId' => 'monster-1']);
        $h->flush();

        self::assertTrue(self::hasFrame($h->batchedA, 'combat:error', static fn (array $p): bool => ($p['code'] ?? null) === 'out_of_range'), '施法者距目标超 range 的单体施法被 out_of_range 拒绝');
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertSame(0.0, $threatTable->threatOf('1001@conn-a'), '拒绝无副作用：威胁表未写入');
    }

    /**
     * P7c 出生安全区：区内玩家对怪物 AI 不可见——巡逻感知不到、普攻不记威胁、嘲讽不写入；
     * 出区后威胁恢复记录（对照）。
     * The P7c spawn safe zone: players inside are invisible to monster AI — patrol perception finds nothing,
     * basic attacks record no threat, taunts write nothing; outside the zone threat recording resumes (control).
     */
    public function testSafeZoneShieldsPlayersFromMonsterAi(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(safeZone: ['x' => 0, 'y' => 0, 'radius' => 5]));
        $h->authPlayer(); // 1001@conn-a at (0,0)：区内
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->skills->register(new SkillDefinition('taunt', '嘲讽', 0.6, 6.0, 3, tauntThreat: 1000.0));
        $h->flush();

        // 感知门：区内玩家不可感知——巡逻态不建目标
        // The perception gate: players inside are imperceivable — the patrol state never acquires a target.
        for ($i = 0; $i < 10; $i++) {
            $h->flush();
        }
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $targetId = (new \ReflectionProperty(BaseMonster::class, 'targetId'))->getValue($monster);
        self::assertNull($targetId, '区内玩家不可感知（巡逻不建目标）');

        // 威胁门：区内玩家的普攻伤害照常结算，但威胁不记录
        // The threat gate: a zone player's basic attack still settles its damage, yet records no threat.
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        for ($i = 0; $i < 10; $i++) {
            $h->flush();
        }
        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertSame(0.0, $threatTable->threatOf('1001@conn-a'), '区内玩家的攻击不记威胁');

        // 嘲讽门：区内玩家的嘲讽威胁不写入
        // The taunt gate: a zone player's taunt threat is never written.
        $h->send($h->connA, 'skill:cast', ['skillId' => 'taunt', 'targetId' => 'monster-1']);
        $h->flush();
        self::assertSame(0.0, $threatTable->threatOf('1001@conn-a'), '区内玩家的嘲讽不写入威胁表');
        for ($i = 0; $i < 10; $i++) {
            $h->flush();
        }

        // 对照：出区（(10,0)，距圆心 10 > 半径 5）后威胁恢复记录
        // Control: outside the zone ((10,0), distance 10 > radius 5) threat recording resumes.
        $h->world->getEntityManager()->get('1001@conn-a')->setPosition(10, 0);
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        self::assertGreaterThan(0.0, $threatTable->threatOf('1001@conn-a'), '区外玩家的攻击恢复记威胁');
    }

    /**
     * P7b 复活点配置化：spawnPoint 注入 auth 挂载与复活传送共用——挂载落点与复活落点均为配置值。
     * The P7b revive-point parameterization: spawnPoint is shared by the auth mount and the revive teleport —
     * both the mount landing and the revive landing sit on the configured point.
     */
    public function testSpawnPointConfigurable(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(), ['x' => 5, 'y' => 7]);
        $h->authPlayer();
        $h->flush();
        self::assertSame(['x' => 5, 'y' => 7], $h->world->getEntityManager()->get('1001@conn-a')->getPosition(), 'auth 挂载落配置出生点');

        $actor = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $actor);
        $actor->takeDamage(100);
        $h->world->getEntityManager()->get('1001@conn-a')->setPosition(50, 50);
        $h->send($h->connA, 'player:revive', []);
        $h->flush();
        self::assertSame(['x' => 5, 'y' => 7], $h->world->getEntityManager()->get('1001@conn-a')->getPosition(), '复活传送回配置出生点');
    }

    public function testAoETauntPullsEveryMonsterInShape(): void
    {
        $h = $this->buildHarness(new MmorpgConfig());
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->spawnMonster('monster-2', 100, 5, 0);
        $h->skills->register(new SkillDefinition('taunt_aoe', '嘲讽风暴', 0.3, 8.0, 3, ['shape' => SkillDefinition::SHAPE_CIRCLE, 'radius' => 10], tauntThreat: 1000.0));
        $h->flush();

        // 施法者攻击冷却就绪（未攻击过）→ 施放 AoE 嘲讽：圆心 (0,0) 半径 10 覆盖两只怪物（(0,0) 与 (5,0)）
        // The caster's attack cooldown is ready (no prior attack) → cast the AoE taunt: center (0,0), radius 10,
        // covering both monsters ((0,0) and (5,0)).
        $h->send($h->connA, 'skill:cast_aoe', ['skillId' => 'taunt_aoe', 'cx' => 0, 'cy' => 0, 'r' => 10]);
        $h->flush();

        // 多目标嘲讽语义：形状内全部怪物被嘲讽者拉取（威胁 1000；伤害威胁 3 被取大覆盖）
        // Multi-target taunt semantics: every monster inside the shape is pulled by the taunter (threat 1000; the
        // 3-point damage threat is maxed over).
        foreach (['monster-1', 'monster-2'] as $monsterId) {
            $monster = $h->mapServer->getActor($monsterId);
            self::assertInstanceOf(MonsterActor::class, $monster);
            $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
            self::assertInstanceOf(ThreatTable::class, $threatTable);
            self::assertSame(1000.0, $threatTable->threatOf('1001@conn-a'), "AoE 嘲讽后 {$monsterId} 威胁表写入嘲讽者 1000（多目标嘲讽语义）");
        }

        // 单体系技能（未声明 AoE 能力）经 AoE 路径施放 → invalid_skill 拒绝
        // A single-target skill (no AoE declaration) cast through the AoE path → invalid_skill rejection.
        $h->skills->register(new SkillDefinition('ice_bolt', '冰锥术', 1.2, 1.5, 3));
        $h->send($h->connA, 'skill:cast_aoe', ['skillId' => 'ice_bolt', 'cx' => 0, 'cy' => 0, 'r' => 10]);
        $h->flush();

        $rejected = false;
        foreach ($h->batchedA as $batch) {
            foreach (json_decode((string) $batch, true) ?? [] as $f) {
                if (($f['type'] ?? null) === 'combat:error' && ($f['payload']['code'] ?? null) === 'invalid_skill') {
                    $rejected = true;
                }
            }
        }
        self::assertTrue($rejected, '单体系技能 AoE 施放被 invalid_skill 拒绝（能力声明裁决）');
    }

    public function testAggroRangeGateSkipsFarAttackers(): void
    {
        $h = $this->buildHarness(new MmorpgConfig()); // aggroRange 缺省 10（与缺省巡逻半径同量级）
        $h->authPlayer(); // 1001@conn-a at (0,0)
        $h->authPlayerB(); // 1002@conn-b at (0,0)
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();

        // 近距受击：1001@conn-a 与怪物同格（distance 0 ≤ aggroRange 10）→ 记威胁 10。
        // In-range hit: 1001@conn-a shares the monster's cell (distance 0 ≤ aggroRange 10) → threat 10.
        $monster = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $monster);
        $monster->noteAttacker('1001@conn-a');
        $monster->takeDamage(10);

        // 远距受击：1002@conn-b 移到 (100,100)（distance ≈141 > aggroRange 10）→ 不记威胁。
        // 走 noteAttacker+takeDamage 直结算路径（AoE 等旁路路径同构），绕开攻击路由的 isNeighborIn 防线，
        // 专门验证装配层的距离门本身。
        // Far hit: 1002@conn-b moves to (100,100) (distance ≈141 > aggroRange 10) → no threat. Driven through the
        // noteAttacker+takeDamage direct-settlement path (the AoE-style side path), bypassing the attack route's
        // isNeighborIn line, to exercise the assembly-layer distance gate itself.
        $h->world->getEntityManager()->get('1002@conn-b')->setPosition(100, 100);
        $monster->noteAttacker('1002@conn-b');
        $monster->takeDamage(10);

        $threatTable = (new \ReflectionProperty(MonsterActor::class, 'threatTable'))->getValue($monster);
        self::assertInstanceOf(ThreatTable::class, $threatTable);
        self::assertSame(10.0, $threatTable->threatOf('1001@conn-a'), '近距攻击者记威胁 the in-range attacker gains threat');
        self::assertSame(0.0, $threatTable->threatOf('1002@conn-b'), '超 aggroRange 的攻击者不记威胁 the far attacker beyond aggroRange gains no threat');
        self::assertSame('1001@conn-a', $threatTable->topThreat(), '仇恨列表只含近距攻击者 the hate list holds only the in-range attacker');
    }

    public function testKillEventCarriesMonsterTypeId(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(respawnMs: 100));
        $h->authPlayer();
        $h->spawnMonster('monster-1', 10, 0, 0); // typeId 'slime'
        $h->flush();

        $seen = [];
        $h->combatEvents->listen(CombatService::EVENT_KILL, static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        // 一击致死 → combat.kill 埋点
        // One-shot kill → the combat.kill instrumentation.
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        self::assertCount(1, $seen);
        self::assertSame('1001', $seen[0]['killerUid']);
        self::assertSame('slime', $seen[0]['monsterTypeId'], '击杀埋点携带怪物类型 id（任务匹配键，P2 收口——spawnMonster 透传 typeId 回归） the kill instrumentation carries the monster type id (the quest matching key, the P2 close-out — the spawnMonster typeId-passthrough regression)');
    }

    public function testRespawnAfterDeathBackToAnchor(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(respawnMs: 100));
        $h->authPlayer();
        $h->spawnMonster('monster-1', 10, 0, 0); // maxHp=10：一击致死 one-shot kill
        $h->flush();

        // 击杀怪物 → combat.kill 埋点 → 重生登记
        // Kill the monster → the combat.kill instrumentation → a respawn registration
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        self::assertNull($h->mapServer->getActor('monster-1'), '怪物死亡后 Actor 摘除 the monster actor is removed after death');

        // 把重生队列时间拨到过去（模拟 respawnMs 流逝），驱动世界 tick → 重生回锚点
        // Rewind the respawn queue to the past (simulating the respawnMs elapse), drive the world tick → respawn back to the anchor
        $respawner = (new \ReflectionProperty(MapServer::class, 'respawner'))->getValue($h->mapServer);
        self::assertInstanceOf(Respawner::class, $respawner);
        $queue = (new \ReflectionProperty(Respawner::class, 'queue'))->getValue($respawner);
        foreach ($queue as $monsterId => $at) {
            $queue[$monsterId] = microtime(true) - 1.0;
        }
        (new \ReflectionProperty(Respawner::class, 'queue'))->setValue($respawner, $queue);

        $h->flush();

        $respawned = $h->mapServer->getActor('monster-1');
        self::assertInstanceOf(MonsterActor::class, $respawned, '到期后怪物重生回锚点 the due monster respawns back to its anchor');
        self::assertSame(10, $respawned->maxHp(), '重生保留出生登记的血量 the respawn keeps the registered maxHp');
        $entity = $h->world->getEntityManager()->get('monster-1');
        self::assertNotNull($entity);
        self::assertSame(['x' => 0, 'y' => 0], $entity->getPosition(), '重生回出生锚点 the respawn lands on the spawn anchor');
    }

    public function testRespawnConsumesSpawnDensity(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(respawnMs: 100, spawnDensity: 2));
        $h->authPlayer();
        $h->spawnMonster('monster-1', 10, 0, 0); // maxHp=10：一击致死 one-shot kill
        $h->flush();

        // 击杀怪物 → combat.kill 埋点 → 重生登记
        // Kill the monster → the combat.kill instrumentation → a respawn registration
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        self::assertNull($h->mapServer->getActor('monster-1'), '怪物死亡后 Actor 摘除 the monster actor is removed after death');

        // 把重生队列时间拨到过去（模拟 respawnMs 流逝），驱动世界 tick → 按密度重生
        // Rewind the respawn queue to the past (simulating the respawnMs elapse), drive the world tick → the density respawn
        $respawner = (new \ReflectionProperty(MapServer::class, 'respawner'))->getValue($h->mapServer);
        self::assertInstanceOf(Respawner::class, $respawner);
        $queue = (new \ReflectionProperty(Respawner::class, 'queue'))->getValue($respawner);
        foreach ($queue as $monsterId => $at) {
            $queue[$monsterId] = microtime(true) - 1.0;
        }
        (new \ReflectionProperty(Respawner::class, 'queue'))->setValue($respawner, $queue);

        $h->flush();

        // spawnDensity=2：锚点本体 + 密度副本各一只（副本 id 加后缀、锚点偏移避免重叠）
        // spawnDensity=2: the anchor itself plus one density copy (the copy gets a suffixed id and an anchor offset)
        $anchor = $h->mapServer->getActor('monster-1');
        $copy = $h->mapServer->getActor('monster-1#2');
        self::assertInstanceOf(MonsterActor::class, $anchor, '锚点本体重生 the anchor itself respawns');
        self::assertInstanceOf(MonsterActor::class, $copy, '密度副本重生 the density copy respawns');
        self::assertSame(10, $anchor->maxHp(), '重生保留出生登记的血量 the respawn keeps the registered maxHp');
        $anchorEntity = $h->world->getEntityManager()->get('monster-1');
        $copyEntity = $h->world->getEntityManager()->get('monster-1#2');
        self::assertNotNull($anchorEntity);
        self::assertNotNull($copyEntity);
        self::assertSame(['x' => 0, 'y' => 0], $anchorEntity->getPosition(), '锚点本体回出生锚点 the anchor lands on the spawn anchor');
        self::assertSame(['x' => 2, 'y' => 2], $copyEntity->getPosition(), '密度副本锚点偏移避免重叠 the density copy offsets off the anchor to avoid overlap');
    }

    public function testRespawnKeepsRegistrationWhenSpawnThrows(): void
    {
        $h = $this->buildHarness(new MmorpgConfig(respawnMs: 100));
        $h->authPlayer();
        $h->spawnMonster('monster-1', 10, 0, 0);
        $h->flush();

        // 击杀怪物 → 重生登记
        // Kill the monster → a respawn registration
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        // 让 spawnMonster 抛异常：把出生登记的血量改成非法类型（string 进 int 参数 → TypeError）
        // Make spawnMonster throw: corrupt the registered maxHp to a non-int (a string into an int param → TypeError)
        $spawnRegistry = (new \ReflectionProperty(MapServer::class, 'spawnRegistry'))->getValue($h->mapServer);
        $spawnRegistry['monster-1']['maxHp'] = 'not-an-int';
        (new \ReflectionProperty(MapServer::class, 'spawnRegistry'))->setValue($h->mapServer, $spawnRegistry);

        // 把重生队列时间拨到过去，驱动世界 tick → spawn 抛异常（先 spawn 后 clear：登记必须保留）
        // Rewind the respawn queue to the past, drive the world tick → the spawn throws (spawn-before-clear: the
        // registration must survive)
        $respawner = (new \ReflectionProperty(MapServer::class, 'respawner'))->getValue($h->mapServer);
        self::assertInstanceOf(Respawner::class, $respawner);
        $queue = (new \ReflectionProperty(Respawner::class, 'queue'))->getValue($respawner);
        foreach ($queue as $monsterId => $at) {
            $queue[$monsterId] = microtime(true) - 1.0;
        }
        (new \ReflectionProperty(Respawner::class, 'queue'))->setValue($respawner, $queue);

        try {
            $h->flush();
            self::fail('损坏的出生登记应让 spawnMonster 抛异常 the corrupted spawn should make spawnMonster throw');
        } catch (\TypeError) {
            // 预期异常：登记保留，下个 tick 重试
            // Expected: the registration survives for the next tick's retry
        }

        self::assertTrue($respawner->pending(), 'spawn 抛异常后重生登记保留 the respawn registration survives a spawn exception');
        self::assertSame(['monster-1'], $respawner->due(microtime(true)), '登记仍在到期集，下个 tick 重试 the registration stays due for the next tick retry');
    }

    /**
     * 组装 MapServer + mmorpg 接线测试线束（真实 SimpleActorSystem 驱动怪物 AI）。
     * Builds the MapServer + mmorpg-wiring test harness (a real SimpleActorSystem drives the monster AI).
     *
     * @param ?MmorpgConfig $mmorpg null = 开关关闭（不装配 mmorpg） null = the switch off (no mmorpg assembly).
     */
    private function buildHarness(?MmorpgConfig $mmorpg, ?array $spawnPoint = null): MapServerMmorpgHarness
    {
        $h = new MapServerMmorpgHarness();

        $h->connA = $this->createStub(ConnectionInterface::class);
        $h->connA->method('getId')->willReturn('conn-a');
        $h->connA->method('getSendBufferQueueSize')->willReturn(0);
        $h->connA->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentA[] = $payload;
        });
        $h->connA->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedA = array_merge($h->batchedA, $payloads);
        });
        $h->connA->method('close')->willReturnCallback(static function () use ($h): void {
            $h->closedConns[] = 'conn-a';
        });

        $h->connB = $this->createStub(ConnectionInterface::class);
        $h->connB->method('getId')->willReturn('conn-b');
        $h->connB->method('getSendBufferQueueSize')->willReturn(0);
        $h->connB->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentB[] = $payload;
        });
        $h->connB->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedB = array_merge($h->batchedB, $payloads);
        });
        $h->connB->method('close')->willReturnCallback(static function () use ($h): void {
            $h->closedConns[] = 'conn-b';
        });

        $server = $this->createStub(ServerInterface::class);
        $server->method('onWorkerStart')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onWorkerStart = $handler;
        });
        $server->method('onConnect')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onConnect = $handler;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onMessage = $handler;
        });
        $server->method('onClose')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onClose = $handler;
        });

        $h->tokens = new FakeTokenManager();
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);
        $h->tokens->records['token-b'] = new TokenRecord('1002', 'map-1', ['map'], 0.0, 999.0);

        $h->registry = new ConnectionRegistry();
        $h->world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $h->timer = new MmorpgFakeTimer();
        $h->clock = new MmorpgFakeClock();

        $h->skills = new SkillRepository();
        $h->items = new ItemRepository();
        $h->items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));

        $h->mapServer = new MapServer(
            $server,
            new JsonBatchSerializer(),
            $h->tokens,
            $h->world,
            $h->registry,
            clock: $h->clock,
            timer: $h->timer,
            serviceId: 'map-1#ch-1',
            mapId: 'map-1',
            serviceRegistry: new FakeServiceRegistry(),
            dropTable: new DropTable(['gold' => 1]),
            typeIndex: new EntityTypeIndex(),
            skills: $h->skills,
            random: new FixedRandomSource(1),
            spawnProtectionFrames: 0,
            spawnPoint: $spawnPoint ?? ['x' => 0, 'y' => 0],
            mmorpg: $mmorpg,
        );
        $h->combatEvents = new EventDispatcher();
        $h->mapServer->attachCombat(new CombatService($h->world, $h->mapServer, $h->skills, $h->items, new FixedRandomSource(100), actorLookup: $h->mapServer, events: $h->combatEvents));
        if ($mmorpg !== null) {
            $h->mapServer->attachMmorpg($mmorpg, $h->combatEvents);
        }
        $h->mapServer->register();

        ($h->onWorkerStart)();

        return $h;
    }
}

/**
 * MapServerMmorpgTest 测试线束：双连接（connA/connB）+ mmorpg 接线依赖与消息驱动工具。
 * The MapServerMmorpgTest harness: dual connections (connA/connB) plus the mmorpg-wiring dependencies and message-driving helpers.
 */
final class MapServerMmorpgHarness
{
    public ConnectionInterface $connA;
    public ConnectionInterface $connB;
    public WorldInterface $world;
    public ConnectionRegistry $registry;
    public MmorpgFakeTimer $timer;
    public MmorpgFakeClock $clock;

    /** token fake：peek/consume 调用记录 Token fake: peek/consume call records. */
    public FakeTokenManager $tokens;

    /** 技能/物品注册表 Skill/item repositories. */
    public SkillRepository $skills;
    public ItemRepository $items;

    /** 应用级事件派发器（combat.kill 订阅用） The application-level event dispatcher (for the combat.kill subscription). */
    public EventDispatcher $combatEvents;

    public MapServer $mapServer;

    /** @var null|callable worker start 回调 Worker-start callback. */
    public $onWorkerStart = null;

    /** @var null|callable 连接建立回调 Connect callback. */
    public $onConnect = null;

    /** @var null|callable 消息回调 Message callback. */
    public $onMessage = null;

    /** @var null|callable 连接关闭回调 Close callback. */
    public $onClose = null;

    /** @var list<string> connA 经 send 直接发送的帧 Frames sent directly to connA via send. */
    public array $sentA = [];

    /** @var list<string> connA 经 sendBatch 批量发送的帧 Frames batch-sent to connA via sendBatch. */
    public array $batchedA = [];

    /** @var list<string> connB 经 send 直接发送的帧 Frames sent directly to connB via send. */
    public array $sentB = [];

    /** @var list<string> connB 经 sendBatch 批量发送的帧 Frames batch-sent to connB via sendBatch. */
    public array $batchedB = [];

    /** @var list<string> 被调用 close() 的连接 id Connection ids whose close() was called. */
    public array $closedConns = [];

    /** 认证玩家 A（uid 1001 → entityId 1001@conn-a，位置 (0,0)）。Authenticates player A (uid 1001 → entityId 1001@conn-a at (0,0)). */
    public function authPlayer(): void
    {
        ($this->onConnect)($this->connA);
        ($this->onMessage)($this->connA, self::frame('auth', ['token' => 'token-a'], 'auth-1'));
    }

    /** 认证玩家 B（uid 1002 → entityId 1002@conn-b，位置 (0,0)）。Authenticates player B (uid 1002 → entityId 1002@conn-b at (0,0)). */
    public function authPlayerB(): void
    {
        ($this->onConnect)($this->connB);
        ($this->onMessage)($this->connB, self::frame('auth', ['token' => 'token-b'], 'auth-2'));
    }

    /** 生成怪物（经 spawnMonster 组装路径）。Spawns a monster (via the spawnMonster assembly path). */
    public function spawnMonster(string $monsterId, int $maxHp, int $x, int $y): void
    {
        $this->mapServer->spawnMonster($monsterId, $maxHp, ['x' => $x, 'y' => $y], 'slime');
    }

    /** 生成怪物并返回其 Actor（P8b 命中判定需要读血量）。Spawns a monster and returns its actor (the P8b hit judgment reads hp). */
    public function spawnMonsterReturning(string $monsterId, int $maxHp, int $x, int $y): MonsterActor
    {
        $this->mapServer->spawnMonster($monsterId, $maxHp, ['x' => $x, 'y' => $y], 'slime');
        $monster = $this->mapServer->getActor($monsterId);
        assert($monster instanceof MonsterActor);

        return $monster;
    }

    /** 发送一条已认证消息并跑两帧 flush。Sends an authenticated message and runs two frames of flush. */
    public function send(ConnectionInterface $conn, string $type, array $payload): void
    {
        ($this->onMessage)($conn, self::frame($type, $payload));
    }

    /** 驱动两帧世界 tick：帧 N 投递 flush 任务，帧 N+1 的 runFrame 执行 flush。Drives two world ticks: frame N submits the flush task, frame N+1's runFrame executes it. */
    public function flush(): void
    {
        $this->timer->trigger();
        $this->timer->trigger();
    }

    /** 构造一条合法协议帧字节。Builds a valid protocol frame payload. */
    public static function frame(string $type, array $payload, ?string $requestId = null): string
    {
        return json_encode([
            'type' => $type,
            'requestId' => $requestId,
            'timestamp' => 123.0,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }
}

/**
 * MmorpgFakeTimer - 测试定时器：只记录回调不真正定时，由测试经 trigger 手动驱动（类名唯一）。
 * MmorpgFakeTimer - test timer: records callbacks without real timing, driven manually by tests via trigger (unique class name).
 */
final class MmorpgFakeTimer implements TimerInterface
{
    /** @var list<callable> 已登记的回调 Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
        // 测试不需要取消语义，空操作 No cancellation semantics needed in tests; no-op
    }

    public function trigger(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }
}

/**
 * MmorpgFakeClock - 测试时钟：每次 tick 推进固定 50ms（类名唯一）。
 * MmorpgFakeClock - test clock: advances a fixed 50ms per tick (unique class name).
 */
final class MmorpgFakeClock implements ClockInterface
{
    /** @var float 当前时钟时间（秒） Current clock time in seconds. */
    private float $current = 0.0;

    public function tick(): void
    {
        $this->current += 0.05;
    }

    public function now(): float
    {
        return $this->current;
    }

    public function deltaTime(): float
    {
        return 0.05;
    }
}
