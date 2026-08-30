# ADR-016：Framework 层与完整 Demo 方向（补齐蓝图阶段 5）

> 状态：已决策。本文确定阶段 5 剩余任务（framework 层 + 完整 Demo）为下一步主线，续接 ADR-014/ADR-015 已完成的架构转向。
> 关联：blueprint `07-阶段实现清单.md`（阶段 5 任务与门禁）、`03-实施路线图.md`（铁律）、ADR-001（Engine/Framework 分离）、ADR-014（gateway-worker 混合架构）、ADR-015（阶段 5 社交层细化）。

## 1. 背景

- 阶段 1-4 已完成引擎地基：核心（Kernel/Scheduler/World/Entity/Actor/Cell-AOI/Event）、网络（Workerman + WebSocket + 协议管线）、认证（Token 五态 + RedisTokenStore）、集群（ServiceRegistry + 服务发现/心跳/自愈）、持久化（存储接口 + MySQL/Redis 落地）。四阶段门禁全部通过。
- 阶段 5 的前半部分——架构转向已完成：社交层从「纯自研 Chat/Team/Gateway」迁移到 gateway-worker 混合架构（ADR-014 决策、ADR-015 实施细化），登录/聊天/组队/帮派走 gateway-worker，战斗层 Map 保留自研直连；RPC 链与 ActorRef 族按 ADR-015 全删。
- 但蓝图阶段 5 的「framework 四基类 + CLI + 插件机制 + 可玩 Demo」尚未启动（`07-阶段实现清单.md` 阶段 5 任务仍为 `- [ ]`）。
- 架构地基已就绪，引擎核心能力已冻结且有测试与 Benchmark 支撑。下一步是从「引擎」到「游戏」的关键一跳：让开发者能够基于引擎稳定、高效地写出一个真正能玩的游戏。

## 2. 决策

**下一步主线 = 补齐蓝图阶段 5 的 framework 层 + 完整 Demo。**

- framework 层提供「开发体验」：四基类、配置系统、Container、EventDispatcher、插件机制、CLI 脚手架、服务管理命令。
- 完整 Demo 用 framework + 引擎跑通最小 MMO 游戏循环，作为「业务代码不碰 Engine 内部」的标杆样例与验收载体。

**分机部署验证跳过**：阶段 5 不单独做跨机部署验证。理由：架构已天然预留跨机能力——

- 社交层：SocialService 状态落在 Redis（团队/位置快照/掉线标记均 TTL 化，见 ADR-015 §2），多 Gateway 接入天然跨机，不依赖单机内存；
- 战斗层：Map 直连的 WebSocket 地址由 Business 在 `auth_ok`/`map:entered` 中下发，客户端按地址连接任意机器上的 Map，天然跨机。

> 注：ADR-015 已裁决 RPC 链全删，故跨机能力不再表述为「RpcClient 用 TCP 跨机」，而以上述两处为准。

## 3. 范围（对应 blueprint `07-阶段实现清单.md` 阶段 5）

### 3.1 framework 包（新建 `packages/framework`）

- 基类：`BasePlayer` / `BaseMonster` / `BaseNPC`。`BaseActor` 已在 `packages/actor` 包（`Nythros\Actor\BaseActor`），三个基类继承复用，不重复定义。
- 基础能力：配置系统、Container、EventDispatcher。
- 插件机制：官方 Plugin 起步（Skill / Item / Buff），保持 Framework 轻量；Quest / Inventory / AI 等继续后置为官方 Plugin（呼应 `03-实施路线图.md` 暂缓清单）。

### 3.2 CLI

- 脚手架：`make:actor` / `make:skill` / `make:event` / `make:map`。
- 服务管理：`bin/server start` / `status` / `stop`（收敛一条命令管理 demo 各服务，与既有 `launch.php`/`run-worker.php` 的关系在实施时确定）。

### 3.3 完整 Demo

完整跑通：登录 → 地图 → 移动 → AOI → 聊天 → 组队 → 战斗 → 技能 → Monster → AI → 掉落 → 持久化。

- 复用阶段 5 已完成的 gateway-worker 社交链路（auth/chat/team）与 Map 战斗层（AOI/九宫格/帧同步/Outbox）。
- 新增战斗闭环：Monster（BaseMonster）+ AI + 技能（Skill 插件）+ 掉落 + 持久化落库。

### 3.4 日志 / 监控 / Health Check / Debug Tool / 热重载

- 按需实现，可裁剪，不作为阶段 5 门禁硬性项。
- 热重载已有先例：Business 走 gateway-worker reload 不断连（ADR-014），Map 走滚动更新（ADR-015 §3）；framework 侧仅补齐统一入口，不新增机制。

## 4. 门禁（对应 blueprint 阶段 5 验收标准）

1. **10 客户端完整跑通最小 MMO 游戏循环**（登录 → 进图 → 移动/AOI → 聊天 → 组队 → 战斗 → 掉落 → 持久化）。
2. **一条命令启动、一条命令创建 Actor**（`bin/server start` 起全链路；`make:actor` 生成一个可挂载的 Actor）。
3. **业务代码不碰 Engine 内部**（铁律 1，见 §5）。

三条全部满足才进入阶段 6（发布与生态）。

## 5. 分层铁律

- framework 层是「开发体验」，**Engine 永远不知道 Framework**（ADR-001，`03-实施路线图.md` 铁律 1）。
- 依赖方向单向：framework → contracts → Engine 公开接口；Engine 不反向依赖 framework，不 import framework 任何符号。
- framework 只依赖 Engine 的公开接口：`Nythros\Contracts` 契约，以及 demo 沉淀的 Social / Map 可复用能力（以接口/契约形式消费，依赖倒置，不依赖 demo 具体实现类）。
- Engine 内部实现类保持 `@internal`（呼应 `architecture.md` 分层原则），业务/framework 代码不得触碰。

## 6. 影响 / 后果

- 正面：补上「引擎 → 游戏」的最后一跳，用可玩 Demo 反向验证引擎 API 的可用性与冻结质量；CLI 脚手架 + 四基类降低开发上手成本，为阶段 6 的 create-project 体验打底。
- 负面：新增 `packages/framework` 包，需在根 `composer.json` 注册 path 仓库并维护其依赖（只依赖 contracts + actor 等公开接口包，不依赖 demo）。
- 约束：插件机制仅 Skill/Item/Buff 起步，禁止为「未来可能需要」提前堆功能（铁律 8）；framework 保持轻量，业务模块继续后置为官方 Plugin。

## 7. 待确认点

1. `bin/server start/status/stop` 与既有 `launch.php` / `run-worker.php` / gateway-worker 启动脚本的关系：是新增统一编排壳，还是把 demo 现有脚本收敛为 `bin/server` 子命令。
2. 完整 Demo 的落点：是改造现有 `packages/demo`，还是新建独立 demo 游戏包，仅复用 `packages/framework` + 引擎。
3. Social / Map 可复用能力的归属：留在 demo 还是提升为 framework 的公开接口，需在实施时按依赖方向裁定。
4. 日志 / 监控 / Health Check / Debug Tool / 热重载的裁剪边界（§3.4 明确非门禁硬性项）。
