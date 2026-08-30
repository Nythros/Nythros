# Nythros Cell / AOI 指南（Cell Guide）

> 本文介绍空间索引（GridAOI）的工作原理、Entity 与 AOI 的职责划分，以及移动同步的完整链路（从 `updateEntity` 差分到客户端 `entity_enter / entity_leave / entity_moved` 帧）。

## 1. AOI 概念

AOI（Area of Interest，兴趣区域）维护实体的空间索引，回答两个问题：

1. **谁在我的视野里**（近邻查询）；
2. **我的视野怎么变化**（进入/离开差分）。

契约 `Nythros\Contracts\AOIProviderInterface`：

```php
interface AOIProviderInterface
{
    /**
     * 登记或更新实体的空间位置，并返回视野变化差分。
     * @return array{entered: list<EntityInterface>, left: list<EntityInterface>}
     *         entered 为进入视野的邻居实体（排除自身）、left 为离开视野的邻居实体（排除自身）
     */
    public function updateEntity(EntityInterface $entity): array;

    /** 从 AOI 索引中移除实体；未登记的实体被静默忽略。 */
    public function remove(EntityInterface $entity): void;

    /**
     * 查询目标实体 AOI 范围内的可见实体集合：九宫格（当前格 + 周围 8 格）。
     * 是否包含自身由实现约定，GridAOI 含自身。
     */
    public function query(EntityInterface $entity): array;
}
```

引擎当前提供 `Nythros\Aoi\GridAOI` 网格实现；API 不承诺「九宫格」这一查询细节（蓝图 §6），后续可替换为 Quadtree / Radius / Sector 实现而业务层无感。

## 2. GridAOI：网格空间索引

### 2.1 结构

```php
final class GridAOI implements AOIProviderInterface
{
    /** 构造：cellSize = 单个格子的边长（世界单位）。 */
    public function __construct(private readonly int $cellSize) {}
}
```

- **格子索引** `$cells`：`"cx:cy"` → 格子内以实体 id 为键的实体表。格子坐标由 `floor(x / cellSize)` / `floor(y / cellSize)` 得到（负坐标同样朝 -∞ 取整，格子边界在原点两侧一致）。
- **反查索引** `$entityCells`：实体 id → 所在格子 key，使移除与更新免于全表扫描。
- `query()` 返回九宫格（当前格 + 周围 8 格，`cx-1..cx+1` × `cy-1..cy+1`）内全部实体，**含自身**，按实体 id 去重。

### 2.2 updateEntity：登记与视野差分

```php
public function updateEntity(EntityInterface $entity): array
{
    $id = $entity->getId();
    $newKey = $this->cellKey($entity);
    $oldKey = $this->entityCells[$id] ?? null;

    // 同格 fast path：格子未变则九宫格与视野均不变，直接返回空差分
    if ($oldKey === $newKey) {
        return ['entered' => [], 'left' => []];
    }

    // 先基于旧格子收集旧邻居集（排除自身）；新登记（$oldKey 为 null）时旧邻居为空集
    $oldNeighbors = $oldKey === null ? [] : $this->neighborhood($oldKey, $id);

    // 迁移实体记录：从旧格移除、写入新格，并同步反查索引
    if ($oldKey !== null) {
        unset($this->cells[$oldKey][$id]);
    }
    $this->cells[$newKey][$id] = $entity;
    $this->entityCells[$id] = $newKey;

    // 基于新格子收集新邻居集（排除自身），再做集合差得到 entered / left
    $newNeighbors = $this->neighborhood($newKey, $id);

    return [
        'entered' => $this->difference($newNeighbors, $oldNeighbors),
        'left' => $this->difference($oldNeighbors, $newNeighbors),
    ];
}
```

要点：

- **同格移动走 fast path**：不跨格时九宫格不变，直接返回空差分，零开销。
- **首次登记**（`$oldKey === null`）：旧邻居为空集，`entered` 即当前九宫格全部邻居（排除自身）。
- **差分方向**：`entered` = 新邻居中有、旧邻居中没有的；`left` = 旧邻居中有、新邻居中没有的。

### 2.3 remove：O(1) 移除

经反查索引定位格子并清除；从未登记过的实体会被静默忽略。

## 3. Entity 与 AOI 的关系

| 概念 | 管什么 | 不管什么 |
|---|---|---|
| Entity（`BaseEntity`） | 身份（不可变 id）、位置（`getPosition()` / `move(dx, dy)`）、生命周期 | 行为、Tick、空间归属 |
| AOI（`GridAOI`） | 空间索引、近邻查询、视野差分 | 业务状态（不存 playerHp / playerPosition 等字段） |

职责分离的关键：**Entity 只声明「我在哪」，AOI 只回答「谁在我周围」**。实体坐标是 AOI 计算格子位置的唯一输入（`entity->getPosition()`），AOI 不持有任何业务状态。

```php
final class BaseEntity implements EntityInterface
{
    public function getId(): string;                       // 不可变 id
    public function getPosition(): array;                  // ['x' => int, 'y' => int]
    public function move(int $dx, int $dy): void;          // 位置平移（Position 不可变，返回新实例）
}
```

## 4. 移动同步链路

### 4.1 整体流程

```text
客户端 move{dx,dy}
  → MapServer::handleMove
      ① entity->move(dx, dy)         只改坐标，不动 AOI
      ② 按新坐标九宫格查询，广播 entity_moved{id, position}（跳过自己，无 move 回执）
  → World::update（每帧，50ms）
      ① ActorSystem 先行（怪物移动等）
      ② 全量刷新：对每个实体 aoi->updateEntity(entity) → 收集 entered/left 差分
      ③ 对每个邻居**成对**发布 TYPE_AOI_ENTER / TYPE_AOI_LEAVE 事件信封
  → EventBus::flush（帧末）
  → MapServer::handleAoiVisibility → 写 entity_enter / entity_leave 帧入 Outbox
  → Outbox 批量 sendBatch 发往各连接
```

### 4.2 World::update 的双向事件（关键设计）

`updateEntity` 的 entered/left 是「实体 A 的视野新增/失去邻居 B」（**A 视角**）。若只据此生成 `source=A, targetScope=B` 的信封，那么「A 进入/离开 B 的视野」这一侧（**B 视角**）将永远缺失——静止的 B 永远无法被新认证/新移动的 A 看到。

因此 World 对每个邻居**成对**生成两个方向的信封（reviewer MAJOR 修复）：

```php
foreach ($diff['entered'] as $neighbor) {
    // 邻居视角：通知 B ——「A 进入了你的视野」
    $enterEnvelopes[] = new EventEnvelope(
        source: $entity->getId(),
        type: EventEnvelope::TYPE_AOI_ENTER,        // 'aoi.enter'
        targetScope: $neighbor->getId(),
        payload: ['position' => $entity->getPosition()],
    );
    // 自身视角：通知 A ——「B 进入了你的视野」
    $enterEnvelopes[] = new EventEnvelope(
        source: $neighbor->getId(),
        type: EventEnvelope::TYPE_AOI_ENTER,
        targetScope: $entity->getId(),
        payload: ['position' => $neighbor->getPosition()],
    );
}
// leave 信封与 enter 完全对称（TYPE_AOI_LEAVE = 'aoi.leave'）
```

对称性去重：B 静止时其 `updateEntity` 走同格 fast path（空差分），不会为同一对实体重复产生「B 视角」的 A 事件。

### 4.3 帧 → 客户端帧

MapServer 订阅两类信封，经统一路径转成客户端协议帧：

```php
$this->world->getEventBus()->subscribe(EventEnvelope::TYPE_AOI_ENTER, $this->handleAoiEnter(...));   // → 'entity_enter'
$this->world->getEventBus()->subscribe(EventEnvelope::TYPE_AOI_LEAVE, $this->handleAoiLeave(...));   // → 'entity_leave'

// handleAoiVisibility：targetScope 经 registry 反查连接，编码通知帧入 outbox；掉落物额外附 itemId
$payload = [
    'id' => $envelope->source,
    'position' => $envelope->payload['position'] ?? null,
];
// $source instanceof DropEntity 时 $payload['itemId'] = $source->itemId;
// outbox->enqueue($targetConn, encode(Message::create($frameType, $payload)))
```

客户端最终收到的三种帧：

| 帧类型 | 触发 | payload |
|---|---|---|
| `entity_enter` | 有实体进入视野（含登录时初始快照） | `{id, position[, itemId]}` |
| `entity_leave` | 有实体离开视野（含连接清理） | `{id, position}` |
| `entity_moved` | 玩家/怪物移动（基于新坐标九宫格广播） | `{id, position}` |

> 注意：移动帧 `entity_moved` 不走 AOI 差分，而是 `handleMove` 里按新坐标 `aoi->query` 直接广播；`entity_enter/entity_leave` 才走 World::update 的差分信封。客户端无需区分移动来源（无 move 回执，以广播为准）。

> 传输层（阶段 2 二进制协议）：帧类型与 payload 字段名只是「语义名」——线上传输经 BinaryBatchSerializer 压缩为
> 枚举编码（packages/demo/src/Protocol/FrameType.php 与 PayloadKey.php 是权威字典，编码一经发布不得复用），
> 且出站以「一包多帧」批量包下发（每连接每帧一次网络写入）。批量包布局见 docs/protocol.md。
> Transport (phase-2 binary protocol): frame types and payload keys are semantic names — on the wire the
> BinaryBatchSerializer compresses them to enum codes (packages/demo/src/Protocol/FrameType.php and
> PayloadKey.php are the authoritative dictionaries; released codes must never be reused), and outbound traffic
> travels as "many frames in one packet" batches (one network write per connection per frame). See docs/protocol.md for the batch layout.

### 4.4 登录初始视野

auth 成功时以 `aoi->query` 全量快照为权威视野源：把九宫格内全部邻居的 `entity_enter` 写入 outbox（每邻居恰好一条），保证新进图玩家立即看到周围实体。

## 5. 示例：注册实体 + 移动触发视野变化

### 5.1 最小离线示例（demo run.php）

```php
use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Kernel\SystemClock;
use Nythros\Scheduler\SimpleScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

// 组装世界各子系统（AOI 网格 10 单位一格）
$clock = new SystemClock();
$scheduler = new SimpleScheduler();
$entityManager = new SimpleEntityManager();
$actorSystem = new SimpleActorSystem();
$eventBus = new SimpleEventBus();
$aoi = new GridAOI(cellSize: 10);
$world = new World($entityManager, $actorSystem, $aoi, $eventBus, $scheduler);

// 注册玩家实体：入 EntityManager + 入 AOI，两者都持有才可被查询/广播
$player = new BaseEntity('player-1', new Position(0, 0));
$entityManager->add($player);
$aoi->updateEntity($player);

// 每帧驱动：World::update 会全量刷新 AOI 并发布 enter/leave 信封
$clock->tick();
$world->update();

// 查询：player 的九宫格视野（含自身）
$nearby = $aoi->query($player);
```

### 5.2 移动触发视野变化（verify-combat 的跨格进入路径）

战斗层验收里的 `moveAwayAndBack` 演示了真实链路：客户端移出出生格（跨 cell）再移回，触发 `World::update` 发布 enter 信封 → `handleAoiVisibility` 广播 `entity_enter`：

```php
// 移出：dx=30（跨格），移回：dx=-30（跨格）
sendMap($uid, 'move', ['dx' => 30, 'dy' => 0], reqId());
// 0.5s 后
sendMap($uid, 'move', ['dx' => -30, 'dy' => 0], reqId());
// 期望：视野内收到怪物/掉落物的 entity_enter（跨格进入）
```

原理：`handleMove` 只改坐标；下一帧 `World::update` 的 `updateEntity` 检测到新旧格子不同 → 返回 `entered` 差分 → 双向信封 → `entity_enter` 帧。

### 5.3 移除实体

```php
$world->getAOI()->remove($entity);      // 摘空间索引（O(1)，未登记则静默忽略）
$world->getEntityManager()->remove($id); // 摘实体管理器
// 怪物死亡走此路径（MonsterActor::onDeath 的五处自清理之一）
```

## 6. 验收对照

| 验收项（verify-combat） | AOI 相关断言 |
|---|---|
| 1/4. 怪物/掉落生成 | spawn 时 entered 非空补发 `entity_enter`（附 itemId）；entered 为空时由 `monster:spawned` / `drop:spawned` 承担出生通知 |
| 2. 攻击 | 攻击距离 = 九宫格距离：`aoi->query` 包含目标即在范围内，否则 `combat:error out_of_range` |
| 7. 失败回执 | 移出九宫格后攻击 → `out_of_range`（3×3 脱离） |
