# Nythros Actor 指南（Actor Guide）

> 本文介绍 Actor 模型、四个基类的职责与钩子、模板方法模式，以及如何用 `make:actor` 生成骨架并实现业务逻辑。

## 1. Actor 模型

**Entity 与 Actor 是两个概念**（蓝图 §5）：

```text
Entity = 身份 + 位置 + 状态 + 生命周期（没有行为，没有 Tick）
Actor  = 行为 + Tick（一个 Actor 可以绑定一个 Entity，也可以不绑定）

玩家   = Entity + Actor
掉落物 = Entity（不是 Actor）
全局技能系统 = Actor（不绑定 Entity）
```

契约入口 `Nythros\Contracts\ActorInterface`：

```php
interface ActorInterface
{
    /** 执行一帧 Actor 逻辑；由 Actor 系统在每帧对已注册 Actor 调用一次。 */
    public function update(): void;
}
```

引擎基类 `Nythros\Actor\BaseActor` 是这个模型的落地：

```php
abstract class BaseActor implements ActorInterface
{
    /** Actor 当前绑定的实体；未绑定时为 null。 */
    protected ?EntityInterface $entity = null;

    /** 将 Actor 绑定到实体；重复绑定会覆盖之前的实体。 */
    public function bindEntity(EntityInterface $entity): void
    {
        $this->entity = $entity;
    }

    /** Actor 每帧逻辑入口：由 ActorSystem 逐帧调用，子类实现具体行为。 */
    abstract public function update(): void;
}
```

用法：绑定后 `update()` 每帧由 `ActorSystem`（`SimpleActorSystem`）驱动，Actor 通过 `$this->entity` 读写绑定的实体状态。

## 2. 四个基类

Framework 层提供四个基类，业务 Actor 一律继承它们（`Nythros\Framework\`）：

### 2.1 BaseActor —— 基础生命周期

所有 Actor 的共同祖先（见 §1）：持有 `bindEntity` 与抽象 `update`。它本身不实现任何业务钩子，只定义「绑实体 + 每帧更新」的骨架。

### 2.2 BasePlayer —— 玩家（连接 / 身份 / 血量 + 钩子）

```php
abstract class BasePlayer extends BaseActor implements Damageable
```

| 能力 | API | 说明 |
|---|---|---|
| 连接绑定 | `attachConnection(string $connectionId, string $uid)` | 绑定连接标识与玩家 uid |
| 连接解绑 | `detachConnection()` | 置空连接与 uid |
| 身份读取 | `connectionId(): ?string` / `uid(): ?string` | 未绑定时返回 null |
| 血量 | `hp()` / `maxHp()` | 默认 100/100 |
| 受伤 | `final takeDamage(int $amount)` | 模板方法：扣血钳制归零，存活→死亡那一次触发一次 `onDeath` |
| 治疗 | `heal(int $amount)` | 恢复生命，钳制在 maxHp，已死不复活 |
| 死亡判定 | `isDead(): bool` | `hp <= 0` |
| 每帧 | `final update()` → 钩子 `onTick()` | 每帧统一入口委托给钩子 |

**钩子**（protected，子类覆盖）：

- `onTick()`：每帧逻辑（冷却递减、状态刷新）。
- `onDamaged(int $amount)`：每次有效扣血触发（属性同步 / 受击表现）。
- `onDeath()`：死亡结算（标记待复活 / 回出生点）。

### 2.3 BaseMonster —— 怪物（AI 状态机 + 钩子）

```php
abstract class BaseMonster extends BaseActor implements Damageable
```

AI 状态机四个状态（公开常量 + `aiState()` 读取）：

| 常量 | 值 | 每帧钩子 |
|---|---|---|
| `STATE_PATROL` | `patrol` | `onPatrol()` |
| `STATE_CHASE` | `chase` | `onChase()` |
| `STATE_ATTACK` | `attack` | `onAttack()` |
| `STATE_DEAD` | `dead` | `onDead()` |

| 能力 | API | 说明 |
|---|---|---|
| 构造 | `__construct(string $monsterId, int $maxHp)` | hp 初始化为 maxHp |
| 身份 | `monsterId()` | 怪物唯一标识 |
| 血量 | `hp()` / `maxHp()` | |
| 目标 | `targetId(): ?string` / `setTarget(?string $targetId)` | 追击目标实体 id |
| 状态迁移 | `enterState(string $state)` | 白名单校验，非法抛 `InvalidArgumentException`；**DEAD 为终态，不再迁出** |
| 受伤 | `final takeDamage(int $amount)` | 模板方法：归零时迁移 DEAD + 幂等触发一次 `onDeath` |
| 每帧 | `final update()` | 按 `aiState` 分发到对应钩子；DEAD 每帧只走 `onDead` |

**钩子**（protected，子类覆盖）：

- `onPatrol()`：巡逻（感知视野内玩家 → CHASE + setTarget；否则随机/路径点移动）。
- `onChase()`：追击（目标丢失 → PATROL；进入攻击范围 → ATTACK；否则朝目标移动）。
- `onAttack()`：攻击（冷却判定 → 攻击结算）。
- `onDead()`：死亡帧钩子（DEAD 状态下每帧调用）。
- `onDeath()`：死亡结算钩子（掉落 roll → spawnDrops → 自清理）；由 `takeDamage` 在归零瞬间触发一次。

### 2.4 BaseNPC —— NPC（静态实体 + 交互）

```php
abstract class BaseNPC extends BaseActor
```

| 能力 | API | 说明 |
|---|---|---|
| 构造 | `__construct(string $npcId)` | NPC 唯一标识 |
| 身份 | `npcId()` | |
| 每帧 | `final update()` → 钩子 `onIdle()` | 静态实体默认空操作 |
| 交互 | `onInteract(BasePlayer $player)` | 由玩家触发，实现对话/商店 |

**钩子**：

- `onIdle()`：空闲钩子（定时刷新等被动行为）。
- `onInteract(BasePlayer $player)`：交互入口（public，由玩家消息触发）。

### 2.5 Damageable —— 战斗契约

```php
interface Damageable
{
    public function hp(): int;
    public function maxHp(): int;
    public function takeDamage(int $amount): void;   // 模板方法：钳制归零 + 幂等死亡结算
    public function heal(int $amount): void;
    public function isDead(): bool;
}
```

`BasePlayer` 与 `BaseMonster` 都实现它，使 `CombatService::attack` 以统一签名承载「玩家→怪物」与「怪物→玩家」双向攻击。

## 3. 模板方法模式

基类用 **final 模板方法 + 子类覆盖钩子** 固定骨架、开放扩展点：

```text
final update()      → 固定每帧入口（BasePlayer: onTick；BaseMonster: 按 aiState 分发；
                       BaseNPC: onIdle）
final takeDamage()  → 固定扣血/钳制/幂等死亡结算顺序，钩子 onDamaged/onDeath 由子类实现
```

规则：

- 模板方法（`update` / `takeDamage`）标记 `final`，子类不可重写流程，只能实现钩子。
- 死亡结算幂等：只在「存活 → 死亡」的那一次伤害触发一次 `onDeath`（`hp <= 0` 后重复 `takeDamage` 走短路返回）。
- 这是唯一保证：冷却、状态机分发、死亡结算的顺序不会被子类破坏。

## 4. 示例：生成骨架 + 实现钩子

### 4.1 生成骨架

```bash
php vendor/bin/make make:actor MonsterActor --kind=monster --ns=Nythros\Demo\Game --out=packages/demo/src/Game
```

生成 `packages/demo/src/Game/MonsterActor.php`（骨架带 TODO 注释与对应钩子集）：

```php
<?php
// 由 make:actor 生成 — 业务 Actor 骨架（kind=monster → extends BaseMonster，钩子集：onPatrol/onChase/onAttack/onDead/onDeath）。
declare(strict_types=1);

namespace Nythros\Demo\Game;

use Nythros\Framework\BaseMonster;

final class MonsterActor extends BaseMonster
{
    public function __construct(string $id, int $maxHp = 100)
    {
        parent::__construct($id, $maxHp);
    }

    protected function onPatrol(): void
    {
        // TODO: 巡逻钩子（感知视野内最近玩家 → CHASE + setTarget）
    }

    protected function onChase(): void
    {
        // TODO: 追击钩子（目标丢失 → PATROL；进入攻击范围 → ATTACK）
    }

    protected function onAttack(): void
    {
        // TODO: 攻击钩子（冷却判定 → 攻击结算）
    }

    protected function onDead(): void
    {
        // TODO: 死亡帧钩子（DEAD 状态下每帧调用）
    }

    protected function onDeath(): void
    {
        // TODO: 死亡结算钩子（掉落 roll → spawnDrops → 自清理）
    }
}
```

### 4.2 实现钩子（参照 demo 现成实现）

参照 `packages/demo/src/Combat/MonsterActor.php` 与 `packages/demo/src/PlayerActor.php`，它们是基类钩子的完整参考实现。

怪物核心（`MonsterActor`）：

```php
// 巡逻钩子：AOI 感知视野内玩家 → 有则 CHASE + setTarget；无则随机移动一格并广播 entity_moved
protected function onPatrol(): void
{
    $entity = $this->entity;
    if ($entity === null) {
        return;
    }

    $playerId = $this->perceivePlayer();   // world->getAOI()->query($entity) 感知
    if ($playerId !== null) {
        $this->setTarget($playerId);
        $this->enterState(self::STATE_CHASE);

        return;
    }

    $dx = $this->random->randomInt(-1, 1);
    $dy = $this->random->randomInt(-1, 1);
    if ($dx !== 0 || $dy !== 0) {
        $entity->move($dx, $dy);
        $this->broadcastMove($entity);
    }
}

// 追击钩子：目标丢失 → PATROL；目标进入攻击范围 → ATTACK；否则朝目标移动一格
protected function onChase(): void
{
    $targetId = $this->targetId();
    if ($this->entity === null || $targetId === null || $this->actorLookup->getActor($targetId) === null) {
        $this->setTarget(null);
        $this->enterState(self::STATE_PATROL);

        return;
    }
    if ($this->isTargetInRange($targetId)) {
        $this->enterState(self::STATE_ATTACK);

        return;
    }
    $this->moveTowardTarget($targetId);
}

// 攻击钩子：冷却判定 → 目标解析与存活前置 → combat->attack 反向攻击玩家
protected function onAttack(): void
{
    if ($this->attackCooldown > 0) {
        $this->attackCooldown--;

        return;
    }
    // ...目标丢失/无效则回 PATROL；否则 combat->attack($this, $target) + 重置冷却
}

// 死亡结算钩子：掉落 roll + 广播 entity_dead + 五处自清理（AOI/entityManager/actorSystem/typeIndex/actorLookup）
protected function onDeath(): void
{
    // $this->combat->spawnDrops(...) + $this->combat->broadcastDeath($this)
    // world->getAOI()->remove($entity) + entityManager->remove + actorSystem->remove ...
}
```

玩家核心（`PlayerActor`）：

```php
// 每帧钩子：攻击冷却递减
protected function onTick(): void
{
    if ($this->attackCooldown > 0) {
        $this->attackCooldown--;
    }
}

// 受伤钩子：定向广播 player:stats 属性同步帧
protected function onDamaged(int $amount): void
{
    $this->broadcaster?->sendToEntity($this->entityId, 'player:stats', [
        'hp' => $this->hp(),
        'maxHp' => $this->maxHp(),
    ]);
}

// 死亡结算钩子：标记待复活（demo 阶段简化，仅状态标记）
protected function onDeath(): void
{
    $this->awaitingRevive = true;
}
```

### 4.3 挂载到 World

生成并实现后的 Actor 需要被注册进 World 才会被逐帧驱动（参照 `run-worker.php` / `MapServer`）：

```php
$world->getEntityManager()->add($entity);   // 实体入 EntityManager
$world->getAOI()->updateEntity($entity);    // 实体入 AOI 空间索引（见 Cell 指南）
$actor->bindEntity($entity);                // Actor 绑定实体
$world->getActorSystem()->add($actor);      // Actor 入 ActorSystem，每帧 update 被调用
```

## 5. 钩子速查表

| 基类 | 模板方法 | 可覆盖钩子 | 触发时机 |
|---|---|---|---|
| BaseActor | `abstract update()` | ——（自身即扩展点） | 每帧 |
| BasePlayer | `final update()` / `final takeDamage()` | `onTick` / `onDamaged` / `onDeath` | 每帧 / 每次有效扣血 / 存活→死亡那一次 |
| BaseMonster | `final update()`（按 aiState 分发）/ `final takeDamage()` | `onPatrol` / `onChase` / `onAttack` / `onDead` / `onDeath` | 对应状态每帧 / 归零瞬间一次 |
| BaseNPC | `final update()` | `onIdle` / `onInteract` | 每帧 / 玩家交互时 |
