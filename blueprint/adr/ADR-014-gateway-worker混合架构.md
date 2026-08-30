# ADR-014：gateway-worker 混合架构（接入+社交层）与数据边界

> 状态：已决策。本文记录从「纯自研」到「gateway-worker 混合架构」的转向，修订 ADR-010。

## 1. 背景与转向理由

阶段 2 的 ADR-010 定「不引入 gateway-worker，纯自研」。经过阶段 4 实践与多轮讨论，转向引入 gateway-worker 做「接入 + 社交层」，自研 Map 继续做「战斗层」。

转向理由（gateway-worker 的两个真实价值，自研在此是短板）：

1. **多 Gateway 分散连接**：玩家连不同 Gateway（gateway1/2/3）也能互聊，全服社交服务可水平扩展。自研的 Chat 是全服单实例，扩展需自建 GroupRegistry。
2. **Business 热更新不断连**：连接在 Gateway 进程，reload BusinessWorker 更新业务逻辑不断开客户端连接。自研「服务自管连接」模型下 reload 会断连。

**修订 ADR-010**：从「不引入 gateway-worker」修订为「引入 gateway-worker 仅做接入+社交层；战斗层（Map/副本）继续自研直连」。铁律：**战斗帧同步必须客户端直连 Map，不经过 gateway-worker 转发**（避免高频多一跳开销）。

## 2. 三层架构（最终形态）

```text
客户端 ──社交连接──> gateway-worker（Gateway 管连接 + Business 无状态业务）
  │                   ├─ 登录、分组维护、信息发送
  │                   └─ 多 Gateway + Business 热更新
  ├─ 战斗连接（直连）──> Map/副本（自研，有状态，帧同步）
  └─ 协调：Redis（登录凭证 + 战斗服务器注册/发现）
```

- **gateway-worker**：登录、地图频道/帮派/队伍/副本分组维护、信息发送。Business 无状态可随时热更新替换。
- **Redis**：登录凭证（token）+ 战斗服务器注册/发现；其余临时数据（组队等）也存 Redis 带 TTL。
- **Map/副本**：有状态（帧级战斗），不能 reload，用「滚动更新」。

## 3. 数据边界（四类状态 + 判定规则）

| 状态层 | 落点 | 生命周期 | 判定标准 |
|---|---|---|---|
| 持久状态 | Redis/DB | 永久 | 掉线/重启不丢（账号/角色/货币/帮派） |
| 临时共享状态 | Redis（TTL） | 掉线超时 | 临时但跨会话（组队/token/心跳/掉线标记） |
| 分组状态 | Gateway | 在线会话 | 谁在哪个分组（joinGroup/bindUid） |
| 业务状态 | Business | 瞬时 | 邀请过期等，reload 丢了重来 |
| 战斗状态 | Map/副本 | 帧级 | AOI/位置/血量，本进程内 |

判定口诀：**「要保留」→ 永久进 DB/Redis、临时带 TTL 进 Redis；「要跨在线共享」→ 分组进 Gateway；「可丢」→ Business；「帧级高频」→ Map。**

关键案例：
- **组队**是「临时但存 Redis TTL」——掉线后 Redis 保留队伍（TTL 超时清除），在线期间分组关系在 Gateway。
- **同一概念拆多层**：位置 = 频道级（在 Gateway+Redis）、坐标级（在 Map）；队伍 = 关系（Redis TTL）、在线成员（Gateway）、邀请（Business）。

## 4. Gateway 分组方案

- **uid = 角色 id**（账号多角色时，登录后选角色再 bind）。
- **groupId 命名**（`类型:标识` 前缀隔离）：
  - 频道 `map:{mapId}:{channelId}`
  - 队伍 `team:{teamId}`
  - 帮派 `guild:{guildId}`
  - 副本 `dungeon:{instanceId}`
  - 世界走 sendToAll，无需分组
- **API 映射**：世界→sendToAll；频道/队伍/帮派聊天→sendToGroup；私聊→sendToUid（先 isUidOnline）；组队邀请→sendToUid。
- **自动解绑**：bindUid/joinGroup 在连接断开时自动解绑（gateway-worker 内置），开发者无需手动 unbindUid/leaveGroup。掉线时 Gateway 自动清理分组，开发者只处理 Redis 掉线标记（TTL）+ 保留队伍/位置。
- **生命周期**：登录 bindUid+恢复分组；进图 joinGroup(频道)；组队 joinGroup(team)；切图 leave+join；进副本 leave(旧频道)+join(dungeon)；出副本 leave(dungeon)+join(返回频道)；掉线自动解绑+Redis 写掉线标记；重连未超时恢复 joinGroup，超时清 Redis 按新登录。

## 5. Map/副本滚动更新（有状态热更新）

新服务启动 → 旧服务在 Redis 标记「stopping」→ Business 不再把新玩家分配到旧服务 → 新服务「serving」→ 旧服务上玩家自然退出/超时迁移 → 旧服务销毁。

副本实例本身有生命周期（打完销毁），与频道滚动更新节奏分开处理。

## 6. 对阶段 4 成果的影响

- **复用**：RedisTokenStore（token 五态）、RedisServiceRegistry（战斗服务器注册/发现/心跳/自愈）——直接复用，几乎零改动。
- **替代**：阶段 4 自研 Chat/Team 的「连接管理 + 对称直连 + 内部握手」层，被 gateway-worker 的 Gateway 替代；组队状态机、聊天分组广播等业务逻辑迁移到 BusinessWorker。
- **保留**：Map 战斗层（AOI/九宫格/帧同步）完整保留。

## 7. 运行时数据流（关键流程）

- **登录**：Business 查 Redis（账号+签发 token+位置快照）→ Gateway bindUid + 下发 Map 地址。
- **进图**：Map 校验 token + 初始 AOI 快照；Gateway joinGroup(频道)。
- **战斗**：纯 Map 进程内，零转发。
- **聊天**：Gateway sendToGroup/sendToAll。
- **组队**：Business 状态机 + 写 Redis 队伍 TTL；Gateway joinGroup(team) + sendToUid 邀请。
- **切图/副本**：Business 写位置 returnPosition；Gateway leave+join；客户端断旧连新。
- **掉线**：Gateway 自动解绑；Business 写掉线标记 TTL；Redis 保留队伍。
- **重连**：未超时恢复分组，超时清除。

## 8. 待阶段 5 落地时细化

- Business 里「分组状态 vs 业务状态」的具体边界（哪些进 joinGroup，哪些进 Business 内存/Redis）。
- Map 滚动更新的「stopping 迁移策略」（自然退出 vs 超时迁移）。
- gateway-worker 与自研服务进程的部署编排（谁先启动、如何配置）。
