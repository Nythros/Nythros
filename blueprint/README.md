# PHP MMO Engine & Framework Blueprint

以 PHP 构建高性能 MMO 服务端：先做引擎，再做开箱即用框架，最终形成开发者生态。

最终目标：

```text
开发者
   │
   ▼
composer create-project ...
   │
   ▼
启动服务器
   │
   ▼
创建 Actor / Map / Skill
   │
   ▼
直接开发游戏业务
   │
   ▼
多人在线游戏
```

## 文档索引与阅读顺序

| 顺序 | 文档 | 内容 |
|---|---|---|
| 1 | `01-架构规范.md` | Engine Architecture Specification v0.1：分层结构、v0.1 冻结的 7 个核心、Tick 顺序、API 边界、路线图 |
| 2 | `02-软件包划分.md` | Composer Monorepo 包划分、依赖方向、目录结构、版本策略 |
| 3 | `03-实施路线图.md` | 双路线图、v0.1 冻结清单、里程碑、每周节奏、开发铁律 |
| 4 | `04-启动执行清单.md` | 开动代码的 12 步最小可运行骨架（含全部代码示例与验收点） |
| 5 | `05-验收与质量门禁.md` | 各阶段验收标准、性能测试原则、质量门禁 |
| 6 | `06-风险与决策记录.md` | 风险登记、ADR 摘录索引（正文统一在 adr/）、开放决策清单 |
| 7 | `07-阶段实现清单.md` | 全阶段可勾选任务清单（阶段 1 引用 04 展开） |
| 8 | `08-阶段2-验收总结.md` | 阶段 2（网络与协议）验收复盘：门禁结果、交付物、设计决策、问题根因、验收证据、遗留项 |
| 9 | `09-阶段3-验收总结.md` | 阶段 3 验收总结：环境信息、四门禁逐项证据、交付物 G1-G8、遗留项 |
| 10 | `10-阶段3-决策确认与偏差复盘.md` | 阶段 3 决策全景回顾与四级偏差核查：流程问题、契约缺口、部分完成项、技术债务、未来愿景对照 |
| 11 | `11-阶段4-验收记录.md` | 阶段 4 验收复盘（最终版）：服务拓扑（多频道/Chat/Team/持久化）+ 五态 token + 真实 RPC 链路，verify-phase4 10 项验收 PASS=9 FAIL=0 SKIP=0（缺口 G-1~G-6 全部修复，含 MAJOR 安全修复）；kill -9 双自愈耗时与 Benchmark 数据、遗留项 |
| 12 | `12-阶段5-验收总结.md` | 阶段 5 验收复盘：gateway-worker 混合架构（社交层 gateway-worker + 战斗层 Map 直连 + Redis 协调），RPC 链 + 三服务全删（34 文件），verify-phase5 8 项验收 PASS=8 FAIL=0 SKIP=0；token 简化 ['map']、组队状态机迁 Redis Lua、滚动更新；遗留项（Register 1236 WSL2 保留端口、guild/副本后置、Redis Cluster hash tag） |
| 13 | `13-阶段5-决策确认与偏差复盘.md` | 阶段 5 决策全景回顾与四级偏差核查：架构转向（纯自研 → gateway-worker 混合）致 RPC 层删除、region 聊天反方向、Lua 并发正确性靠测试锁定、蓝图阶段 5 目标偏离、未来愿景对照 |
| 14 | `14-framework与Demo验收总结.md` | 阶段 5（framework 层 + 完整 Demo）验收复盘：framework 包（四基类 + Damageable + Container/Config/EventDispatcher + Skill/Item/Buff 插件机制）+ CLI（make:* + bin/server）+ 完整 Demo 战斗闭环（CombatService/MonsterActor/DropEntity/DropTable/Inventory/EntityTypeIndex + MapServer 战斗路由 + PlayerActor 改造 + 持久化接线）；verify-combat PASS=9 FAIL=0 SKIP=0、铁律 1 零 @internal import、phpunit 413 / phpstan 0 / cs-fixer 0；reviewer PASS（6 MINOR：3 修复 + 3 记录债务）；遗留项（spawnDrops 未登记 typeIndex、run-worker 未走 PluginRegistry、怪物 PATROL 同格位移不广播） |

> 15~31 号文档为 mmorpg 试点（P1~P19）的阶段验收记录，按编号递增阅读即可；目录内另有：

| 文档 | 内容 |
|---|---|
| [`adr/`](adr/README.md) | ADR-001~026 全量架构决策记录（单一权威源） |
| [`32-架构分层审计报告.md`](32-架构分层审计报告.md) | 三包分层审计：「机制 vs 玩法」判别、上提候选、分层口诀 |

## 技术路线

```text
Runtime Kernel
     ↓
Actor + Scheduler
     ↓
World
     ↓
Entity / Cell / AOI
     ↓
Event
     ↓
Network
     ↓
Persistence
     ↓
Multi Process
     ↓
Multi Server
     ↓
Cluster
```

## 最重要的原则

> **不是按时间进入下一阶段，而是按验收结果进入下一阶段。**

先跑通最小闭环（`04-启动执行清单.md`），再逐步引入 Workerman、网络与协议、AOI 完善、RegionScheduler 与 CPU 预算、持久化，最后才考虑 Cluster。停止无限扩充蓝图，不做"架构设计模拟器"。
