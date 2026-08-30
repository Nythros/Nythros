# mmorpg 试点推进第六批验收记录与试点总结（P8：同源校验 / AoE 矩形形状 / AI 攻击距离参数化）

> 本批为试点收尾批，按第 19 号验收记录第 5 节候选推进三项：P8a 安全区/出生点同源校验（装配期
> fail-fast）、P8b AoE 矩形形状消费（`aoe.shape` 字段自插件设计预留以来的首个第二种形状）、P8c AI
> 攻击距离参数化（怪物 AI 的攻击距离从隐式视野口径变为可配置）。全部改动 WSL 复现 CI 门禁 +
> verify-mmorpg E2E 全绿（连续三轮 PASS=11 FAIL=0）后提交（不推送）。

## 1. 交付物

### 1.1 P8a 安全区/出生点同源校验

| 变更 | 文件 | 说明 |
|---|---|---|
| 联动校验 | `packages/demo/src/MapServer.php` `attachMmorpg` | 安全区的保护语义锚定出生/复活落点——圆心偏离 spawnPoint 意味着复活玩家落在保护区外（复活即被集火）或保护了非出生区域；`LogicException` 装配期 fail-fast（P7 遗留债务关闭） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testSafeZoneCenterMismatchFailsFast` | spawnPoint (3,0) vs safeZone 圆心 (3,3) → LogicException |

### 1.2 P8b AoE 矩形形状消费

| 变更 | 文件 | 说明 |
|---|---|---|
| 形状声明 | `packages/framework/src/Plugin/Skill/SkillDefinition.php` | 新增 `SHAPE_RECT = 'rect'`；`$aoe` docblock 扩展为 circle（radius）/ rect（width/height）联合形状 |
| 形状构造 | `packages/demo/src/MapServer.php` `handleSkillCastAoE` | 按定义声明的形状键构造引擎形状值对象：circle 消费 payload `r`（圆心）；rect 消费 payload `w/h`（cx/cy 为几何中心，RectangleShape 锚点为最小角：anchor = center − half，整除向下）；形状键与 payload 参数不匹配分别 invalid_skill / invalid_target 拒绝 |
| 技能注册 | `packages/demo/src/MapChannelFactory.php` | `slash_rect` 矩形斩击（0.8 倍伤害 + 5s 冷却 + range 6，形状 6×4，mmorpg 门控内注册） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testAoERectShapeConsumesDeclaration` | 矩形 6×4 中心 (0,0)：形状内怪物受击、角点 (-3,-2) 含边受击、形状外 (5,0) 不受影响（命中判定归引擎 queryShape，与圆形同路径） |

### 1.3 P8c AI 攻击距离参数化

| 变更 | 文件 | 说明 |
|---|---|---|
| 配置参数 | `packages/framework/src/Game/Mmorpg/MmorpgConfig.php` | 新增 `attackRange`（缺省 0 = 视野口径，与接入前逐字节等价；负值 fail-fast） |
| AI 门禁 | `packages/framework/src/Combat/MonsterActor.php` | 构造注入；`isTargetInRange` 在视野命中之上叠加欧氏距离上限（格级粗粒度视野裁决 → 精确距离裁决）；`onAttack` 结算前当 attackRange > 0 且超距时回 CHASE 逼近（缺省口径攻击结算不做额外距离裁决） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testAttackRangeCapsAiTargeting` | attackRange 3：(2,0) 命中、(5,0)（视野内但超距）被拦；对照 harness（attackRange 0）距离 5 不拦截 |

## 2. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（1162 tests / 145621 assertions，新增 3 项） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E | **PASS=11 FAIL=0（连续三轮；本批 P8b/P8c 缺省关闭、P8a 为装配校验，E2E 环境与 P7 批一致）** |

重跑前提沿用（清 quest 键、每轮重启服务器、就绪轮询同时等 gateway/maps）。

## 3. mmorpg 试点推进总结（P1–P8，六批）

### 3.1 六批脉络

| 批 | 记录 | 主题 | 关键闭合 |
|---|---|---|---|
| 第一批 | 15 | aggroRange 距离门 + 任务链运行时 + E2E step4 | P1 预留的距离门在装配层生效；任务链线缆闭环 |
| 第二批 | 16 | 领奖闭环 + 嘲讽技能 + 任务进度持久化 | P4a/P4b 接入；quest 进度落 Redis |
| 第三批 | 17 | 玩家复活 + 奖励落库复核 + AoE 嘲讽 | P5a/P5b/P5c；帧词表 86/87；世界侧 actorLookup 补齐 |
| 第四批 | 18 | 时间制自动复活 + 复活传送即时视野差分 + AoE 施法距离门 | 死亡实体滞留债务关闭；range 字段首个消费点；复活帧动力学适配 |
| 第五批 | 19 | 单体技能距离门 + 复活点配置化 + 出生安全区 | 技能距离裁决层统一（P7a）；spawnPoint 参数化；怪物 AI 显式 no-aggro 区域 |
| 第六批 | 20 | 同源校验 + AoE 矩形形状 + AI 攻击距离参数化 | 装配期 fail-fast；aoe.shape 首个第二种形状；AI 攻击距离可配置 |

### 3.2 验收规模终值

- 单测：**1162 tests / 145621 assertions**（试点起点 15 号记录时约 1100 tests）。
- verify-mmorpg E2E：**11 步**全绿（威胁切换 / 重生 / 任务链配置与运行时 / 领奖 / 嘲讽 / 玩家复活 /
  MySQL 落库复核 / AoE 嘲讽 + 距离门 / 时间制自动复活）。
- 六批全部按「WSL 复现 CI 四门禁 + E2E 连续三轮全绿」口径验收，提交均未推送。

### 3.3 收尾判断：试点目标已达成，主动收官

候选清单消耗情况：P5 记录的四项候选中三项（视野差分、时间制自动复活+复活点配置化、AoE 距离门）已在
P6/P7 消费，P7 记录的四项候选中三项（同源校验、AI 攻击距离、AoE 形状）在本批消费。**唯一遗留候选为
「玩家死亡掉落归属回收」——它需要产品层裁决掉落语义（掉落比例、归属窗口、拾取保护），超出「按既有
设计意图把闲置机制接上」的试点范畴**，转入正式产品 backlog 更合适。试点阶段「框架提供参数与规则、
starter-kit 装配消费」的模式已跑通六批，后续玩法扩展（掉落、PVP 治理、更多形状/技能）建议按同一模式
立独立批次推进，本试点到此收官。

### 3.4 长期遗留（转入 backlog）

- 玩家死亡掉落归属回收（需产品裁决：掉落比例 / 归属窗口 / 拾取保护）。
- 安全区只在怪物 AI 侧生效，玩家间 PVP 不消费 safeZone（无 PVP 治理需求）。
- AoE 命中判定的伤害归属聚合（castSkillAoE 死亡攒批窗口已就绪，多源归属统计未做）。
- E2E 失败残留的定时器不取消（沿用：依赖步骤全绿）。
