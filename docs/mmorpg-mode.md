# MMORPG 大地图模式开发手册

> 面向读者：要用 Nythros 开发大地图多人世界（AOI 视野、多地图进程、跨图迁移、动态扩缩容）的服务端开发者。
> 读完你能：看懂「一频道一进程一 World」的部署模型与启动铁序、写对 deploy.yaml 拓扑、正确使用
> GridAOI 与视野快照重同步、打通跨 map 换线、用容量准入 + drain 做动态扩缩容、
> 执行滚动更新与断线重连恢复，并借 Game/Horde 与 Game/Mmorpg 两大玩法插件组织自己的大地图玩法。
> AOI 细节见 [docs/cell-guide.md](cell-guide.md)，状态同步与客户端插值见 [docs/state-sync.md](state-sync.md)，
> 总体架构见 [docs/architecture.md](architecture.md)，插件生命周期见 [docs/plugin-guide.md](plugin-guide.md)。

## 1. 模式总览：大地图 vs 房间制

Nythros 单机进程内跑两种 World 形态，部署侧对应两类服务（均在 `packages/demo/config/deploy.yaml` 声明）：

| 形态 | World 类型 | AOI 注入 | 典型用途 | 部署特征 |
|---|---|---|---|---|
| 大地图（本手册） | `WorldType::AOI` | `GridAOI(10)`（九宫格） | 主城/野外持续世界 | mapId 多频道、可扩缩容、支持跨图迁移 |
| 房间/副本 | `WorldType::FULL_BROADCAST` | `UniversalAOI`（全世界即视野） | 副本/竞技场/守波 | mapId=副本类型 + channelId=pool-N，无空间索引 |

大地图模式的三条铁律：

1. **一频道一进程一 World**：每个 map 服务实例（serviceId 编码 `{mapId}#{channelId}`，如 `map-1#ch-1`）
   就是一个独立 Workerman 进程 + 一个完整 World（`packages/demo/bin/run-worker.php` 头注释；
   组装统一走 `packages/demo/src/MapChannelFactory.php` 的 `MapChannelFactory::attachChannel`）。
   同一张 mapId 可以开多个频道（ch-1/ch-2/...）分摊在线；世界状态不跨进程共享。
2. **客户端直连 Map**：社交层（gateway/chat/team）与战斗层（map）是两类进程。客户端先连 gateway（JSON 协议）
   完成 auth，拿到 `token` 与 map 频道 `wsAddress`，之后**直连 Map 频道**走二进制批量协议（`MapCodec`），
   社交连接全程保持（换线/组队/聊天靠它）。不要把 gateway 当成战斗帧中继。
3. **Redis 是唯一跨进程事实**：token（`nythros:token:`）、服务注册表（`nythros:svc:`）、转移票据
   （`nythros:transfer:`）、任务进度（RedisQuestStore）等跨进程状态全部在 Redis；Map 进程互相不感知。

跨进程互通路径：Map worker 启动即向 `RedisServiceRegistry` register（meta 携带
`mapId/channelId/playerCount/wsAddress/status`，见 `MapServer::onStart`，`packages/demo/src/MapServer.php`），
社交层 `SocialService::selectChannel` discover 后分配频道。

## 2. 拓扑与部署

### 2.1 deploy.yaml 写法（真实键名）

`packages/demo/config/deploy.yaml` 是服务拓扑唯一事实源，解析模型在
`packages/framework/src/Deploy/DeployConfig.php`（`DeployConfig::parseYaml`）。三层结构：

```yaml
redis:                # host/port —— token/注册表/票据共享层
  host: 127.0.0.1
  port: 6379

mysql:                # host/port/user/password/dbname —— 归档（ArchivePipeline::MySqlStorage）
  host: 127.0.0.1
  port: 3306
  user: root
  password: ""
  dbname: nythros

processes:            # 必填段：process 名 => 服务列表
  social:
    - type: gateway
      port: 18285
    - type: chat
      port: 18286
    - type: team
      port: 18287
  map-1:
    - type: map
      mapId: map-1        # map 服务必填
      channelId: ch-1     # serviceId = {mapId}#{channelId}，全局唯一
      port: 18081
    - type: map
      mapId: map-1
      channelId: ch-2
      port: 18082
  map-2:
    - type: map
      mapId: map-2
      channelId: ch-1
      port: 18083
  dungeon:
    - type: map
      mapId: dungeon-A
      channelId: pool-1
      worldType: full     # aoi（缺省）| full
      port: 18084
```

service 键白名单：`type/port/count/mapId/channelId/worldType/pidFile`（`DeployConfig::SERVICE_KEYS`）。
约束（违反即启动期 `InvalidArgumentException` 带行号报错）：

- `type` ∈ `gateway|chat|team|map`；`port` 1~65535 且**全局唯一**；
- map 服务必须声明非空 `mapId + channelId`，serviceId 全局唯一；
- `count`（整数 ≥1，缺省 1）把同一声明展开成多个 worker 进程；
- `pidFile` 可选（Workerman 单实例锁键），缺省按 type+port 生成 `/tmp/nythros-{type}-{port}.pid`。

### 2.2 启动铁序与命令

启动铁序（`bin/server` 头注释）：**Redis（外部，先 ping 检查）→ social → map**——社交组先起，
Map 进程 register 进注册表后社交层才 discover 得到。`bin/server stop` 逆序优雅停（Map → Social）。

统一编排入口是仓库根的 `bin/server`（声明组；`packages/demo/bin/launch.php` 是 maps-only 便捷入口）：

```bash
# 全量启动（social + maps）
NYTHROS_MMORPG=1 setsid -f php bin/server start

# 只起社交组 / 只起地图与副本组
php bin/server start --parts=social
php bin/server start --parts=maps

# 查看运行清单 / 停止
php bin/server status
php bin/server stop
```

`--parts` 取值 `all|social|maps`（`bin/server` 的 `parseCli` 校验）。maps 组内部由根 `bin/start-maps.php`
以 Workerman 原生多频道形态托管（一个 master 管全部频道进程，自带监督/自动重启）；social 组逐角色
spawn `packages/demo/bin/run-worker.php --service=<type>`。

单实例直启（动态扩容、调试都用它，registry 自动注册）：

```bash
php packages/demo/bin/run-worker.php --service=map --mapId=map-1 --channelId=ch-3 --port=18089 \
  --redisHost=127.0.0.1 --redisPort=6379
# 可选：--worldType=full --pidFile=... --mysqlHost=... --mysqlPort=... --mysqlUser=... --mysqlPass=... --mysqlDb=...
```

### 2.3 功能开关（env，装配在 MapChannelFactory）

大地图玩法能力全部 env 门控（缺省关闭 = 接入前语义，零影响）：

| env | 作用 |
|---|---|
| `NYTHROS_MMORPG=1` | 装配 Mmorpg 插件（威胁/重生/热区/死亡掉落/PVP 治理），见 §8 |
| `NYTHROS_ROOMS=1` | 装配 Horde 插件 + 房间中枢 RoomHub（副本/守波） |
| `NYTHROS_GAMEPLAY=1` | 经济批之外的玩法批（Buff/冷却/匹配/任务） |
| `NYTHROS_ECONOMY=1` | 邮件/交易行/账本 |
| `NYTHROS_GM_UIDS=1001` | GM 白名单（逗号分隔），装配 `status/broadcast/kick/drain` 命令总线 |
| `NYTHROS_MAP_CAPACITY=200` | 本实例容量上限（见 §5） |
| `NYTHROS_CONFIG_DIR=packages/demo/config` | 玩法数据外置（gameplay/skills/drops 三表 + 5s mtime 热载） |
| `NYTHROS_MMORPG_HOT_CELL='12:1,25:2,0:4'` | 热区分频档位（格子玩家密度:分频，0=无界兜底末位） |
| `NYTHROS_MMORPG_HOT_CELL_HYSTERESIS_S` / `_NEIGHBOR_RADIUS` | 降温滞回秒数（缺省 5）/ 邻接格外扩半径 |
| `NYTHROS_MMORPG_SAFE_ZONE='0,0,5'` | 出生安全区（x,y,r；必须与出生点同源，attachMmorpg fail-fast） |
| `NYTHROS_MMORPG_PLAYER_RESPAWN_MS` | 玩家自动复活延迟（0=关闭，复活仅路由驱动） |
| `NYTHROS_PVP=1` / `NYTHROS_KILL_CREDIT='damage_leader'` | PVP 开关（缺省关）/ 击杀归属（last_hit 缺省） |
| `NYTHROS_DEATH_DROP=1`（+`_RATIO/_WINDOW_SECONDS/_MAX/_BOUND`） | 玩家死亡掉落策略 |
| `NYTHROS_MMORPG_CHAINS='id=q1,q2;id2=q3'` | 任务链（分号分隔链，等号后逗号分隔任务顺序） |
| `NYTHROS_ARCHIVE_RESTORE=1` | 票据缺席时归档兜底恢复背包 |
| `NYTHROS_RANDOM_SEED=<数字>` | 种子随机源（E2E 复现确定性） |

地图内容（出生点/初始怪物表）在 `packages/demo/config/gameplay.php`（gameplay 表）：
`spawnPoint`、`player.maxHp`、`monsters[]`（`id/typeId/maxHp/anchor/patrolRadius/respawnMs`）。
table 缺席回落 `GameplayTables::defaultTable`；`NYTHROS_CONFIG_DIR` 未设时 spawnPoint 还可由
`NYTHROS_SPAWN_POINT='x,y'` 覆盖。

## 3. AOI 与视野

本节只列大地图模式的关键配置点，AOI 机制详解见 [docs/cell-guide.md](cell-guide.md)，
帧语义/插值/快照重同步见 [docs/state-sync.md](state-sync.md)。

- **格子尺寸**：装配层固定 `new GridAOI(10)`（`packages/engine/src/Aoi/GridAOI.php`；cellSize=10）。
  热区密度统计与 AOI 同源——`MapServer::AOI_CELL_SIZE = 10`（MapServer.php 常量注释明确：
  改格子尺寸必须两处同步改）。
- **视野差分**：实体跨格移动时 `GridAOI::updateEntity` 返回 `entered/left` 差分（同格移动走 fast path
  返回空差分），服务端据此广播 `entity_enter/entity_leave` 结构帧；自身视角用全量快照下发
  （auth 挂载时 `enqueueVisionSnapshot`）。
- **周期快照重同步**：`MapChannelFactory` 以 `snapshotResyncIntervalSeconds: 1.0` 装配 MapServer，
  worker 启动后每 1s 调 `resyncVision` 全量重发视野快照——丢帧/漂移的自愈兜底（client 侧以快照
  全量重建实体表，SDK 重连语义同源）。
- **热区分频**：装配 `NYTHROS_MMORPG_HOT_CELL` 后，世界 tick 内
  `MapServer::tickMmorpg` 每 base tick（50ms）采样玩家密度 → `CellDensityGovernor::sample` 推进热区等级
  （升温即时、降温滞回）→ 给每个实体指派 divisor，并在档位变化时定向下发 `world:tick_rate{divisor}`
  帧让客户端调整插值窗口；玩家移动广播在非到期 tick 被节流（`shouldBroadcastMove`）。

## 4. 跨 map 实体迁移

协议裁决是 **ADR-025 方案 C：客户端驱动换线 + 转移票据**（否决连接代理接力与无缝世界分片）。
全链复用既有 token 一次性语义 / closeConnection detach 钩子 / auth attach 点，**零新增协议帧**。

### 4.1 换线协议（客户端视角）

```
gateway(JSON)                     map-1 进程                        map-2 进程
  │ map:enter {mapId:'map-2'}        │                                 │
  │ ← map:entered {token,            │                                 │
  │     map{wsAddress,mapId,channelId}}（SocialService::handleMapEnter 选频道+重签 token）│
  │ close() 旧 Map 连接 ──────────→ detach：closeConnection 清理路径导出转移票据 │
  │ 新地址 ws 握手 + auth{token} ──────────────────────────────────→ attach：原子消费票据重建实体
  │ ← auth_ok {uid, id}                                                    │
  │ map:join{mapId,channelId}（社交层更新会话与位置快照）                    │
```

关键实现点：

- `SocialService::handleMapEnter`（`packages/framework/src/Social/SocialService.php`）：mapId 白名单校验 →
  `selectChannel` → `tokenManager->issue($uid, $mapId, ['map'], TOKEN_TTL)` 签发一次性 map-scope token →
  `map:entered` 下发 `token` + `map{wsAddress, mapId, channelId}`。
- **网关全消息校验数字 timestamp**（auth 之外的 `map:enter` 同样要求，缺省拒绝 400）——实测踩坑，
  自研客户端必须每帧带 `timestamp`。
- detach 导出：`MapServer::exportTransferSnapshot`（挂在 `onEntityCleanedUp`，closeConnection/evict/kick
  全部断连路径汇入），把 `fromMapId/position/hp/inventory` 写入票据；**hp clamp ≥1——死亡态不迁移**。
- attach 消费：`MapServer::handleAuthMessage` 里 `consumeTransferSnapshot` **原子取走**票据：
  位置仅**同图**恢复（异图回落目的图 `spawnPoint`——经典换线语义）；背包/血量同图异图均恢复
  （`importHp` clamp 进 [1, 合成 maxHp]）；出生保护窗口照常启用（防落地集火）。

### 4.2 转移票据（Redis key 与原子单消费）

实现：`packages/framework/src/Cluster/RedisPlayerTransferStore.php`（契约
`PlayerTransferStoreInterface`，单进程形态 `InMemoryPlayerTransferStore`）。

- **key**：`nythros:transfer:{uid}`（前缀缺省 `nythros:`，与 token 的 `nythros:token:`、注册表的
  `nythros:svc:` 严格分离）。
- **导出**：`SETEX` 覆盖写，TTL 缺省 **30s**——源端导出后客户端未在窗口内重连，票据过期回落全新入场。
- **消费**：Lua `GET+DEL` 单脚本原子单消费（不依赖 Redis 6.2 GETDEL）——第二次 auth 拿不到票，
  天然防重放。
- **快照形状**：`{fromMapId: string, position: {x,y}, hp: int(≥1), inventory: {itemId: count}}`。
- **故障方向**：消费失败/TTL 过期/坏 JSON 一律回落「全新入场」——变保守不变错。
- 票据之外的状态不迁移：房间归属/匹配队列走既有 Redis 持久化重进，任务进度本就在 RedisQuestStore，
  装备挂载重登重建；`NYTHROS_ARCHIVE_RESTORE=1` 时票据缺席再兜底读 MySQL 归档
  （`MapServer::restoreInventoryFromArchive`）。

E2E 参考：`packages/demo/bin/verify-transfer.php`（登录 → 承伤致死 → `map:enter` 换图 →
「迁移后首击 hp≤1」的区间分离断言验证 hp=1 经票据恢复）。

### 4.3 步骤：新增一张地图并打通换线

1. `packages/demo/config/deploy.yaml` 的 `processes` 下新增部署单元：

   ```yaml
   map-3:
     - type: map
       mapId: map-3
       channelId: ch-1
       port: 18085
   ```

   （端口全局唯一；mapId 自动进入白名单——`DeployConfig::mapIds()` 按拓扑声明收集，
   `bin/server` 据此注入社交层的 `NYTHROS_MAP_IDS`，无需另声明。）
2. 重启/扩容：`php bin/server stop && php bin/server start`（或对存量部署直接
   `run-worker.php --service=map --mapId=map-3 --channelId=ch-1 --port=18085` 直启新实例）。
3. 地图内容：编辑 gameplay 表（`NYTHROS_CONFIG_DIR` 指向的目录）给 map-3 配出生点与怪物
   （当前 demo 的 gameplay 表全 mapId 共用一份；每图差异化内容需在装配层按 mapId 分流——待验证，
   demo 未内建 per-map 表路由）。
4. 客户端换线：社交连接上发 `map:enter{mapId:'map-3'}` → 按 §4.1 时序断旧连、连新地址、auth、`map:join`。
5. 验证：`php packages/demo/bin/verify-transfer.php`（需 map-2 存在的拓扑）为全链参考实现。

## 5. 容量准入与动态扩缩容

三个缺口全部零新增协议帧补齐：容量准入、摘除（scale-in）、扩容（scale-out）。

### 5.1 容量准入：maxCapacity 双保险

- **装配**：`NYTHROS_MAP_CAPACITY=200`（数字，0=不限量缺省）→ `MapChannelFactory` 传入
  `MapServer` 构造参数 `maxCapacity`。
- **路由侧软过滤**：`MapServer::onStart` 注册 meta 携带 `maxCapacity`；
  `SocialService::selectChannel` 跳过 `playerCount >= maxCapacity` 的实例。
- **auth 侧硬守卫**：`MapServer::admissionRejection` 在 token 消费**前置**裁决——
  满员拒绝 `auth_failed{code:403, reason:'map_full'}`（token 保留，客户端可重连其他频道）。
  这兜住 selectChannel 与 attach 之间的并发窗口（心跳 5s 水位有滞后）。

### 5.2 draining 生命周期（GM drain 命令）

```
gm:exec{command:'drain'} → GmCommandBus → DrainCommand（framework/src/Gm/Command/DrainCommand.php）
  → MapServer::drain()（实现 GmDrainHandlerInterface）：
      本地 draining=true（新 auth 一律拒绝 reason:'draining'，token 不消费）
      + registry 心跳 meta 置 status=draining（Lua 原子合并，只覆盖 status 字段）
  → selectChannel 过滤 draining/stopping → 目录不再路由新会话；存量连接不受影响
  → 在场玩家归零后由外部编排停机摘除（bin/server stop / map-rolling.php watch）
```

重复 drain 幂等返回 error 态（`gm:result{code:'error'}`）。使用前提：`NYTHROS_GM_UIDS` 含发起者 uid。

### 5.3 目录路由过滤汇总

`SocialService::selectChannel`（framework/src/Social/SocialService.php）对 `discover('map')` 的过滤链：

1. `meta.mapId === 请求 mapId`；
2. `meta.status` ∉ `{stopping, draining}`；
3. 声明了 `maxCapacity` 且 `playerCount >= maxCapacity` 的实例跳过；
4. 分配策略：**同图重入优先会话频道**（session loc → Redis 位置快照兜底，保证登录确定性），
   原频道不在候选才最少在线重选（`minPlayerCount`，playerCount 缺失视为满员永不误选）；
5. 全部被过滤 → `map:error{code:503, message:'no available channel'}`。

### 5.4 动态扩容发现 E2E（verify-scale.php 实测流程）

```bash
# 前置：Redis 可用；deploy.yaml 全拓扑（map-1 两频道）+ GM 白名单
NYTHROS_MMORPG=1 NYTHROS_GM_UIDS=1001 setsid -f php bin/server start
php packages/demo/bin/verify-scale.php
```

四步（`packages/demo/bin/verify-scale.php`）：

1. **登录**：gateway auth → Map(map-1) 直连 auth_ok，记录 auth_ok 下发的 channelId（ch-A）；
2. **drain**：`gm:exec{command:'drain'}` → `gm:result ok`，本实例 status=draining；
3. **缩容路由**：`map:enter{mapId:'map-1'}` → map:entered 落到非 ch-A 的频道（drained 实例被过滤）→
   重连 auth_ok → `map:join` 更新会话；
4. **动态扩容**：shell 直启 `map-1#ch-3` worker（`run-worker.php --service=map --mapId=map-1
   --channelId=ch-3 --port=18089`，registry 即时可见，注册即 discover 可见——心跳 15s 水位只影响回收）
   → **先 drain 当前频道**（否则同图重入的「会话频道优先」直接命中原频道，实测踩坑）→
   `map:enter` 断言落点 channelId == 'ch-3'（0 玩家最少在线优先）。

「何时扩/缩」的决策是部署编排层关切（观测 registry meta 的 playerCount/status 做脚本或 K8s HPA），
engine 侧原语（注册/发现/过滤/drain）已齐备；**自动扩缩容器 demo 未内建（待验证：官方无现成实现）**。

## 6. 滚动更新

编排脚本：`packages/demo/bin/map-rolling.php`（ADR-015 §3，一次性 CLI 直连 Redis，两个子命令）：

```bash
# ① 新版本实例先起好（run-worker.php 直启或换配置重启 maps 组），确认 serving
php packages/demo/bin/run-worker.php --service=map --mapId=map-1 --channelId=ch-1 --port=18081 ...

# ② 标记旧实例 stopping（heartbeat merge 只覆盖 status，mapId/channelId/wsAddress 保留）
php packages/demo/bin/map-rolling.php mark-stopping map-1#ch-1

# ③ 轮询 watch 该实例 meta.playerCount：归零提示「可安全 stop」；--timeout 超时提示「强制 stop」
php packages/demo/bin/map-rolling.php watch map-1#ch-1 --timeout=600

# ④ 按提示 stop 旧进程（kill / bin/server stop）
```

完整流程语义：

1. **新实例 serving**：新版本 worker 直启，registry 自动注册，selectChannel 立即可选；
2. **旧实例 stopping**：`mark-stopping` 置 status=stopping，目录过滤停止分配新会话
   （与 draining 同一过滤分支；区别是 stopping 通常配合 watch 等归零，draining 走 GM 命令在线热摘）；
3. **社交层 discover 过滤**：过滤发生在 `SocialService::selectChannel`，无需重启社交层；
4. **自然退出**：watch 每隔 5s 轮询，`playerCount <= 0` 即安全停机；实例从 discover 消失
   （心跳键过期 15s 或已 unregister）也视为已停止。超时强制 stop 时，剩余玩家会走 §7 的
   断线重连/票据迁移自愈——「剩余玩家将断线重连自迁移」。

等价替代：不标 stopping、直接对旧实例发 GM `drain` 也可（draining 同样被 selectChannel 过滤），
适合在线运维通道齐全的部署。

## 7. 断线重连即迁移

SDK：`packages/client-js/nythros-client.js`（NythrosClient），参考示例
`packages/client-js/examples/reconnect-demo.js`。

- **开关与参数**：`autoReconnect`（缺省 false 向后兼容）/ `maxReconnectAttempts`（缺省 5，
  耗尽派发 `:reconnectfailed`）/ `reconnectDelayMs`（缺省 2000，逐次 ×2^n 指数退避，上限 30s）。
- **触发条件**：底层 socket 断开（崩溃/网络闪断等价——**不经 `close()`**）；用户主动 `close()`
  被 `closedByUser` 守卫拦截，不触发重连。
- **重连链路 = 整链重登**：gateway 重签 token → Map 重连 attach。服务端在 detach 时已自动导出
  转移票据（§4.2），重连 attach 原子消费——**重连即同图迁移**：同图恢复权威位置
  （reconnect-demo 实测：move 到 (30,40) 后强断，重连落点即 (30,40)）、血量、背包。
- **本地合成事件**：`:reconnecting` / `:reconnected` / `:reconnectfailed`（经 handlers 派发，
  非线上帧）；重连成功清空实体表，attach 后由视野快照全量重建（state-sync 口径）。

客户端 checklist：

1. 构造 `NythrosClient({autoReconnect: true, ...})`，监听三个合成事件更新 UI 状态；
2. 重连期间进行中的 request 会被 reject（pending 清空）——调用方需自行重试；**不做请求重放**
   （离线队列有重复扣费类风险，blueprint/31 边界）；
3. 不要缓存跨连接的 entityId（`entityId = uid@connId`，重连后 conn 段更替）；
4. 每条消息带数字 `timestamp`（gateway 校验）；二进制 Map 帧/JSON 社交帧的编解码直接用 SDK；
5. 渲染读 `interpolator.sample()`、判定读 `interpolator.position()`（详见 docs/state-sync.md）；
6. 换线场景（跨图）SDK 不自动发起——需业务层发 `map:enter` 后按 §4.1 时序处理（同图断线重连
   则全自动）。

## 8. 玩法插件：Game/Horde 与 Game/Mmorpg

两大玩法插件都在 `packages/framework/src/Game/`，遵循 ADR-020 §4 的「配置型插件」形态：
framework 提供参数与规则，starter-kit（`MapChannelFactory`/`MapServer`/`RoomHub`）装配消费。
插件本体只做一件事——经 `PluginRegistry::load` 把配置注册进 Container（`register/enable/disable/uninstall`
四态生命周期，见 docs/plugin-guide.md）。

### 8.1 Game/Mmorpg（大地图玩法内核）

| 类（`packages/framework/src/Game/Mmorpg/`） | 职责 |
|---|---|
| `MmorpgPlugin` | 配置型插件，Container id `mmorpg.config`（`CONFIG_ID` 常量） |
| `MmorpgConfig` | 只读参数聚合：`aggroRange`/`threatDecayPerSec`/`tauntMultiplier`/`maxThreat`（威胁组）、`respawnMs`/`spawnDensity`（重生组）、`playerRespawnMs`（自动复活）、`safeZone`（安全区）、`attackRange`、`hotCell`（热区策略）、`questChains`（任务链）、`deathDrop`、`pvpEnabled`、`killCredit`（`last_hit` 缺省 / `damage_leader`） |
| `ThreatRules` / `ThreatTable` | 威胁纯规则（aggro 选择/衰减/嘲讽倍率/上限钳制）与 per-actor 威胁状态（`addThreat` 距离门/`applyTaunt`/`decay` 衰减到零自动摘除/`selectTarget`），由 MonsterActor 持有 |
| `Respawner` | 纯调度组件：`registerDeath(monsterId, now, overrideMs)` → `due(now)` 到期查询 → 消费方 `clear`；实际重生回锚点由装配层执行 |
| `CellDensityGovernor` + `HotCellPolicy` | 热区分频策略层：每 base tick 采样玩家密度 → 档位推导（升温即时/降温滞回 `hysteresisSeconds`）→ `divisorFor(x,y)` 查询（含 `neighborRadius` 邻接格取最热档） |
| `DeathDropPolicy` | 玩家死亡掉落：逐单位 roll `dropRatioPercent`、绑定物品 `boundItemIds` 恒不掉、`maxDropsPerDeath` 上限、`ownerWindowSeconds` 击杀者/同队专享窗口（复用 DropEntity not_owner 拾取保护，零新协议） |

装配接线（`MapChannelFactory`，env `NYTHROS_MMORPG=1`）：构造 `MmorpgConfig` → 插件 load/enable →
`MapServer::attachMmorpg($config, $combatEvents)`：

- **安全区/出生点同源校验**：`safeZone` 圆心与 `spawnPoint` 不一致直接 `LogicException`（装配期 fail-fast，
  防止复活落点在保护区外）；
- 按配置创建怪物 `Respawner` 与（`playerRespawnMs > 0` 时）玩家自动复活调度器；热区策略存在时创建
  `CellDensityGovernor`（cellSize 与 AOI 同源 10）；
- 订阅 `CombatService::EVENT_KILL`：怪物死亡登记重生（逐怪 `respawnMs` 覆盖全局）、玩家死亡走
  `dropInventoryOnDeath` 死亡掉落与自动复活登记。

世界 tick 内 `MapServer::tickMmorpg`（每 50ms）驱动：热区采样与分频指派（档位变化下发
`world:tick_rate{divisor}`）→ 怪物威胁衰减（`decayThreats`）→ 重生到期消费（每锚点按 `spawnDensity`
重生，副本 id 加 `#N` 后缀并沿对角偏移 `densityPosition` 避免重叠；仅锚点本体登记重生防指数增长；
先 spawn 后 clear 保证异常可重试）→ 玩家自动复活到期消费（与路由 `player:revive` 共用 `applyRevive` 核心）。

### 8.2 Game/Horde（房间守波玩法）

| 类（`packages/framework/src/Game/Horde/`） | 职责 |
|---|---|
| `HordePlugin` | 配置型插件，Container id `horde.config` |
| `HordeConfig` | `waves`（波次定义，至少一波否则构造拒绝）/`periodMs`（房间 tick 50ms）/`maxMembers`（512）/`aoeMaxRadius`（300，room:aoe DoS 防线）/`dropStorm`/`spawnProtection`/`settlement` |
| `WaveDefinition` | 一波怪的网格布局与战斗参数，`positionAt(index)` 纯函数行优先生成刷怪坐标 |
| `SettlementRules` | 结算纯函数：`isCleared(spawnedCount, killedCount)` 按 `minKillRatio`（缺省 100=全清）判定 |
| `DropStormConfig` / `SpawnProtectionConfig` | 掉落寿命（300s）/ 出生保护窗口帧数（60 帧） |

装配在 `NYTHROS_ROOMS=1` 下：Horde 配置注入 `RoomHub` 与 `MapServer`（出生保护帧数取
`$hordeConfig->spawnProtection->frames` 覆盖 `PlayerActor::SPAWN_PROTECTION_FRAMES` 基准）。
这是「大地图 + 动态小容器」的混合形态：世界层 AOI + 房间层独立 tick 预算
（`RoomInstanceManager`，宿主心跳 15ms、tick 预算 9ms）。

### 8.3 如何借用它们组织自己的大地图玩法

1. **参数归 framework、装配归 starter-kit**：模仿 MmorpgPlugin 写一个配置型插件（构造注入你的
   Config，`register` 时 `$container->set(self::CONFIG_ID, $config)`），在 `MapChannelFactory`
   加一段 env 门控装配（解析 env → 构造 config → `PluginRegistry::load` → 从 Container 取出 →
   注入 MapServer 或新服务）。
2. **复用四条既有骨架**，不要另起炉灶：
   - 怪物：`MapServer::spawnMonster(monsterId, maxHp, position, typeId, patrolRadius, respawnMs)`
     登记出生参数；死亡走 `EVENT_KILL` 订阅登记重生；
   - 威胁/仇恨：`ThreatTable/ThreatRules` 直接构造即可单测（纯函数 + 状态组件分离）；
   - 重生/复活：`Respawner` 纯调度 + 世界 tick 消费（先 due 后 clear）；
   - 热区：`HotCellPolicy` 档位表（构造期校验单调性/无界兜底末位）+ `CellDensityGovernor`。
3. **数据外置**：玩法数值走 gameplay/skills/drops 三表（`NYTHROS_CONFIG_DIR`，schema 校验、
   坏表启动 fail-fast、热载回滚），`MapServer::applyGameplayConfig` / `replaceDropTable` 是热载换入点。
4. **E2E 验收脚本化**：照 `packages/demo/bin/verify-mmorpg.php`（威胁切换/重生/任务链步骤矩阵）
   的 `[verify] PASS/FAIL` 输出契约写你的玩法验收。

## 9. 性能运维

### 9.1 PerfSampler 指标

运行期采样（`packages/framework/src/Observability/PerfSampler.php`，`MapChannelFactory` 装配为
每 5s 读 `PerfProbe::instance()` 快照写 Redis；采样失败只记日志）。Redis 键约定：

```
nythros:perf:{serviceId}:counters   Hash  事件计数（单调累计，观测端取差值）
nythros:perf:{serviceId}:hist       Hash  帧耗时桶分布（桶界 0/0.5/1/2/4/8/16/32/64ms）
nythros:perf:{serviceId}:totals     Hash  指标累计 ms
nythros:perf:{serviceId}:unique     HLL   唯一连接/实体估数（PFADD）
nythros:perf:{serviceId}:last       String JSON 最近快照时间戳 + 业务指标
```

查询脚本（只读 Redis，不触碰服务进程）：

```bash
php packages/demo/bin/perf-stats.php --serviceId=map-1#ch-1 [--json]
# 输出最近采样窗口的帧耗时 P50/P90/P99（桶分布近似）、信封吞吐、出站字节/包
```

### 9.2 压测脚本（benchmarks/）

```bash
# Map 频道并发压测（真实 WS 链路）：连接数/auth 成功/帧吞吐 fps/peak/到达延迟 P50-P99/字节吞吐
php benchmarks/stress-map.php --clients=50 --seconds=15 [--json]

# 热区聚团混战压测：观测 world:tick_rate 降/升往返、每客户端带宽/帧率、maps worker CPU/RSS（/proc 采样）
#   服务器以低密度热区阈值启动以便少量 bot 触发降频（脚本头有完整 boot 命令）
php benchmarks/stress-hotzone.php --players=30 --phase1=30 --phase2=15

# 房间容量压测：M 房 × 6 bot（room:create/join/spawn/aoe），观测 RSS/CPU 与心跳指标 rooms/roomsDeferred
php benchmarks/stress-rooms.php --rooms=15 --seconds=25
```

### 9.3 容量判定经验

- **在线数口径**：`playerCount`（auth 成功 +1、已认证连接清理 -1）随 5s 心跳上报，注册表 TTL 15s——
  扩缩容决策读 registry meta 即可，别自己另建计数通道；
- **预算结构**：每频道 `RegionScheduler(totalBudgetMs: 6.0)` 分 actors 2.0 / network 3.0 / maintenance 1.0
  三个分区（`MapChannelFactory::attachChannel`），世界 tick 50ms；帧耗时分布看 perf hist，
  桶越过 8~16ms 即预算吃紧信号；
- **热区先行**：大地图玩家聚集是第一负载源——先用 `NYTHROS_MMORPG_HOT_CELL` 档位（如 `'12:1,25:2,0:4'`）
  把聚团区的实体 tick 降下来（50ms base tick 下 divisor 1/2/4 即 20/10/5Hz），再谈加进程；
- **扩容信号**：单频道 playerCount 逼近 `NYTHROS_MAP_CAPACITY`、或 perf counters 的 auth 拒绝
  （`map_full`）出现，即加频道（同 mapId 加 channelId）或加 mapId；
- **压测结论复测**：stress 脚本输出为开发机实测，绝对值以目标硬件复测为准（脚本头声明）。

## 10. 反模式清单

1. **把 gateway 当战斗帧中继**——客户端必须直连 Map 频道；gateway 只承载登录/社交/换线凭证下发。
   经 gateway 转发 move/attack 会多两跳进程间开销且破坏 MapCodec 二进制批量路径。
2. **给迁移/扩缩容新增协议帧**——迁移与扩缩容的设计裁决就是零新增帧（复用 map:enter/auth/gm:exec）；
   自造 `transfer:*` 之类的帧是在倒退回被否决的 ADR-025 备选方案。
3. **同图重入不做频道偏好管理就期待扩容实例接客**——会话频道优先级高于最少在线；
   扩容后必须 drain（或 stopping）旧频道，新实例才有流量（verify-scale 实测踩坑）。
4. **把转移票据当持久存储**——TTL 30s、原子单消费、同图才恢复坐标。长线离线恢复走
   `NYTHROS_ARCHIVE_RESTORE=1` 的归档兜底，不是票据。
5. **改 AOI 格子尺寸只改一处**——`GridAOI(10)`（MapChannelFactory）与 `MapServer::AOI_CELL_SIZE`
   （热区密度统计）必须同步；不同源会让热区分频按错误密度采样。
6. **在帧路径里做同步 I/O**——持久化一律走 `ArchivePipeline` 的 markDirty（零 I/O）→
   断连/登出 `flushId` 强制同步点 → 30s 兜底批量 `saveBatch`（`FLUSH_INTERVAL_SECONDS`）。
   直接在 move/attack 路由里写 MySQL/Redis 会击穿 6ms 预算。
7. **把玩法参数硬编码进 framework**——framework 只放参数与规则（MmorpgConfig/ThreatRules 等），
   env 解析与装配留 starter-kit（唯一组装点铁律）；数值进 gameplay/skills/drops 表享受热载。
8. **一个 World 服务多张图 / 一个频道多 World**——大地图水平扩展的单位是频道
   （`{mapId}#{channelId}`）；试图单进程多 World 或跨图共享实体表都会破坏迁移与扩缩容语义。
9. **drain 后直接 kill**——draining 只挡新会话；存量玩家归零检测用
   `map-rolling.php watch <serviceId>`（或观测 registry meta），归零再停；强制停机的残余玩家
   依赖断线重连自愈，别把这当成常规摘除路径。
10. **忽视网关 timestamp 校验**——所有发往社交层（含 `map:enter`）的消息都必须带数字
    `timestamp` 字段，缺省拒绝 400；自研客户端/压测 bot 最常见的假性「登录失败」即源于此。
11. **给 `MmorpgConfig`/`HotCellPolicy` 填非法参数指望运行期容错**——构造期即 fail-fast
    （档位单调性、无界兜底末位、安全区与出生点同源等校验）；带病启动会把不变量责任泄漏到运行期。

---

## 附：本文档引用的关键代码路径速查

| 主题 | 路径 |
|---|---|
| 频道组装 | `packages/demo/src/MapChannelFactory.php`（attachChannel / mmorpgConfigFromEnv） |
| Map 服务本体 | `packages/demo/src/MapServer.php`（handleAuthMessage / exportTransferSnapshot / consumeTransferSnapshot / drain / admissionRejection / attachMmorpg / tickMmorpg / dropInventoryOnDeath） |
| 部署模型 | `packages/demo/config/deploy.yaml`、`packages/framework/src/Deploy/DeployConfig.php`、`bin/server`、`bin/start-maps.php`、`packages/demo/bin/run-worker.php`、`packages/demo/bin/launch.php` |
| 换线/路由 | `packages/framework/src/Social/SocialService.php`（handleMapEnter / handleMapJoin / selectChannel / minPlayerCount） |
| 转移票据 | `packages/framework/src/Cluster/RedisPlayerTransferStore.php`、`PlayerTransferStoreInterface.php`、`InMemoryPlayerTransferStore.php` |
| 扩缩容 | `packages/framework/src/Gm/Command/DrainCommand.php`、`packages/framework/src/Gm/GmDrainHandlerInterface.php`、`packages/demo/bin/verify-scale.php`、`packages/demo/bin/map-rolling.php` |
| AOI | `packages/engine/src/Aoi/GridAOI.php`、`packages/engine/src/Aoi/UniversalAOI.php`、`docs/cell-guide.md` |
| 玩法插件 | `packages/framework/src/Game/Mmorpg/*`、`packages/framework/src/Game/Horde/*`、`packages/demo/config/gameplay.php` |
| 持久化/观测 | `packages/framework/src/Persistence/ArchivePipeline.php`、`packages/framework/src/Observability/PerfSampler.php`、`packages/demo/bin/perf-stats.php`、`benchmarks/stress-map.php`、`benchmarks/stress-hotzone.php`、`benchmarks/stress-rooms.php` |
| 客户端 | `packages/client-js/nythros-client.js`、`packages/client-js/examples/reconnect-demo.js`、`packages/client-js/examples/mmorpg-canvas.html` |
| E2E 验收 | `packages/demo/bin/verify-transfer.php`、`packages/demo/bin/verify-scale.php`、`packages/demo/bin/verify-mmorpg.php` |
