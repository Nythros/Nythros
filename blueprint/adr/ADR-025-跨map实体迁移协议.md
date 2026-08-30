# ADR-025 · 跨 map 实体迁移协议（P15）

- 状态：**已采纳（Accepted）**，随 P15 批实现（blueprint/27 验收记录）
- 日期：2026-08-29
- 关联：ADR-021（统一网关栈）、ADR-015（位置快照/掉线恢复）、ADR-024（容器维度 V6 / RoomHub transfer 编排）、blueprint/21 P15

## 1. 背景与问题

多 map 部署（deploy.yaml：map-1 两频道 / map-2 / dungeon-A）下，实体状态（位置/血量/背包）是
**map worker 进程内私有的**（`MapServer::$inventories` / PlayerActor hp / 实体坐标）。玩家切图
（map:enter）或重连到另一 worker 时，这些状态全部丢失——背包归零、落点回出生点、血量回满。
归档管线（ADR-013）只写不读（设计即"归档"），不能作为迁移读路径。

跨服务迁移在 MMO 工程中有三类经典方案，需裁决本仓库采用哪一类。

## 2. 备选方案（经典网游工程方案对比）

| 方案 | 代表实践 | 适配性分析 | 结论 |
|---|---|---|---|
| **A. 连接代理接力**（connection handoff / TCP splicing）：旧服务把客户端 TCP 字节流移交给新服务，连接不断 | EVE Online 无缝世界、部分自研网关 | WS 握手与 Workerman worker 模型下无法移交已建立的连接（连接归属 worker 进程的事件循环）；需要独立代理层（额外一跳、延迟与运维面） | **否决**——与 PHP worker 模型冲突，成本远超收益 |
| **B. 无缝世界 / 共享状态区**（seamless world / sector authority）：世界坐标分片，多服务器共同权威同一连续空间，边界区由主从协调 | EVE、无缝大世界引擎 | 需要跨进程空间权威仲裁与实体跨进程 tick——本仓库的引擎模型是「一频道一 World 一进程」（ADR-024），引入分片权威等于重写空间层 | **否决**——重构面不可接受，且 demo 规模不需要 |
| **C. 客户端驱动的区域迁移 + 转移票据**（client-driven zoning + transfer ticket，即 WoW/传统分线服的经典换线协议）：客户端向目录服务请求迁移 → 目录签发一次性凭证 → 客户端断开旧服（旧服在 detach 时把实体状态快照写入共享存储）→ 凭新凭证连新服（attach 时单次消费快照重建实体） | WoW 分区/分线、MQ 游戏服迁移、经典 zone server 架构 | 与既有机制**逐件契合**：map:enter 已是「目录签发一次性 token + 下发目标地址」的目录服务（ADR-015 §1.7）；token 体系天然一次性（per-scope 墓碑）；closeConnection 模板已有统一的 detach 钩子；auth 已是统一的 attach 点 | **采纳** |

## 3. 裁决：方案 C 的协议设计

### 3.1 迁移时序（全链复用既有原语，零新增协议帧）

```
客户端                    网关(Social)                 源 map A                Redis                 目的 map B
  │ map:enter{mapId}──▶│                              │                      │                      │
  │                    │ 选频道(负载感知) + issue(['map'])│                      │                      │
  │◀──map:entered──────│  {token, map{wsAddress,...}}  │                      │                      │
  │ close(旧连接)──────────────────────────────────▶│ detach：closeConnection│                      │
  │                    │                              │ 导出快照 ──────────▶│ SETEX transfer:{uid}  │
  │ connect(新地址)──────────────────────────────────────────────────────────────────────────────────▶│
  │ auth{token}─────────────────────────────────────────────────────────────────────────────────▶│ peek/consume('map')
  │                    │                              │                      │◀──GETDEL transfer:{uid}─│
  │◀──auth_ok{id}───────────────────────────────────────────────────────────────────────────────│ 快照重建实体
  │ map:join{...}───▶│（既有确认路径，写位置快照/分组）  │                      │                      │
```

### 3.2 快照契约（`nythros:transfer:{uid}`，SETEX 30s，GETDEL 原子单消费）

```php
[
  'fromMapId' => string,          // 源地图 id
  'position'  => ['x'=>int,'y'=>int], // 源坐标
  'hp'        => int,             // clamp ≥1（不迁移死亡态——跨图入场复活为满血属既有 revive 语义）
  'inventory' => array<string,int>,   // itemId => count 全量背包
]
```

### 3.3 重建规则

- **同图迁移/重连**（fromMapId === 目的 mapId）：位置按快照恢复；异图：落点 = 目的图缺省入场点
  （spawnPoint）——经典换线语义（进新区从入口进，不跨世界搬运坐标）。
- **背包/血量：同图异图均恢复**。血量按快照 clamp 进 [1, 合成 maxHp]。
- **不迁移的状态**（明确边界）：房间归属与匹配队列（重进走既有 Redis 持久化路径）、任务进度
  （RedisQuestStore 本就跨进程）、装备加成（挂载件重登重建，掉落绑定物品不迁移）。
- **一次性**：GETDEL 原子消费，消费失败/超时（30s TTL）自然回落「全新入场」——故障方向是
  「变保守」而非「变错」，与 P9 fail-open 同哲学。
- **出生保护**：迁移到达视同登录，保护窗口照常启用（防落地集火，复用 P7c 语义）。

## 4. 影响面

- engine/Cluster：无接口变更（bind/unbind/resolve 已够寻址）。
- framework：新增 `PlayerTransferStoreInterface` + Redis/InMemory 双实现（RedisTokenStore/InMemory
  同范式）；`PlayerActor::importHp`（clamp 导入）。
- demo：MapServer detach 导出（closeConnection 的实体清理路径）+ auth 导入（重建位置/背包/血量）；
  装配层注入 store。
- 协议：**零新增帧/字段**（导出挂 detach、导入挂 auth，词表零改动——规避 blueprint/23 发现 2 的
  词表同步风险）。
- 行为变更点：重连/切图后背包与血量**恢复**（此前为进程内私有、重连即失）。这是 P15 的目的本身，
  记录为显式行为变更（blueprint/27）。

## 5. 后续演进（非本批）

- 跨图位置语义（map 间传送点图元）、迁移时在途请求的排空窗口、跨进程实体广播桥（A 图玩家看 B 图
  实体）——均为增量，不改变本 ADR 的协议骨架。
