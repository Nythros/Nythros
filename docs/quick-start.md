# Nythros 快速启动（Quick Start）

> 有两条路，先选一条：
>
> | 路线 | 适合谁 | 你要什么 | 耗时 |
> |---|---|---|---|
> | **A. 入门套件**（推荐起步） | 要开新游戏项目的开发者 | `composer create-project nythros/skeleton my-game` 得到最小可运行 Map 服务器（认证/移动/AOI 视野/NPC 巡游），随后按[成长教程](growth/00-roadmap.md)逐阶加功能 | 5 分钟 |
> | **B. monorepo demo**（本文余下部分） | 要研究/验收全功能实现、参与 Nythros 开发的人 | 完整参考实现：战斗/社交/匹配/经济/集群（`packages/demo`，对内验收场，不对用户发布） | 10~30 分钟 |
>
> 路线 A 的操作细节见入门套件仓库 README（[Nythros/skeleton](https://github.com/Nythros/skeleton)）：
> create-project → `php bin/launch.php` → `php client.php alice 18081`，其仓库 CI 每次发布自动验证这条链路。
>
> 以下是路线 B。

## 1. 前置依赖

| 依赖 | 版本要求 | 说明 |
|---|---|---|
| PHP | >= 8.3 | 需启用 `pcntl`、`posix` 扩展（服务管理与信号转发依赖） |
| PHP Redis 扩展 | ext-redis | 引擎的 token / 服务注册 / 状态存储都走 Redis 客户端 |
| Redis 服务端 | 任意现代版本 | 默认连接 `127.0.0.1:6379` |
| Composer | 2.x | 依赖安装与 autoload 生成 |

验证环境：

```bash
php -v        # 应显示 8.3 及以上
php -m | grep -i redis
redis-cli ping   # 应返回 PONG
composer --version
```

### 1.1 替代：Docker Compose 一键起依赖

不想在宿主机安装 Redis/MySQL 时，用根目录 `compose.yaml` 一键拉起依赖栈（与 `deploy.yaml` 的 redis/mysql 段完全对齐：`127.0.0.1:6379` 无密码 / `127.0.0.1:3306` root 空密码预建 `nythros` 库）：

```bash
docker compose up -d     # 启动 redis(7-alpine) + mysql(8.0)
docker compose ps        # 两容器 healthy 即就绪
```

随后按步骤 ②③ 继续（`composer install` → `php bin/server start`，deploy.yaml 缺省地址即指向本栈）。停止与清理：

```bash
docker compose down      # 停止并移除容器（数据不持久化，重建即全新实例）
```

说明：compose 栈只承载外部依赖；Nythros 服务进程本身仍在宿主机运行（拓扑唯一事实源是 `deploy.yaml`）。容器化 app 服务待后续提供——`php:8.3-cli` 官方镜像缺 `ext-redis/pdo_mysql/pcntl`，需自定义镜像构建。

## 2. 步骤 ①：安装依赖

在项目根目录（monorepo，含 `packages/engine`、`packages/framework`、`packages/skeleton`、`packages/demo` 四个子包与 `packages/client-js`）执行：

```bash
composer install
```

安装完成后确认 `vendor/bin/make` 存在（它软链到 `packages/framework/bin/make` 脚手架入口）：

```bash
ls -l vendor/bin/make
php vendor/bin/make   # 无参数应打印 make:* 命令用法
```

## 3. 步骤 ②：配置（端口说明 + WSL2 换端口）

### 3.1 服务拓扑与端口

服务拓扑的唯一事实源是 `packages/demo/config/deploy.yaml`；端口约定（ADR-021 自研单栈）：

| 端口 | 服务 | 归属 | 说明 |
|---|---|---|---|
| 18285 | Social `gateway` 角色 | 社交单元 | 登录入口：auth_ok 下发 map/chat/team 三地址 |
| 18286 | Social `chat` 角色 | 社交单元 | 聊天五语义（world/channel/team/guild/private） |
| 18287 | Social `team` 角色 | 社交单元 | 组队状态机、帮派 |
| 18081 | Map `map-1#ch-1` | 地图单元 | 战斗直连 |
| 18082 | Map `map-1#ch-2` | 地图单元 | 第二频道 |
| 18083 | Map `map-2#ch-1` | 地图单元 | 第二张地图 |
| 18084 | Map `dungeon-A#pool-1` | 副本池 | 全量广播型 World |
| 6379 | Redis | 外部 | 共享状态（token 多 scope / 服务注册 / 组队·位置·帮派快照） |

`deploy.yaml` 描述全部部署单元：`social` 单元（gateway/chat/team 三角色三进程，对称直连，各角色连接表进程内独立）+ 各地图/副本单元。端口全局唯一，重复会在 DeployConfig 解析时被拒绝。

### 3.2 WSL2 保留端口：换端口部署

WSL2 下上述端口可能被 Windows 保留占用。ADR-021 后没有端口透传环境变量，换端口请直接编辑 `packages/demo/config/deploy.yaml` 中对应服务的 `port` 字段（拓扑唯一事实源），保存后重新 `php bin/server start` 即可。

### 3.3 玩法数据配置（地图怪物 / 技能 / 掉落三表）

玩法内容（出生点与初始怪物、技能表、掉落表）以 PHP 数组配置表声明，参考表在 `packages/demo/config/{gameplay,skills,drops}.php`。把 `NYTHROS_CONFIG_DIR` 环境变量指向一个配置目录即可启用：

```bash
NYTHROS_CONFIG_DIR=packages/demo/config php bin/server start
```

- **加载与校验**：三表经 ConfigRepository 挂载并由各自的 schema 校验（`GameplayTables::schemas()`）。坏表启动即拒绝，错误精确到文件行号（如 `第 3 行 entries.0.weight：应为 int，实际 string("heavy")`）；热载改坏则回滚（旧配置保留，修好表后自动恢复）。
- **热载**：目录内文件按 mtime 轮询（5s），`config.changed` 后自动重放——`skills` 表增删一行即生效（新技能可施、删除行失效）；`drops` 表原子换入（新出生怪物用新表）；`gameplay` 表换入出生点并 diff 怪物表（已登记锚点参数热更、新增行立即刷出、删除行不再重生；在场怪物不会被驱逐）。
- **缺省回落（零破坏）**：不设 `NYTHROS_CONFIG_DIR` 或对应文件缺席时，回落到与外置前硬编码逐字段一致的缺省表；出生点此前的 `NYTHROS_SPAWN_POINT` env 覆盖仅在配置表缺席时生效（配置表优先）。
- **feature 行**：`skills` / `drops` 表的行可标 `feature`（`mmorpg`/`rooms`/`economy`/`gameplay`/`anticheat`），仅对应 `NYTHROS_*` 开关为 1 时装配；怪物表行可标 `respawnMs` 做逐怪重生延迟（缺省用 mmorpg 全局值）。

## 4. 步骤 ③：一键启动服务器

```bash
php bin/server start
```

启动铁序（ADR-021 §3.3）：**Redis（外部）→ social 单元 → map 单元**。

- `bin/server` 是根编排壳，按 `deploy.yaml` 声明组依次 spawn 两级服务：
  - `social-gateway` / `social-chat` / `social-team` → 逐角色 spawn `packages/demo/bin/run-worker.php --service=<type>`（社交三角色共用 SocialServer 类，连接表进程内独立）
  - `maps` → `bin/start-maps.php`（Workerman 原生多频道单入口，一个 master 管全部地图/副本频道进程）
- 可用 `--parts=social|maps` 只起其中一个单元（缺省 `all` 全起）。
- 启动前会先探测 Redis（按 `deploy.yaml` 的 redis 段 ping），不可用则中止。
- 前台运行，日志按服务落盘：`/tmp/nythros-server/{social-gateway,social-chat,social-team,maps}.log`；运行清单在 `/tmp/nythros-server/run.json`。
- 停止：前台按 `Ctrl+C`（信号转发优雅停止），或在另一个终端执行：

```bash
php bin/server status   # 查看各服务 pid 与状态
php bin/server stop     # 逆序优雅停止（Map → Social）
```

## 5. 步骤 ④：创建 Actor（脚手架）

`make:actor` 生成业务 Actor 骨架（`--kind` 决定继承的基类与钩子集）：

```bash
php vendor/bin/make make:actor MonsterActor --kind=monster --ns=Nythros\Demo\Game --out=packages/demo/src/Game
php vendor/bin/make make:actor VendorNPC --kind=npc --ns=Nythros\Demo\Game --out=packages/demo/src/Game
php vendor/bin/make make:actor PlayerActor2 --kind=player --ns=Nythros\Demo\Game --out=packages/demo/src/Game
```

- `--kind=player|monster|npc`：分别继承 `BasePlayer`（钩子 `onTick/onDamaged/onDeath`）、`BaseMonster`（钩子 `onPatrol/onChase/onAttack/onDead/onDeath`）、`BaseNPC`（钩子 `onIdle/onInteract`）。
- `--out` 目录不存在时会自动创建。
- 另有 `make:skill` / `make:event` / `make:map` 三类脚手架，用法见 `php vendor/bin/make` 输出。
- 生成的骨架带 TODO 注释，参照 `packages/framework/src/Actor/PlayerActor.php` 与 `packages/framework/src/Combat/MonsterActor.php` 实现钩子即可（详见《Actor 指南》）。

## 6. 步骤 ⑤：客户端连接验证

服务器启动后，用验收脚本模拟真实客户端（WebSocket）验证链路。两套脚本的分工：

| 脚本 | 覆盖 | 账号 | 能否直接跑 |
|---|---|---|---|
| `packages/demo/bin/verify-phase5.php` | 社交层端到端：登录、进图凭证、战斗直连铁律、聊天、组队、掉线重连、滚动更新、token 单向 | `1001/1002/1003`（密码 `secret`，与 `run-worker.php` 缺省账号装配一致） | **能**，直接跑 |
| `packages/demo/bin/verify-combat.php` | 战斗层端到端：怪物生成、攻击、死亡、掉落、拾取、技能、失败回执、持久化（共 9 项） | `1001~1010` | **不能**，需临时副本（见下文） |

### 6.1 推荐：跑社交层验收（与正式启动一致）

```bash
php packages/demo/bin/verify-phase5.php
```

输出契约：每项一行 `[verify] [PASS|FAIL|SKIP]`，末行 `RESULT` 汇总。PASS 即整条链路（登录 → 进图 → 战斗直连）验证通过。

### 6.2 战斗层验收：verify-combat（需临时副本）

`verify-combat.php` 是阶段 5 战斗层端到端验收脚本，其前置要求与正式 monorepo 启动**不一致**，直接跑会因账号缺失等原因 FAIL/SKIP：

- 账号表需 `1001~1010`（正式装配缺省只含 `1001/1002/1003`，可用 `NYTHROS_ACCOUNTS` 环境变量扩展）；
- Map 怪物需延迟 spawn（正式 `run-worker.php` 是启动即生成 `monster-1/monster-2`）；
- 归档存储需 Redis 可观察实现（正式为 `InMemoryStorage`）。

该脚本头部注释记录了临时副本的路径与改造点（`/tmp/opencode/combat/run-worker.php` 等）。普通用户验证连接请用 6.1 的 `verify-phase5.php`。

### 6.3 最简冒烟：echo 客户端

若只想验证 WebSocket 服务能收发：

```bash
# 终端 A：启动最小回显服务（18080，独立于主链路）
php packages/demo/bin/echo-server.php

# 终端 B：发一条消息并打印回显
php packages/demo/bin/ws-client.php   # 期望输出 [client] received: echo: hello nythros
```

## 7. 最小游戏循环

一次「登录 → 进图 → 打怪 → 拾取 → 落库」的完整闭环（阶段 5 战斗层，协议帧见 `verify-combat.php`）：

```text
1. 登录      客户端 → Social gateway(18285) auth{username,password,mapId}
              ← auth_ok{uid, token, map:{wsAddress}, team, guild, endpoints:{chat,team}}
              （token 多 scope；endpoints 携带 chat/team 服务地址，由部署拓扑注入）
2. 进图      客户端 → Map(18081) 直连 auth{token} → auth_ok{id=1001@…}
              （同时社交侧 joinGroup 频道分组）
3. 攻击      客户端 → attack{targetId=monster-1} → 视野广播 combat:hit{attackerId,targetId,damage,hp}
4. 死亡      多玩家集火 → 视野广播 entity_dead（怪物 Actor 自清理，尸体攻击得 combat:error invalid_target）
5. 掉落      怪物死亡 → drop:spawned（视野）+ 掉落物 entity_enter 附 itemId
6. 拾取      pickup{dropId} → 定向 item:added（拾取者）+ 视野 drop:removed
7. 落库      拾取后背包经 ArchivePipeline.markDirty → 归档存储（正式 monorepo 为 InMemoryStorage，
              验收/生产可替换为可观察存储实现）
```

## 8. 本文与入门套件、demo 的关系

- **入门套件（nythros/skeleton）**是唯一的 create-project 模板（Packagist 已发布），也是路线 A 的起点；
  它刻意只做最小集（无战斗/社交/集群），完整语义在 demo 里都有参考实现。
- **demo（nythros/demo）**是对内参考实现与验收场，`type: library`，**不会**也**不应**被 create-project。
- 从路线 A 出发逐步长出 demo 的全部功能：看[成长教程](growth/00-roadmap.md)——每章打通一个功能语义，
  验收脚本可直接跑通该章里程碑。

## 9. 常见问题

| 现象 | 原因与处理 |
|---|---|
| `[server] fatal: Redis 不可用` | Redis 未启动或不在 `127.0.0.1:6379`；先 `redis-cli ping`，或 `docker compose up -d` 拉起依赖栈（见 1.1 节） |
| `[server] 已有运行实例` | 运行清单存在且服务存活；先 `php bin/server stop` 再 start |
| WSL2 下端口被占用 | 直接编辑 `deploy.yaml` 换端口（见 3.2 节） |
| `make:actor` 报参数错误 | 按 `php vendor/bin/make` 的 USAGE 检查 `--kind/--ns/--out` 三者齐全 |
| verify-combat 出现大量 FAIL | 该脚本需要临时副本（账号 1001~1010、延迟 spawn、Redis 归档），请改跑 `verify-phase5.php` |
