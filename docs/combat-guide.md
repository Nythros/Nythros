# Nythros 战斗与数值系统开发手册（Combat & Numbers Guide）

> 面向读者：要在 Nythros 上搭建战斗结算、技能、怪物 AI、掉落与 buff 数值体系的程序。
> 读完你能：说清一次攻击从客户端帧到 `combat:hit` 广播的完整链路、按 `gameplay/skills/drops`
> 三张外置表调数值而无需改代码、新增技能与怪物类型、接掉落与拾取链路、用 BuffService 做
> 属性修正与 DOT、并按 `packages/demo/bin/verify-combat.php` 的蓝本跑通端到端验收。
> Actor 基类钩子与通用反模式总纲见 [docs/actor-guide.md](actor-guide.md) 与
> [docs/best-practices.md](best-practices.md)；帧格式见 [docs/protocol.md](protocol.md)；
> 配置热载机制见 [docs/quick-start.md](quick-start.md) §3.3。

## 1. 总览与设计立场

战斗层由三块组成（`packages/framework/src/Combat/`）：

- **CombatService**：普攻/技能/AoE 伤害结算、死亡掉落生成与拾取结算的纯业务服务
  （`packages/framework/src/Combat/CombatService.php`）。无连接、无协议依赖，可单测。
- **MonsterActor**：怪物 AI（巡逻/追击/攻击）与死亡自清理
  （`packages/framework/src/Combat/MonsterActor.php`），实现 `BaseMonster` 的钩子骨架
  （`packages/framework/src/BaseMonster.php`）。
- **数据组件**：`DropTable`/`DropEntry`/`DropEntity`（掉落）、`SkillCooldownTable`（技能冷却）、
  `BuffService`（buff 状态机）、`EntityTypeIndex`（实体种类登记表）、随机源。

三条设计立场贯穿全部代码：

1. **服务端权威结算**。客户端只发意图帧（`attack` / `skill:cast` / `skill:cast_aoe` /
   `pickup` / `buff:apply`），伤害数值、冷却判定、距离裁决全部在服务端完成；
   客户端算出的伤害仅作表现层预估，以广播帧为准。
2. **Damageable 统一战斗面**。`packages/framework/src/Damageable.php` 只声明
   `hp() / maxHp() / takeDamage() / heal() / isDead()` 五个方法；`CombatService::attack`
   以同一签名承载「玩家→怪物」与「怪物→玩家」双向结算，不关心对端是谁。死亡结算在
   `BaseMonster::takeDamage` / `BasePlayer::takeDamage`（均为 `final`）模板方法内闭环：
   扣血钳制归零 → 归零时幂等触发一次 `onDeath()`。
3. **种子化 RNG 的可复现性**。伤害浮动与掉落 roll 全部经
   `RandomSourceInterface::randomInt(min, max)`（`packages/framework/src/Combat/RandomSourceInterface.php`）。
   生产缺省 `SystemRandomSource`（`random_int`）；部署注入 `NYTHROS_RANDOM_SEED`（纯数字）时
   装配 `SeededRandomSource`（Mt19937），同种子同序列——E2E 断言从「时序容忍」升级为
   「数值确定」（装配点见 `packages/demo/src/MapChannelFactory.php` 的随机源注入分支）。

## 2. 攻击结算链路

以玩家普攻怪物为例，逐步链路（每步给出真实代码位置）：

1. **客户端发 `attack{targetId}` 帧** → `MapServer` 已认证路由分发
   （`packages/demo/src/MapServer.php` `handleAuthenticated()` 的 `case 'attack'`）。
2. **前置校验 `resolveCombatant()`**（同文件，私有方法），依次判定：
   - 连接已认证且对应 `PlayerActor`（未认证：401 + 断连）；
   - payload 有 `targetId`、**非自身**（`invalid_target`）；
   - 目标经 Actor 表解析为 `Damageable` 且未死亡（`invalid_target`）；
   - **九宫格距离**：`isNeighborIn()` 用攻击方所在容器的 AOI 查询，目标必须在视野内
     （`out_of_range`）；
   - **攻击冷却**：`PlayerActor::isAttackReady()`（帧制冷却，见
     `packages/framework/src/Actor/PlayerActor.php`，`startAttackCooldown()` 置
     `ATTACK_COOLDOWN_FRAMES`）。
   全部失败路径定向回 `combat:error{code, message}`，连接不断、无副作用。
3. **PVP 对抗门（可选）**：`MapServer::pvpRejection()`——mmorpg 装配且双方均为玩家时，
   按 `pvp_disabled` / `in_safe_zone` / `spawn_protected` 拒绝；PVE 不治理。
4. **CombatService::attack 结算**（`CombatService::attack`）：
   - 伤害 = `BASE_ATTACK_DAMAGE`（常量 10）× `randomInt(80, 120) / 100` 随机浮动；
   - 目标为 `BaseMonster` 时先 `noteAttacker()`（最后伤害来源，掉落归属依据）与
     `noteDamage()`（伤害账本，多源归属裁决用）；
   - `target->takeDamage($damage)`；若目标为玩家且装配了威胁表，怪物侧
     `MonsterActor::onDamaged` 记仇恨（见 §5）；
   - **广播 `combat:hit{attackerId, targetId, damage, hp}`**（视野广播，经
     `VisionBroadcasterInterface::broadcastToVision`——MapServer 实现该接口）；
   - 目标死亡：发布应用事件 `combat.kill`（`CombatService::EVENT_KILL`，供任务/重生消费）；
     非怪物目标（如玩家）补 `broadcastDeath()` 广播 `entity_dead{id}`——怪物死亡帧由其
     `onDeath` 自行广播，此处跳过防重复。
5. **MapServer 启动攻击冷却**：`$player->startAttackCooldown()`。
6. **死亡掉落**（怪物目标）：`BaseMonster::takeDamage` 归零触发 `MonsterActor::onDeath()` →
   `combat->spawnDrops(...)`（每件掉落广播 **`drop:spawned{dropId, itemId, x, y}`**，
   并对 spawn 瞬间视野内旧邻居补发 `entity_enter` 附 `itemId`）+ 死亡五处自清理
   （AOI remove / entityManager remove / actorSystem remove / typeIndex remove /
   actorLookup removeActor）。

**AoE 变体**：`skill:cast_aoe` 路由（`MapServer::handleSkillCastAoE`）校验技能声明了
AoE 形状、按形状键构造引擎形状值对象（circle 用 payload `r`；rect 用 `w/h`，锚点 =
几何中心 − 半宽高）、施法距离门（中心距施法者超 `definition->range` 拒绝）后调
`CombatService::castSkillAoE`：1 次 `queryShape`（形状查询归引擎）→ N 次
`takeDamage`（非战斗体/已死/被 pvpGate 拒绝的目标静默跳过）→ 1 次 `combat:aoe` 合并帧；
连锁死亡/掉落经攒批窗口合并为 `entity_dead_batch` / `drop:spawned_batch` 单帧。

## 3. 数值外置：gameplay / skills / drops 三表

三张内容表以 PHP 数组文件外置在 `packages/demo/config/`，经 ConfigRepository 装载并由
`GameplayTables::schemas()`（`packages/demo/src/GameplayTables.php`）校验——坏表启动即拒
（错误带行号），热载改坏走回滚。加载与热载机制（mtime 轮询 / `config.changed` / 表级重放）
见 [docs/quick-start.md](quick-start.md) §3.3，此处不重复；本节只讲逐键语义。

### 3.1 gameplay.php（`packages/demo/config/gameplay.php`）

| 键 | 含义 |
| --- | --- |
| `spawnPoint` `{x, y}` | 出生/复活点；mmorpg 安全区圆心须与其同源（`MapServer::attachMmorpg` fail-fast 校验） |
| `player.maxHp` | 玩家初始血量基线，auth 挂载时经 `BasePlayer::initVitals` 一次性注入 |
| `monsters[]` | 初始怪物表，`onWorkerStart` 逐行 spawn |
| `monsters[].id` | 怪物实体/Actor 共用 id（如 `monster-1`） |
| `monsters[].typeId` | 怪物类型 id（`monster:spawned` 造型标识 + 任务击杀匹配键） |
| `monsters[].maxHp` | 最大生命值（≥1） |
| `monsters[].anchor` `{x, y}` | 出生锚点：巡逻中心、重生回锚 |
| `monsters[].patrolRadius` | 巡逻半径（可空 = `MonsterActor` 缺省 10） |
| `monsters[].respawnMs` | 逐怪重生延迟（可空 = `MmorpgConfig.respawnMs` 全局值） |

消费：`GameplayConfig::fromTable()`（`packages/demo/src/Gameplay/GameplayConfig.php`）把表行
装配成值对象，怪物行经 `MonsterSpawn::fromRow()`（同目录 `MonsterSpawn.php`）；
文件缺席时 `GameplayTables::defaultTable('gameplay')` 兜底（与外置前硬编码逐字段一致）。

### 3.2 skills.php（`packages/demo/config/skills.php`）

| 键 | 含义 |
| --- | --- |
| `id` / `name` | 技能唯一 id / 显示名 |
| `damageMultiplier` | 相对普攻的伤害倍率（≥0.0；结算 = 普攻基准 × 倍率 × 随机浮动） |
| `cooldownSeconds` | 秒制冷却，由 `SkillCooldownTable` 消费（见 §4） |
| `range` | 施法距离（世界单位）；单体与 AoE 路径的距离门共用 |
| `aoe`（可空） | `{shape: 'circle', radius}` 或 `{shape: 'rect', width, height}`；形状参数不完备在装配期 fail-fast |
| `mpCost` | MP 消耗（0 = 无消耗；当前 demo 路由未做扣减，扩展点见 §8「待验证」） |
| `itemCostId` / `itemCostCount` | 物品消耗（同上，定义侧只承载数据） |
| `tauntThreat` | 嘲讽威胁量（>0 且目标为怪物时写入威胁表，见 §5） |
| `feature` | 特性标注（`mmorpg`/`rooms`/`economy`/`gameplay`/`anticheat`），仅对应 `NYTHROS_*` env = 1 时装配；未标注恒生效 |

消费：`GameplayTables::applySkills()` 过滤 feature 后逐行构造 `SkillDefinition`
（`packages/framework/src/Plugin/Skill/SkillDefinition.php`）注册进 `SkillRepository`；
热载经 `reapplySkills()` 全量重放（增改覆盖、删除行摘除、手写注册不受影响）。

### 3.3 drops.php（`packages/demo/config/drops.php`）

| 键 | 含义 |
| --- | --- |
| `noDropWeight` | 每条目的不掉落权重段（0 = 声明权重即全部命中段） |
| `entries[].itemId` | 物品 id——**必须已在物品表注册**（引用完整性 fail-fast，见 `GameplayTables::buildDropTable`） |
| `entries[].weight` | 掉落权重（独立 roll 的命中段宽） |
| `entries[].minCount` / `maxCount` | 命中后数量的独立 roll 区间（缺省均 1） |
| `entries[].feature` | 同 skills 表 |

消费：`buildDropTable()` → `DropTable::fromRows()`（`packages/framework/src/Combat/DropTable.php`）。
roll 语义见 §6。

## 4. 技能系统

### 4.1 SkillCooldownTable

`packages/framework/src/Combat/SkillCooldownTable.php`：按「施法者实体 id × 技能 id」管理
**秒制**冷却，与普攻的**帧制**冷却（`PlayerActor::startAttackCooldown`）是两套独立状态——
互不读写、互不重置，同一施法者可同时处于两套冷却中（预期行为）。API：

- `start(casterKey, skillId, cooldownSeconds, now)`：置冷（now 注入保证可测）；
- `isReady(casterKey, skillId, now)` / `remaining(...)`：施法路由校验，未就绪回
  `combat:error{code: 'cooldown'}` 并带剩余秒数；
- `reset(casterKey)`：断连清理。

装配：`NYTHROS_GAMEPLAY=1` 时由 `MapChannelFactory` 创建并经 `MapServer::attachGameplay`
注入；未装配时技能路由跳过冷却校验（接入前语义）。

### 4.2 施法路由与结算

`skill:cast` 路由（`MapServer::handleSkillCast`）校验链：`resolveCombatant`（同普攻）→
PVP 门 → `SkillRepository::get` 校验技能存在（`invalid_skill`）→ **技能距离门**
（施法者到目标超 `definition->range` 拒绝 `out_of_range`）→ 冷却表校验 →
`CombatService::castSkill`（伤害 = 普攻基准 × `damageMultiplier` × 浮动；广播
`skill:cast` 回执 + `combat:hit`）→ 置冷 → `tauntThreat > 0` 且目标为怪物时
`MonsterActor::applyTaunt` 写威胁表。

### 4.3 如何新增一个技能（步骤化）

1. **加表行**：在 `packages/demo/config/skills.php` 加一行声明（单体技能省略 `aoe`，
   AoE 技能声明 `shape` + 完备的 `radius` 或 `width/height`）。增删一行即生效——
   启动装配与热载重放双路径。
2. **（AoE 形状）无需写代码**：circle/rect 由 `MapServer::handleSkillCastAoE` 内的形状
   构造分支直接支持；新形状键需扩展该分支并同步 `SkillDefinition::SHAPE_*` 与
   `GameplayTables::schemas()` 的 `aoe.shape` 枚举。
3. **需要公式/消耗扩展时**：`SkillDefinition` 是纯数据 readonly 值对象，新增可选字段 +
   schema 同步 + 消费路由读取，三处一致（参考 `tauntThreat` 的接入方式）。
4. **物品消耗联动**：`itemCostId` 引用的物品须先注册（见 §6.3 物品表）。
5. **验收**：仿照 `packages/demo/bin/verify-combat.php` step6（技能施放）与
   step10（首次置冷 + 二次拒绝）补断言。

## 5. 怪物与 AI

### 5.1 状态机骨架

`packages/framework/src/BaseMonster.php`：四状态白名单
`STATE_PATROL` / `STATE_CHASE` / `STATE_ATTACK` / `STATE_DEAD`（`enterState` 校验，DEAD
为终态不再迁出）。`update()` 为 final 模板方法，按状态分发到五个钩子，子类覆写：

- `onPatrol()` / `onChase()` / `onAttack()`：AI 主体；
- `onDamaged(?string $attackerId, int $amount)`：每次有效扣血后触发（威胁表接入点）；
- `onDead()`：DEAD 态每帧调用（`MonsterActor` 未用它，自清理在 `onDeath` 完成）；
- `onDeath()`：hp 归零时幂等触发一次（掉落与自清理）。

另有 tick 分频门（`TickCadence`，区域降频时非到期帧跳过整个 AI 节拍），详见
[docs/cell-guide.md](cell-guide.md)。

### 5.2 MonsterActor 的行为实现

`packages/framework/src/Combat/MonsterActor.php`：

- **巡逻**：AOI 感知视野内第一个玩家（经 `EntityTypeIndex::kindOf` 判定 `KIND_PLAYER`，
  玩家/怪物共用 final BaseEntity 无法 instanceof）→ 有则 CHASE + setTarget；无则随机
  移动一格并广播 `entity_moved`。移动受**出生锚有界**约束（预览落点超出
  `anchor ± patrolRadius` 拒绝，防止怪物漂出攻击视野导致广播无人可收）。
- **追击**：目标丢失（Actor 不存在**或已死**）→ 威胁表模式先从仇恨列表切换，仍无目标才
  回 PATROL；目标进入攻击范围 → ATTACK；否则朝目标移一格。
- **攻击**：帧制冷却（`ATTACK_COOLDOWN_FRAMES = 8`）→ aggro 切换 → 出生保护跳过
  （`PlayerActor::isSpawnProtected()`，不结算也不耗冷却）→ 攻击距离门（`attackRange > 0`
  时在视野命中之上叠加欧氏距离）→ 安全区门 → `combat->attack($this, $target)` 反向打玩家。
- **死亡**：`combat->spawnDrops`（击杀者 uid 由 `lastAttacker()` 解析，掉落归属绑定）+
  `broadcastDeath` + 五处自清理。
- **仇恨（threat）**：`onDamaged` 把攻击者按伤害量记入 `ThreatTable`
  （`packages/framework/src/Game/Mmorpg/ThreatTable.php`，超 `aggroRange` 不记、安全区内
  忽略）；`applyAggroSwitch` 每次攻击前选最高威胁者，仇恨列表空时清空目标。
  `applyTaunt`（嘲讽）与 `decayThreats`（按帧衰减）由装配层驱动。

### 5.3 重生（respawner）

`packages/framework/src/Game/Mmorpg/Respawner.php`：mmorpg 装配（`NYTHROS_MMORPG=1`）时
`MapServer::attachMmorpg` 创建，订阅 `combat.kill`——怪物死亡即 `registerDeath`（逐怪
`respawnMs` 覆盖全局值），到期由世界 tick 的 `tickMmorpg` 驱动在原锚点重生；
`spawnMonster(..., registerSpawn: false)` 的密度副本不登记重生，防指数增长。
玩家侧另有 `playerRespawner`（`playerRespawnMs > 0` 时自动复活）。

### 5.4 如何新增怪物类型

1. **表行**：在 `packages/demo/config/gameplay.php` 的 `monsters[]` 加一行（id/typeId/
   maxHp/anchor/patrolRadius/respawnMs）——热载 diff：新增行立即刷出、删除行不再重生、
   在场怪物不驱逐。
2. **类型即数据**：demo 阶段所有怪物共用 `MonsterActor`，`typeId` 只作造型标识与任务
   匹配键；没有 per-type 类（与技能同立场）。
3. **差异化行为**：继承 `BaseMonster` 覆写钩子（参考 `MonsterActor`），在装配层注册进
   ActorSystem；注意保持 `takeDamage`/`update` 的 final 模板契约。
4. **数值**：maxHp 走表；攻击伤害目前是 `CombatService::BASE_ATTACK_DAMAGE` 常量
   （怪物与玩家共用同一基准）——如需按怪分档，属 §8 扩展点。

## 6. 掉落与背包

### 6.1 DropTable / DropEntry / DropEntity

- `DropTable::roll($random)`（`packages/framework/src/Combat/DropTable.php`）：**逐条目独立
  roll**——每条目在 `[1, weight + noDropWeight]` 掷点，落入前 `noDropWeight` 段则不掉；
  命中后数量在 `[minCount, maxCount]` 再独立 roll。多条目可同时命中（掉落风暴语义）。
  `DropEntry`（同目录）构造时对数量区间 fail-fast。
- `DropEntity`（同目录）：实现 `EntityInterface` 的掉落物实体，携带 `itemId` / `count` /
  `ownerUid` / `ownerTeamId` / `expiresAt`（归属与过期语义见 §2 与下文）。

### 6.2 掉落生成与拾取链路

击杀掉落：`CombatService::spawnDrops`（§2 第 6 步）——itemId 经 `ItemRepository` 校验
（未注册跳过）→ drop id 进程内唯一 → EM add + AOI updateEntity → `drop:spawned` 广播。
归属：`killerUid` 非空时绑定 `ownerUid/ownerTeamId`；null（如 AoE 连锁死亡）生成无归属
掉落自由拾取。过期：`dropLifetimeSeconds > 0` 时写 `expiresAt`，`purgeExpiredDrops(now)`
由装配层定时回收并广播 `drop:removed`。

拾取链路（客户端 `pickup{dropId}` → `MapServer::handlePickup`）：

1. dropId 经攻击方所在容器的 entityManager 解析为 `DropEntity`（`invalid_target` 兜底）；
2. 视野距离校验（`isNeighborIn`，`out_of_range`）；
3. `CombatService::pickup($player, $drop, $inventory)`：归属校验（本人/同队可拾，非归属者
   定向 `combat:error{code: 'not_owner'}`）→ AOI/EM/登记表摘除 → `inventory->add` →
   广播 **`drop:removed`**（视野）+ 定向 **`item:added{itemId, count}`** → 发布
   `combat.pickup` 事件（任务收集进度源）；
4. **`ArchivePipeline::markDirty(uid, ['inventory' => ...])`**：背包标脏落库
   （`MapServer::handlePickup` 尾部；登出时 `flushId` 强制同步）。

### 6.3 Inventory / Equipment / 物品表

- `packages/framework/src/Inventory.php`：`itemId => count` 计数表，`add/remove/count/all`；
  数量不足时 `remove` 整组移除，永不为负。
- 物品定义 `ItemDefinition`（`packages/framework/src/Plugin/Item/ItemDefinition.php`）：
  `type ∈ consumable|material|currency|equipment`；equipment 型带 `slot`（合法值由
  `EquipmentSlot` 枚举：weapon/armor/accessory）与 `attributes` 加成表（如
  `['maxHp' => 30]`）。demo 装配注册 gold/potion/bone，economy 特性启用时注册
  `sword`（武器槽，maxHp+30）——见 `packages/demo/src/MapChannelFactory.php`。
- `Equipment`（`packages/framework/src/Inventory/Equipment/Equipment.php`）：穿戴双闸
  （type 必须 equipment + slot 必须登记），同槽顶替返回被顶替 itemId；
  `BasePlayer::attachEquipment` 挂载后 `maxHp()` 走合成口径：**基础 maxHp + 装备加成 +
  buff 属性修正和**，挂/摘时 `clampHpToMax` 收敛不变量。

## 7. Buff 系统

`BuffService`（`packages/framework/src/Combat/BuffService.php`）是 buff 的状态机：
施加/叠加裁决、到期 tick 与效果结算（属性修正/DOT）。

- **定义**：`BuffDefinition`（`packages/framework/src/Plugin/Buff/BuffDefinition.php`）——
  `durationSeconds`（必须为正）、`effects` 约定键（`attributes: 属性名 => 每层整数增量`、
  `dot: {damage, intervalSeconds}`）、叠加三元组 `stackRule`（`refresh` 刷新时长 /
  `stack` 叠层封顶 `maxStacks`）与 `mutexGroup`（同组互斥顶替）。
- **叠加边界矩阵**（`BuffService::apply`）：首次施加 stacks=1 并广播 `buff:applied`；
  refresh 只刷到期时刻；stack 层数 +1（封顶）且新增层追加一份属性修正；mutexGroup 冲突
  先顶替（修正回退 + `buff:expired`）；到期摘除全部层修正并广播 `buff:expired`，DOT
  不结算过期后的尾拍。
- **驱动宿主**：`tick(now, hostResolver)` 由装配层 **0.5s 周期定时器**驱动（TickScheduler
  路线，不侵入 ActorSystem 继承树）——到期摘除与 DOT 结算（自伤 `takeDamage` +
  `combat:hit{attackerId=targetId=宿主}` 帧）都在这里；宿主经 hostResolver（hostKey →
  BasePlayer）解析，断连宿主由 `purgeHost` 清理。
- **能力边界**：宿主约束为 `BasePlayer`（属性修正挂载点
  `BasePlayer::addAttributeModifier / removeAttributeModifier`）；怪物宿主与
  buff 数值外置（rage/poison 目前硬编码注册在 `MapChannelFactory`）属扩展点。
- **接入方式**：客户端路由 `buff:apply{buffId, targetId}` / `buff:remove{buffId}`
  （`MapServer::handleBuffApply/Remove`，`NYTHROS_GAMEPLAY=1` 装配）；新增 buff 即在
  装配处 `BuffRepository::register(new BuffDefinition(...))`——demo 现有 rage（maxHp+50，
  4s）与 poison（DOT 5/1s，stack 至 3 层）两个样例。

## 8. 常见变体与扩展点

- **暴击/元素克制**：框架无内置实现。扩展点在 `CombatService::rollDamage` 之后、
  `takeDamage` 之前——仿照伤害浮动的注入思路给 CombatService 增加可插拔裁决（如
  `DamageModifierInterface`）。注意保持「服务端权威 + 广播帧携带最终数值」口径。
- **仇恨（threat）**：已有实现——`ThreatTable` + `MonsterActor` 威胁表接入
  （`NYTHROS_MMORPG=1`），嘲讽技能 `taunt` / `taunt_aoe` 由 skills 表
  `feature: mmorpg` 行驱动；规则参数（`aggroRange` / `threatDecayPerSec` /
  `tauntMultiplier` / `maxThreat`）在 `MmorpgConfig`
  （`packages/framework/src/Game/Mmorpg/MmorpgConfig.php`）。
- **击杀归属（多源）**：`MmorpgConfig::killCredit` 支持 `last_hit`（缺省）与
  `damage_leader`（伤害账本最高者，平局取先达）；账本由 `BaseMonster::noteDamage`
  维护，快照经 `damageContributors()` 进 `combat.kill` 事件负载。
- **波次刷怪**：`packages/framework/src/Game/Horde/`（HordePlugin + `WaveDefinition`，
  `positionAt` 纯函数给出行优先网格坐标）+ `DropStormConfig` / `SpawnProtectionConfig`；
  `spawnDropsBatch` 支持一波掉落合并为单条 `drop:spawned_batch`。
- **击杀/拾取埋点**：`CombatService::EVENT_KILL` / `EVENT_PICKUP` 经应用级
  `EventDispatcher` 派发，任务系统以此驱动 kill/collect 进度（见
  `packages/framework/src/Quest/`，装配见 `MapChannelFactory` 任务接线块）。
- **PVP 治理**：普攻/单体技能的路由级门在 `MapServer::pvpRejection`，AoE 逐目标门经
  `CombatService` 构造参数 `pvpGate` 注入；`MmorpgConfig::pvpEnabled` 缺省关闭。
- **MP/物品消耗结算**：`SkillDefinition.mpCost / itemCostId` 已承载定义数据，但
  demo 施法路由未做扣减结算——接入点在 `handleSkillCast` 置冷之前（**待验证**：
  框架层无既有扣减实现，需自行补结算与回执帧）。

## 9. 反模式清单（战斗专项）

1. **客户端算伤害**：客户端本地伤害预估只许做表现层动画；任何影响状态（hp/背包/任务）
   的数值必须来自服务端广播帧。伪造 `combat:hit` 帧对服务端状态零影响（服务端不消费
   客户端上行这些帧型，未在路由表注册即 404）。
2. **在钩子里做阻塞 IO**：`onDamaged` / `onDeath` / `update()` 都在世界 tick 内逐帧调用；
   落库/日志一律走内存登记 + 异步管线（参考 `ArchivePipeline::markDirty` 的标脏模式）。
   通用总纲见 [docs/best-practices.md](best-practices.md)。
3. **绕过 Damageable 直改 hp**：`takeDamage` / `heal` 是带幂等短路、死亡触发与 hp 钳制的
   模板方法（玩家侧还牵连合成上限）；直接改 `$hp` 会跳过 `onDeath`、破坏
   `hp ≤ maxHp()` 不变量、并让 `combat:hit` 的 hp 字段失真。治疗/扣血一律走接口方法。
4. **数值硬编码进代码**：伤害基准、冷却、掉率、刷怪参数全部进三张外置表（§3）；
   `CombatService::BASE_ATTACK_DAMAGE` 与 demo 装配处的 buff 定义是历史样例，新项目
   不要再往代码里加数值。新增数值键时同步 `GameplayTables::schemas()`（否则启动即拒）。
5. **在结算链路里广播逐目标帧**：AoE 场景逐目标 `combat:hit` 会退回洪泛——已有合并帧
   `combat:aoe` / `entity_dead_batch` / `drop:spawned_batch`，扩展结算管线时沿用攒批窗口
   语义（窗口内只登记、关窗出帧，`try/finally` 保证关窗）。
6. **怪物无界追击/无界巡逻**：位移必须受出生锚域约束（`exceedsAnchor` 门）——否则怪物
   漂出攻击视野，`out_of_range` 攻击全废、死亡/掉落广播无人可收。
7. **跨类触碰死亡结算**：死亡闭环在 `takeDamage` → `onDeath` 内完成；外部代码不应在
   `isDead()` 后自行再广播/再掉落（`CombatService` 对怪物目标跳过 `broadcastDeath` 正是
   为防重复帧）。
8. **冷却双表混用**：技能冷却查 `SkillCooldownTable`（秒制），普攻冷却查
   `PlayerActor::isAttackReady`（帧制）——不要互相读写或重置对方状态。
