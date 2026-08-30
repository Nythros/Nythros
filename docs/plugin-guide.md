# 玩法开发指南：从零写一个插件

> 面向读者：要用 Nythros 做新玩法的开发者。读完你能：理解插件四态生命周期、区分两种插件形态、
> 独立写出一个可装配、可测试、可退订的玩法插件，并把它接进 demo 装配层跑通 E2E。
> 本教程的随教产物是一个真实插件：`packages/demo/src/Plugin/AnnouncerPlugin.php`（击杀播报计数），
> 全部代码可对照阅读。

## 0. 前置：插件放在哪一层

三层 monorepo（`packages/engine` / `framework` / `demo`）的分工铁律（ADR-017/ADR-020）：

| 层 | 放什么 | 例子 |
|---|---|---|
| engine | 引擎原语（AOI/Actor/调度/网络） | GridAOI、RegionScheduler |
| framework | 玩法**机制与规则**：插件契约、官方插件、值对象、纯函数规则 | PluginInterface、SkillPlugin、MmorpgConfig |
| demo（starter-kit） | **装配与业务动作**：你的玩法插件、频道组装、验收脚本 | AnnouncerPlugin、MapChannelFactory |

**你的业务插件写应用层**（如 `packages/demo/src/Plugin/`），依赖 framework 的契约而非实现——
这样框架升级时插件面稳定。反过来，framework 侧的官方插件（Skill/Item/Buff/Mmorpg/Horde）是
「机制长什么样」的权威范例，写插件前建议先通读 `packages/framework/src/Plugin/`。

## 1. 四态生命周期

每个插件实现 `Nythros\Framework\Plugin\PluginInterface`：

```php
interface PluginInterface
{
    public function name(): string;                              // 唯一名，如 'announcer'
    public function register(ContainerInterface $c, EventDispatcherInterface $d): void; // load
    public function enable(): void;                              // 激活运行态
    public function disable(): void;                             // 暂停运行态（保留注册）
    public function uninstall(ContainerInterface $c, EventDispatcherInterface $d): void; // 完整卸载
}
```

- **load（register）→ enable 分离**：先装配全部插件、再统一 enable。register 必须**幂等**——
  重复调用不重建服务、不重置状态、不重复订阅。
- **register 订阅的事件，uninstall 必须退订**（见 §3 闭包引用约定）。
- **disable 是暂停不是卸载**：Container 注册项保留，运行行为静默（事件照收但业务不生效）。
- 生命周期由 `PluginRegistry` 驱动（`load()` → `enable()`；`uninstall()` 逆序清理），见
  `packages/framework/src/Plugin/PluginRegistry.php`。

## 2. 两种插件形态

### 2.1 仓库型：向容器注册一个能力（SkillPlugin 范式）

Skill 插件做的事：① 把 `SkillRepository` 以 `'skill.repository'` 放进 Container；② 订阅
`skill.cast` 事件作为退订机制示范。业务侧（demo 的 CombatService）从容器解析仓库查询技能定义——
**框架只提供定义与注册表，技能执行逻辑在 demo 层**（依赖倒置）。

```php
final class SkillPlugin implements PluginInterface
{
    private const REPOSITORY_ID = 'skill.repository';

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->repository ??= new SkillRepository();          // 幂等：已有实例不重建
        $container->set(self::REPOSITORY_ID, $this->repository);
        // ... 订阅事件（保存句柄，uninstall 退订）
    }
    // ...
}
```

### 2.2 配置型：注册一份玩法参数（MmorpgPlugin 范式）

Mmorpg 插件是「配置型插件」样板——插件本体只做一件事：把玩法配置以约定 id 注册进 Container，
starter-kit 组装层解析后注入消费方（MapServer 的威胁表/重生器接线）：

```php
final class MmorpgPlugin implements PluginInterface
{
    public const CONFIG_ID = 'mmorpg.config';

    public function __construct(?MmorpgConfig $config = null) { $this->config = $config; }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->config ??= MmorpgConfig::default();            // 构造未给 → 缺省配置
        $container->set(self::CONFIG_ID, $this->config);
    }
    // enable/disable/uninstall：配置型插件无独立运行态，占位
}
```

`MmorpgConfig` 自身是**不变量收敛的值对象**（构造期 fail-fast 校验，见
`packages/framework/src/Game/Mmorpg/MmorpgConfig.php`）——玩法参数的合法性在构造期锁死，插件层零校验。
**选型判断**：插件只是「给装配层递参数」→ 配置型；插件拥有可查询的能力（仓库/服务）→ 仓库型；
两者可组合（Horde 插件注册配置，装配层解析后同时注入 RoomHub 与 MapServer）。

## 3. 事件订阅与退订（最容易踩的坑）

PHP 闭包每次字面量求值都是**新实例**，`removeListener` 按引用精确匹配。所以：

```php
// register 里：保存句柄
$this->listener = function (array $payload): void { /* ... */ };
$dispatcher->listen(self::KILL_EVENT, $this->listener);

// uninstall 里：用同一引用退订——重新写一遍闭包字面量将因引用不一致而无法退订！
if ($this->subscribed && $this->listener !== null) {
    $dispatcher->removeListener(self::KILL_EVENT, $this->listener);
}
```

事件负载键是权威契约：如 `combat.kill` 的 payload 为
`{killerUid, victimId, monsterId, monsterTypeId}`（见 `CombatService::notifyKill`）——
订阅方按这些键消费，不要猜。

## 4. 实战：五步写一个玩具插件

教程随教产物：**击杀播报插件**——订阅击杀事件，按攻击者计数，enable/disable 门控计数。
以下每一步对应 `packages/demo/src/Plugin/AnnouncerPlugin.php` 里的真实代码。

**第 1 步 · 骨架**：实现 PluginInterface，声明两个约定 id（服务 id + 事件名）：

```php
final class AnnouncerPlugin implements PluginInterface
{
    public const SERVICE_ID = 'announcer.service';
    private const KILL_EVENT = CombatService::EVENT_KILL;   // 'combat.kill'

    public function name(): string { return 'announcer'; }
}
```

**第 2 步 · register**：把自己作为服务注册进 Container（计数器即插件实例自身的 `kills` 表），
并订阅事件（保存句柄）：

```php
public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
{
    $container->set(self::SERVICE_ID, $this);                 // 幂等：重复 register 不重建
    if ($this->subscribed) {
        return;                                               // 幂等：不重复订阅
    }
    $this->listener = function (array $payload): void {
        if (!$this->active) { return; }                       // disable 期间事件照收但不计数
        $killerUid = $payload['killerUid'] ?? null;
        if (is_string($killerUid) && $killerUid !== '') {
            $this->kills[$killerUid] = ($this->kills[$killerUid] ?? 0) + 1;
        }
    };
    $dispatcher->listen(self::KILL_EVENT, $this->listener);
    $this->subscribed = true;
}
```

**第 3 步 · enable/disable 门控**：运行行为开关（暂停语义——注册保留、事件照收、业务静默）：

```php
public function enable(): void  { $this->active = true; }
public function disable(): void { $this->active = false; }
```

**第 4 步 · uninstall**：同引用退订 + Container 清理 + 状态回收（完整卸载语义）：

```php
public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
{
    if ($this->subscribed && $this->listener !== null) {
        $dispatcher->removeListener(self::KILL_EVENT, $this->listener);
    }
    $this->subscribed = false;
    $this->listener = null;
    $this->kills = [];
    $this->active = false;
    $container->remove(self::SERVICE_ID);
}
```

**第 5 步 · 装配接线**：在 starter-kit 组装层（`MapChannelFactory::attachChannel`）load + enable：

```php
$pluginRegistry->load(new AnnouncerPlugin(), $container, $dispatcher);
// ...
$pluginRegistry->enable('announcer');
```

完成。此时每次玩家击杀都会被计数，`$container->get('announcer.service')` 可随时查询。

### 设计红线（教程插件刻意示范的边界）

- **不广播协议帧**：新增帧/字段必须同步 `FrameType::nameToCode` 与 `PayloadKey` 词表
  （DIVISOR=83，已发布码永不复用），否则服务端 ProtocolException 会断开全部客户端
  （blueprint/23 发现 2）。教程插件的副作用刻意只到服务内部状态为止。
- **不改 framework**：业务插件放应用层；发现框架机制缺口时先出提案再动框架。
- **Container id 命名**：沿用官方插件约定 `<域>.<物>`（`skill.repository` / `mmorpg.config` /
  `announcer.service`）。

## 5. make 脚手架

`php vendor/bin/make` 提供骨架生成（当前 `make:actor` 一族）：

```bash
php vendor/bin/make make:actor BossActor --kind=monster --ns=Nythros\\Demo\\Game --out=packages/demo/src/Game
```

- `--kind` 决定继承基类与钩子集：`player`（BasePlayer：onTick/onDamaged/onDeath）、
  `monster`（BaseMonster：onPatrol/onChase/onAttack/onDead/onDeath）、`npc`（BaseNPC：onIdle/onInteract）；
- 模板在 `packages/framework/templates/actor/{player,monster,npc}.php.tpl`，生成的是**可跑的骨架**
  （钩子空实现 + 契约注释），行为靠你填；
- 生成的 Actor 经装配层接线后才进入世界（参照 `MapServer::spawnMonster` 对 MonsterActor 的组装：
  EntityManager/AOI/ActorSystem/typeIndex 四处登记缺一不可）。

## 6. 怎么验收一个插件

两层验收口径，教程产物两条都过了（blueprint/25）：

**① 生命周期集成测试**（快，锁行为）：见 `packages/demo/tests/AnnouncerPluginTest.php`——
register 幂等（重复 register 不重复订阅，同一击杀不双计）、enable 前不计数、disable 暂停、
uninstall 后再 dispatch 不计数且 Container 已清理。新插件照此写一个测试类即可。

**② 线缆级 E2E**（真实链路）：插件接进装配层后，跑既有 E2E 全绿证明插件与整链共存无回归：

```bash
# WSL 内起服（mmorpg + 玩法批）
NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 php bin/server start
# 另一终端跑验收
php packages/demo/bin/verify-mmorpg.php     # 期望 RESULT: PASSED (PASS=11 FAIL=0)
```

**验收脚本写法**（给新玩法写 verify 脚本时的骨架，完整范例 `packages/demo/bin/verify-mmorpg.php`）：

- 脚本本体是一个 Workerman `Worker`（`onWorkerStart` 里驱动步骤状态机），经
  `AsyncTcpConnection` 连 gateway(18285，JSON 文本) 登录拿 token → 连 Map(18081，二进制批量，
  编解码用 `bin/lib/map-codec.php`) 发 auth；
- 步骤 = 注册到 `$steps` 表的 `[名称, 处理函数, 超时秒]`，处理函数用 `waitMapFrame`（事件驱动、
  非阻塞）推进相位，断言用 `check(bool, 描述)` 汇总，`closeStep('PASS'|'FAIL', 摘要)` 收步；
- 断言尽量读**服务器权威帧**（quest:rows/combat:hit 等），对位移/时序敏感的步骤先发探针请求
  确定基准（见 step4 的复活探针注释——E2E 时序竞态的实测教训）；
- 注意：任务进度持久化在 Redis（`nythros:gw:quest:<uid>`），复跑前清键；每轮验收用全新启动的
  服务器（blueprint/24 口径）。

> verify 脚本的 inbox/wait/step 骨架已抽为公共库 `packages/demo/bin/lib/verify-framework.php`，
> 新验收脚本直接复用即可；断言口径与状态清理铁律不变。

## 7. 下一步

- 状态同步的客户端侧（插值、帧语义、快照重同步）见 [docs/state-sync.md](state-sync.md)；
  官方 JS SDK 的插值参考实现在 `packages/client-js/nythros-client.js`（NythrosInterpolator）。
- 玩法数据表（技能/掉落/怪物）如何数据化外置见 [docs/quick-start.md](quick-start.md) §3.3
  （ConfigRepository + schema 校验 + 热载）。
