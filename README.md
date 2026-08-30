# Nythros

Nythros 是一套 **PHP 游戏服务器引擎框架**：Actor 模型 + AOI 视野管理 + 多进程拓扑 + 服务端权威状态同步，
基于 Workerman 5 与 PHP 8.3，用于搭建房间制与 MMORPG 大地图类多人在线游戏。

> 运行环境：Linux / WSL2（依赖 `pcntl`/`posix` 扩展），PHP ≥ 8.3 + `ext-redis`，Redis 6+（MySQL 可选）。

## 包结构（monorepo）

| 包 | 说明 | 依赖 |
|---|---|---|
| [`packages/engine`](packages/engine) | **nythros/engine** 引擎核心：Contracts 契约接口（v0.1 冻结）+ 15 个核心模块实现（World/Entity/Actor/AOI/Event/Network/Protocol/Security/Persistence/Cluster/Scheduler 等） | workerman ^5.2 |
| [`packages/framework`](packages/framework) | **nythros/framework** 开箱即用层：四基类（BasePlayer/BaseMonster/BaseNPC/BaseActor）+ Combat/Inventory/Social/Mail/Quest/Auction/Matching/Leaderboard/GM/插件机制 + `make` 脚手架 CLI | nythros/engine |
| [`packages/demo`](packages/demo) | **nythros/demo** 参考实现：deploy.yaml 拓扑、MapServer/SocialServer 装配、玩法数据三表、verify-* 端到端验收脚本 | engine + framework |
| [`packages/client-js`](packages/client-js) | **@nythros/client** 官方 JS SDK：二进制协议编解码、登录链路、事件订阅/回执、插值引擎、断线重连（零依赖，Node ≥22 / 浏览器通用） | — |
| [`skeleton`](skeleton) | **nythros/skeleton** create-project 启动模板：最小可运行游戏骨架（GridAOI 主城 + 全量广播副本） | engine + framework |

依赖方向铁律：`Framework → Engine`、`Demo → Framework`；引擎不知道框架层存在（见 [docs/architecture.md](docs/architecture.md) §3）。

## 5 分钟跑起来

```bash
# ① 依赖栈（Redis + MySQL；或自备 Redis：127.0.0.1:6379）
docker compose up -d

# ② 安装
composer install

# ③ 启动服务器（读 packages/demo/config/deploy.yaml 拓扑，前台运行）
NYTHROS_CONFIG_DIR=packages/demo/config php bin/server start

# ④ 端到端验证（另开终端；末行 RESULT: PASS 即链路通）
php packages/demo/bin/verify-phase5.php
```

详细步骤（含端口表、WSL2 换端口、脚手架、常见问题）：[docs/quick-start.md](docs/quick-start.md)。

## 文档索引

**入门**

| 文档 | 内容 |
|---|---|
| [quick-start](docs/quick-start.md) | 环境依赖、一键启动、验收、常见问题 |
| [architecture](docs/architecture.md) | 三层结构、服务拓扑、依赖铁律、tick 顺序、数据流 |
| [protocol](docs/protocol.md) | 双通道线协议：Map 二进制批量包 + Social JSON（帧/字段码表） |

**核心机制**

| 文档 | 内容 |
|---|---|
| [actor-guide](docs/actor-guide.md) | Actor 模型、四基类、模板方法钩子、make 脚手架 |
| [cell-guide](docs/cell-guide.md) | AOI 概念、GridAOI 空间索引、视野 enter/leave |
| [state-sync](docs/state-sync.md) | 服务端权威、STATE/EVENT 帧语义、插值策略、快照重同步 |
| [plugin-guide](docs/plugin-guide.md) | 插件生命周期、仓库型/配置型插件、五步教程 |

**功能模式手册（按玩法组织，端到端）**

| 文档 | 内容 |
|---|---|
| [room-mode](docs/room-mode.md) | 房间制：匹配 → 建房 → 对局 → 结算 → 销毁全流程 |
| [mmorpg-mode](docs/mmorpg-mode.md) | 大地图：AOI 多图拓扑、跨 map 迁移、容量准入、动态扩缩容、滚动更新 |
| [combat-guide](docs/combat-guide.md) | 战斗结算链路、技能/怪物/掉落/Buff、数值三表外置 |
| [social-guide](docs/social-guide.md) | 好友/组队/公会/聊天/邮件/任务/拍卖行/排行榜 |

**工程实践**

| 文档 | 内容 |
|---|---|
| [best-practices](docs/best-practices.md) | 并发纪律、状态边界、性能红线、安全边界 |
| [testing-guide](docs/testing-guide.md) | 单测/集成测试、verify E2E 脚本、bench 回归门禁 |
| [security](docs/security.md) | 多 scope token、限流、GM 权限、安全清单 |
| [performance](docs/performance.md) | 离线基准、压测、运行期采样、容量与硬件选型 |
| [api-reference](docs/api-reference.md) | 公开 API 一览（脚本生成，`php tools/generate-api-docs.php`） |
| [deployment](docs/deployment.md) | Docker 镜像、compose 部署、Prometheus 指标、生产清单 |
| [persistence-guide](docs/persistence-guide.md) | 存储适配器、归档管线、schema 建立与迁移约定 |

**设计决策与演进**：[blueprint/](blueprint/README.md) —— 架构规范、ADR 决策记录、31 篇阶段验收记录。

## 客户端接入

- JS/TS / Node / 浏览器：[@nythros/client](packages/client-js/README.md)（含 canvas 图形化示例与重连演示）
- Unity/C#：参考实现见 [docs/unity-guide.md](docs/unity-guide.md)
- 自研客户端：按 [docs/protocol.md](docs/protocol.md) 实现线协议，对接清单见 [docs/state-sync.md](docs/state-sync.md) §5

## 质量门禁

```bash
composer cs        # php-cs-fixer
composer stan      # phpstan
composer internal  # @internal 公开符号门禁（ADR-023/024）
composer test      # phpunit（123 个测试类；集成测试需 Redis/MySQL）
php tools/generate-api-docs.php   # 校验并再生成 docs/api-reference.md
```

CI 在 push/PR 时执行以上全部 + benchmark 回归门禁（`.github/workflows/ci.yml`）。

## 版本与发布

版本策略与变更记录见 [CHANGELOG.md](CHANGELOG.md)；打 `v*` tag 触发 Release workflow（composer 打包 + GitHub Release + npm 发布）。

## 社区

- 文档站：GitHub Pages（推 master 自动构建，配置见 [mkdocs.yml](mkdocs.yml)）
- 贡献流程与工程纪律：[CONTRIBUTING.md](CONTRIBUTING.md)
- 安全漏洞报告（请勿用公开 Issue）：[SECURITY.md](SECURITY.md)
- 设计决策与全部阶段验收记录：[blueprint/](blueprint/README.md)

## License

[MIT](LICENSE)
