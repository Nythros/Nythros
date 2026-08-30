<?php

declare(strict_types=1);

// 定位：benchmarks/framework-bench.php — 框架层基准（一次性离线执行）。
// 覆盖：BaseMonster AI 状态机完整循环（PATROL→CHASE→ATTACK→DEAD 转移吞吐，用 demo 的 MonsterActor 做真实装配）、
// PluginRegistry 加载/启用/查找吞吐、SkillRepository/ItemRepository 注册与查找。
// 说明：AI 状态机是 demo 的 MonsterActor（framework 基类 + demo 实现），体现「基类钩子 + 业务填充」的真实开销。
// Located at: benchmarks/framework-bench.php — framework-layer benchmark (one-shot offline). Covers: the BaseMonster
// AI state machine full cycle (PATROL→CHASE→ATTACK→DEAD transition throughput, assembled with the demo MonsterActor),
// PluginRegistry load/enable/lookup throughput, and Skill/Item repository register + lookup.

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\BaseMonster;
use Nythros\Framework\Combat\ActorLookupInterface;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\MonsterActor;
use Nythros\Framework\Combat\SystemRandomSource;
use Nythros\Framework\Combat\VisionBroadcasterInterface;
use Nythros\Framework\Container\Container;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\PluginRegistry;
use Nythros\Framework\Plugin\Skill\SkillDefinition;
use Nythros\Framework\Plugin\Skill\SkillPlugin;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\MovementValidator;
use Nythros\Scheduler\RegionScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

$hrtime = static function (callable $fn, int $iters): float {
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }
    return (hrtime(true) - $t0) / 1e6;
};

echo "== 框架基准 Framework Benchmarks ==" . PHP_EOL;

// ── 1. 插件注册/启用/查找吞吐 ──
// 1. PluginRegistry load/enable/lookup throughput
echo PHP_EOL . "[1] PluginRegistry" . PHP_EOL;
$container = new Container();
$dispatcher = new EventDispatcher();
$registry = new PluginRegistry();
// load 对同名插件只允许一次（重复抛异常）；基准用独立 registry 测单次 load 全成本
// load allows a name only once (duplicates throw); the benchmark uses a fresh registry per iteration for a full single-load cost
$ms = $hrtime(static function () use ($container, $dispatcher): void {
    (new PluginRegistry())->load(new SkillPlugin(), $container, $dispatcher);
}, 3000);
printf("  load (独立 registry): %.0f ops/s\n", 3000 / $ms * 1000);
$registry->load(new SkillPlugin(), $container, $dispatcher);
$registry->enable('skill');
$ms = $hrtime(static fn () => $registry->get('skill'), 100000);
printf("  get:   %.0f lookups/s\n", 100000 / $ms * 1000);

// ── 2. SkillRepository 注册 + 查找 ──
// 2. SkillRepository register + lookup
echo PHP_EOL . "[2] SkillRepository" . PHP_EOL;
$skills = new SkillRepository();
$ms = $hrtime(static fn () => $skills->register(new SkillDefinition('fireball', '火球术', 1.5, 2.0, 3)), 50000);
printf("  register: %.0f ops/s\n", 50000 / $ms * 1000);
$skills->register(new SkillDefinition('ice_bolt', '冰锥术', 1.2, 1.5, 3));
$ms = $hrtime(static fn () => $skills->get('fireball'), 200000);
printf("  get:      %.0f lookups/s\n", 200000 / $ms * 1000);

// ── 3. ItemRepository ──
// 3. ItemRepository
echo PHP_EOL . "[3] ItemRepository" . PHP_EOL;
$items = new ItemRepository();
$ms = $hrtime(static fn () => $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY)), 50000);
printf("  register: %.0f ops/s\n", 50000 / $ms * 1000);
$items->register(new ItemDefinition('potion', '生命药水', ItemDefinition::TYPE_CONSUMABLE));
$ms = $hrtime(static fn () => $items->get('gold'), 200000);
printf("  get:      %.0f lookups/s\n", 200000 / $ms * 1000);

// ── 4. MonsterActor AI 状态机（真实装配：World + AOI + CombatService + DropTable）──
// 4. MonsterActor AI state machine (real assembly: World + AOI + CombatService + DropTable)
echo PHP_EOL . "[4] MonsterActor AI update()" . PHP_EOL;
$actorSystemStub = new class () implements ActorSystemInterface {
    public function add(\Nythros\Contracts\ActorInterface $actor): void
    {
    }
    public function remove(\Nythros\Contracts\ActorInterface $actor): void
    {
    }
    public function updateAll(): void
    {
    }
    public function get(string $id): ?\Nythros\Contracts\ActorInterface
    {
        return null;
    }
    public function all(): array
    {
        return [];
    }
};
$world = new World(new SimpleEntityManager(), $actorSystemStub, new GridAOI(10), new SimpleEventBus(50000), new RegionScheduler(100.0));
$broadcaster = new class () implements VisionBroadcasterInterface {
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void
    {
    }
    public function sendToEntity(string $entityId, string $type, array $payload): void
    {
    }
};
$items2 = new ItemRepository();
$items2->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
$combat = new CombatService($world, $broadcaster, $skills, $items2, new SystemRandomSource());
$typeIndex = new EntityTypeIndex();
$lookup = new class () implements ActorLookupInterface {
    public function getActor(string $entityId): ?\Nythros\Contracts\ActorInterface
    {
        return null;
    }
    public function removeActor(string $entityId): void
    {
    }
};
$monster = new MonsterActor('monster-1', 100, $world, $combat, new DropTable(['gold' => 1]), $lookup, $typeIndex, new SystemRandomSource(), $broadcaster);
$monster->bindEntity(new BaseEntity('monster-1', new Position(0, 0)));

// 状态机循环：PATROL 每次随机移动一格（无感知 → 始终 patrol），测得纯 AI 决策 + AOI 更新开销
// State-machine loop: PATROL moves one cell per tick (no perception → stays in patrol), measuring pure AI decision + AOI update cost
$ms = $hrtime(static fn () => $monster->update(), 20000);
printf("  update() PATROL: %.0f ticks/s\n", 20000 / $ms * 1000);

// 状态转移成本 State transition cost
$ms = $hrtime(static function () use ($monster): void {
    $monster->enterState(BaseMonster::STATE_CHASE);
    $monster->enterState(BaseMonster::STATE_PATROL);
}, 200000);
printf("  state transition: %.0f ops/s\n", 200000 / $ms * 1000);

// ── 5. MovementValidator 反作弊校验（R3：move 热路径 O(1) 门控成本）──
// 5. MovementValidator anti-cheat validation (R3: the move hot-path's O(1) gating cost)
// 接受路径：合法小步 + 时间推进（窗滚动重锚也在环内）；拒绝路径：恒超速短路。
// Accept path: legal small steps with time advancing (window rollover re-anchoring included); reject path: a
// constant overspeed short-circuit.
echo PHP_EOL . "[5] MovementValidator" . PHP_EOL;
$validator = new MovementValidator();
$t = 0.0;
$ms = $hrtime(static function () use ($validator, &$t): void {
    $t += 0.001;
    $validator->validate('p-1', 1, 0, 0, 0, $t);
}, 200000);
printf("  validate accept: %.0f ops/s\n", 200000 / $ms * 1000);
$ms = $hrtime(static fn () => $validator->validate('p-2', 999999, 0, 0, 0, 0.0), 200000);
printf("  validate reject: %.0f ops/s\n", 200000 / $ms * 1000);

echo PHP_EOL . "== 框架基准完成 ==" . PHP_EOL;
