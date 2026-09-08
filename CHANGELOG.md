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

- **exporter 运维安全网三件套（上线前必做闭环,「Redis 管热数据」模型的监控补齐）**：
  ① [框架/Observability] `PerfSampler` 连接记忆化（照 RedisFriendStore 先例缓存工厂产物;失败即丢弃
  连接下一轮重连,保留自愈性;审计 P1-D 的「每 5s connect+auth+select 连接 churn」消除）;
  ② [demo/运维工具链] backlog 观测链路——run-exporter 每 5s 把 PEL 滞留（XPENDING）+ Stream 长度
  （XLEN）写入 `nythros:perf:storage-exporter:gauge` 键族 + `:last` 活性心跳;metrics-exporter 新增
  gauge 键族暴露 → `nythros_perf_gauge{service="storage-exporter",metric="backlog|stream_len"}`,
  exporter 失联=lag 停走可告警（deployment §4 告警面）;③ [demo/工具链] fault-drill 新增第四场景
  `exporter`——kill 全树（Workerman proctitle 双形态匹配:args 含 run-exporter.php 的 master/manager +
  改名后的孤儿 worker,两遍杀+死透轮询,防「只杀 worker 被 master 重生」的假绿）→ 断言宕机期间登录
  不受影响（解耦证明）+ 心跳停更可观测（kill 后两次跨周期读数相等,无竞态判定）→ 手动重启（生产归
  systemd,bin/server 只收割）→ 断言心跳恢复;WSL 实跑三项 PASS。真实全栈验证:gauge 键产出
  backlog/stream_len=0（消费健康）、phase5 11/11 不受 storage 组影响。
- **登录 bcrypt 恒时化 + 成本可调（主循环 CPU 剥离收尾,审计 P0-A 闭环）**：`StaticAuthenticator`
  对不存在账号也执行一次 dummy 哈希 `password_verify`（恒时路径,「账号不存在」与「密码错误」同耗时
  同异常,关闭时序枚举侧漏;dummy 惰性生成,构造零 bcrypt）;新增 `NYTHROS_BCRYPT_COST`（缺省 9）
  统一控制开发账号装载哈希与 dummy 哈希的 cost——WSL2 实测单进程登录吞吐 cost 10≈24/s(42ms)
  →9≈45/s(22ms)→8≈93/s(11ms),每 -1 翻倍。洪峰优先级:调 cost → gateway `count>1`
  （deploy.yaml 原生横扩,登录无状态;security.md §2 + deploy.yaml 注记）。未知 username 枚举的
  恒定 bcrypt 成本由既有 gateway 令牌桶（10/s）封顶,与 ThrottledAuthenticator 锁定叠加成
  「成本有硬上界的恒时认证」。
- **「Redis 管热数据、MySQL 只落盘」持久化模型（worker 零 PDO）**：①背包换 Redis 权威
  `RedisInventoryStore`（`nythros:bag:{uid}` hash,快照覆盖写 = pipeline 单往返,attach 恢复主路径,与
  CurrencyLedger 同风格);②持久化管线抽契约 `PersistPipelineInterface`(+`bindTimer` fork 后绑定,修正
  `scheduleFlushId` 紧急合并窗在 demo 装配中 timer 恒 null 从不 arm 的潜伏缺陷——bindTimer 同时启用
  合并窗与 30s 兜底,幂等);新实现 `RedisExportPipeline`:markDirty 零 I/O→冲刷点一条 pipeline 同窗写
  背包 hash + XADD 导出 Stream(`nythros:export:players`,MAXLEN 近似保险丝),worker 进程内不出现 \PDO;
  ③新部署角色 **storage-exporter**（deploy.yaml `type: storage`,单消费者消费组落 `nythros_archive`,
  复用 `MySqlStorage::saveBatch` 不自造 SQL;at-least-once:失败不 ack→PEL 重放,毒消息 ack+日志;
  `run-exporter.php --self-test` 离线自检;count>1 硬降 1 保 Stream 全序前提);④demo 双模式
  `NYTHROS_PERSIST_MODE`（缺省 `export`,export 下 attach 恢复读背包权威缺省开;`mysql` 保留旧直写口径,
  ArchivePipeline 与测试线束零改动）;bin/server `--parts` 增 `storage`,启动铁序 Redis→social→map→storage。
  失败模式预案与丢失窗口契约写进 deployment §3/§6/§7.3（exporter 失联=报表老化不回档;worker 崩溃=
  丢 ≤30s 未冲刷增量,在线态 Redis 可恢复）。验证:新增 13 测（Inventory/ExportPipeline 契约 + 两形态
  phpredis 回复归一化实测校准——xReadGroup 实为 [stream=>[entryId=>fields]]）;E2E 带 exporter 实跑
  `verify-phase5` 11/11、`verify-mmorpg` 11/11（step8 领奖直查 MySQL potion=4 为导出链路铁证）。
- **热路径同步 IO 剥离（「每 tick/每消息主循环零往返」纪律化）**：①任务进度写回缓冲
  `CachedQuestStore`（framework Quest，内存装饰器：attach 预热整批载入 / combat.kill·pickup 驱动
  的 advance 读写全走内存 / detach 回写+淘汰 / 30s 定时兜底,批量化经新增能力接口
  `QuestBatchStoreInterface::saveMany`——RedisQuestStore 按 uid 归组 hMSet、跨 uid pipeline 合并为
  1 往返,未实现者自动逐条回落;崩溃丢失窗口 = 距上次冲刷,与归档裁决 4 同口径）;
  ②归档断连/登出 `ArchivePipeline::scheduleFlushId` 紧急合并窗（0.2s 窗口内到齐的脏记录并成
  一次 saveBatch——掉线风暴把 N 条串行 MySQL 往返压成 1,断连处理器零往返;`flushId` 保留为
  强制同步点旧语义,无定时器装配自动回落）;③登录链读聚合:`RedisServiceRegistry::discover`
  心跳存活检查 exists 由逐实例串行（1+N 往返）改单条 pipeline,惰性回收合并批量、正常路径
  零回收往返,过滤/回收语义逐条对齐（P14/33 §11 验收网保持）;`RedisTeamStore::get` 的
  leaderUid/members 双 hGet 合并为单 hMGet。门禁 `tools/check-io-free-path.php`（composer io-free
  + CI 步骤）:12 个热路径源文件出现 `\Redis`/`\PDO`/`Redis::` 客户端引用即 FAIL——守护本纪律
  （blueprint/33 压测证实的唯一热路径 IO 违规 combat.kill→QuestService 同步链就此闭环）;
  best-practices §1 补写回缓冲纪律与强一致例外区清单（货币/竞拍/票据保持同步,勿缓冲化）;
  E2E 回归:`verify-phase5` 11/11、`verify-mmorpg` 11/11（任务链运行时/领奖/落库复核步骤全走
  CachedQuestStore 实链路）,combat.kill→QuestService 同步链为审计发现的首例热路径 IO 违规、就此闭环。
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
