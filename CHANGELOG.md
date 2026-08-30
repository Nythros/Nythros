# Changelog

本文件记录 Nythros 所有对外可见的变更。格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## 版本策略

- 当前处于 **0.x 阶段**：`Nythros\Contracts` 契约接口自 v0.1.0 起冻结（变更须走 ADR）；
  engine/framework 的 `@internal` 符号不构成 API 承诺（CI 门禁 `composer internal` 强制）。
- 0.x 期间允许 minor 版本携带 breaking change，但必须在 `Changed`/`Removed` 小节显著列出并给出迁移说明。
- 进入 1.0 后遵循严格 semver：breaking change 只允许出现在 major 版本。
- 发布节奏：tag `v*` 触发 Release workflow（composer 打包 + GitHub Release + npm `@nythros/client` 发布）。

## 开发演进记录

本仓库的 git 历史已压缩为单一初始提交（开源发布形态）。P1~P19 的完整演进过程**不依赖 git 历史查阅**：
[blueprint/](blueprint/README.md) 按时间序保存了 32 篇阶段验收记录、ADR-001~026 决策记录与分层审计报告，
即项目的演进档案——每个能力「为什么这么设计、验收证据是什么」都在对应阶段文档里。

## [Unreleased]

### Added

- 稳定性演练器：`benchmarks/soak-map.php`（长跑 + RSS 斜率泄漏哨兵 + 认证率/帧率裁决）、
  `benchmarks/fault-drill.php`（redis-down/mysql-down/kill9 故障矩阵）、共享编排库
  `benchmarks/lib/drill-harness.php`（最小 RFC6455 客户端 + 服务栈托管 + 运行期采样）；CI 新增 soak 冒烟门禁。
- **soak 玩法混合波次**：`benchmarks/stress-play.php` 混合玩法客户端（跨图切换/副本 dungeon-A 进出/
  组队全状态机/聊天三通道，gateway 连接全程保活 + map 二进制连接迁移重连），`soak-map.php --play`
  接线 + `drillPlayProbe` 服务端发生探针（累计计数器口径）+ 「玩法静默」FAIL 裁决 +
  `--play-silence` 中途静默熔断 + `.zcode/soak-hourly-check.sh` 每小时只读巡检（双保险防白跑，
  blueprint/34 §8）；
  5 分钟 240 客户端验收：迁移 948、副本 进948/出853、组队 405/384/384、聊天 5489/486509，
  dungeon worker 历史首次载入真实玩家（playerCount=187）；11h 全负载长跑 `RESULT: PASS`——
  651 波、迁移 13.0 万/副本进出 13.0万·12.96万/组队三态各 6.3 万精确闭合/聊天收 7144 万，
  RSS 斜率 0.000（13 万次实体重建零泄漏）、frameMean 11h 平台化（+0.00016ms/波，§13 缓升命题消解）、
  零丢弃满认证；副本频道累计出站 38.3GB 为服务端铁证（blueprint/34 §6）。
  **24h 开服前基准长跑 `RESULT: PASS` 达标**——1416 波、RSS 177,884KB 逐字节冻结 24h
  （28 万迁移+28 万副本往返+13.4 万组队周期+173 万聊天下零泄漏）、auth 1416/1416 满员、
  dropped=0、frameMean 2.9ms 平台（后半斜率转负）、p99 mean 540ms 无恶化、副本累计出站 74.5GB、
  双保险巡检 21 次全 OK 零误报（blueprint/34 §9）。
- 事件队列容量可配置：装配缺省 10k → 30k（覆盖 join 洪峰脉冲，`NYTHROS_EVENTBUS_QUEUE` 覆盖）；
  perf-stats 新增丢弃率守恒交叉核对行（published = dispatched + dropped + in-flight）；
  soak 编排支持 `--map-ids` 多地图轮转（均衡拓扑长跑）与 frameMean 帧耗时趋势采样；
  2h 全负载 soak 实证收敛（`RESULT: PASS`：RSS 斜率 0.000、auth 98/98 波满员、dropped=0、
  frameMean 生命周期均值经启动瞬态后稳态 ~8.0ms，§11 爬升观察点裁决为收敛，blueprint/33 §12）。
  tracing JIT 对照轮（`opcache.jit=tracing`+`enable_cli=1`，与 §12 严格同参，`RESULT: PASS`）：稳态
  p50 −47% / p99 mean −29% / 吞吐 +30%、RSS 一次性 +24.7MB 但斜率仍 0.000（无泄漏）；后段 frameMean
  呈 +0.010ms/波 缓降（绝对值全程 < 基线），生产默认开启前需 24h 确认 trace 稳定性（blueprint/33 §13）。
- 生产账号装配：`NYTHROS_ACCOUNTS_FILE`（PHP 文件返回 `uid => password_hash`，明文不进 env）+
  `ThrottledAuthenticator` 防爆破装饰器（`NYTHROS_AUTH_MAX_ATTEMPTS`/`NYTHROS_AUTH_LOCKOUT_SECONDS`）。
- 协议版本协商（ADR-027）：gateway/Map 双通道 auth 帧携带 `version`（PayloadKey 码表 83→84，TS 同步再生成）；
  `NYTHROS_MIN_CLIENT_VERSION` 启用最低版本守卫（token 不消费、拒绝于 authenticate 之前），缺省关闭。
- Redis 认证与库选择：`NYTHROS_REDIS_PASSWORD` / `NYTHROS_REDIS_DB`（run-worker 与 metrics-exporter 同口径，ADR-028）。
- 备份与恢复演练手册（docs/deployment.md §7）：MySQL/Redis 备份策略、恢复步骤、票据丢失专项、已知边界。
- 发布管线（ADR-019 §5，决策 B=subsplit）：`release.yml` 升级四段流水线——质量门禁+GitHub Release zip →
  `git subtree split` 推 `Nythros/engine`/`Nythros/framework` 拆分仓 → Packagist webhook 显式刷新 →
  npm（条件跳过）；monorepo 内部依赖 `@dev`→`^0.1`（path repo `options.versions` 注入 `0.1.x-dev`
  保开发期解析，拆分仓纯 tag 定版）；`composer require nythros/engine` 待人工建拆分仓+注册后可用。

### Fixed

- **soak 编排器健壮性**：时间线 fopen 改 fail-fast（先于托管栈打开）、失联/熔断一律 break 而非
  exit——php fatal 与 exit 不执行 finally，会以孤儿服务形态泄漏托管栈（启动器以普通用户跑
  root 属主目录实抓）。
- **phpredis 6.x `scan()` 返回键数组兼容**：逐键 while-assign 循环在新版 phpredis 下把数组喂给
  `hGetAll` 抛 TypeError 被静默吞——drill 采样的 droppedTotal/frameMeanMs 与 metrics-exporter
  的指标聚合在部分环境整体失明。统一为 `scanKeys()` 兼容生成器（新旧双形态）。
- **frameMeanMs 采样口径**：`world.frame_ms` 由 recordDuration 写入 totals/histogram 而非 counters，
  均值改为 totals ÷ hist 桶计数和（此前长跑逐波恒显 `frameMean=n/a`）。
- **SocialService 降级契约**：`selectChannel` 注册表读失败（discover 可抛）归一为 null →
  `auth_failed 503 no available channel`，不再依赖异常裸传落 dispatch 兜底的通用 500——
  故障演练 redis-down 场景确认宕机窗口 503 确定性（blueprint/33 §10）。
- **SimpleEventBus 丢弃探针虚高**：flush 时重加生命周期累计（20 flush/s × 累计值 → Redis 单调累加），
  导出速率随运行时长虚增数个数量级（3h soak 的「151 亿丢弃」即此伪影，真实值 39k 且全部为 join 洪峰、
  稳态为 0）——丢弃改为丢弃点即时上报，窗口语义与 PerfProbe 对齐（blueprint/33 §9）。
- client-js `readI64` 浮点拼接精度丢失：负整数（PHP `pack('q')`）解码错码为 0，改走 BigInt 读取。
- MapServer 注册表心跳无异常边界：Redis 宕机期间心跳异常打死常驻定时器，恢复后注册表永不回填（实例永久 503）。
- MapServer 心跳 meta 不完整：Redis 数据丢失后仅 playerCount 的合并产出缺 mapId/wsAddress 的残缺 meta，
  selectChannel 永久拒绝该频道——心跳现携带完整注册 meta，首个心跳（≤5s）无损重建（故障演练实抓，见 blueprint/33）。
- start-maps/stress-map 占用 Workerman 全局缺省 pidfile 导致单实例锁互相顶撞：各配独立 pidFile（G-5 同口径）。
- stress-map 客户端引擎重写（Workerman AsyncTcpConnection → 原生 socket + stream_select）：
  旧客户端随单栈化后建链时序腐化（10 客户端 25s 仅 3 建链），新引擎建链 <100ms。


## [0.1.0] - 2026-08-30

首个公开版本。以下按能力域归纳 P1~P19 全部阶段成果（验收记录见 `blueprint/`）。

### Added — engine（nythros/engine）

- Contracts 契约层：Clock/Scheduler/World/Entity/Actor/AOI/EventBus/Timer 等接口，实现类一律 `@internal`。
- World/RoomInstance/RoomInstanceManager 运行时聚合根，驱动 Actor → AOI → 调度器 tick 链。
- GridAOI 空间索引 + UniversalAOI（全量广播型），视野 enter/leave 差分信封。
- 调度：TickScheduler/RegionScheduler/TaskQueue/TimerWheel（CPU 预算截断）。
- 网络：Workerman WebSocket 服务端、ConnectionManager、令牌桶限流、批量出站（FrameMerger/Outbox）。
- 协议：Json/BinaryBatch/Msgpack/Protobuf 序列化器 + ProtocolVocabulary 枚举压缩词表（Map 二进制 + Social JSON 双通道）。
- 安全：TokenManager + InMemory/Redis token store（多 scope）。
- 持久化：StorageInterface/RepositoryInterface + InMemoryStorage + MySqlStorage（幂等 createSchema）。
- 集群：RedisServiceRegistry 服务注册与发现。

### Added — framework（nythros/framework)

- 四基类 BaseActor/BasePlayer/BaseMonster/BaseNPC + Damageable 战斗契约 + make 脚手架 CLI（actor/skill/event/map）。
- 业务模块：Combat（CombatService/BuffService/掉落/技能冷却/种子化 RNG）、Inventory（+Equipment）、Social（好友/组队/帮派/位置 + Redis stores + ConnectionHub）、Mail、Quest（链式任务）、Auction（货币账本）、Matching（匹配票据）、Leaderboard、Plugin（官方 Skill/Item/Buff 插件）、Gm（命令总线：broadcast/kick/drain/status）、Config（三表外置 + schema 校验 + 5s 热载回滚）、Deploy（deploy.yaml 拓扑 + rolling）、Observability（PerfSampler → Redis）、Persistence（ArchivePipeline 归档管线）。
- 玩法插件：Game/Horde（波次/威胁）与 Game/Mmorpg（热区治理/重生/死亡掉落）。

### Added — demo（nythros/demo）

- deploy.yaml 拓扑事实源：social 三角色（gateway/chat/team）+ 地图/副本频道，`php bin/server start` 一键编排。
- P15 跨 map 实体迁移：客户端驱动换线 + Redis 转移票据原子单消费（detach 导出 / attach 重建，零新增协议帧）。
- P16 动态扩缩容：maxCapacity 容量准入（auth 硬守卫）+ GM drain 生命周期 + 目录路由过滤 + 动态扩容发现。
- P18 归档读路径：关闭归档只写半闭环。
- P19 客户端体验：SDK 自动重连（重连即同图迁移）+ canvas 图形化示例。
- verify-* 端到端验收脚本族（social/combat/economy/matching/mmorpg/room/scale/transfer/phase5）。

### Added — 客户端生态

- `@nythros/client` v0.1.0：零依赖单文件 JS SDK（NythrosCodec/NythrosClient/NythrosInterpolator）+ TS 类型定义（由 PHP 枚举自动生成）+ reconnect/canvas 示例。

### Added — 文档与工程化

- docs/ 指南体系：quick-start / architecture / actor-guide / cell-guide / plugin-guide / state-sync / protocol / performance，及功能模式手册（room-mode / mmorpg-mode / combat-guide / social-guide）与工程实践篇（best-practices / testing-guide / security / deployment / persistence-guide / unity-guide）。
- 根 README、CHANGELOG、API 一览（`tools/generate-api-docs.php` 生成）、docker 镜像与部署文档、GM Web 控制台、Prometheus 指标端点、Unity/C# 参考客户端。
- CI：php-cs-fixer + phpstan + @internal 门禁 + phpunit + benchmark 回归门禁（bench-gate）；Release workflow（tag 触发）。

[Unreleased]: https://github.com/nythros/nythros/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/nythros/nythros/releases/tag/v0.1.0
