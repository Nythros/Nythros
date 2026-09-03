# Nythros

**PHP 游戏服务器引擎框架**：Actor 模型 + AOI 视野管理 + 多进程拓扑 + 服务端权威状态同步，
基于 Workerman 5 与 PHP 8.3，用于搭建房间制与 MMORPG 大地图类多人在线游戏。

```text
客户端 ── 社交连接(18285~18287) ──> Social 单元（gateway / chat / team 对称直连）
   ├──── 战斗直连(18081~18084) ──> Map 单元（一频道一进程一 World，AOI 视野广播）
   └──── 协调：Redis（多 scope token / 服务注册发现 / 快照 TTL）
```

## 它解决什么问题

- **长连接游戏服务端的完整骨架**：登录鉴权、视野管理、帧级状态同步、战斗结算、
  匹配组队、经济背包、GM 运营、动态扩缩容——开箱即用，而不是只给一个 WebSocket echo。
- **明确的分层**：`engine`（冻结契约 + 15 个核心模块，含 `BaseActor` 基类）/ `framework`（三基类
  BasePlayer/BaseMonster/BaseNPC + 业务模块 + 插件）/ 组装层（`skeleton` 入门套件与 `demo` 参考实现），
  依赖方向单向，CI 门禁强制 `@internal` 可见性。
- **性能有实测背书**：60 人热区混战每客户端 ≈3.8KB/s 下行、attack→hit p95 ≤51ms；
  CI 带 benchmark 回归门禁防性能劣化（数据见[性能与压测](performance.md)）。

## 5 分钟跑起来

```bash
docker compose up -d                 # Redis + MySQL 依赖栈
composer install
NYTHROS_CONFIG_DIR=packages/demo/config php bin/server start
php packages/demo/bin/verify-phase5.php   # 末行 RESULT: PASS 即链路通
```

详细步骤见[快速启动](quick-start.md)。

## 文档导览

| 我想… | 去看 |
|---|---|
| 把服务器跑起来 | [快速启动](quick-start.md) |
| 理解整体结构与服务拓扑 | [架构总览](architecture.md) |
| 写业务 Actor / 玩法插件 | [Actor 指南](actor-guide.md) · [插件机制](plugin-guide.md) |
| 做房间制游戏（匹配/开局/结算） | [房间制玩法](room-mode.md) |
| 做大地图 MMORPG（AOI/换线/扩缩容） | [MMORPG 大地图](mmorpg-mode.md) |
| 搭战斗/技能/掉落/数值 | [战斗与数值](combat-guide.md) |
| 接好友/组队/公会/邮件/任务/拍卖 | [社交与经济](social-guide.md) |
| 自研客户端 / Unity 接入 | [线协议](protocol.md) · [状态同步](state-sync.md) · [Unity/C# 接入](unity-guide.md) |
| 查某个类怎么用 | [公开 API 一览](api-reference.md) |
| 部署上线 / 接监控 | [部署指南](deployment.md) · [安全指南](security.md) |

## 生态

| 包 | 说明 |
|---|---|
| `nythros/engine` | 引擎核心：Contracts 契约（v0.1 冻结）+ 15 核心模块 |
| `nythros/framework` | 开箱即用层：三基类 + Combat/Social/Economy/GM + 插件 + make CLI |
| `nythros/skeleton` | create-project 入门套件：`composer create-project nythros/skeleton my-game` 起步的最小可运行骨架（[仓库](https://github.com/Nythros/skeleton)） |
| `nythros/demo` | 参考实现（对内验收）：deploy.yaml 拓扑 + verify-* 端到端验收脚本族，不对用户发布 |
| `@nythros/client` | 官方 JS SDK：二进制协议 + 插值引擎 + 自动重连（Node ≥22 / 浏览器） |
| `clients/unity` | Unity/C# 参考客户端（[接入指南](unity-guide.md)） |

## 设计决策与演进

全部架构决策（ADR-001~029）、阶段验收记录与分层审计在仓库
[blueprint/](https://github.com/nythros/nythros/tree/master/blueprint) 目录——
「不是按时间进入下一阶段，而是按验收结果进入下一阶段」是贯穿项目的开发原则。

## License

[MIT](https://github.com/nythros/nythros/blob/master/LICENSE)
