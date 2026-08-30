# ADR-017：Framework 层与完整 Demo 战斗闭环设计

> 状态：已决策（阶段 5 剩余任务实施依据）。续接 ADR-016 已裁决的四个方向点，把「framework 包 + CLI + 完整 Demo 战斗闭环」落地为可实施设计。
> 关联：ADR-016（方向裁决）、ADR-015（社交层细化 / gateway-worker 混合架构）、ADR-001（Engine/Framework 分离）、blueprint `01-架构规范.md`（分层铁律）、`03-实施路线图.md`（铁律 + 冻结清单）、`07-阶段实现清单.md`（阶段 5 任务）。

## 1. 背景

- 四基类只完成 BaseActor（`packages/actor`，`Nythros\Actor\BaseActor`），BasePlayer/BaseMonster/BaseNPC 无；CLI（make:* + bin/server）不存在；插件机制空白。
- Demo 已有：登录/聊天五语义/组队/地图 AOI 移动/帮派最小/进图凭证/掉线恢复/滚动更新/持久化雏形（ArchivePipeline + Storage 抽象）。战斗侧（Monster/AI/技能/掉落/物品）全缺，MapServer 消息路由只有 auth/move，PlayerActor 是空 update 占位。
- 引擎公开接口：`Nythros\Contracts` 11 项（ActorInterface / EntityInterface / WorldInterface / AOIProviderInterface / EventBusInterface / EntityManagerInterface / ActorSystemInterface / SchedulerInterface / ClockInterface / TimerInterface + EventEnvelope 值对象）。
- `Nythros\Entity\BaseEntity` 是 `final` 类：业务实体不能继承它，只能实现 `EntityInterface`（见 §5 战斗闭环的实体策略）。

## 2. 决策总览

1. 新建 `packages/framework`，只依赖 `php>=8.3` + `nythros/contracts` + `nythros/actor`，不依赖 demo、不依赖任何引擎实现包。
2. 四基类 BasePlayer/BaseMonster/BaseNPC 继承 `Nythros\Actor\BaseActor`；战斗状态（hp/aiState）封装在基类内（铁律 6），业务扩展走钩子。
3. Container / Config / EventDispatcher 均为 framework 自实现轻量件，不 import 引擎实现类；EventDispatcher 与引擎 EventBus 职责分层、并行存在。
4. 插件机制 = PluginInterface + PluginRegistry，官方起步 Skill/Item/Buff 三插件（AI/Quest/Inventory 继续后置）。
5. CLI 分两处：`packages/framework/bin/make`（脚手架 make:*）；根 `bin/server`（服务编排壳，start/status/stop，import demo 编排脚本，**不属于 framework**）。
6. 战斗闭环全部落在 demo 层（改造现有 `packages/demo`），Monster 用 BaseEntity 做空间实体 + MonsterActor（extends BaseMonster）持战斗状态；掉落物用 demo 自实现 `DropEntity implements EntityInterface`。
7. 铁律 1 分级落实：framework 零触碰引擎内部；demo 业务类（Combat/MonsterActor/DropEntity/Inventory）只依赖 contracts + framework 基类 + demo 自身接口；引擎实现类的 import 收敛到 demo 组装脚本（bin/ 下）。

## 3. framework 包结构

```
packages/framework/
├── composer.json
├── bin/make                       # 脚手架 CLI 入口（make:actor/skill/event/map）
├── src/
│   ├── BasePlayer.php
│   ├── BaseMonster.php
│   ├── BaseNPC.php
│   ├── Damageable.php          # 战斗可损伤面接口（hp/maxHp/takeDamage/heal/isDead）
│   ├── Container/
│   │   ├── ContainerInterface.php
│   │   └── Container.php
│   ├── Config/Config.php
│   ├── Event/
│   │   ├── EventDispatcherInterface.php
│   │   └── EventDispatcher.php
│   └── Plugin/
│       ├── PluginInterface.php
│       ├── PluginRegistry.php
│       ├── Skill/SkillDefinition.php + SkillRepository.php + SkillPlugin.php
│       ├── Item/ItemDefinition.php  + ItemRepository.php  + ItemPlugin.php
│       └── Buff/BuffDefinition.php  + BuffRepository.php  + BuffPlugin.php
└── tests/
```

### 3.1 composer.json

```json
{
    "name": "nythros/framework",
    "type": "library",
    "require": {
        "php": ">=8.3",
        "nythros/contracts": "@dev",
        "nythros/actor": "@dev"
    },
    "autoload": { "psr-4": { "Nythros\\Framework\\": "src/" } },
    "bin": ["bin/make"]
}
```

根 `composer.json` 增加 `"nythros/framework": "@dev"`（path 仓库已 `packages/*` 通配，自动解析）。

## 4. 四基类设计

类图：

```
Nythros\Contracts\ActorInterface (update())
        ▲
Nythros\Actor\BaseActor (bindEntity + abstract update)
        ▲
   ┌────┴────────────┬─────────────┐
BasePlayer       BaseMonster     BaseNPC
(连接/uid/hp)   (AI 状态机/hp)  (静态/交互)
   └──────┬───────────┘
          │ 共同实现（implements）
   Nythros\Framework\Damageable
   (hp/maxHp/takeDamage/heal/isDead)   ← 战斗最小公共面
```

### 4.0 Damageable（framework，战斗最小公共面）

```php
namespace Nythros\Framework;

/**
 * 可损伤面：玩家与怪物共同实现的最小战斗契约，使 CombatService 的 attack
 * 以统一签名承载「玩家→怪物」与「怪物→玩家」双向攻击（修复 MAJOR-1）。
 */
interface Damageable
{
    public function hp(): int;
    public function maxHp(): int;
    /** 模板方法：扣血钳制归零，归零时幂等触发死亡结算（见 §4.1/§4.2） */
    public function takeDamage(int $amount): void;
    public function heal(int $amount): void;
    public function isDead(): bool;
}
```

### 4.1 BasePlayer（framework）

```php
namespace Nythros\Framework;

use Nythros\Actor\BaseActor;

abstract class BasePlayer extends BaseActor implements Damageable
{
    private ?string $connectionId = null;
    private ?string $uid = null;
    protected int $hp = 100;
    protected int $maxHp = 100;

    // 连接/身份
    public function attachConnection(string $connectionId, string $uid): void;
    public function detachConnection(): void;
    public function connectionId(): ?string;
    public function uid(): ?string;

    // 战斗（Damageable 最小公共面）
    public function hp(): int;
    public function maxHp(): int;
    final public function takeDamage(int $amount): void;  // 模板方法：钳制归零，归零时幂等 onDamaged + onDeath
    public function heal(int $amount): void;
    public function isDead(): bool;                       // hp <= 0

    // update 模板方法：统一做公共帧逻辑后调用 onTick 钩子
    final public function update(): void { $this->onTick(); }

    // 子类覆盖钩子
    protected function onTick(): void {}
    protected function onDamaged(int $amount): void {}
    protected function onDeath(): void {}   // 死亡结算钩子：takeDamage 归零时幂等触发一次（修复 MAJOR-2）
}
```

`takeDamage` 模板方法实现（闭环，修复 MAJOR-2）：

```php
final public function takeDamage(int $amount): void
{
    if ($amount <= 0 || $this->hp <= 0) {
        return;                                // 无效伤害/已死：幂等短路，不重复结算
    }
    $this->hp = max(0, $this->hp - $amount);
    $this->onDamaged($amount);
    if ($this->hp === 0) {
        $this->onDeath();                      // 仅从存活→死亡的那次伤害触发一次
    }
}
```

职责：绑定连接/uid（玩家核心职责）；hp/maxHp 最小战斗面；模板方法 update→onTick；**takeDamage 内闭环死亡结算（onDamaged→onDeath），CombatService 不再跨类调用 onDeath**。level/exp/背包等业务属性不下沉 framework（铁律 8），由 demo 子类扩展。

### 4.2 BaseMonster（framework）

```php
namespace Nythros\Framework;

use Nythros\Actor\BaseActor;

abstract class BaseMonster extends BaseActor implements Damageable
{
    public const STATE_PATROL = 'patrol';
    public const STATE_CHASE  = 'chase';
    public const STATE_ATTACK = 'attack';
    public const STATE_DEAD   = 'dead';

    /** 合法 AI 状态白名单：enterState 迁移校验用 */
    private const VALID_STATES = [self::STATE_PATROL, self::STATE_CHASE, self::STATE_ATTACK, self::STATE_DEAD];

    protected int $hp;
    protected int $maxHp;
    protected string $aiState = self::STATE_PATROL;
    protected ?string $targetId = null;

    public function __construct(string $monsterId, int $maxHp);  // hp = maxHp
    public function monsterId(): string;
    public function hp(): int;
    public function maxHp(): int;
    public function aiState(): string;
    public function targetId(): ?string;
    public function setTarget(?string $targetId): void;
    public function enterState(string $state): void;        // 白名单校验（非法抛 InvalidArgumentException）；DEAD 后不再迁出
    final public function takeDamage(int $amount): void;    // 模板方法：钳制归零，归零时幂等 enterState(DEAD) + onDeath
    public function heal(int $amount): void;                // hp += amount（钳制 maxHp）；DEAD 后不复活（isDead 短路）
    public function isDead(): bool;

    // update 模板方法：按 aiState 分发钩子（DEAD 每帧只走 onDead；onDeath 只在死亡瞬间触发一次）
    final public function update(): void
    {
        match ($this->aiState) {
            self::STATE_PATROL => $this->onPatrol(),
            self::STATE_CHASE  => $this->onChase(),
            self::STATE_ATTACK => $this->onAttack(),
            self::STATE_DEAD   => $this->onDead(),
        };
    }

    protected function onPatrol(): void {}
    protected function onChase(): void {}
    protected function onAttack(): void {}
    protected function onDead(): void {}
    protected function onDeath(): void {}   // 死亡结算钩子（掉落等）：takeDamage 归零时幂等触发一次
}
```

`enterState` 与 `takeDamage` 模板方法实现（闭环，修复 MAJOR-2）：

```php
public function enterState(string $state): void
{
    if (!in_array($state, self::VALID_STATES, true)) {
        // 非法状态显式抛 InvalidArgumentException，而非让 update 的 match 抛 UnhandledMatchError
        throw new \InvalidArgumentException(sprintf('非法 AI 状态: %s', $state));
    }
    if ($this->aiState === self::STATE_DEAD) {
        return;                              // DEAD 为终态：不再迁出
    }
    $this->aiState = $state;
}

final public function takeDamage(int $amount): void
{
    if ($amount <= 0 || $this->hp <= 0) {
        return;                              // 已死/无效伤害：幂等短路，不重复结算
    }
    $this->hp = max(0, $this->hp - $amount);
    if ($this->hp === 0) {
        $this->enterState(self::STATE_DEAD); // 迁移 DEAD（幂等，后续 update 走 onDead）
        $this->onDeath();                    // 死亡结算：仅存活→死亡那次触发一次
    }
}
```

职责：AI 状态机骨架 + hp + 目标；AI 具体决策（巡逻随机/追击/攻击）由 demo 子类在钩子里实现——framework 定义状态与分发，demo 提供行为（依赖倒置，呼应 ADR-016 §7.3）。**死亡结算在基类 takeDamage 内闭环：hp 归零 → enterState(DEAD) + onDeath()，子类只需重写 onDeath 做掉落 roll，CombatService 只做 isDead 判断与广播，不再跨类触碰 onDeath/enterState。**

### 4.3 BaseNPC（framework）

```php
namespace Nythros\Framework;

use Nythros\Actor\BaseActor;

abstract class BaseNPC extends BaseActor
{
    public function __construct(private readonly string $npcId) {}
    public function npcId(): string;

    // 静态：update 默认空操作（不主动行为）
    final public function update(): void { $this->onIdle(); }

    protected function onIdle(): void {}
    // 交互入口（玩家触发）
    public function onInteract(BasePlayer $player): void {}
}
```

## 5. Container / 配置 / EventDispatcher

### 5.1 Container（framework 自实现，轻量）

```php
namespace Nythros\Framework;

interface ContainerInterface
{
    public function get(string $id): mixed;
    public function has(string $id): bool;
    public function remove(string $id): void;   // 插件 uninstall 卸载注册项（修复 MAJOR-6）
}

final class Container implements ContainerInterface
{
    private array $instances = [];   // id => mixed
    private array $factories = [];   // id => callable(): mixed

    public function set(string $id, mixed $value): void;              // 实例
    public function factory(string $id, callable $fn): void;          // 延迟工厂
    public function get(string $id): mixed;                           // 工厂优先，实例其次，未命中抛异常
    public function has(string $id): bool;
    public function remove(string $id): void;                         // 同时清理实例与工厂表项，未命中静默忽略
}
```

### 5.2 Config（PHP 数组文件，零 yaml 依赖，铁律 8）

```php
namespace Nythros\Framework;

final class Config
{
    public function __construct(private array $items) {}
    public static function fromPhpFile(string $path): self;   // require 返回 array
    public function get(string $key, mixed $default = null): mixed;  // 点号路径 a.b.c
    public function has(string $key): bool;
    public function all(): array;
}
```

`deploy.yaml` 保持不动（服务拓扑，DeployConfig 手写解析器已有）；framework Config 只服务应用级配置（怪物刷新表、掉落表等，`config/*.php`）。

### 5.3 EventDispatcher（framework 应用级事件，与引擎 EventBus 分层）

```php
namespace Nythros\Framework;

interface EventDispatcherInterface
{
    public function listen(string $event, callable $listener): void;
    public function removeListener(string $event, callable $listener): void;  // 插件 uninstall 退订（修复 MAJOR-6）
    public function dispatch(string $event, array $payload = []): void;  // 同步即时派发
}

final class EventDispatcher implements EventDispatcherInterface
{
    private array $listeners = [];  // event => list<callable>
    public function listen(string $event, callable $listener): void;
    public function removeListener(string $event, callable $listener): void;  // 按 event 精确移除首个匹配监听器，未命中静默忽略
    public function dispatch(string $event, array $payload = []): void;
}
```

与引擎 EventBus 的关系（关键区分）：
- 引擎 `EventBusInterface`（`SimpleEventBus` @internal）：World 内部帧内事件，AOI enter/leave 信封，publishEnvelope + flush 时序，由 World::update 驱动。
- framework `EventDispatcher`：应用层业务事件（`player.killed` / `item.dropped` / `skill.cast` / `buff.applied`），同步即时派发，供插件与业务组件解耦。
- 两者**并行存在、职责分层**，互不 import；如需把业务事件广播进 AOI 视野，由 demo 组装层桥接，framework 不感知。

## 6. 插件机制

### 6.1 PluginInterface + 生命周期

```php
namespace Nythros\Framework;

interface PluginInterface
{
    /** 插件唯一名，如 'skill' / 'item' / 'buff' */
    public function name(): string;

    /** 加载：向 Container/仓库登记本插件能力、订阅事件（幂等，可重复调用） */
    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void;

    /** 启用：激活运行时行为 */
    public function enable(): void;

    /** 停用：暂停运行时行为（保留注册） */
    public function disable(): void;

    /** 卸载：清理注册与订阅、回收资源 */
    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void;
}

final class PluginRegistry
{
    private array $plugins = [];   // name => PluginInterface

    public function load(PluginInterface $p, ContainerInterface $c, EventDispatcherInterface $d): void; // 调 $p->register
    public function enable(string $name): void;
    public function disable(string $name): void;
    public function uninstall(string $name, ContainerInterface $c, EventDispatcherInterface $d): void;
    public function get(string $name): ?PluginInterface;
    public function all(): array;
}
```

生命周期：`load`（register）→ `enable` → （运行）→ `disable`/`uninstall`。加载与启用分离，支持「先装配全部插件，再统一启用」。**uninstall 具备完整运行时卸载语义（修复 MAJOR-6）：register 期间写入 Container 的注册项经 `ContainerInterface::remove` 清理，订阅的业务事件经 `EventDispatcherInterface::removeListener` 退订——不再退化为「仅进程退出回收，不做运行时卸载」。**

### 6.2 三个官方插件（起步，保持轻量）

```php
// Skill：技能定义注册表（纯数据 + 统一公式，demo 阶段不引入每技能独立类）
final readonly class SkillDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public float $damageMultiplier,  // 相对普攻的倍率
        public float $cooldownSeconds,
        public int $range,               // 作用距离
    ) {}
}

final class SkillRepository
{
    private array $skills = [];          // id => SkillDefinition
    public function register(SkillDefinition $s): void;
    public function get(string $id): ?SkillDefinition;
    public function all(): array;
}

final class SkillPlugin implements PluginInterface { /* name='skill'，register 往 Container 注册 SkillRepository */ }

// Item：物品定义
final readonly class ItemDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,             // 'consumable' | 'material' | 'currency'
    ) {}
}

final class ItemRepository
{
    private array $items = [];           // id => ItemDefinition
    public function register(ItemDefinition $i): void;
    public function get(string $id): ?ItemDefinition;
    public function all(): array;
}

final class ItemPlugin implements PluginInterface { /* name='item' */ }

// Buff：buff 定义
final readonly class BuffDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public float $durationSeconds,
        public array $effects,           // 效果描述（demo 阶段占位，效果结算后置）
    ) {}
}

final class BuffRepository
{
    private array $buffs = [];           // id => BuffDefinition
    public function register(BuffDefinition $b): void;
    public function get(string $id): ?BuffDefinition;
    public function all(): array;
}

final class BuffPlugin implements PluginInterface { /* name='buff' */ }
```

技能执行、物品背包、buff 效果结算均在 demo 层（CombatService / Inventory），framework 只提供定义与注册表——依赖倒置：framework 定义接口与数据，demo 提供行为与实现。

## 7. CLI 设计

### 7.1 make:*（`packages/framework/bin/make`）

手写 argv 解析（不引入 symfony/console，铁律 8）：

```
php vendor/bin/make make:actor Player --kind=monster --ns=Nythros\Demo\Game --out=packages/demo/src/Game
php vendor/bin/make make:skill Fireball --out=packages/demo/config/skills.php
php vendor/bin/make make:event PlayerKilled --out=packages/demo/src/Game
php vendor/bin/make make:map Forest --out=packages/demo/config/maps.php
```

生成模板（示例 `make:actor`，修复 MINOR-8 的 kind 矛盾）：

```php
<?php
// 由 make:actor 生成 — 业务 Actor 骨架（挂载点：MapServer/组装层）
// kind=monster → extends BaseMonster（钩子集：onPatrol/onChase/onAttack/onDead/onDeath）
declare(strict_types=1);

namespace Nythros\Demo\Combat;

use Nythros\Framework\BaseMonster;

final class MonsterActor extends BaseMonster
{
    public function __construct(string $id, int $maxHp = 100)
    {
        parent::__construct($id, $maxHp);
    }

    protected function onPatrol(): void
    {
        // TODO: 巡逻行为（感知视野内最近玩家 → CHASE + setTarget）
    }
}
```

`kind` → 基类 + 钩子集映射（模板渲染的唯一依据）：

| kind | 生成基类 | 可重写钩子集 |
|---|---|---|
| `player` | `BasePlayer` | `onTick` / `onDamaged` / `onDeath` |
| `monster` | `BaseMonster` | `onPatrol` / `onChase` / `onAttack` / `onDead` / `onDeath` |
| `npc` | `BaseNPC` | `onIdle` / `onInteract` |

命令结构：`make.php` 主入口 → `Make\MakeActor` / `Make\MakeSkill` / `Make\MakeEvent` / `Make\MakeMap`，各自负责校验参数 + 渲染模板（`templates/` 目录存模板文件）+ 写入目标路径。

### 7.2 bin/server（根 `bin/server`，统一编排壳，import demo 脚本）

**归属：项目根（不属于 framework——framework 不得 import demo）。** 收敛 launch.php + gateway-worker 三脚本为一条命令。

启动铁序（ADR-015 §4.1）：`Redis(外部) → Register → Gateway → BusinessWorker → Map`。

```
php bin/server start
  ├─ ① 检查 Redis 依赖：连 Redis ping，失败 stderr + exit(1)（Redis 是外部依赖，只检查不拉起）
  ├─ ② spawn Register：  php packages/demo/gateway-worker/start_register.php start      （pid 落盘）
  ├─ ③ spawn Gateway：   php packages/demo/gateway-worker/start_gateway.php start
  ├─ ④ spawn Business：  php packages/demo/gateway-worker/start_businessworker.php start
  ├─ ⑤ spawn Map：       php packages/demo/bin/launch.php                               （deploy.yaml 驱动，内部 spawn 各 map worker）
  └─ ⑥ 写运行清单（服务名 → pid → 日志路径）到 /tmp/nythros-server/run.json

php bin/server status
  └─ 读 run.json → 逐服务 posix_kill(pid, 0) 探活 → 打印 {服务, pid, 状态(running/stopped)}

php bin/server stop
  └─ 逆序优雅停：Map(SIGTERM→launch 转发) → Business(SIGTERM) → Gateway(SIGTERM) → Register(SIGTERM)
     等待 Workerman 优雅 stopAll（≤5s 收尾窗口）→ 清理 run.json
```

实现要点：复用 launch.php 的 `proc_open` + 独立日志文件 + `pcntl` 信号转发先例；Workerman 单实例锁由各脚本既有 pidFile 保证；`bin/server` 自身不 import framework（它编排 demo 具体脚本，属于 demo/根组装层）。

## 8. 完整 Demo 战斗闭环（demo 层）

### 8.1 新增文件

```
packages/demo/src/Combat/
├── CombatService.php     # 伤害/死亡/掉落结算（纯业务可单测）
├── MonsterActor.php      # extends BaseMonster，AI 钩子实现 + 掉落表
├── DropEntity.php        # implements EntityInterface（携带 itemId/count）
├── DropTable.php         # 掉落表（按权重 roll）
├── Inventory.php         # 玩家背包（itemId => count）
├── VisionBroadcasterInterface.php  # 视野/定向广播接口（MapServer 实现；修复 MAJOR-3/5）
├── ActorLookupInterface.php        # 按 entityId 查 Actor（MapServer 以 $actors 表实现；修复 MAJOR-4）
├── EntityTypeIndex.php             # entityId → kind（player/monster）类型索引；修复 MAJOR-4
└── RandomSourceInterface.php       # 随机源（可注入，确定性单测；修复 MAJOR-3）
```

### 8.2 实体策略（BaseEntity 为 final 的约束）

- **Monster**：`BaseEntity`（final 复用，做空间实体）+ `MonsterActor`（extends BaseMonster）持战斗状态（typeId/hp/掉落表）——状态封装在 Actor（铁律 6），Entity 只做身份/位置。
- **ItemDrop**：`DropEntity implements EntityInterface`（组合 Position + itemId/count）——掉落物无行为无 Actor，物品数据只能落在实体上（Entity 的「Components/State」部分）；EntityInterface 只要求 getId/getPosition/move，demo 可自由增字段。
- **Player**：`BaseEntity` + `PlayerActor`（extends BasePlayer）。

**「玩家 vs 怪物 vs 掉落」的区分手段（修复 MAJOR-4）**：由于玩家/怪物都用 `BaseEntity`（final，无法 instanceof 区分），且 `AOIProviderInterface::query` 只返回 `list<EntityInterface>`，感知侧无法从实体对象本身判定种类。demo 维护一张 `EntityTypeIndex`（entityId → kind，kind ∈ `player|monster`），在实体登记处写入：auth → `player`、`spawnMonster` → `monster`，摘除时同步删除。MonsterActor 感知视野时经 `EntityTypeIndex::kindOf($neighborId)` 判定邻居是玩家还是怪物，而不是 instanceof BaseEntity。**掉落物是 demo 自身类 `DropEntity`（implements EntityInterface），可直接 `instanceof DropEntity` 判定，无需登记 typeIndex**——typeIndex 仅为区分「玩家 vs 怪物」而设（两者共用 final BaseEntity 无法 instanceof），drop 是独立类可直接 instanceof（用于 AOI enter 广播附加 itemId，见 §8.7）。

### 8.3 CombatService（demo，纯业务，修复 MAJOR-1/3）

```php
namespace Nythros\Demo\Combat;

final class CombatService
{
    public function __construct(
        private readonly WorldInterface $world,                    // EntityManager/AOI/ActorSystem 门面（修复 MAJOR-3）
        private readonly VisionBroadcasterInterface $broadcaster,  // 视野/定向广播接口（MapServer 实现）
        private readonly SkillRepository $skills,                  // framework 插件
        private readonly ItemRepository $items,                    // framework 插件
        private readonly RandomSourceInterface $random,            // 随机源（可注入，确定性单测）
    ) {}

    /** 普攻结算（双向：玩家→怪物 / 怪物→玩家，修复 MAJOR-1） */
    public function attack(Damageable $attacker, Damageable $target): void;
    // 伤害 = 基础 × random 浮动 → target->takeDamage（基类模板方法内闭环死亡结算）
    // → broadcaster 广播 combat:hit → target->isDead() 则广播 entity_dead（Actor 移除由 MonsterActor.onDeath / 调用方负责）
    // 冷却/距离/存活前置校验在调用方（MapServer.handleAttack / MonsterActor.onAttack）完成

    /** 技能结算 */
    public function castSkill(Damageable $caster, string $skillId, Damageable $target): void;
    // 查 SkillRepository → 伤害 = 普攻 × damageMultiplier × random 浮动 → 同 attack 结算
    // 冷却/距离前置校验在调用方（MapServer.handleSkillCast）完成

    /** 死亡掉落：在 monsterId 实体位置生成 DropEntity（itemId/count），经 world 注册 EntityManager + AOI */
    public function spawnDrops(string $monsterId, array $position, array $drops): void;

    /** 拾取：经 world 摘除 DropEntity（AOI remove + EntityManager remove）→ Inventory::add → broadcaster 广播 */
    public function pickup(Damageable $player, DropEntity $drop, Inventory $inventory): void;
}
```

### 8.3a demo 侧战斗支撑接口（修复 MAJOR-3/4/5）

```php
namespace Nythros\Demo\Combat;

use Nythros\Contracts\ActorInterface;

/** 视野/定向广播接口：CombatService 依赖它出帧，MapServer 实现（持有 Outbox + connections + registry） */
interface VisionBroadcasterInterface
{
    /** 向 centerEntityId 视野内的全部连接广播一帧（帧末 flush） */
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void;
    /** 定向发送一帧给某 entityId 对应连接（拾取者/攻击发起者回执） */
    public function sendToEntity(string $entityId, string $type, array $payload): void;
}

/** 按 entityId 查 Actor：MonsterActor 解析目标 PlayerActor 用；MapServer 以 $actors 表实现（玩家+怪物都登记） */
interface ActorLookupInterface
{
    public function getActor(string $entityId): ?ActorInterface;
}

/** 随机源：伤害浮动/掉落 roll 用，可注入确定实现做单测 */
interface RandomSourceInterface
{
    public function randomInt(int $min, int $max): int;
}
```

`EntityTypeIndex`（demo 具体类，非接口）：

```php
namespace Nythros\Demo\Combat;

final class EntityTypeIndex
{
    public const KIND_PLAYER = 'player';
    public const KIND_MONSTER = 'monster';

    public function set(string $entityId, string $kind): void;   // 登记处写入（auth/spawnMonster）
    public function remove(string $entityId): void;              // 摘除处同步删除（cleanup/死亡）
    public function kindOf(string $entityId): ?string;           // 未登记返回 null
}
```

### 8.4 MonsterActor（demo，AI 钩子实现）

```php
namespace Nythros\Demo\Combat;

final class MonsterActor extends BaseMonster
{
    public function __construct(
        string $monsterId,
        int $maxHp,
        private readonly WorldInterface $world,                   // contracts 接口（感知 AOI）
        private readonly CombatService $combat,
        private readonly DropTable $dropTable,
        private readonly ActorLookupInterface $actorLookup,       // 解析目标 PlayerActor（修复 MAJOR-4）
        private readonly EntityTypeIndex $typeIndex,              // 区分玩家/怪物（修复 MAJOR-4）
        private readonly VisionBroadcasterInterface $broadcaster, // PATROL/CHASE 移动后广播 entity_moved（修复 MINOR-6）
    ) { parent::__construct($monsterId, $maxHp); }

    protected function onPatrol(): void
    {
        // 感知视野内最近玩家（$typeIndex->kindOf($neighborId) === 'player'）→ 有则 CHASE + setTarget
        // 无则随机/路径点巡逻移动（entity->move(dx,dy)）→ broadcaster 广播 entity_moved（含新位置，跳过自己，修复 MINOR-6）
    }
    protected function onChase(): void
    {
        // 目标丢失 → PATROL；距离进入攻击范围 → ATTACK
        // 朝目标方向 move 一格（简化）→ broadcaster 广播 entity_moved（含新位置，与玩家移动一致）
    }
    protected function onAttack(): void
    {
        // 攻击冷却判定 → 解析目标：$target = $this->actorLookup->getActor($this->targetId)
        // → $target instanceof BasePlayer 则 $this->combat->attack($this, $target)（怪物反向攻击玩家）
    }
    protected function onDeath(): void
    {
        // 由 BaseMonster::takeDamage 模板方法在 hp 归零时幂等触发一次（不再由 CombatService 跨类调用）：
        // combat->spawnDrops($this->monsterId(), $this->entity?->getPosition() ?? ['x'=>0,'y'=>0], $this->dropTable->roll())
        // combat->broadcastDeath() 广播 entity_dead；随后死亡自清理：world->getActorSystem()->remove($this) + typeIndex->remove($this->monsterId())
        //（掉落物 spawn 走 AOI enter → entity_enter 附 itemId，见 §8.7）
    }
}
```

### 8.5 PlayerActor 改造（demo）

现有 `PlayerActor`（final extends BaseActor，空 update）→ 改为 `extends BasePlayer`：

```php
namespace Nythros\Demo;

use Nythros\Framework\BasePlayer;

final class PlayerActor extends BasePlayer
{
    public function __construct(private readonly string $entityId) {}

    protected function onTick(): void
    {
        // 攻击冷却递减 / 死亡后处理（demo 阶段简化：无自然回复或缓回）
    }
    protected function onDamaged(int $amount): void { /* 触发属性同步帧 */ }
    protected function onDeath(): void { /* 玩家死亡：标记待复活/回出生点 */ }
}
```

### 8.6 MapServer 战斗路由扩展

`dispatchSafe` 已认证分支从「只 move」扩展为「move / attack / skill:cast / pickup」：

```php
if ($this->registry->has($conn->getId())) {
    switch ($message->type) {
        case 'move':       $this->handleMove($conn, $message); return;
        case 'attack':     $this->handleAttack($conn, $message); return;
        case 'skill:cast': $this->handleSkillCast($conn, $message); return;
        case 'pickup':     $this->handlePickup($conn, $message); return;
    }
    // 其余 404 宽容不关闭（沿用现口径）
}
```

`handleAttack`（前置校验 + 失败回执，修复 MAJOR-4/5）：

1. 校验 `payload.targetId` → registry 反查攻击方 `entityId` → 攻击方 Actor 必须 `instanceof BasePlayer`（否则拒绝）。
2. 目标解析：`ActorLookupInterface::getActor($targetId)`（MapServer 以既有 `$actors` 表实现，玩家+怪物都登记）→ 目标必须 `instanceof Damageable`、非自身、存活（`!isDead()`）。
3. 前置校验：距离（攻击方/目标实体九宫格或指定格内）、冷却（攻击方冷却表）——命中失败回执 `combat:error{code, message}`（定向发起者），**不关闭连接**（修复 MAJOR-5 缺失败回执）。
4. 结算：`combatService->attack($attacker, $target)`（双向签名；怪物→玩家经 AI 侧 `MonsterActor.onAttack` 调 attack，不走此路由）。

类型收窄原则：demo 层对自身 Actor/实体体系做类型判断（`instanceof BasePlayer` / `instanceof BaseMonster` / `instanceof DropEntity`），不 instanceof 引擎 @internal 类。

`ActorLookupInterface` 与 `EntityTypeIndex` 的登记处（修复 MAJOR-4）：
- `auth` 成功：`EntityTypeIndex::set($entityId, 'player')` + `$actors[$entityId] = $playerActor`（既有行为）。
- 怪物 spawn（demo 组装层/地图初始化）：`EntityTypeIndex::set($monsterEntityId, 'monster')` + `$actors[$monsterEntityId] = $monsterActor`。
- `spawnDrops`：不登记 `EntityTypeIndex`——drop 判定统一走 `instanceof DropEntity`（demo 自身类，implements EntityInterface），无需 typeIndex 登记（掉落无 Actor，不入 `$actors` 表）。
- 摘除（cleanup/死亡）同步 `EntityTypeIndex::remove` 与 `unset($actors[...])`；掉落物摘除（拾取）不涉及 typeIndex（经 instanceof DropEntity 判定）。

### 8.7 战斗消息协议（type + payload）

| 方向 | type | payload | 说明 |
|---|---|---|---|
| 上行 | `attack` | `{targetId}` | 普攻目标 |
| 上行 | `skill:cast` | `{skillId, targetId}` | 施放技能 |
| 上行 | `pickup` | `{dropId}` | 拾取掉落 |
| 下行 | `combat:hit` | `{attackerId, targetId, damage, hp}` | 伤害结算（视野广播） |
| 下行 | `combat:error` | `{code, message}` | 战斗失败回执：目标无效/距离/冷却失败（定向发起者，修复 MAJOR-5） |
| 下行 | `entity_moved` | `{id, position}` | 实体移动（玩家与怪物一致，视野广播，跳过自己；怪物 PATROL/CHASE 移动后由 MonsterActor 广播，修复 MINOR-6） |
| 下行 | `entity_dead` | `{id}` | 实体死亡（视野广播） |
| 下行 | `monster:spawned` | `{id, typeId, position}` | 怪物生成（视野广播，修复 MAJOR-5） |
| 下行 | `drop:spawned` | `{dropId, itemId, x, y}` | 掉落生成（视野广播） |
| 下行 | `drop:removed` | `{dropId}` | 掉落移除（视野广播） |
| 下行 | `item:added` | `{itemId, count}` | 拾取入包（定向拾取者） |
| 下行 | `skill:cast` | `{casterId, skillId, targetId}` | 技能施放广播（视野） |
| 下行 | `player:stats` | `{hp, maxHp}` | 玩家属性同步（定向） |

**entity_enter 与专用帧的语义分工（修复 MAJOR-5）**：

- `entity_enter`：通用「进入视野」帧（id + position），覆盖所有实体（玩家/怪物/掉落）的跨格进入与 join 快照；对掉落物额外附加 `itemId`（MapServer 的 `handleAoiVisibility` 内经 demo 自身 `instanceof DropEntity` 判定，非引擎类），保证掉落物进入视野与 `drop:spawned` 信息等价。
- `monster:spawned` / `drop:spawned`：专用「生成」帧，仅在 spawn 瞬间广播一次，携带类型信息（怪物 `typeId` / 掉落 `itemId`），弥补 entity_enter 不带类型导致客户端无法渲染造型/图标的问题。
- 分工：`spawned` 是「出生事件」（一次性、带类型），`enter` 是「可见事件」（通用、跨格触发）；掉落物 spawn 后立即进入当前视野邻居走 entity_enter（附 itemId），跨格移动进入他人视野只走 entity_enter（附 itemId），不重复发 `drop:spawned`——二者职责不同、不冗余。
- `entity_moved`：统一「移动」帧（id + position），玩家（MapServer.handleMove）与怪物（MonsterActor PATROL/CHASE 移动后）一致发出，视野广播、跳过自己——修复 MINOR-6 怪物同格位移不可见的问题。

### 8.8 战斗闭环数据流

```
玩家 A attack{targetId=monster-1}
  → MapServer.handleAttack（前置校验：目标解析 + 距离/冷却/存活；失败回 combat:error{code,message}）
  → CombatService.attack(Damageable(A), Damageable(monster-1))
      → 伤害结算 → target->takeDamage(dmg)   [MonsterActor.hp 减少；基类模板方法闭环死亡结算]
           └─ hp 归零 → enterState(STATE_DEAD) + onDeath()（幂等一次，由 takeDamage 模板方法触发）
                └─ MonsterActor.onDeath：combat->spawnDrops(monsterId, pos, dropTable->roll())
                     → DropEntity 经 world->getEntityManager()->add + world->getAOI()->updateEntity（返回 entered 差分）
                     → broadcaster 广播 drop:spawned（掉落物生成）；视野内邻居走 AOI enter → entity_enter(附 itemId)
                     （monster:spawned 在怪物生成/重生时广播，属地图初始化路径，非死亡路径）
                     → 死亡自清理：world->getActorSystem()->remove($this) + typeIndex->remove(monsterId)
      → broadcaster 广播 combat:hit{damage, hp}（视野内入 Outbox，帧末批量发送）
      → target->isDead()? 则广播 entity_dead（Actor 移除已由 onDeath 自清理完成）
  →（怪物 AI 侧，每帧 world->update 驱动 MonsterActor.update → onAttack
      → actorLookup->getActor(targetId) 解析目标 PlayerActor → combat->attack(monster, player) 反向攻击玩家）

玩家 A pickup{dropId}
  → MapServer.handlePickup
  → CombatService.pickup(A, DropEntity, Inventory)
      → world->getAOI()->remove + world->getEntityManager()->remove（摘掉落物）
      → Inventory.add(itemId, 1)
      → broadcaster 广播 drop:removed（视野）+ item:added（定向 A）
      → ArchivePipeline.markDirty(uid, 玩家状态含背包)   [持久化雏形复用]
  → 30s 兜底 saveBatch → MySqlStorage 落库
```

## 9. 铁律 1 落实（依赖方向 + @internal 清单）

### 9.1 依赖方向图

```
┌────────────┐  组装层：import 引擎实现类（组装 World）+ framework 基类 + contracts
│    demo    │  业务类只依赖 contracts + framework 基类 + demo 自身接口
└─────┬──────┘
      │ depends on
┌─────▼──────┐  只 import：contracts（接口）+ actor 的 BaseActor（公开基类）
│ framework  │  不 import：demo 任何类、引擎任何 @internal 实现
└─────┬──────┘
      │
┌─────▼──────┐
│   actor    │  BaseActor（公开，可被 framework 依赖）+ SimpleActorSystem（@internal）
└─────┬──────┘
      │
┌─────▼──────┐
│ contracts  │  纯接口层（全部公开）
└────────────┘
```

### 9.2 @internal 清单（framework 严禁 import；demo 业务类严禁 import；仅 demo 组装脚本可 import）

| 包 | @internal 实现类 |
|---|---|
| kernel | `SystemClock` |
| scheduler | `SimpleScheduler` / `RegionScheduler` / `TickScheduler` / `TimerWheel` / `TaskQueue` |
| world | `World` / `SimpleEntityManager` |
| actor | `SimpleActorSystem`（`BaseActor` 为公开，排除） |
| event | `SimpleEventBus` |
| aoi | `GridAOI` |
| entity | `BaseEntity` / `Position` |
| kernel-workerman | `WorkermanClock` / `WorkermanTimer` |
| network | `SimpleTokenBucket`（`ConnectionInterface` / `ServerInterface` / `RateLimiterInterface` 为公开，排除） |
| network-workerman | `WorkermanWebSocketServer` / `ConnectionManager` / `WorkermanConnection`（修复 MINOR-7 补全） |
| security | `RedisTokenStore` / `TokenManager` / `InMemoryTokenStore`（`TokenStoreInterface` 为公开，排除；修复 MINOR-7 补全） |
| cluster | `RedisServiceRegistry` |
| persistence | `MySqlStorage` / `InMemoryStorage` |

**protocol 为公共协议层（非 @internal，修复 MINOR-7）**：`Frame` / `FrameInterface` / `Message` / `SerializerInterface` / `JsonSerializer` / `DecodeException` / `ProtocolException` 全部公开，framework 之外的 demo 业务类与 MapServer 可直接 import（当前 MapServer 已 import `Frame`/`Message`/`SerializerInterface`/`DecodeException`，属合法公开依赖）。协议层不夹带任何引擎实现细节，故不入 @internal 清单。

**判定口径（正向白名单为硬门禁，负向清单为辅助，修复 MINOR-7）**：framework 的 import 判定以 §9.3 第 1 条正向白名单为准——只允许 `Nythros\Contracts\*` + `Nythros\Actor\BaseActor`；上表负向清单用于 demo 业务类与组装脚本的边界自检，两表互补、正向优先。

### 9.3 分级铁律（明确「组装边界」）

1. **framework**（硬门禁）：零触碰 @internal；只 import `Nythros\Contracts\*` + `Nythros\Actor\BaseActor`（正向白名单，唯一判据，修复 MINOR-7——负向清单仅为辅助自检，正向优先）。
2. **demo 业务类**（`src/Combat`、`src/Social/SocialService`、改造后 `PlayerActor` 等）：只依赖 contracts 接口 + framework 基类 + demo 自身接口（CombatService 依赖 `WorldInterface` + 自建 `VisionBroadcasterInterface`/`RandomSourceInterface` + framework 的 `SkillRepository`/`ItemRepository`/`Damageable`；MonsterActor 依赖自建 `ActorLookupInterface`/`EntityTypeIndex`/`VisionBroadcasterInterface`——均不 import `GridAOI`/`World`/`Outbox` 等 @internal）。
3. **demo 组装脚本**（`bin/run-worker.php` / `bin/launch.php` / 根 `bin/server` / `gateway-worker/start_*`）：唯一允许 import @internal 的位置——引擎必须由某处实例化，组装脚本即该处（ADR-001「demo 是唯一允许依赖具体实现的组装层」）。

MapServer 现状 import `BaseEntity`/`Position`（连接/实体生命周期组装核心），保留为组装性质，但新增战斗逻辑不写入 MapServer（委托 CombatService），逐步把「实体创建」收敛为注入工厂（可选债务，见 §11）。

## 10. 实现顺序（依赖在前）

```
① framework 包骨架：composer.json + 四基类 + Container + Config + EventDispatcher（含单测）
② 插件机制：PluginInterface + PluginRegistry + Skill/Item/Buff 三插件（含单测）
③ CLI：framework/bin/make（make:actor/skill/event/map）+ 根 bin/server（start/status/stop）
④ 完整 Demo 战斗闭环：CombatService + MonsterActor + DropEntity + DropTable + Inventory
   + MapServer 战斗路由 + PlayerActor 改造（extends BasePlayer）+ 持久化接线
⑤ 10 客户端验收：扩展 verify-phase5.php 或新增 verify-combat.php（战斗 8 项验收）
```

## 11. 自检结果（检查清单）

1. **framework 不碰 Engine 内部（铁律 1，第 6 项重点）**：framework composer 只声明 contracts + actor；import 以正向白名单（`Nythros\Contracts\*` + `Nythros\Actor\BaseActor`）为硬门禁；§9.2 负向清单补全（`ConnectionManager`/`WorkermanConnection`/`SimpleTokenBucket`/`InMemoryTokenStore`）后核查零越界；protocol 明示为公共协议层（非 @internal）。✓
2. **framework 不 import demo**：framework 无 `Nythros\Demo\*` 引用；新增 `Damageable` 接口（framework 自持）供 BasePlayer/BaseMonster 共同实现，战斗结算由 demo 的 CombatService 消费（依赖倒置，framework 定义契约、demo 提供实现）。✓
3. **依赖方向单向**：framework → contracts/actor；demo → framework + 引擎实现；引擎不反向依赖 framework（引擎包零 framework 引用）。✓
4. **契约完整性（数据流对称）**：上行 attack/skill:cast/pickup 均有对应下行（combat:hit/combat:error/entity_dead/item:added）；下行补 `monster:spawned`（带 typeId）与 `combat:error`（失败回执）；entity_enter 对 DropEntity 附 itemId，entity_enter 与 drop:spawned/monster:spawned 双路径语义分工明确、信息等价（§8.7）。✓
5. **四基类职责清晰（边界条件）**：BasePlayer=连接/hp、BaseMonster=AI 状态机/hp、BaseNPC=静态/交互；死亡结算闭环在基类 takeDamage 模板方法（hp 归零幂等触发 onDeath + enterState(STATE_DEAD)），enterState 白名单校验非法状态抛 InvalidArgumentException（不再 UnhandledMatchError）；CombatService 只做 isDead 判断与广播，不再跨类触碰 protected 钩子。✓
6. **插件生命周期完整（可卸载）**：register/enable/disable/uninstall 四态 + 加载/启用分离；ContainerInterface 增 `remove`、EventDispatcherInterface 增 `removeListener`，uninstall 具备运行时卸载语义（清理注册 + 退订），不再退化为「仅进程退出回收」。✓
7. **CLI 不引入重依赖**：make:* 手写 argv 解析；bin/server 复用 proc_open + pcntl 先例；不引入 symfony/console、不引入 yaml 库。✓
8. **持久化复用**：战斗闭环掉落/背包走既有 ArchivePipeline + StorageInterface，不新建存储机制。✓

## 12. 待确认点

1. `bin/server` 落点：项目根 `bin/server`（本设计采用）vs `packages/demo/bin/server`；影响阶段 6 create-project 入口形态。
2. 怪物重生策略：战斗闭环不自动重生（死亡即移除）是否满足验收；简单重生 Timer（固定刷新点 + 间隔）是否阶段 5 内实现。
3. 货币/任务：战斗闭环暂不含货币系统（gold 作为特殊 itemId 承载）与任务系统（Quest 后置 Plugin）；确认是否阶段 5 门禁项。
4. 玩家死亡行为：demo 阶段「标记待复活/回出生点」的粒度（仅状态标记 vs 完整回城流程）。
5. MapServer 实体创建的工厂化债务是否本次消化（§9.3 第 3 条），还是记录为后续债务。
6. 战斗验收脚本落点：扩展 `verify-phase5.php` vs 新增 `verify-combat.php`（建议新增，隔离社交/战斗两链路）。
7. framework 的 `BaseMonster` AI 用「钩子模板方法」而非「注入 MonsterAIInterface」——AI 接口后置为官方 Plugin（ADR-016 §3.1）的取舍是否接受。
8. `Damageable` 契约边界（本次修复后遗留）：接口仅五方法（hp/maxHp/takeDamage/heal/isDead），不含身份/位置，攻击冷却/距离校验前置到 demo 路由层（MapServer.handleAttack/handleSkillCast）与 AI 层（MonsterActor.onChase/onAttack）；若后续要把冷却/距离下沉进 CombatService，需扩展 Damageable（增 entityId/position）或引入独立身份契约——本次是否接受该边界。
9. 怪物 spawn 组装落点：`EntityTypeIndex` + `$actors` 的怪物登记在 demo 组装层完成，具体脚本（MapServer 启动钩子 vs launch.php 地图数据加载）待实现时确定。
