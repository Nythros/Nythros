<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/CombatFakes.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\MapServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropEntity;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Inventory;
use Nythros\Framework\Persistence\ArchivePipeline;
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
use Nythros\Persistence\InMemoryStorage;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerCombatTest - MapServer 战斗路由集成测试：attack 伤害帧、失败 combat:error、pickup+markDirty、skill:cast 与掉落物 entity_enter 附 itemId。
 * MapServer combat-route integration tests: attack damage frames, failed combat:error receipts, pickup+markDirty, skill:cast and drop-entity entity_enter carrying itemId.
 *
 * 组装策略：与 MapServerTest 一致（stub 连接/Server，真实 World/EventBus，Fake 时钟定时器），
 * 额外注入战斗依赖（dropTable/typeIndex/inventories/archive/skills）并经 attachCombat 回填 CombatService。
 * Assembly strategy: same as MapServerTest (stub connections/Server, real World/EventBus, fake clock/timer),
 * plus combat dependencies (dropTable/typeIndex/inventories/archive/skills) and a CombatService back-filled via attachCombat.
 */
final class MapServerCombatTest extends TestCase
{
    public function testAttackDealsDamageAndBroadcastsCombatHit(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        $hits = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:hit');
        self::assertCount(1, $hits);
        self::assertSame('1001@conn-a', $hits[0]->payload['attackerId']);
        self::assertSame('monster-1', $hits[0]->payload['targetId']);
        self::assertSame(10, $hits[0]->payload['damage']);
        self::assertSame(90, $hits[0]->payload['hp']);

        $monster = $h->mapServer->getActor('monster-1');
        self::assertSame(90, $monster->hp());
        self::assertSame([], $h->closedConns, '攻击成功不关闭连接。A successful attack never closes the connection.');
    }

    public function testAttackRejectsInvalidTargetWithCombatError(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'ghost-target']);
        $h->flush();

        $errors = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:error');
        self::assertCount(1, $errors);
        self::assertSame('invalid_target', $errors[0]->payload['code']);
        self::assertSame([], $h->closedConns, '失败回执不关连接。A failure receipt never closes the connection.');
    }

    /**
     * V6 世界路径契约锁（ADR-024 §9）：无容器记录的连接战斗路由走宿主世界 EM/AOI——
     * registry 容器维度恒 null、实体解析与视野判定与世界侧容器化前逐字节等价。
     * The V6 world-path contract lock (ADR-024 §9): a connection without a container record routes combat through
     * the host-world EM/AOI — the registry container dimension stays null, and entity resolution plus view checks
     * stay byte-for-byte equivalent to the pre-containerization world side.
     */
    public function testAttackWithoutContainerRecordRoutesThroughHostWorld(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();

        // 零 moveToContainer 调用点：容器维度缺省 null，路由必须回落宿主世界
        // Zero moveToContainer call sites: the container dimension defaults to null and routing must fall back to the host world
        self::assertNull($h->registry->getContainer('conn-a'));
        $context = $h->registry->resolveContainerContext('conn-a', $h->world);
        self::assertNull($context['container']);
        self::assertSame($h->world->getEntityManager(), $context['entityManager']);
        self::assertSame($h->world->getAOI(), $context['aoi']);

        $h->batchedA = [];
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        $hits = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:hit');
        self::assertCount(1, $hits, '无容器记录的攻击在世界上下文正常结算 an attack without a container record settles in the world context');
        self::assertSame(90, $h->mapServer->getActor('monster-1')->hp());
    }

    public function testAttackRejectsOutOfRangeTargetWithCombatError(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('far-monster', 100, 50, 50);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'far-monster']);
        $h->flush();

        $errors = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:error');
        self::assertCount(1, $errors);
        self::assertSame('out_of_range', $errors[0]->payload['code']);
    }

    public function testAttackRejectsWhileOnCooldownWithCombatError(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();
        $h->batchedA = [];

        // 第一次攻击成功（冷却开始），第二次在冷却内被拒
        // The first attack succeeds (cooldown starts), the second is rejected while on cooldown
        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        $errors = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:error');
        self::assertCount(1, $errors);
        self::assertSame('cooldown', $errors[0]->payload['code']);
        self::assertSame(90, $h->mapServer->getActor('monster-1')->hp(), '冷却中的攻击不结算伤害。An attack on cooldown deals no damage.');
    }

    public function testSkillCastBroadcastsAndAppliesMultiplier(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'skill:cast', ['skillId' => 'fireball', 'targetId' => 'monster-1']);
        $h->flush();

        $casts = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'skill:cast');
        self::assertCount(1, $casts);
        self::assertSame('fireball', $casts[0]->payload['skillId']);
        self::assertSame('monster-1', $casts[0]->payload['targetId']);

        $hits = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:hit');
        self::assertCount(1, $hits);
        self::assertSame(15, $hits[0]->payload['damage'], 'fireball 倍率 1.5：普攻 10 × 1.5 = 15。fireball multiplier 1.5: base 10 × 1.5 = 15.');
        self::assertSame(85, $h->mapServer->getActor('monster-1')->hp());
    }

    public function testSkillCastRejectsUnknownSkillWithCombatError(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 100, 0, 0);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'skill:cast', ['skillId' => 'unknown-skill', 'targetId' => 'monster-1']);
        $h->flush();

        $errors = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'combat:error');
        self::assertCount(1, $errors);
        self::assertSame('invalid_skill', $errors[0]->payload['code']);
    }

    public function testPickupAddsToInventoryAndMarksDirty(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        // maxHp=10：一次普攻打死怪物触发死亡掉落（drop 生成在 (0,0)）
        // maxHp=10: one normal attack kills the monster and triggers the death drop (spawned at (0,0))
        $h->spawnMonster('monster-1', 10, 0, 0);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        $drop = self::worldDrop($h->world);
        self::assertNotNull($drop, "怪物死亡应生成掉落物。The monster's death must spawn a drop.");

        $h->send($h->connA, 'pickup', ['dropId' => $drop->getId()]);
        $h->flush();

        // 入包 + 持久化标脏：背包表更新且 ArchivePipeline 标脏（flushId 后落库）
        // Inventory addition + persistence dirty-marking: the inventory table updates and ArchivePipeline marks dirty (persisted after flushId)
        self::assertSame(1, $h->inventory->count('gold'));
        $h->archive->flushId('1001');
        self::assertSame(['inventory' => ['gold' => 1]], $h->storage->load('players', '1001'));

        $removed = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'drop:removed');
        self::assertCount(1, $removed);
        self::assertSame($drop->getId(), $removed[0]->payload['dropId']);

        $added = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'item:added');
        self::assertCount(1, $added);
        self::assertSame(['itemId' => 'gold', 'count' => 1], $added[0]->payload);
    }

    public function testAoiEnterCarriesItemIdForDropEntity(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->flush();
        $h->batchedA = [];

        // 掉落生成在 A 视野外（cell 2:0 vs A 的 0:0），A 跨格移动进入其九宫格时 world 扫描
        // 发布 entity_enter 信封（source=掉落物），handleAoiVisibility 经 instanceof DropEntity 附 itemId。
        // The drop spawns outside A's view (cell 2:0 vs A's 0:0); when A moves across cells into its 3x3
        // neighborhood the world sweep publishes an entity_enter envelope (source = the drop), and handleAoiVisibility
        // attaches itemId via the demo-owned instanceof DropEntity check.
        $h->combat->spawnDrops('monster-1', ['x' => 20, 'y' => 0], [['itemId' => 'gold', 'count' => 1]]);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'move', ['dx' => 10, 'dy' => 0]);
        $h->flush();

        $enters = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'entity_enter');
        $dropEnters = array_values(array_filter(
            $enters,
            static fn (Message $message): bool => str_starts_with((string) $message->payload['id'], 'drop-'),
        ));
        self::assertCount(1, $dropEnters);
        self::assertSame('gold', $dropEnters[0]->payload['itemId'], '掉落物 entity_enter 必须附 itemId。Drop-entity entity_enter must carry itemId.');
    }

    public function testSpawnMonsterCrossCellBroadcastsEntityEnterToNeighbors(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer(); // A 在 (0,0) A at (0,0)
        $h->flush();
        $h->batchedA = [];

        // 怪物出生在 (10,0)（cell 1:0，与 A 跨格）：updateEntity 返回 entered=[A]，
        // spawn 后补发 entity_enter（ADR-017 §8.7：spawned 是出生事件、enter 是可见事件）
        // The monster spawns at (10,0) (cell 1:0, cross-cell from A): updateEntity returns entered=[A],
        // so entity_enter is back-filled after spawn (ADR-017 §8.7: spawned is the birth event, enter the visibility event)
        $h->spawnMonster('monster-1', 100, 10, 0);
        $h->flush();

        $enters = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertCount(1, $enters);
        self::assertSame('monster-1', $enters[0]->payload['id']);
        self::assertSame(['x' => 10, 'y' => 0], $enters[0]->payload['position']);

        // 出生帧照常广播：与 entity_enter 职责不同（出生事件带 typeId）
        // The birth frame still broadcasts: its role differs from entity_enter (a birth event carrying typeId)
        $spawned = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'monster:spawned');
        self::assertCount(1, $spawned);
        self::assertSame('slime', $spawned[0]->payload['typeId']);
    }

    public function testSpawnMonsterWithoutNeighborsSkipsEntityEnter(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer(); // A 在 (0,0) A at (0,0)
        $h->flush();
        $h->batchedA = [];

        // 怪物出生在 (50,50)：九宫格内无旧邻居，entered 为空，不补发 entity_enter（monster:spawned 承担出生通知）
        // The monster spawns at (50,50): no neighbors in its 3x3 neighborhood, entered is empty,
        // so no entity_enter back-fill (monster:spawned carries the birth notice)
        $h->spawnMonster('far-monster', 100, 50, 50);
        $h->flush();

        $enters = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertSame([], $enters);
    }

    public function testMonsterDeathBroadcastsEntityDead(): void
    {
        $h = $this->buildHarness();
        $h->authPlayer();
        $h->spawnMonster('monster-1', 10, 0, 0);
        $h->flush();
        $h->batchedA = [];

        $h->send($h->connA, 'attack', ['targetId' => 'monster-1']);
        $h->flush();

        $deaths = self::messagesOfType(MapServerCombatHarness::decodeFrames($h->batchedA), 'entity_dead');
        self::assertCount(1, $deaths);
        self::assertSame('monster-1', $deaths[0]->payload['id']);

        // 死亡完整清理（修复 MINOR-2）：MapServer 的 $actors 表与 entityManager 同步摘除。
        // Full death cleanup (fixes MINOR-2): the MapServer $actors table and the entityManager are purged together.
        self::assertNull($h->mapServer->getActor('monster-1'), '$actors 表经 removeActor 摘除');
        self::assertNull($h->world->getEntityManager()->get('monster-1'), 'entityManager 中怪物实体已移除');
    }

    /**
     * 组装 MapServer 战斗测试线束。
     * Builds the MapServer combat test harness.
     */
    private function buildHarness(): MapServerCombatHarness
    {
        $h = new MapServerCombatHarness();

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
        $h->tokens->records['token-a'] = new \Nythros\Security\TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);

        $removedActors = [];
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('remove')->willReturnCallback(static function (ActorInterface $actor) use (&$removedActors): void {
            $removedActors[] = $actor;
        });

        $h->registry = new ConnectionRegistry();
        $h->world = new World(new SimpleEntityManager(), $actorSystem, new GridAOI(10), new SimpleEventBus(), new RegionScheduler());
        $h->timer = new CombatFakeTimer();
        $h->clock = new CombatFakeClock();

        $h->skills = new SkillRepository();
        $h->skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3));
        $h->items = new ItemRepository();
        $h->items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));

        $h->inventory = new Inventory();
        $h->storage = new InMemoryStorage();
        $h->archive = new ArchivePipeline($h->storage, 'players');

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
            inventories: ['1001@conn-a' => $h->inventory],
            archive: $h->archive,
            skills: $h->skills,
            random: new FixedRandomSource(1),
        );
        $combat = new CombatService($h->world, $h->mapServer, $h->skills, $h->items, new FixedRandomSource(100));
        $h->mapServer->attachCombat($combat);
        $h->combat = $combat;
        $h->mapServer->register();

        ($h->onWorkerStart)();

        return $h;
    }

    /**
     * 从 world 实体表取出唯一 DropEntity。
     * Extracts the sole DropEntity from the world entity table.
     */
    private static function worldDrop(WorldInterface $world): ?DropEntity
    {
        foreach ($world->getEntityManager()->all() as $entity) {
            if ($entity instanceof DropEntity) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * 按消息类型过滤并返回全部匹配消息。
     * Filters messages by type and returns all matches.
     *
     * @param list<Message> $messages 已解码消息列表 Decoded messages.
     * @param string $type 目标消息类型 Target message type.
     * @return list<Message> 匹配消息列表 Matching messages.
     */
    private static function messagesOfType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->type === $type,
        ));
    }
}

/**
 * MapServerCombatTest 测试线束：持有战斗依赖与消息驱动工具。
 * The MapServerCombatTest harness: holds the combat dependencies and message-driving helpers.
 */
final class MapServerCombatHarness
{
    public ConnectionInterface $connA;
    public WorldInterface $world;
    public ConnectionRegistry $registry;
    public CombatFakeTimer $timer;
    public CombatFakeClock $clock;

    /** token fake：peek/consume 调用记录 Token fake: peek/consume call records. */
    public FakeTokenManager $tokens;

    /** 技能/物品注册表 Skill/item repositories. */
    public SkillRepository $skills;
    public ItemRepository $items;

    /** 玩家背包（注入表实例，pickup 后断言） Player inventory (the injected table instance, asserted after pickup). */
    public Inventory $inventory;

    /** 归档管线 + 内存存储（pickup markDirty 后 flushId 断言落库） Archive pipeline + in-memory storage (asserted after flushId following pickup). */
    public ArchivePipeline $archive;
    public InMemoryStorage $storage;

    public MapServer $mapServer;

    /** 战斗服务（spawnDrops 直接调用路径） Combat service (direct spawnDrops call path). */
    public CombatService $combat;

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

    /** @var list<string> 被调用 close() 的连接 id Connection ids whose close() was called. */
    public array $closedConns = [];

    /** 认证玩家 A（uid 1001 → entityId 1001@conn-a，位置 (0,0)）。Authenticates player A (uid 1001 → entityId 1001@conn-a at (0,0)). */
    public function authPlayer(): void
    {
        ($this->onConnect)($this->connA);
        ($this->onMessage)($this->connA, self::frame('auth', ['token' => 'token-a'], 'auth-1'));
    }

    /** 生成怪物（经 spawnMonster 组装路径）。Spawns a monster (via the spawnMonster assembly path). */
    public function spawnMonster(string $monsterId, int $maxHp, int $x, int $y): void
    {
        $this->mapServer->spawnMonster($monsterId, $maxHp, ['x' => $x, 'y' => $y], 'slime');
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

    /** 把帧字节列表解码为消息列表（每个元素可能是单帧对象或多帧批量包）。Decodes frame bytes into messages (each may be a single frame or a batch). */
    public static function decodeFrames(array $frames): array
    {
        $serializer = new JsonBatchSerializer();
        $out = [];
        foreach ($frames as $frame) {
            foreach ($serializer->decodeBatch($frame) as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }
}

/**
 * CombatFakeTimer - 测试定时器：只记录回调不真正定时，由测试经 trigger 手动驱动（类名唯一，避免与 MapServerTest 的 FakeTimer 冲突）。
 * CombatFakeTimer - test timer: records callbacks without real timing, driven manually by tests via trigger (unique class name, avoiding a clash with MapServerTest's FakeTimer).
 */
final class CombatFakeTimer implements TimerInterface
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
 * CombatFakeClock - 测试时钟：每次 tick 推进固定 50ms（类名唯一）。
 * CombatFakeClock - test clock: advances a fixed 50ms per tick (unique class name).
 */
final class CombatFakeClock implements ClockInterface
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
