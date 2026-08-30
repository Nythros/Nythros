# Nythros Skeleton —— 入门套件（create-project 模板）

> 惯例：中文为主，关键段落附英文对照（项目惯例）。
> 这是 composer create-project nythros/skeleton 的模板内容：**最小但功能完整**的 Map 游戏服务器骨架，
> 只依赖 nythros/engine + nythros/framework（+ Workerman，由 engine 传递引入），开箱即用。

## 它有什么（最小但齐全）

| 功能 | 说明 | 对应代码 |
|---|---|---|
| 两种 World | AOI 局域（主城，九宫格视野）与全量广播（副本，全世界即视野）**走同一条广播路径**（引擎 AOI 恒非空，UniversalAOI 撑起全量语义） | bin/map-worker.php + src/GameServer.php |
| 部署 | config/servers.php 声明拓扑，bin/launch.php 一键起全部频道 | 多进程、端口/serviceId 唯一性校验 |
| 认证 | uid 直通（开发用；生产请接引擎 Security：TokenManager + RedisTokenStore） | GameServer::handleAuth |
| 移动广播 | move → 改坐标 → 视野广播 entity_moved（含 NPC 巡游移动） | GameServer::handleMove / WanderingNpc |
| 视野事件 | World 每帧 AOI 差分 → entity_enter / entity_leave 帧 | GameServer::handleAoi* |
| NPC 示例 | framework BaseNPC 子类：有界随机巡游 + 移动广播（onIdle 钩子） | src/Actor/WanderingNpc.php |
| 玩家示例 | framework BasePlayer 子类（模板方法：takeDamage/heal/onTick 钩子） | src/Actor/PlayerActor.php |
| 客户端 | WebSocket 演示脚本：登录 → 移动 → 打印收到的帧 | client.php |
| 协议 | JSON 批量帧（JsonBatchSerializer，自描述易调试；二进制见 demo） | src/GameServer.php |

刻意不包含（交给更成熟的实现，均在 demo/ 中有完整版）：战斗/技能/背包、社交层（gateway/chat/team 多角色部署，见 ADR-021）、
Redis 集群注册与服务发现、MySQL 归档、慢客户端背压/字节配额、二进制协议、性能观测。

## 5 分钟跑起来

```bash
cd skeleton
composer install          # monorepo 开发阶段用 path 仓库解析本地 packages/*
php bin/launch.php        # 起全部频道（main#ch-1 :18081 aoi + dungeon-A#pool-1 :18082 full）
```

另开终端观察客户端与服务器交互：

```bash
php client.php alice 18081      # 连主城（AOI 型 World）
php client.php bob 18082        # 连副本（全量广播型 World）
```

预期输出（客户端）：auth_ok → 若干 npc:spawned（世界现状快照）→ 4 次 move 后收到
entity_moved（自己与他人）/ entity_enter（AOI 型 World 里 NPC 进入视野）→ 5 秒超时退出。

也可以只起单个频道手测：

```bash
php bin/map-worker.php --mapId=main --channelId=ch-1
php client.php alice 18081
```

停止：launch.php 所在终端按 Ctrl+C（转发 SIGTERM 给所有 worker，Workerman 优雅退出）。

## 接入你自己的游戏（三处就位）

1. **加频道/改 NPC**：改 config/servers.php（新增一个条目即可，世界类型/端口/NPC 全在这里）。
2. **加玩法**：继承 BasePlayer / BaseMonster / BaseNPC 写你的 Actor（参考 src/Actor/；
   也可用 php vendor/bin/make make:actor ... 脚手架生成）。
3. **加协议帧**：在 GameServer::dispatchSafe 的 switch 里加一个 case，业务逻辑放 handle* 方法。

源码目录 src/ 挂 Nythros\\Skeleton\\ 命名空间（composer autoload），新增类即用。

## 发布形态

- 依赖：nythros/engine ^0.1 + nythros/framework ^0.1（正式发行后删除 repositories 的 path 段，走 Packagist）。
- 安装：composer create-project nythros/skeleton <目录> → composer install → 直接按上文跑。
- 本地开发：composer.json 用 path 仓库指向 ../packages/* 并模拟本地包为 0.1.0。
