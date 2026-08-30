# mmorpg 试点推进第四批验收记录（P6：时间制自动复活 / 复活传送即时视野差分 / AoE 施法距离门）

> 本批按第三批验收记录第 4 节候选推进三项：P6a 玩家死亡自动复活（playerRespawnMs 化，关闭「死亡实体
> 长期滞留 AOI」债务）、P6b 复活传送即时视野差分（enter/leave 不再等 World::update 下一帧）、P6c AoE
> 施法距离门（技能 definition->range 字段首次被消费）。全部改动 WSL 复现 CI 门禁 + verify-mmorpg E2E
> 全绿（连续三轮 PASS=11 FAIL=0，step10 新增）后提交（不推送）。

## 1. 交付物

### 1.1 P6a 时间制自动复活（respawnMs 化）

| 变更 | 文件 | 说明 |
|---|---|---|
| 配置参数 | `packages/framework/src/Game/Mmorpg/MmorpgConfig.php` | 新增 `playerRespawnMs`（缺省 0 = 关闭，复活仅路由驱动——保持 P5a 语义与存量部署零影响）；不变量非负校验 |
| 环境注入 | `packages/demo/src/MapChannelFactory.php` `mmorpgConfigFromEnv` | `NYTHROS_MMORPG_PLAYER_RESPAWN_MS` 注入（纯数字校验；两条返回路径均携带） |
| 复活调度器 | `packages/demo/src/MapServer.php` | `attachMmorpg` 按 playerRespawnMs > 0 创建第二个 `Respawner`（复用怪物重生调度器，泛型 id 队列）；`EVENT_KILL` 监听按 victim 分流：怪物 victim 走 spawnRegistry 登记（原逻辑），玩家 victim 且 awaitingRevive 时登记自动复活 |
| 复活核心抽取 | `packages/demo/src/MapServer.php` `applyRevive` | 从 `handlePlayerRevive` 抽取复活核心（满血回生 + 清标记 + 重挂出生保护 + 传送出生点 + 回执），路由与自动复活共用；自动复活路径无消息上下文——连接经 registry 反查（在线玩家恒可解析），无连接回落宿主世界 |
| tick 消费 | `packages/demo/src/MapServer.php` `tickMmorpg` | 到期待复活玩家服务端直接复活（客户端未请求也主动下发 player:revive ok）；已手动复活者登记静默作废；先复活后 clear（reviewer MINOR-4 同口径：异常保留登记下个 tick 重试） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testAutoReviveAfterRespawnDelay` | combat.kill 埋点登记（反射拨队列时间）→ 世界 tick 服务端复活：满血 + 清标记 + 落点 (0,0) + 未请求的 player:revive ok 回执 |

### 1.2 P6b 复活传送即时视野差分

| 变更 | 文件 | 说明 |
|---|---|---|
| 即时差分 | `packages/demo/src/MapServer.php` `applyRevive` | 传送后立即 `aoi->updateEntity` 刷新索引并双向补发（AoiDiffEnvelopes 同语义的帧级直发）：新邻居收到 entity_enter{me}、我收到新邻居 entity_enter；离开的旧邻居收到 entity_leave{me}、我收到旧邻居 entity_leave。同格传送 / UniversalAOI（恒空差分）补发自然为空；差分先行消费后，下一帧 World::update 的 drainMoved 再次 updateEntity 走同格 fast path，不产生重复信封 |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testReviveTeleportEmitsImmediateVisionDiff` | 双玩家 + 尸体远移（世界 tick 刷新索引到远格）后复活传送：进入方向（新邻居双向 entity_enter）与离开方向（旧邻居双向 entity_leave）四断言 |

### 1.3 P6c AoE 施法距离门（definition->range 消费）

| 变更 | 文件 | 说明 |
|---|---|---|
| 距离门 | `packages/demo/src/MapServer.php` `handleSkillCastAoE` | 形状为世界绝对坐标，此前施法者可在任意远处施放（P5 遗留债务）——形状中心距施法者超 `definition->range` 即 `out_of_range` 拒绝（无副作用：冷却/攻击冷却均未启动）；拒绝回执前先解析施法实体（缺失 `not_ready`） |
| 距离参数调整 | `packages/demo/src/MapChannelFactory.php` | `taunt_aoe` range 3 → 10：站位即形状中心的施法模式下，巡逻漂移不应把合法施法推出距离门（E2E step9 实测风险）；与 AoE 半径同量级 |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testAoECastBeyondRangeRejected` | (0,0) 施法形状中心 (50,0)（range 3）→ out_of_range + 威胁表零写入（拒绝无副作用） |
| E2E 扩展 | `packages/demo/bin/verify-mmorpg.php` step9 | 远点施法（cx = 1003 位置 +100）先断言 out_of_range 拒绝（range 消费的线缆证据），通过后再进入合法施法重试循环 |

## 2. E2E step10 与 P6a 适配（重要复盘）

### 2.1 step10（时间制自动复活验收）

1001 攻击 monster-2 被反击至死（全程不发送 player:revive）→ playerRespawnMs（env 2000ms）到期后世界
tick 服务端主动复活：断言未请求收到 player:revive ok（落点 (0,0)）+ player:stats 满血。死亡确定性依据：
monster-2 威胁表 1003 的嘲讽威胁（1000）在 1003 死亡时被 aggro「清死尸」剔除，且 1001 的累计伤害威胁
不衰减终将登顶——两路任一先到即切换到 1001，反击致死。

### 2.2 P6a 对既有步骤的扰动（诊断复盘，E2E 实测暴露）

自动复活改变了「尸体动力学」：死亡玩家 2s 后回生在 (0,0)（3s 出生保护），成为游荡怪物的持续目标源，
并使 E2E 既有的「位置硬编码假设」失效。实测定位（服务端 entity-move/kill/applyRevive 埋点 + 客户端
combat:error 采样）确认的扰动链：

1. **1001 闲置被杀**：step1 结束后 1001 驻 (15,15)（monster-1 巡逻域内），step2/3 期间被游荡的
   monster-1 反击致死 → 自动复活传回 (0,0) → step4 开局 `move(-21,-21)` 从 (0,0) 出发落在
   (-21,-21)，攻击全程 out_of_range → 击杀超时。修复：step1 PASS 后 1001 撤回避险位 (100,100)；
   step4 开局位移基准「复活帧感知」（清箱**前**先取 player:revive ok 证据——清箱会抹掉证据）。
2. **战中复活**：step4 杀怪循环内 1001 再次死亡 → 复活传回 (0,0) → 远离巡逻域攻击静默失效。
   修复：杀怪循环观察 player:revive ok 帧（选择性 inboxTake，不得整箱清空——entity_dead 的
   waitMapFrame 轮询同一收件箱），命中即重新归位巡逻域中心；击杀窗口 25s → 40s。
3. **step6 同构**：1003 的开局位移同样复活帧感知（基准 (0,0) 或 (-6,-6)）。

### 2.3 重跑前提（更新）

任务进度落 Redis（`nythros:gw:quest:*`），重跑前清理：

```bash
redis-cli --scan --pattern 'nythros:gw:quest:*' | xargs -r redis-cli del
```

服务器启动（WSL 内 setsid -f 防 SIGHUP）；就绪轮询须**同时等待 gateway(18285) 与 maps(18081)**；
**每轮验收前重启服务器**——威胁表不衰减、服务器进程内跨轮累积会让 step1 的「初始威胁」断言漂移
（实测：不重启的第二轮 step1「威胁切换未生效」）：

```bash
cd /mnt/d/workspace/php/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 \
  NYTHROS_MMORPG_CHAINS='main-line=kill_wolves,collect_bones,talk_elder' \
  NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000 \
  NYTHROS_ACCOUNTS=1001=secret,... setsid -f php bin/server start
```

## 3. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（1156 tests / 145598 assertions，新增 3 项） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E（NYTHROS_MMORPG=1 + NYTHROS_GAMEPLAY=1 + NYTHROS_MMORPG_CHAINS + NYTHROS_MMORPG_PLAYER_RESPAWN_MS=2000） | **PASS=11 FAIL=0（连续三轮，每轮重启服务器 + 清 Redis quest 键）** |

## 4. 遗留与债务

- **复活点配置化**：出生/复活点仍为 `MapServer::PLAYER_SPAWN` 常量 (0,0)，未参数化（demo 规模单出生点语义）。
- **step4 杀怪循环与游荡怪物的交织**：monster-1 可能进入 monster-2 域参与围攻 1001（自动复活使怪物
  目标源更动态）；40s 击杀窗口 + 复活重试已覆盖实测路径，但极端伤害随机序列仍可能超窗。
- **step9 依赖 1002 存活连接**（沿用）：1002 若在 step9 前自动复活至 (0,0)，其 3×3 视野（cell(0,0)±1）
  仍覆盖 monster-2 巡逻域 cell(-1,-1)，位置帧读取不受影响；但断连路径无 fallback（沿用）。
- **E2E 失败残留**（沿用）：任一步失败时该步已注册的定时器不取消；当前依赖步骤全绿。
- **AoE 距离门只覆盖 AoE 路径**：单体施法路径的距离约束仍由「视野距离」承担（resolveCombatant），
  技能 definition->range 在单体路径的消费留待战斗层扩展时统一裁决。

## 5. 下一步候选（供参考）

- 复活点配置化（出生点/复活点按地图配置，消费 deploy/config 体系）。
- 单体技能路径消费 definition->range（与 AoE 距离门统一为「技能距离」裁决层）。
- 玩家死亡掉落归属回收（死亡时随身物品的掉落/保留裁决，demo 目前无掉落语义）。
- 怪物巡逻域与玩家安全区的空间隔离声明（组装层显式声明 no-aggro 区域，替代锚点外移的隐式约定）。
