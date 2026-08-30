# mmorpg 类型模块试点推进（P1/P2/P3）验收记录

> 文档定位：R4 mmorpg 类型模块试点「继续推进」批次的验收复盘（配置聚合 + 插件生命周期 + 纯函数规则 + 组装接线 + E2E 五要素的收口与补齐），非设计文档。
> 验收环境：WSL2 / Ubuntu / PHP 8.3.33 / Workerman 5.2.2 / Redis（127.0.0.1:6379）/ MySQL（127.0.0.1:3306，nythros 库）（Windows 侧仅编辑，测试/部署目标为 Linux 口径）。
> 前置基线：CI #8（c8e72a5）全绿——phpunit 1135/145456、cs 0、phpstan 0、internal OK、verify-mmorpg PASS=4。

## 1. 目标

按建议推进顺序收口 mmorpg 试点与 horde 实践五要素的差距：

- **P1 收口装配层缺口**：`aggroRange` 距离门在装配层从未生效（`onDamaged` 不传攻击者距离，配置参数空转）；`tauntMultiplier` 无生产调用方（需显式标注预留，不做空接线）。
- **P2 任务链运行时**：`QuestChain`（mmorpg 配置）从未被 QuestService 消费——链式解锁/顺序推进缺失；且 `combat.kill` 载荷传怪物**实例 id** 而任务定义 targetId 是**类型 id**，击杀进度源从未匹配上；E2E step3 仅断言配置解析，无真实行为。
- **P3 验收记录**：本文档。

## 2. 交付物

### 2.1 P1 收口（装配层）

| 变更 | 文件 | 说明 |
|---|---|---|
| aggroRange 距离门接线 | `packages/framework/src/Combat/MonsterActor.php` | `onDamaged` 经世界 EM 解析攻击者实体，欧氏距离超 `aggroRange` 不记威胁（攻击路由的 `isNeighborIn` 是首道防线，AoE/直结算等旁路靠本门兜底）；攻击者不可解析（跨容器/离场）不默认入仇恨列表 |
| 距离门测试 | `packages/demo/tests/MapServerMmorpgTest.php` | 新增 `testAggroRangeGateSkipsFarAttackers`：近距（同格）记威胁、远距（(100,100)，≈141 > 10）经 `noteAttacker+takeDamage` 直结算路径不记威胁、仇恨列表只含近距者 |
| taunt 预留标注 | `packages/framework/src/Game/Mmorpg/ThreatTable.php` | `applyTaunt` 无生产调用方（demo 技能层无嘲讽技能）——规则与状态组件就绪，待技能层接入时经 MonsterActor/装配层消费，不做空接线 |

### 2.2 P2 任务链运行时（framework → Quest 子系统）

| 变更 | 文件 | 说明 |
|---|---|---|
| `QuestChain` 迁入 Quest 子系统 | `packages/framework/src/Quest/QuestChain.php`（自 `Game\Mmorpg` 迁入，引用方同步：`MmorpgConfig`/`MmorpgConfigTest`/`verify-mmorpg`） | 链配置是任务概念而非 mmorpg 专属，依赖方向修正为 `Game\Mmorpg → Quest` |
| 任务链纯函数规则 | `packages/framework/src/Quest/QuestChainRules.php`（新） | `chainOf`/`isUnlocked`（前序全完成才解锁）/`nextQuestId`/`isChainComplete` 纯函数集合，对齐 horde `SettlementRules` 分工 |
| 链门接入状态机 | `packages/framework/src/Quest/QuestService.php` | 构造期注入 `list<QuestChain>`（缺省 [] = 无链，行为与链前一致）；`advance` 对链上未解锁任务短路（解锁是完成集的派生状态，无独立动作） |
| 击杀类型匹配键修复 | `packages/framework/src/BaseMonster.php` / `Combat/MonsterActor.php` / `Combat/CombatService.php` / `packages/demo/src/MapServer.php` | `BaseMonster` 增 `typeId`；`combat.kill` 载荷新增 `monsterTypeId`（原 `monsterId` 传实例 id 导致任务类型匹配永不触发）；`QuestService` 击杀监听优先读 `monsterTypeId`（回退 `monsterId` 兼容旧载荷）；**spawnMonster 透传 `typeId`（E2E 走线缆暴露：此前漏传导致 monsterTypeId 恒空串，击杀进度源永不匹配）** |
| quest:* 二进制词表补齐 | `packages/demo/src/Protocol/PayloadKey.php` / `tests/Protocol/MapCodecVocabularyTest.php` | R3 quest:* 帧从未走线缆——`quest:rows` 的 `counts` 与 `quest:claim` 的 `questId` 未登记进词表，编码即抛 `ProtocolException`（E2E 首次暴露）；补两键并把 quest:* 全部帧形纳入词表往返测试 |
| 任务链单测 | `packages/framework/tests/Quest/QuestChainRulesTest.php`（新）+ `QuestServiceTest::testChainLocksUntilPredecessorCompletes` + `CombatServiceTest` 类型键断言 | 链归属/按序解锁/下一任务/链完成；锁定任务忽略三类进度源上报；解锁后推进生效；整链闭环 |
| 路由级可用性守卫 | `packages/demo/src/MapServer.php` | 原「全组非空才放行」把 `quest:*` 与房间依赖的 `matching` 绑死——GAMEPLAY 单独开启时任务路由 404；改按路由组各自判空（buff/matching/quest 互不阻塞） |
| 装配接线 | `packages/demo/src/MapChannelFactory.php` | `NYTHROS_MMORPG_CHAINS='id=q1,q2;id=q3,q4'` env → `list<QuestChain>` → `MmorpgConfig.questChains`（经 MmorpgPlugin 注入容器）→ 玩法批装配时传给 `QuestService`；`collect_bones` requiredCount 5→1（链门语义：kill1 的骨在 kill_wolves 完成前被忽略、kill2 的骨解锁后计入——required 1 让两杀后集骨即完成，一条链两杀一谈闭环；原 5 骨需五杀、required 2 需三杀，试点验收时长均不可接受） |
| E2E 真实链行为 | `packages/demo/bin/verify-mmorpg.php` | 新增 step4：初始 quest:rows 全零 → 锁定断言（kill_wolves 未完成时 quest:talk 不计）→ 集火杀 wolf×2 + 拾骨×2 → kill_wolves=2 完成、collect_bones=1 完成（kill1 的骨在解锁前被链门忽略，kill2 的骨解锁后计入）→ talk_elder 解锁后一次对话完成 → 整链闭环（进度 [2,1,1]、completed 全 true） |

### 2.3 P3 验收记录

本文档。

## 3. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 定向（Quest + Game\Mmorpg + CombatService + MonsterActor + MapServerMmorpg + Protocol 词表） | OK（104 tests / 2382 assertions） |
| phpunit 全量 | OK（1144 tests / 145519 assertions） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E（NYTHROS_MMORPG=1 + NYTHROS_GAMEPLAY=1 + NYTHROS_MMORPG_CHAINS） | **PASS=5 FAIL=0**（新增 step4 链运行时，威胁/重生/任务链全链路真实闭环） |

### 3.1 E2E 验收口径

服务器启动（WSL 内 setsid -f 防 SIGHUP）：

```bash
cd /mnt/d/workspace/php/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 \
  NYTHROS_MMORPG_CHAINS='main-line=kill_wolves,collect_bones,talk_elder' \
  setsid -f php bin/server start
```

- step0 前置登录 / step1 威胁切换 / step2 重生 与链前行为一致（GAMEPLAY 的 buff/冷却表对普攻路径惰性，不影响威胁/重生断言）。
- step4 任务链运行时为真实链路：`quest:list → quest:rows` 轮询进度、`quest:talk` 对话路由、攻击/击杀/掉落/拾取全走既有战斗链路。
- 链式解锁语义由框架 `QuestChainRules` 判定，装配层零业务逻辑。

## 4. 遗留与债务

- **嘲讽技能未接**：`tauntMultiplier`/`ThreatTable::applyTaunt` 保持预留（P1 显式标注），待技能层加入嘲讽技能时消费。
- **任务进度未持久化**：`InMemoryQuestStore` 随进程存活，重启即失；与背包归档（ArchivePipeline）同轨的持久化后置。
- **任务奖励领取**：`quest:claim` 路由与奖励入包已存在（R3 玩法批），本次未在 E2E 覆盖领奖环节——链完成断言到 completed 为止。
- **`monsterTypeId` 旧载荷回退**：QuestService 保留 `monsterId` 回退读法，旧 producer 消失后可移除。
