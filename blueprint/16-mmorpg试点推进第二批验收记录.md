# mmorpg 试点推进第二批验收记录（P4：领奖闭环 / 嘲讽技能 / 进度持久化）

> 本批关闭 blueprint/15 遗留债务三项：任务领奖环节未在 E2E 覆盖（P4a）、嘲讽技能预留未消费（P4b）、
> 任务进度仅进程内存储（P4c）。全部改动在 WSL 复现 CI 门禁 + verify-mmorpg E2E 全绿后提交（不推送）。

## 1. 交付物

### 1.1 P4a 任务领奖 E2E 闭环

| 变更 | 文件 | 说明 |
|---|---|---|
| 领奖 E2E 步骤 | `packages/demo/bin/verify-mmorpg.php` step5 | 已完成未领奖的 kill_wolves → `quest:claim`（奖励 potion×2 入包 + `quest:result claim ok`）→ `quest:rows` rewarded 落位 [true,false,false] → 重复领奖幂等拒绝（`claim not_ready`）→ talk_elder（无奖励表）领奖 ok 且无 item:added → 终态 rewarded [true,false,true]；收件箱清箱隔离 step4 遗留帧 |

### 1.2 P4b 嘲讽技能接入（关闭 P1 预留）

| 变更 | 文件 | 说明 |
|---|---|---|
| 技能定义嘲讽字段 | `packages/framework/src/Plugin/Skill/SkillDefinition.php` | 新增 `tauntThreat`（0 = 非嘲讽技能，缺省）；纯数据值对象风格（与 mp/物品消耗字段同款），裁决归消费方 |
| 怪物嘲讽入口 | `packages/framework/src/Combat/MonsterActor.php` | 新增 `applyTaunt(string $taunterId, float $amount)` 透传 `ThreatTable::applyTaunt`（tauntMultiplier 倍率裁决归威胁表）；无威胁表/非正量零操作 |
| 施法路由接线 | `packages/demo/src/MapServer.php` `handleSkillCast` | `tauntThreat > 0` 且目标为 MonsterActor → 施法伤害结算后写目标威胁表（非怪物目标静默无效果） |
| 嘲讽技能注册 | `packages/demo/src/MapChannelFactory.php` | mmorpg 门控内注册 `taunt`（0.6 倍伤害、6s 冷却、tauntThreat 1000）——无威胁表时 tauntThreat 无意义 |
| 预留标注更新 | `packages/framework/src/Game/Mmorpg/ThreatTable.php` | applyTaunt 的「无生产调用方」标注更新为已接入 |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` | `testTauntSkillSwitchesAggroToTaunter`（威胁 20/10 → 嘲讽 1000 → aggro 切到嘲讽者）；`testMonsterReleasesDeadChaseTarget`（死目标释放追击回归） |
| E2E 步骤 | `packages/demo/bin/verify-mmorpg.php` step6 | 位置联动（1003 先到巡逻域中心 (15,15)——3×3 视野恰好覆盖怪物整域 → 收件箱读怪物最新位置恒新鲜 → 移到怪物所在格施法；out_of_range 清箱重试 ≤5 轮）；嘲讽者选 1003（存活、无历史威胁）——1001/1002 已在 step4 战死，aggro 切换会剔除死者 |

**P4b 关联修复（E2E 实测暴露的框架缺口）**：`MonsterActor::applyAggroSwitch` 仇恨列表空时**清空目标**
（此前 `selectTarget` 返回 null 就什么都不做，调用方以 targetId 非空判断「已切换」→ 怪物永久锁死在旧目标，
死目标/全员离场即卡死、无法触发 aggro 切换）；配套 `onChase`/`onAttack` 的目标丢失判定把**已死目标**计入
丢失（玩家死亡仅打 awaitingRevive 标记、Actor 持久存活，只查 Actor 存在会让怪物追尸体被巡逻边界卡死）。

### 1.3 P4c 任务进度持久化

| 变更 | 文件 | 说明 |
|---|---|---|
| Redis 存储 | `packages/framework/src/Quest/RedisQuestStore.php`（新） | `QuestStoreInterface` 的 Redis 实现（照 RedisFriendStore 先例：`\Redis|\Closure` 构造 + 键前缀 + uid 白名单 + 无 TTL 持久）；键 `nythros:gw:quest:{uid}` hash {questId → JSON(全字段)}；坏数据静默丢弃 |
| 装配切换 | `packages/demo/src/MapChannelFactory.php` | QuestService 存储由进程内 InMemoryQuestStore 换为 RedisQuestStore（服务器重启进度不丢：击杀/收集/对话/领奖标记全量落盘） |
| 单测 | `packages/framework/tests/Quest/RedisQuestStoreTest.php`（新） | 保存/查询/枚举/整体覆盖/删除/坏数据静默/uid 白名单（真实 Redis，不可用整体跳过，随机前缀隔离） |

**P4c 附带修正**：step2 改用 1002/1003 攻击（最后一击归属随机——真实随机伤害 8-12 让 step2 击杀可能给 1001
记 kill_wolves 进度，破坏 step4 初始全零断言；P2 潜在抖动本次暴露）；并修正 step2/step6 中 1003 的位移起点
（1003 在 step0 避险位 (100,100)，此前按 (-6,-6) 推算位移导致其全程 out_of_range）。

## 2. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（1151 tests / 145546 assertions） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E（NYTHROS_MMORPG=1 + NYTHROS_GAMEPLAY=1 + NYTHROS_MMORPG_CHAINS） | **PASS=7 FAIL=0（连续两轮，含 step5 领奖 + step6 嘲讽）** |

### 2.1 E2E 重跑前提（P4c 持久化）

任务进度现落 Redis（`nythros:gw:quest:*`），E2E 依赖「初始全零」断言——**重跑前必须清理任务键**：

```bash
redis-cli --scan --pattern 'nythros:gw:quest:*' | xargs -r redis-cli del
```

服务器启动（WSL 内 setsid -f 防 SIGHUP）：

```bash
cd /mnt/d/workspace/php/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 \
  NYTHROS_MMORPG_CHAINS='main-line=kill_wolves,collect_bones,talk_elder' \
  setsid -f php bin/server start
```

## 3. 遗留与债务

- **任务领奖奖励发放走 Inventory 未落库校验**：`quest:claim` 的奖励经 `markInventoryDirty` 标脏（经济批关闭时
  由归档管线兜底 flush），本批未在 E2E 断言 MySQL 侧余额（kill_wolves 奖励 potion×2 的入包帧已断言）。
- **嘲讽仅单体路径**：`tauntThreat` 对 AoE 施法（castSkillAoE）不生效（AoE 路径无单体目标概念）；
  demo 技能表无 AoE 嘲讽技能，语义留待技能层扩展时裁决。
- **玩家死亡无复活流程**：awaitingRevive 标记只有语义无落地（无复活路由/重生调度），demo 玩家死亡后实体
  /Actor 常驻；怪物对死目标的追击已由 P4b 修复释放。
- **任务进度无版本/事务**：RedisQuestStore 单键单字段整体覆盖（demo 规模单机 Redis 成立，与 FriendStore 同口径）。
- **E2E 失败残留**：任一步失败时该步已注册的定时器不取消（后续步骤可能被残留帧污染）；
  当前依赖步骤全绿，未做通用清理（后续批次可加 closeStep 时全局 timer 清扫）。

## 4. 下一步候选（供参考）

- 玩家复活流程（awaitingRevive 消费）+ 死亡掉落归属回收。
- 任务奖励发放的 MySQL 侧断言（关经济批时走归档管线）。
- AoE 嘲讽 / 群体仇恨技能（castSkillAoE 路径扩展 tauntThreat 语义）。
