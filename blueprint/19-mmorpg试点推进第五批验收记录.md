# mmorpg 试点推进第五批验收记录（P7：单体技能距离门 / 复活点配置化 / 出生安全区）

> 本批按第四批验收记录第 5 节候选推进三项：P7a 单体技能路径消费 `definition->range`（与 P6c 的 AoE
> 距离门统一为「技能距离」裁决层，技能 range 字段全路径生效）、P7b 出生/复活点参数化（`PLAYER_SPAWN`
> 常量 → 装配注入）、P7c 出生安全区声明（怪物 AI 显式 no-aggro 区域，替代「怪锚点外移避开出生格」的
> 隐式约定）。全部改动 WSL 复现 CI 门禁 + verify-mmorpg E2E 全绿（连续三轮 PASS=11 FAIL=0）后提交
> （不推送）。

## 1. 交付物

### 1.1 P7a 单体技能距离门（definition->range 全路径消费）

| 变更 | 文件 | 说明 |
|---|---|---|
| 距离门 | `packages/demo/src/MapServer.php` `handleSkillCast` | 此前单体路径的距离约束由视野距离（resolveCombatant）承担，`definition->range` 从未被消费；施法者到目标超 range 即 `out_of_range` 拒绝（无副作用：冷却/攻击冷却均未启动）。与 P6c 的 AoE 距离门同裁决层、同拒绝码 |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testSingleSkillCastBeyondRangeRejected` | 施法者 (0,0)、目标 (5,0)（视野内但超 range 3）→ out_of_range + 威胁表零写入 |
| 回归适配 | 同文件 `testMonsterReleasesDeadChaseTarget` | 嘲讽者站位 (10,10)（距怪 14.1 > range 3）→ (2,2)（2.8 ≤ 3）——P7a 距离门使旧测试的越距嘲讽被拒（实测暴露），死目标释放语义不变 |

### 1.2 P7b 出生/复活点配置化

| 变更 | 文件 | 说明 |
|---|---|---|
| 构造参数 | `packages/demo/src/MapServer.php` | 新增 `spawnPoint`（缺省原点 (0,0)，与接入前逐字节等价）：auth 挂载 `new Position(spawnPoint)` 与复活传送 `applyRevive` 共用；`PLAYER_SPAWN` 常量移除 |
| 环境注入 | `packages/demo/src/MapChannelFactory.php` | `NYTHROS_SPAWN_POINT='x,y'` 解析（负坐标容许）传入构造 |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testSpawnPointConfigurable` | spawnPoint (5,7)：auth 挂载落点与击杀后复活落点均为配置值 |

### 1.3 P7c 出生安全区（怪物 AI 显式 no-aggro 区域）

| 变更 | 文件 | 说明 |
|---|---|---|
| 配置参数 | `packages/framework/src/Game/Mmorpg/MmorpgConfig.php` | 新增 `safeZone`（`{x,y,radius}`；null = 未声明，零门禁）；radius 非正 fail-fast |
| AI 门禁 | `packages/framework/src/Combat/MonsterActor.php` | 构造注入 `safeZone`（spawnMonster 从 mmorpg 配置透传），四处门禁：① 感知跳过（perceivePlayer，与出生保护同语义的常驻版）② 攻击无效化（onAttack，区内目标不结算不置冷却；威胁表路径由 ④ 提前剔除）③ 威胁/嘲讽写入忽略（onDamaged/applyTaunt——否则区内玩家可无反伤骚扰，安全语义破缺）④ 仇恨列表清理剔除（applyAggroSwitch，区内玩家不参与 aggro 选择，防追至边界卡死在「攻击被跳过」态） |
| 环境注入 | `packages/demo/src/MapChannelFactory.php` | `NYTHROS_MMORPG_SAFE_ZONE='x,y,r'` 解析注入 MmorpgConfig |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testSafeZoneShieldsPlayersFromMonsterAi` | 区内（半径 5）：巡逻不建目标 + 普攻伤害照常结算但威胁零记录 + 嘲讽不写入；对照：出区（距圆心 10）后威胁恢复记录 |

## 2. E2E 适配（P7c 与自动复活的叠加效应）

- **E2E 开启安全区**（`NYTHROS_MMORPG_SAFE_ZONE='0,0,5'`，与出生点对齐）：复活落点 (0,0) 常驻免战，
  结构性消灭 P6 批遗留的「复活后蹲守集火」扰动源；monster-2 巡逻域与区的几何重叠（角 (-2,-2) 距圆心
  2.83 < 5）不影响语义（区限制的是玩家侧）。
- **step7 战死前置出区**：区内玩家的攻击不记威胁（P7c 门禁 ③）→ 怪物永不反击 → 1001 无法战死；
  攻击循环前先归位 monster-2 巡逻域中心 (-6,-6)（距圆心 8.5 > 5，且全巡逻域在其视野内）。基准判定：
  step7 探针的 not_ready 分支中 1001 只可能在 (0,0)（步骤间隙自动复活——其 ok 帧已被探针 take-any
  消费）或 (-6,-6)（step4 后未死），收件箱残留复活 ok 帧即前者。
- **step10 出区同构**：pre-move 从「monster-2 实时位置」改为固定 (-6,-6)（域中心恒在区外且全视野覆盖）。
- **step4 基准探针化（P6 遗留竞态修复）**：复活帧观察存在「死亡发生在基准检查后、复活帧到达前」的竞态
  （实测：基准误判 → 移动落点错 → 击杀超时），改为先发 player:revive 读回执——ok = 死过且复活落点
  (0,0)；not_ready = 存活且自 step1 撤离后未死（恒在 (100,100)）。两分支均无歧义；探针 take-any 语义下
  残留的旧自动复活 ok 帧同样指向 (0,0)，判定不受污染。

## 3. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（1159 tests / 145611 assertions，新增 3 项 + 1 项回归适配） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E（NYTHROS_MMORPG=1 + NYTHROS_GAMEPLAY=1 + NYTHROS_MMORPG_CHAINS + NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 + NYTHROS_MMORPG_SAFE_ZONE='0,0,5'） | **PASS=11 FAIL=0（连续三轮，每轮重启服务器 + 清 Redis quest 键）** |

重跑前提沿用 P6 批（清 quest 键、每轮重启服务器、就绪轮询同时等 gateway/maps）。

## 4. 遗留与债务

- **安全区与出生点的对齐靠装配纪律**：safeZone 圆心与 spawnPoint 是两个独立参数，装配层应一致（E2E 均
  为 (0,0)）；框架层未做联动校验。
- **追击到安全区边界的路径行为**：怪物可追进区内（移动不受区限制），到边界后经 onAttack 的
  applyAggroSwitch 剔除/切换离开；不做 Chase 态的区内目标实时失效（下一攻击 tick 才裁决）。
- **安全区只在怪物 AI 侧生效**：玩家间 PVP（attack 路由）不消费 safeZone——demo 无 PVP 治理需求。
- **单体技能距离门与怪物攻击无联动**：怪物攻击玩家由 AI 态机裁决（视野/巡逻域），不走技能距离层。
- **E2E 失败残留**（沿用）：任一步失败时该步已注册的定时器不取消；当前依赖步骤全绿。

## 5. 下一步候选（供参考）

- 死亡掉落归属回收（死亡时随身物品的掉落/保留裁决；demo 目前玩家死亡无掉落语义）。
- 安全区/出生点联动校验（框架层 fail-fast：两者同源声明）。
- 怪物攻击玩家同样走「距离裁决层」（AI 攻击范围与技能 range 统一为可配置参数组）。
- AoE 形状消费扩展（RectangleShape/SectorShape 已在引擎侧就绪，协议路由仅支持圆形）。
