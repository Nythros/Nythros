# 成长教程 · 总览与路线图

> 你已经有了一条能跑的 Nythros 链路（见[快速启动](../quick-start.md)路径 A：
> `composer create-project nythros/skeleton my-game`）。本教程从那个最小骨架出发，
> **一章打通一个功能语义**，直到覆盖 [demo](https://github.com/Nythros/Nythros/tree/master/packages/demo)
> 的全部玩法。每章末尾都有「可运行验收」——照做就能确认这一阶真的通了。
>
> 本教程只教你用**公开 API**（engine 的 `Nythros\Contracts\*` 接口 + framework 无 `@internal` 的类）。
> demo 里你会看到它装配引擎 `@internal` 实现（`World`、`GridAOI`、序列化器……）——那是 0.x 组装层的
> 特权（见 [ADR-023](https://github.com/Nythros/Nythros/tree/master/blueprint/adr)），教程刻意不依赖它，
> 以便这些代码在 v0.1 → v1.0 之间稳定可用。

## 起点：skeleton 已经有什么

`skeleton` 的 `GameServer` 继承 `Nythros\Framework\Server\RealtimeServer`，开箱即带：

| 能力 | 入口 | 用的公开 API |
|---|---|---|
| 认证（uid 直通） | `GameServer::handleAuthMessage` | `Contracts` 实体 + `BasePlayer` |
| 移动 + 视野广播 | `RealtimeServer::handleMove`（基类模板） | `WorldInterface` + `broadcastToView` |
| NPC 巡游 | `src/Actor/WanderingNpc` | `BaseNPC::onIdle` |
| 心跳 | `GameServer` 的 `ping`→`pong` 路由 | `Message::create` |

它**没有**：token 认证、战斗、掉落、背包、持久化、聊天、组队帮派、匹配房间、经济、GM、反作弊、插件、多进程集群。
下面每一样是一章。

## 成长阶梯

按依赖顺序排列。**第 01→10 章建议在同一个 my-game 工程里连续叠加**（后面章节会用到前面的成果）。

| 章 | 打通的语义 | 新引入的公开 API | Redis 依赖 | demo 对照 |
|---|---|---|---|---|
| [01 认证](01-token-auth.md) | uid 直通 → 签发/消费多 scope token | `TokenManager`、`InMemoryTokenStore`/`RedisTokenStore`、`TokenStatus` | 单进程可 InMemory，跨进程需 Redis | `StaticAuthenticator` + `handleAuthMessage` |
| [02 战斗](02-combat.md) | attack/skill/掉落，视野广播 combat:hit | `CombatService`、`MonsterActor`、`Damageable`、`make:actor --kind=monster` | 否（进程内） | `MapServer` 战斗路由 |
| [03 背包与持久化](03-inventory-persistence.md) | 拾取进背包 → 归档落库 | `Inventory`、`StorageInterface`、`ArchivePipeline` | 否（`InMemoryStorage` 起步，MySQL 另配） | `ArchivePipeline` + `MySqlStorage` |
| [04 聊天](04-chat.md) | 五 scope 世界/频道/队伍/帮派/私聊 | `SocialService`、`ConnectionHubInterface` | 单进程可，跨角色需 Redis 多 scope token | `SocialServer` + `WorkermanHubTransport` |
| [05 组队与帮派](05-team-guild.md) | invite→accept 状态机、帮派 CRUD | `TeamStoreInterface`、`GuildStoreInterface` | 跨进程共享需 Redis 实现 | `SocialServer::handleTeam/handleGuild` |
| [06 匹配与房间](06-matching-rooms.md) | 撮合进房、每房间独立战斗容器 | `MatchingService`、`MatchJoinHandlerInterface`、`RoomInstanceInterface` | 否 | `RoomHub` + `MatchJoinOrchestrator` |
| [07 经济](07-economy.md) | 邮件/交易行/货币账本 | framework 经济服务（均**仅 Redis 实现**——本章起需 Redis） | 是 | `MapServer` auction/mail/economy 路由 |
| [08 GM 与反作弊](08-gm-anticheat.md) | 白名单命令总线、超速检测 | `GmCommandBus`、`GmPermissionInterface`、`MovementValidator` | 否 | `StaticGmAuthorizer` + `MapChannelFactory` 装配 |
| [09 插件系统](09-plugin.md) | 生命周期插件、配置型插件注册 | `PluginInterface`、`PluginRegistry`、`Container` | 否 | `AnnouncerPlugin` |
| [10 集群扩展](10-scale-out.md) | 多进程、服务发现、跨图迁移 | `ServiceRegistryInterface`（**仅 Redis**）、`DeployConfig` | 是 | `bin/server` + `run-worker` + 转移票据 |

## 怎么读

- **前置**：先跑通路径 A（skeleton 起服 + 客户端）。没跑通的先回[快速启动](../quick-start.md)。
- **环境**：Linux / WSL2（`pcntl`/`posix`），PHP ≥ 8.3。07/10 额外需要 Redis（`docker compose up -d redis` 即可）。
- **每章结构**：① 这一阶要什么 → ② 依赖注入（`bin/map-worker.php` 里 new 出服务）→ ③ 处理路由（`GameServer::handleAuthenticated` 加 case）→ ④ Actor 钩子 → ⑤ 客户端验收 → ⑥ demo 对照。
- **改的是你自己的工程**（`my-game/`），不是 monorepo；每章代码都基于 create-project 出来的骨架叠加。

准备好了就从 [01 认证](01-token-auth.md) 开始。
