# 02 · 战斗：让怪能被打、打人会被咬

> **这一阶打通**：attack 结算与视野广播（combat:hit / entity_dead）、技能、怪物 AI、死亡掉落与拾取。
> **新用 API**（全公开）：`Nythros\Framework\Combat\{CombatService, MonsterActor, DropTable, EntityTypeIndex, SystemRandomSource, VisionBroadcasterInterface, ActorLookupInterface}`、`Nythros\Framework\Damageable`、`Nythros\Framework\Plugin\Skill\{SkillRepository, SkillDefinition}`、`Nythros\Framework\Plugin\Item\{ItemRepository, ItemDefinition}`。
> **Redis**：不需要。

## 1. 一张图看清依赖方向

```
你的 GameServer（implements VisionBroadcasterInterface + ActorLookupInterface）
   │  注入                        ▲ 反向消费（依赖倒置，ADR-020）
   ▼                              │
CombatService ──结算──► Damageable（BasePlayer / BaseMonster 都实现）
   │
   └─ 命中/死亡/掉落 → broadcaster->broadcastToVision(...) → 视野内所有连接收到帧
```

`MonsterActor` 是 framework 给的 BaseMonster 完整 AI 实现（巡逻→追击→攻击→死亡掉落自清理），
你只需装配它，不用手写状态机。

## 2. 装配（`bin/map-worker.php`）

```php
use Nythros\Framework\Combat\{CombatService, DropTable, EntityTypeIndex, MonsterActor, SystemRandomSource};
use Nythros\Framework\Plugin\Item\{ItemDefinition, ItemRepository};
use Nythros\Framework\Plugin\Skill\{SkillDefinition, SkillRepository};

// —— 战斗三仓库：先最小内联，第 09 章换成配置表 + 插件 ——
$skills = new SkillRepository();
$skills->register(new SkillDefinition(id: 'fireball', name: '火球', damageMultiplier: 2.5, cooldownSeconds: 3.0, range: 5));
$items = new ItemRepository();
$items->register(new ItemDefinition(id: 'potion', name: '小血瓶', type: 'consumable'));

$typeIndex = new EntityTypeIndex();
$random = new SystemRandomSource();
$dropTable = new DropTable(['potion' => 100]);   // itemId => 权重

// GameServer 自己充当两个反转接口的实现（下一步定义）——与 demo 的 MapServer 同款做法
$combat = new CombatService($world, $game, $skills, $items, $random, $game, $typeIndex);
$game->attachCombat($combat, $dropTable, $skills, $items);
```

> 构造参数逐个对得上 `CombatService::__construct(WorldInterface, VisionBroadcasterInterface, SkillRepository,
> ItemRepository, RandomSourceInterface, ?ActorLookupInterface, ?EntityTypeIndex, ...)`。

## 3. 接线（你的 `GameServer`）

### 3.1 实现反转接口（三行适配器）

```php
use Nythros\Contracts\ActorInterface;
use Nythros\Framework\Combat\ActorLookupInterface;
use Nythros\Framework\Combat\VisionBroadcasterInterface;

final class GameServer extends RealtimeServer implements VisionBroadcasterInterface, ActorLookupInterface
{
    // 视野广播：委托基类现成的 broadcastToView（demo MapServer 同款桥）
    public function broadcastToVision(string $centerEntityId, string $type, array $payload): void
    {
        $center = $this->entityManager->get($centerEntityId);
        if ($center !== null) {
            $this->broadcastToView($center, $type, $payload);
        }
    }

    // 注意接口的第二个方法 sendToEntity() 不必写——RealtimeServer 已有同名 final public 方法，签名兼容，
    // implements 后自动满足契约（基类运行时与战斗广播共用同一条投递路）。

    // Actor 解析：CombatService 用来把 entityId 反查成 Damageable Actor（$this->actors 是骨架自建的登记簿）
    public function getActor(string $entityId): ?ActorInterface
    {
        return $this->actors[$entityId] ?? null;
    }

    public function removeActor(string $entityId): void
    {
        unset($this->actors[$entityId]);
    }
}
```

### 3.2 认证时登记玩家类型（掉落/仇恨靠它区分玩家与怪物）

`handleAuthMessage` 挂载玩家处补一行：

```php
$this->typeIndex->set($entityId, EntityTypeIndex::KIND_PLAYER);
```

### 3.3 路由：attack / skill:cast

`handleAuthenticated` 的 switch 加两个 case：

```php
case 'attack': {
    // 前置校验三件套：发起者存在且 Damageable、目标存在、目标活着（距离/CD 校验见 combat-guide）
    $attackerId = $this->registry->getEntityId($conn->getId()); // 基类连接↔实体双向表
    $attacker = $attackerId === null ? null : ($this->actors[$attackerId] ?? null);
    $target = $this->getActor((string) ($message->payload['targetId'] ?? ''));
    if (!$attacker instanceof Damageable || !$target instanceof Damageable || $target->isDead()) {
        $this->send($conn, Message::create('combat:error', ['code' => 404, 'message' => 'invalid_target'], $message->requestId));

        return;
    }
    $this->combat->attack($attacker, $target); // 结算 + combat:hit 视野广播都在里面

    return;
}
case 'skill:cast': {
    // 同上取 attacker/target，再：
    // $this->combat->castSkill($attacker, (string) $message->payload['skillId'], $target);
    return;
}
```

### 3.4 刷怪（NPC 巡游旁边加真怪）

`spawnNpcs()` 同款姿势，在 onStart 里装配：

```php
$monster = new MonsterActor(
    monsterId: 'mob-1', maxHp: 30, world: $this->world, combat: $this->combat,
    dropTable: $this->dropTable, actorLookup: $this, typeIndex: $this->typeIndex,
    random: $this->randomSource, broadcaster: $this,
    patrolAnchor: ['x' => 3, 'y' => 3], patrolRadius: 8, typeId: 'wolf',
);
$entity = new BaseEntity('mob-1', new Position(3, 3));
$this->entityManager->add($entity);
$this->aoi->updateEntity($entity);
$monster->bindEntity($entity);
$this->typeIndex->set('mob-1', EntityTypeIndex::KIND_MONSTER);
$this->world->getActorSystem()->add($monster);
```

怪物死亡后的 `entity_dead` 广播与 `drop:spawned` 掉落由 MonsterActor 的 onDeath 钩子自己完成——
这正是「装配框架件、不发明轮子」的红利。

## 4. 验收

```bash
php bin/launch.php
php client.php alice 18081
```

在客户端里（或另开一个 ws 调试页）登录后进图，发：

```json
{"type": "attack", "payload": {"targetId": "mob-1"}}
```

预期看到：`combat:hit{attackerId, targetId:"mob-1", damage, hp}` 反复出现 → 血量清零后
`entity_dead` + `drop:spawned`；再走近会听到怪物反咬（它也在 50ms tick 里巡逻/追击）。
同视野开 bob（第二个客户端）能收到 alice 攻击的广播帧——视野语义没被战斗破坏。

拾取（`pickup{dropId}`）归下一阶（和背包一起讲才有意义）→ [03 背包与持久化](03-inventory-persistence.md)。

## 5. demo 对照与常见坑

- 路由级前置校验（CD/距离/存活）demo 全在 `MapServer::handleAttack`（L2335）；战斗规则细节见
  [战斗与数值](../combat-guide.md)。
- **坑 1**：忘记 `typeIndex->set(...)` → 怪物 AI 分不清玩家/怪物/掉落，不索敌。
- **坑 2**：把结算写在路由里 `switch` 深处 → 帧预算被击穿；一切结算只走 `CombatService`。
- **坑 3**：怪物 patrolRadius 超过视野口径漂走 →「打不着的怪」。默认值 10 就是为对齐九宫格视野设的，别乱放大。
