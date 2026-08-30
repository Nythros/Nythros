# mmorpg 试点推进第三批验收记录（P5：玩家复活 / 任务奖励落库复核 / AoE 嘲讽）

> 本批按建议顺序推进：P5a 玩家复活流程（awaitingRevive 消费）、P5b 任务奖励 MySQL 侧断言（归档 30s 兜底
> 定时器落实）、P5c AoE 嘲讽路径扩展（castSkillAoE 消费 tauntThreat）。全部改动 WSL 复现 CI 门禁 +
> verify-mmorpg E2E 全绿（连续三轮 PASS=10 FAIL=0）后提交（不推送）。

## 1. 交付物

### 1.1 P5a 玩家复活流程（awaitingRevive 消费）

| 变更 | 文件 | 说明 |
|---|---|---|
| 复活语义 | `packages/framework/src/Actor/PlayerActor.php` | 新增 `revive()`：清 awaitingRevive 标记 + 回满血（收敛进合成上限）；非待复活态幂等短路 |
| 复活路由 | `packages/demo/src/MapServer.php` `handlePlayerRevive` | 待复活玩家 → 满血回生 + 清标记 + 重挂出生保护（与登录同口径）+ 传送回出生点 `PLAYER_SPAWN` (0,0)（世界/容器双路径，复用移动模板广播语义）→ player:stats 属性同步 + player:revive 回执（code=ok\|not_ready，携带落点） |
| 词表 | `packages/demo/src/Protocol/FrameType.php` | 新增 `player:revive`（帧类型 86）——新帧类型走线缆前入权威字典（此前直接编码抛「未知帧类型」） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testPlayerReviveRestoresHpAndTeleportsToSpawn` | 未死复活 not_ready 幂等拒绝；击杀后复活满血 + 标记清除 + 传送出生点 + 回执帧断言 |
| E2E step7 | `packages/demo/bin/verify-mmorpg.php` | 状态判定用**确定性探针**（先发 player:revive：ok=已死直接断言、not_ready=存活先战死）——step5 领奖步骤开场清空 1001 收件箱会把 step4 战死的 player:stats 证据冲掉，扫描判定不可靠（E2E 实测暴露）；断言回执落点 (0,0) + player:stats 满血 |

### 1.2 P5b 任务奖励 MySQL 落库复核

| 变更 | 文件 | 说明 |
|---|---|---|
| 归档 30s 兜底定时器落实 | `packages/demo/src/MapChannelFactory.php` | ArchivePipeline 在 fork 前构造（timer 缺省 null，30s 兜底从未真正注册——设计意图闲置）；onWorkerStart 内补注册 `periodicFlush` 定时器：断连/登出同步点之外的有界丢失窗口由定时批量 saveBatch 兜底（ADR-013 10.5 裁决 4） |
| E2E step8 | `packages/demo/bin/verify-mmorpg.php` | 从 MySQL 归档表（nythros_archive, collection=players）复核 1001 的 inventory.potion = 4（step4 拾取 2 + step5 领奖 2）；连接口径与服务器同源（deploy.yaml）；2s 轮询、40s 窗口自终止 |

### 1.3 P5c AoE 嘲讽路径扩展

| 变更 | 文件 | 说明 |
|---|---|---|
| AoE 施法路由 | `packages/demo/src/MapServer.php` `handleSkillCastAoE` | {skillId, cx, cy, r} → 技能定义须声明 AoE 能力（aoe ≠ null，单体系拒绝 invalid_skill）→ castSkillAoE（形状为世界绝对坐标）→ tauntThreat > 0 时对命中怪物逐一写入嘲讽威胁（多目标嘲讽语义，与单体同裁决） |
| 世界侧 actorLookup 补齐 | `packages/demo/src/MapChannelFactory.php` | 世界侧 CombatService 此前漏传 ActorLookupInterface（castSkillAoE 命中结算依赖；房间路径早已注入）——世界侧首个生产调用点 skill:cast_aoe 暴露该缺口 |
| AoE 嘲讽技能 | `packages/demo/src/MapChannelFactory.php` | mmorpg 门控内注册 `taunt_aoe`（嘲讽风暴：0.3 倍伤害、8s 冷却、圆形 r=10、tauntThreat 1000） |
| 词表 | `packages/demo/src/Protocol/FrameType.php` | 新增 `skill:cast_aoe`（帧类型 87） |
| 单测 | `packages/demo/tests/MapServerMmorpgTest.php` `testAoETauntPullsEveryMonsterInShape` | 双怪同形（(0,0)/(5,0) 半径 10）→ 两怪威胁表均写入 1000；单体系技能经 AoE 路径被 invalid_skill 拒绝 |
| E2E step9 | `packages/demo/bin/verify-mmorpg.php` | **先探针复活 1003**（step6 的嘲讽让 monster-1 永久锁定 1003，steps 7-8 的持续攻击把它磨死——死者会被 aggro 切换的「清死尸」从仇恨列表剔除，威胁随之消失，E2E 实测暴露）→ 首次位移用 1002 收件箱读 monster-2 位置（其视野覆盖整巡逻域）→ 移过去后 1003 自身视野恒新鲜 → skill:cast_aoe 施放 → monster-2 切到嘲讽者 → 断言 combat:hit |

## 2. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（1153 tests / 145579 assertions） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK（engine 公开面/框架导入合规） |
| verify-mmorpg E2E（NYTHROS_MMORPG=1 + NYTHROS_GAMEPLAY=1 + NYTHROS_MMORPG_CHAINS） | **PASS=10 FAIL=0（连续三轮）** |

### 2.1 E2E 重跑前提（沿用 P4c）

任务进度落 Redis（`nythros:gw:quest:*`），重跑前清理：

```bash
redis-cli --scan --pattern 'nythros:gw:quest:*' | xargs -r redis-cli del
```

服务器启动（WSL 内 setsid -f 防 SIGHUP）；就绪轮询须**同时等待 gateway(18285) 与 maps(18081)**——gateway
就绪慢于 maps，只等 maps 会让 E2E 在 gateway 未就绪时启动（实测抖动，本批修复轮询口径）。

## 3. 遗留与债务

- **玩家死亡无自动复活**：复活为路由驱动（player:revive），无时间制自动回生（demo 语义：显式操作）；路由
  未启用时（未登录/非待复活态）回执 not_ready。
- **复活传送的视野差分**：复活传送复用移动模板（AOI 索引随 World::update 全量刷新），无即时 enter/leave
  差分广播（与既有移动路径一致；本批未改变该语义）。
- **AoE 嘲讽无施法距离限制**：形状为世界绝对坐标，施法者可在任意远处施放（与 room:aoe 同口径）；demo
  规模无滥用治理，留待战斗层扩展时裁决。
- **step9 依赖 1002 存活连接**：首次位移从 1002 收件箱读 monster-2 位置（1002 驻 (-6,-6) 且连接存活）；
  若 1002 断连，fallback 缺失（当前 E2E 客户端全程在线，未做断连路径）。
- **E2E 失败残留**（沿用）：任一步失败时该步已注册的定时器不取消；当前依赖步骤全绿。

## 4. 下一步候选（供参考）

- 玩家死亡掉落归属回收 / 尸体清理（死亡实体长期滞留 AOI）。
- 复活传送的即时视野差分（enter/leave 即时广播，替代等 World::update 刷新）。
- 时间制自动复活（respawnMs 化）与复活点配置化。
- AoE 施法距离与施法者约束（技能 range 字段消费）。
