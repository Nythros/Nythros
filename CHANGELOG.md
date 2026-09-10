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

- **出站链回归测试网 + 热路径微优化实验（[框架/Server + 引擎/Kernel + 探针]，「实测说话」流程第二轮）**：
  FrameMergerTest 新增 3 例钉住 drain 边界语义——全 LOW 连接在软过滤下整条缺席、超配额且全 LOW 本帧不发、
  多连接独立成包键序稳定（正是本轮重构触碰的分支）。两项同机 A/B 通过的优化见下方 Performance。
  探针 `benchmarks/probe-fm-perf-ab.php` 可复跑：drain 四场景（无过滤/软过滤/超配额重编码/整条跳过）
  逐字节对拍 + bucketOf 穷举值域对拍（NaN/负值/±0/边界/INF）。
- **GridAOI 回归测试网 + AOI 优化实验裁决（[引擎/AOI + 文档]，「AOI 优化」方向收口为实测否决）**：
  GridAOITest 新增 4 例——同格微移 fast path 与 queryShape 精判读实时位置、邻格/对角/大跨度传送差分、
  负坐标与远离原点规模、remove 后同 id 重登记重算 entered（测试类数不变,engine 43 类口径不变）。
  两项候选优化经同机 A/B 后**否决回退**并固化为红线（best-practices §4）：① SoA 坐标列 + 邻格带边
  差分——带边双扫退化 + 列刷新破坏 fast path 零写优势,本机 updateEntity 实测回退 2.6×;② 整数打包
  格子键 `(cx+SHIFT)*STRIDE+(cy+SHIFT)`——query 无稳定收益（WSL A/B 中位数持平）,且整数打包算术在
  `opcache.jit=tracing`（WSL 开发环境缺省配置）下触发 PHP 8.3.33 tracing JIT miscompile——GridAOI
  28 回归 9 红、`jit=off` 全绿,字符串键原实现同环境 1296 全绿;常驻进程必然热编译,风险不可接受。
  performance.md §8 验收矩阵新增「JIT 兼容」行（复跑命令口径）,防止该方向被再次引入。
- **平台量化验收矩阵 + 社交横扩边界声明（[文档,路线图⑥+④],「高负载」从形容词变承诺）**：
  performance.md 新增 §8——每格「实测记录或可复跑命令」二选一(帧 P99<5ms@1000 实体、24h RSS 斜率 0、
  dispatch_ms 32ms 越界即现形、登录 ≥45/s@cost9、15 房 30Hz 无顺延、backlog<5000 告警、四场景故障矩阵
  PASS…),统一劣化阈值走 bench-gate(20%);探针表补 network.dispatch_ms/inbound_messages 与
  eventbus.listener_error_total;§7.2 故障矩阵更新四场景。architecture.md §6 显式化社交角色横扩边界
  (连接表进程内语义→presence 层为前置条件;登录洪峰当前解=security §2 三级旋钮)——路线图④以
  「边界成文」交付,不做半吊子多实例(静默破单点登录/群路由比不支持更糟)。
- **能力报告:make:capabilities（[框架/Capability + 框架/Make]，路线图⑤「开箱即用」的导航面）**：
  `CapabilityCatalog` 能力目录单一事实源（18 项能力块:key=开关名,标注 所属模块/装配 env 门/入口类）;
  `make:capabilities [--format=text|json]` 一站输出「全部能力 × FeatureFlags 当前判定 × env 门」——
  开发者按目标游戏挑积木从「翻文档猜开关」变成「跑一条命令看清单」;JSON 形态供工具/CI 消费。
  CI 锁测试 4 例:entry 类可加载（防重构漂移）、FeaturePluginInterface 声明必须登记目录（开关体系与
  报告不漂移）、双格式渲染稳定、白名单判定透传。README/文档的「框架提供什么能力」自此可由命令现场生成。
- **会话状态统一生命周期（[框架/Persistence + 框架/Quest + demo/装配]，路线图③「搭积木」会话地基）**：
  `SessionParticipantInterface`（onSessionOpen/onSessionClose 幂等契约:open=attach 读路径预热（每连接
  同步点允许批量读）、close=detach 回写+释放（失败留脏不丢））;QuestService 实现之（委托既有
  preload/evict,非写回后端天然免疫）;MapServer 侧 `attachGameplay` 自动登记 + `addSessionParticipant`
  显式注册(按对象同一性去重),attach/detach 改为参与者循环驱动——新会话态能力块(宠物/成就/在线表)
  接入框架而**零主循环改动**。会话重开语义经测试钉死:close 淘汰后再 open=新会话重预热,同会话重复
  open 幂等。测试 +3(契约实现/预热回写往返计数/直连后端免疫)。
- **能力开关：声明式条件装配（[框架/Plugin + 框架/Game]，路线图②「搭积木」地基）**：
  `FeatureFlags`（三级优先：`NYTHROS_FEATURE_<NAME>` 覆盖 > `NYTHROS_FEATURES` 白名单 > 缺省全开=存量
  零影响;解析只发生在 fromEnvironment 一处,类不读全局可测可注入）+ `FeaturePluginInterface`
  （能力自声明,能力探测式照 QuestBatchStoreInterface 先例——未实现接口的既有/第三方插件不受约束）+
  `PluginRegistry::load` 返回 bool：关闭的能力跳过装配（零 Container/dispatcher 足迹、`skipped()`
  名单可观测、翻转开关重装清名单、不占名字防 squat）;MmorpgPlugin/HordePlugin 作示范实现（'mmorpg'/'horde',
  与装配层既有 env 门叠加互不替代）;demo 两处调用点改为「load 返回值守 enable」防「跳过→enable 抛未加载」。
  plugin-guide 新增 §2.3 用法与装配纪律。测试 +5（三级优先/环境解析/跳过零足迹/翻转重装/遗留插件免疫）。
- **热路径可观测性与监听器故障隔离（[引擎/Event + 引擎/NetworkWorkerman]，平台目标「高可维护」的运行时地基）**：
  ① `SimpleEventBus` 派发隔离——publish/flush 逐监听器 try/catch,一个坏监听器不再吃掉同事件其余送达
  （flush 在帧末执行,修复前异常沿栈上抛会吞掉本帧剩余信封+打帧管线;与网络层「handler 崩溃不拖垮消息循环」
  同纪律,故障计数 eventbus.listener_error_total + 日志归因,事件名进日志不进指标键防基数爆炸;既有语义零锁定
  经全仓核实,行为变更已在类 docblock 双语声明）;② `WorkermanWebSocketServer::handleMessage` 派发计时——
  network.dispatch_ms 直方图（finally 口径,异常路径同样入桶）+ network.inbound_messages 计数,经既有
  PerfSampler→gauge→Prometheus 链路自动导出:「哪类消息吃了帧预算」自此可归因（审计 P0-A 类回归的现形网）。
  新增隔离行为测试 2 例（publish 续送+计数恰一、flush 循环不断），错误帧既有 15 例语义不变全绿。
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

### Changed

- **协议 v2 一次切换：type 明文→1B 词表码 + 清单协商地基（[引擎/Protocol + 框架/Social + demo/装配 + client-js]，
  ADR-030，⚠️ breaking）**：线字节审计发现每帧传 14-16B 明文类型名（词表 typeCode 早已存在却只用于校验、
  从未上线——「枚举压缩」名不副实）。v2 定稿为**唯一线上形态**：魔数 `NX\0\x02`、type 字段 0xF3 +
  新 valueType 0x08 TYPE_CODE + 1B 码值；**v1 就此退役，旧魔数/明文 type 包一律 `DecodeException` 拒绝**
  （0.x 无外部存量窗口执行一次切，不留双栈）。协商地基补全：auth `version` 存下并经 auth_ok 回显，新增
  `manifestVersion`（双码表 CRC32，`MapCodec::manifestVersion()`，PayloadKey 84→85）——客户端与编译期生成物
  比对，**不一致即断开升级（A 模型：拒绝而非适配；B 模型运行时下发被裁决否决：协议变更必然伴随客户端业务代码
  变更，「能解码」≠「会处理」）**。实测收益：单帧 `entity_moved` 45→33B、热区 60 帧批量 2218→1498B
  （**-32.5%**），engine-bench 监听项 binary_batch_decode +290%（含机器漂移，wire 变小为实因）；跨语言黄金
  向量（163B hex）逐字节钉死 PHP/JS 两侧（`testV2GoldenBytesMatchClientJsCrossEncoder` ↔ `codec.test.mjs`），
  改 wire 必两端同步重生成。验证链：协议测 250 全绿 + node 19/19 + **E2E `verify-phase5` 11/11**
  （含 Map 二进制 auth 与战斗直连；`verify-combat` 前置在 v1 对照组同样失败，证实与本改动无关的环境因素）。
  迁移：客户端 `protocolVersion` 缺省升 2；生产建议 `NYTHROS_MIN_CLIENT_VERSION=2`；自研客户端按
  protocol.md §2-§4/§7 新表接入——**Unity 参考实现 `clients/unity/NythrosClient.cs` 已同步 v2**
  （MAGIC 0x02、type 编/解走 1B TYPE_CODE、顺带修正 STRING 边界 256→255 预存笔误）；
  后续演进（INT varint、keyCode 1B、EVENT_BUNDLE 位图）各走独立 ADR。

### Performance

- **BaseEntity::getPosition 数组缓存：实测达标但**决策挂起**（记录在案，未实施）**：该方法每次调用
  新建 `['x'=>,'y'=>]`（46 个调用点，AOI 帧内每实体读 2~8 次）。实体侧缓存变体经探针
  `probe-position-ab.php` 验证达标——10 万次随机 move 逐值全等 + COW 改写不污染 + JIT 双模式，
  读密集 +15~45%（值对象侧变体被实测否决）。但绝对量属微秒级（单帧省 <0.1ms，帧预算利用率 <3%），
  不改变任何瓶颈顺序；**决策：让位于带宽主线**（大地图形态下网卡先于 CPU 撞墙），CPU 微优化整体
  冻结于此。更大的「getPosition 返 Position 对象」（消费侧 -46%）卡在 `EntityInterface` v0.1 冻结
  契约，与协议 type 压缩一并留作 1.0 ADR 议题。探针与结论保留，重启成本≈零。

- **FrameMerger::drain 出站每连接省一遍全帧复制（+10% 端到端，双 JIT 一致）**：① 无软过滤（常态）时
  `$chosen` 经 PHP 数组 COW 直接共享帧槽列表，免逐槽 append 重建；② `$encode` 闭包提升为私有方法
  `encodeSlots`（原每连接每帧分配一次闭包）；③ `array_map`+`array_values` 双中间数组改 foreach 直建
  Message 列表（列表上 array_values 本为冗余拷贝）。输出逐字节一致（探针 4 场景对拍 + 既有 7 测 +
  新 3 测），drain(8 槽) 0.0114→0.0103 ms/op（JIT ON）/ 0.0153→0.0139（JIT OFF）。
- **PerfProbe::bucketOf 升序比较链 + is_nan 显式守卫（热分布 3~5×）**：原 foreach 无提前退出、每次全扫
  9 边界。分布实测：帧耗时/派发耗时强右偏（P50=0.264/P90=0.475，~90% 落桶 0）——**升序**让热值 1-2 次
  比较即出（平均 1.7 次），首版曾选**降序**（对热值平均 8.3 次，恰为最坏）经分布分析后纠正；**二分被实测
  否决**——9 桶规模下 while 循环 + 数组取界的常数开销使其在偏态与均匀分布下都慢于升序（探针
  `benchmarks/probe-bucket-ab.php` 三分布 × 交替中位）。**新发现并入档**：`!($ms >= 0.5)` 取反守卫在
  PHP 8.3.33 tracing JIT 下对 NaN 实测误编译（冷对拍通过、返回 8），`is_nan()` 显式守卫无此问题——
  生产实现用 is_nan 头部守卫并在注释禁止取反写法；新增 `PerfProbeTest` 2 例（全域边界 18 值冷对拍 +
  5 万次压热后热态复验，Kernel 测 ×3 连跑 WSL JIT 全绿）。绝对量仍属微优化（每帧+每入站消息各一次），
  价值在方向正确与 JIT 陷阱固化成测试。
- 本轮**未发现新缺陷**；上轮 LIST 静默补 null 的同型模式（`?? "\x00"` 兜底读字节）全 Protocol
  目录扫描仅此一处。其余解码器结构审查均严格：Msgpack 每次读字节前 `need()` 验长、
  Protobuf 主循环 `offset < end` 收口且 varint 带显式截断异常、JsonBatch 依赖 json_decode
  原生报错——静默补 null 类缺陷在协议层已闭环。

### Fixed

- **压测服务端采样器三连修 + 连接规模标定落地（[benchmarks/ + docs]，「容量口径」从错到准）**：
  ① 采样对象修正——`stress-map/hotzone/rooms` 旧口径按 cmdline 匹配 `start-maps.php`，命中的是
  Workerman **master**（不承载连接，CPU/RSS 恒平）：这正是历史档「CPU avg 0%」「RSS ≈37MB 恒定」
  的真相（此前误判为「jiffies 分辨率不足」）。改采 master 的 **worker 子进程**；② jiffies 解析修正——
  comm 含空格导致 `/proc/PID/stat` 字段错位，改从最后一个 `)` 起切分；③ 精度修正——逐样本 jiffies
  差分在 clk_tck=100 下低负载必为 0%，改**首末累计差分**跨全运行窗算率。修正后 60 人混战实测
  CPU 4-7%/RSS 100-107MB（旧档全为 0%/37MB）。
- **stress-map 新增服务端采样与 `--json` 服务端段**（每连接 CPU/内存口径）：并新增
  **§6.5 连接规模标定**——50/100/200/400 四档冷栈阶梯实测：每连接 CPU 0.32-0.46% 核、内存
  16-336KB（随视野扇出增长），单 worker ~200 连接近饱和（~75% 核、P99 上升）、~100 为舒适区；
  400 档 P99 劣化的主因是单进程压测客户端自身饱和（服务端仍有 ~2 核余量）。原始数据归档
  `benchmarks/results/conn-{scale,warm}-*.json` 与 `hotzone-*.txt`；§6.1/§6.2/§8 数字全部按修正
  口径重测更新（协议 v2 后 60 人聚格带宽 3822→2469 B/s）。
- **压测工具链协议版本升 v2**：`stress-map/stress-play/drill-harness` 的 auth 帧 `version` 1→2
  （v2 一次切后旧值会被 min-version 守卫拒绝）；`stress-rooms` 修 `$state['conn']` 空键访问。

### Fixed

- **BinaryBatchSerializer 截断包静默 null 填充（协议严格性缺陷 + 热路径提速）**：LIST 元素字节被裁掉时，
  旧实现经 `?? "\x00"` 把缺失元素类型字节读成 T_NULL——截断包「成功解码」出 null 填充的列表（cut=1/2/11/20/24/27
  实抓，payload 出现线上根本不存在的 null），违反 protocol.md §5 与类头「严格」声明。LIST 元素读取先验长再读；
  decodeBatch 增加帧长首道闸（声明长度超出缓冲 → `帧体越界` DecodeException，fail-fast）。回归 2 例
  （逐档截断必抛 × 20、伪造超长帧）+ 探针 40 档异常对拍（benchmarks/probe-protocol-ab.php）。
  同轮 A/B 落地两项已验证热路径优化：编码定长字段单次 pack（nCq/nCd/nCnn/nCC/nCN 替代双 pack+拼接）、
  解码标量读改 unpack 三参/ord 位运算（免 substr 中间串）——探针同机对拍 encode +27~46%、decode +36~47%
  （JIT 开/关双配置一致、字节/值/异常三重对拍通过），engine-bench 监听项 binary_batch_decode 相对
  提交基线 +2.7×、encode +1.7×。协议线教训反向验证：本轮改动经 WSL tracing-JIT 下 249 协议测 ×3 全绿复验。
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
