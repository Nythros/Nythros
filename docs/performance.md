# 性能测试与运行期检测指南

> 三包（engine/framework/demo）的离线基准、真实链路压力测试，以及正式运行时的性能采样/观测方案。
> Covers: offline benchmarks for the three packages (engine/framework/demo), real-link stress testing, and the
> runtime sampling/observability scheme for production use.

## 1. 离线基准（一次性执行，不需要 Redis/MySQL/网络服务）

位于 \`benchmarks/\`，全部为普通 PHP CLI（\`php benchmarks/xxx.php\`）：

| 脚本 | 覆盖 | 输出 |
|---|---|---|
| \`benchmarks/engine-bench.php\` | World::update 帧耗时（100/500/1000 实体梯度）、GridAOI query/update 吞吐、SimpleEventBus 入队+批量 flush、BinaryBatchSerializer vs JsonSerializer 编解码、RegionScheduler 预算截断 | ops/s、均值/帧 |
| \`benchmarks/framework-bench.php\` | PluginRegistry load/get、Skill/ItemRepository 注册与查找、MonsterActor AI update()（PATROL 循环）与状态转移 | ops/s |
| \`benchmarks/demo-bench.php\` | FrameMerger 批量入队/排空吞吐、MapServer auth/move 消息处理（stub server + 二进制批量包全链路） | 帧/s、msgs/s |

运行：\`php benchmarks/engine-bench.php\`（其余同理）。数据仅供本机相对对比：绝对数字随硬件/JIT 变化，
建议**同一机器跑多轮取中位数**，并记录 PHP 版本与 opcache 状态。

## 2. 真实链路压力测试（需要完整服务）

\`benchmarks/stress-map.php\`：N 客户端并发登录 → 直连 Map → 周期 move 触发广播回程。

1. 启动服务（见 \`packages/demo/bin/verify-combat.php\` 头部前置：Register/Gateway/BusinessWorker/Map）。
2. 运行：\`php benchmarks/stress-map.php --clients=50 --seconds=15 [--json]\`

输出：auth 成功数、帧吞吐（fps/peak）、字节吞吐、帧到达延迟 P50/P90/P99（同批并包记 0，
跨批间隙反映广播周期/拥塞）、连接级唯一抽样（HLL，见下）。
注意：压测复用演示账号 1001-1010（\`clients\` 上限 10）；更大规模需先扩展账号表。

## 3. 运行期性能检测（正式运行采样）

**非侵入三层**：引擎记账 → demo 采样 → Redis 汇聚 → 观测端查询。

### 3.1 引擎探针（\`packages/engine/src/Kernel/PerfProbe.php\`）

静态计数/直方图/累计累加器，零依赖。打点位置：

| 探针 | 位置 | 指标 |
|---|---|---|
| \`world.frame_ms\` | \`World::update()\` 帧末 | 帧耗时直方图（桶 0.5/1/2/4/8/16/32/64 ms）+ 均值 |
| \`world.envelope_published\` | World::update 帧末 | 视野信封吞吐 |
| \`eventbus.batch\` / \`eventbus.envelopes_dispatched\` | SimpleEventBus::flush() | 单批分发数与总量 |
| \`eventbus.dropped_total\` | SimpleEventBus::flush() | 队列拥塞丢弃（可靠事件不丢；droppable 丢弃计数） |
| \`network.out_bytes\` / \`out_packets\` / \`batch_packets\` | WorkermanConnection::sendBatch | 出站字节/包/批量包大小分布 |
| \`network.dispatch_ms\` / \`network.inbound_messages\` | WorkermanWebSocketServer::handleMessage（finally 口径,异常路径同入桶） | 消息派发链耗时直方图 + 入站计数——「哪类消息吃了帧预算」的归因入口（审计 P0-A 类回归现形网） |
| \`eventbus.listener_error_total\` | SimpleEventBus::publish/flush | 监听器故障隔离计数（一个坏监听器不吞其余送达;事件名进日志不进键,防基数爆炸） |

### 3.2 采样器（\`packages/framework/src/Observability/PerfSampler.php\`）

\`run-worker.php\` 在 \`onWorkerStart\` 注册：每 5s \`PerfProbe::drain()\` → 快照写 Redis。

Redis 键（serviceId 如 \`map-1#ch-1\`）：

| 键 | 类型 | 内容 |
|---|---|---|
| \`nythros:perf:{serviceId}:counters\` | Hash | 事件计数（单调累计；观测端取窗口差值） |
| \`nythros:perf:{serviceId}:hist\` | Hash | \`metric.bucket\` => count |
| \`nythros:perf:{serviceId}:totals\` | Hash | metric => 累计毫秒（均值 = totals/counters） |
| \`nythros:perf:{serviceId}:unique\` | HLL | 唯一连接/实体估计（PFADD） |
| \`nythros:perf:{serviceId}:last\` | String | 最近采样时间戳 |

采样失败只记日志、绝不抛给游戏主循环（探针零影响保障）。

### 3.3 观测（\`packages/demo/bin/perf-stats.php\`）

\`php packages/demo/bin/perf-stats.php [--serviceId=map-1#ch-1] [--json]\`，输出格式见 §4 示例。

## 4. 示例输出（一次 10 客户端压测后的运行期采样）

\`\`\`
== 运行期性能快照（map-1#ch-1） ==
采样时间: 12:41:00
帧耗时(ms): P50=0.264  P90=0.475  P99=1.230  样本=18599
信封发布: 21510
事件分发: 21510
网络出站: 16488.3 KB / 87574 packets
事件总线 dropped: 0
\`\`\`

解读：tick 预算 50ms，P99 帧耗 1.23ms 占 2.5%——正常负载下有充足余量；dropped=0
说明事件总线无拥塞丢弃；信封=事件分发 说明无丢失（可靠帧与 droppable 帧都到达）。

## 5. 抽样方案说明（回答「是否需要抽验样本采集」）

游戏中**抽样是必须的**，但分两层：

- **按连接抽样**：全量连接逐帧记账成本高（N 连接 × 20fps × 桶累加）。正式方案是**等距抽样**——
  每帧从连接表取固定 K 个（如 32）测 RTT/丢帧，全量吞吐用 HLL（\`unique\` 键）近似。
  当前 \`PerfProbe\` 的 \`world.frame_ms\` 是**帧级全量**（每帧必记账，已足够轻），连接级 RTT 留待扩展。
- **按帧自适应采样**：繁忙帧（帧耗时超预算）全量记账定位热点；空闲帧跳过低频帧。
  当前未实现——若出现帧耗时波动需定位，可加「帧耗时超阈值时多记一档明细」的开关。

结论：**已有帧级全量 + HLL 连接估计**已覆盖「总量观测」；**连接级 RTT 抽样**是下一步可选增强
（新增 \`PerfProbe::record\` 调用点 + 压测脚本的 \`--slow\` 仿真即可落地）。


## 6. 容量压测（stress-hotzone / stress-rooms）

三个压测脚本位于 \`benchmarks/\`，用法见脚本头注释。热区压测以线缆级 \`world:tick_rate\` 帧观测
区域密度降频的**降-升往返**；房间压测以 \`room:spawn\`/`room:aoe` 施压并观测帧率扇出；连接规模压测
（\`stress-map.php\`）以真实 WS 链路阶梯加压，并采样服务端 maps worker 的 CPU/RSS。

> 2026-09 采样器修正：三个压测的服务端采样曾按 cmdline 匹配 `start-maps.php` 而命中 Workerman
> **master**（不承载连接，CPU/RSS 恒平），且 jiffies 字段因 comm 含空格而错位——旧档「CPU avg 0%」
> 「RSS ≈37MB 恒定」皆源于此。现改为「master 的 worker 子进程」求和 + 从最后一个 `)` 起解析
> jiffies + 首末累计差分算率；本节表格为修正后重测。

### 6.1 热区混战（stress-hotzone，格子密度档位 3:1/8:2/0:4）

| 规模 | 带宽/客户端 | 帧率/客户端 | attack→hit p50/p95 | 降档观测 | 服务端 CPU avg/max | RSS max |
|---|---|---|---|---|---|---|
| N=30 | 1553 B/s | 43.6 f/s | 53ms / 60ms | max divisor=4 | 3% / 4% | 100 MB |
| N=60 | 2469 B/s | 71.4 f/s | 37ms / 41ms | max divisor=4 | 4% / 7% | 103 MB |

（2026-09 重测：协议 v2 帧压缩后带宽较旧档显著下降；CPU/RSS 为 4 个 maps worker 求和口径。）

解读（WSL2 开发机实测，形态供参考，绝对值以目标硬件复测为准）：

- **带宽/客户端随 N 次线性增长**（1553→2469），显著低于 O(N) 理论值——因为聚格密度越高档位
  越深（divisor 4），移动广播节流把 O(N²) 扇出的增长压平了。60 人聚团时每客户端 ≈2.5KB/s 下行，
  按同档位外推 100 人 ≈4-5KB/s，千兆网卡支撑 **万级同时在线客户端下行** 无压力。
- **降-升往返自动完成**：tick_rate 时间线呈现 1→2→4（聚格）→…→1（散开+滞回 5s）——双向滞回防抖
  符合设计；边界处 1↔2 振荡是 bot 随机走位跨越格界的真实行为。
- **延迟稳定**：p50 37-53ms（attack→hit 全链路含服务器 tick 粒度），p95 ≤60ms——降档到 5Hz 下
  攻击结算仍随请求到达即时结算（事件驱动），p95 未随规模恶化。
- **单 worker 余量充足**：60 人混战仅占单核约 7%（上限），4 个 maps worker 求和后仍为个位数百分比。

### 6.2 房间容量（stress-rooms，30Hz 房间 tick，每房 6 bot + 周期 spawn/AoE）

| 规模 | 带宽/客户端 | 帧率/客户端 | 服务端 CPU avg/max | RSS max |
|---|---|---|---|---|
| M=15 房（90 bot） | 3522 B/s | 106.4 f/s | 4% / 6% | 107 MB |

（2026-09 重测；旧档「M=15 房 6204 B/s / RSS ≈37MB」的 RSS 是 master 进程值，修正后为 worker 求和。）

### 6.3 发现与限制

- **网关登录限速**：\`run-worker.php:309\` 的 \`SimpleTokenBucket(refillPerSecond: 10, capacity: 20)\`
  使并发认证在 ~60 个后阻塞（压测实测 ready=60/90 封顶）——这是登录通道的保护性限速，批量开服
  场景（开新副本潮）需调大容量或改用按连接限速。
- **CPU 采样（2026-09 已修）**：旧口径按 cmdline 命中 Workerman **master**（不承载连接→CPU 恒 0%、
  RSS 恒平 37MB），叠加 jiffies 字段因 comm 含空格错位——并非「低负载分辨率不足」。现三压测统一
  改采 worker 子进程 + 最后 `)` 起解析 + 首末累计差分；修正后 60 人混战实测 CPU 4-7%、RSS 100-107MB。
- **进程预算层**：预算顺延（deferred）信号已接入心跳指标（rooms/roomsDeferred），本次压测
  未观测到持续顺延（15 房间 30Hz 余量充足）——预算层的降档验证需要更高密度（30+ 活跃房间）。

### 6.4 硬件选型建议（基于上述实测形态）

- 服务端瓶颈为**单 worker 单核**：选型看单核频率/IPC。开发/验证用 Ryzen 5 5600 / i5-12400F 级
  即可；生产按预算选高频档（消费级 Ryzen 9 / X3D 系列，或云上高频睿频 ≥3.8GHz 的通用型实例，
  每 map worker 绑定 1 vCPU）。
- 内存与网卡均为次要项：单 worker RSS ≈40-100MB，32GB 富余；带宽按「每客户端 × 在线数 × 2 倍
  冗余」估算，千兆起。
- 复测清单：在目标硬件以 stress-hotzone N=60/100/150 + stress-rooms M=30/60 重跑本节表格，
  以实测 CPU% 曲线标定单 worker 容量天花板（jiffies 采样已修复，见 §6.3）；连接规模上限参考 §6.5。

### 6.5 连接规模标定（stress-map，真实 WS 链路阶梯加压）

2026-09 新增。口径：`stress-map.php --clients=N --seconds=15 --json`，客户端按 1 move/s 在走廊
ping-pong 走位（高互见密度），服务端采样 maps worker 的 CPU/RSS（修正后口径，见 §6.3）。
每档冷启动服务栈；「每连接」= 当档总增量 ÷ 连接数。

**单进程客户端阶梯**（`--procs=1`，全部落在 map-1 的 2 个频道 worker）：

| 连接数 | 每连接 CPU（单核%） | 每连接内存（增量） | 测量期 P50 / P99 帧间隔 | 服务端 CPU 合计 |
|---|---|---|---|---|
| 50 | 0.32% | ~79 KB | 59 / 80 ms | 16% |
| 100 | 0.33% | ~16 KB（预热栈边际；冷栈 ~300 KB 含 JIT/预热） | 58 / 80 ms | 33% |
| 200 | 0.36–0.42% | ~107 KB | 55 / 128 ms | 73–84% |
| 400 | 0.38–0.46% | ~336 KB | 54 / 293–1163 ms ⚠️ | 150–184% |

**多进程客户端阶梯**（`--procs=N`，突破单进程 ~400 连接的自饱和天花板；分散
map-1,map-2 共 3 个频道 worker）：

| 连接数 | procs | 每连接 CPU | 每连接内存 | P50 / P90 / P99 帧间隔 | 服务端 CPU 合计 | 结果 |
|---|---|---|---|---|---|---|
| 400 | 4 | 0.73% | ~306 KB | 48 / 98 / 1118 ms | 291% | auth=400/400 |
| 600 | 6 | 0.49% | ~399 KB | 38 / 216 / 2151 ms | 293% | auth=600/600 |
| 800 | 8 | 0.39% | ~630 KB | 8 / 266 / 2075 ms | 310% | auth=800/800 |
| 1000 | 10 | 0.33% | ~709 KB | 7 / 281 / 1724 ms | 331% | auth=1000/1000 |
| 1600 | 16 | — | — | — | — | **宿主内存不足中止**（WSL 3.9GB，服务端 RSS 外推 >1.2GB + 16 个客户端进程，OOM） |

解读与边界：

- **多进程客户端把测量天花板从单进程 ~400 推到 1000 连接**（工具层 `--procs=N`，就绪栅栏保证
  各 worker 同时开表，避免建链错峰污染测量窗）。1600 档受阻于 WSL 开发机的 3.9GB 内存（服务端
  每连接内存随扇出增长 + 客户端进程本身），非工具限制；目标硬件（≥16GB）可继续上探。
- **每连接 CPU 0.33–0.49%/核**（600–1000 连接段），且随规模下降——固定开销（tick 空转、
  空闲 worker 基线）被更多连接摊薄。1000 连接时服务端 CPU 合计 331%（4 个 maps worker，
  其中 3 个承载负载）≈ **单个承载 worker 已近单核饱和**——这与单进程阶梯「~200 连接/worker
  近饱和」一致，是**单机容量天花板的第一个硬信号**。
- **每连接内存随规模增长**（306 KB @400 → 709 KB @1000）：高互见密度下广播缓冲（FrameMerger
  槽位 / outbox）随扇出扩张，比静止态（~16 KB）高一个量级——容量规划按此上界预留。
- **P99 尾部（~1–2s）的解释修正**：旧档曾把 400 档 P99 劣化归因「单进程客户端饱和」，多进程
  400 档 P99 仍有 1118ms，**该归因被证伪**。候选解释：高密度扇出下服务端的按连接字节配额/
  慢客户端软过滤会丢弃低优先级 STATE 帧（entity_moved 可丢、靠周期快照重同步兜底），
  表现为部分连接的帧到达间隔拉长；待专项验证（记入复测清单）。
- 归档：`benchmarks/results/conn-scale-{50,100,200,400}.json`（单进程冷栈）、`conn-warm-*.json`
  （单进程预热栈）、`conn-mp-{400,600,800,1000}.json`（多进程）。


## 7. 长跑（soak）与故障矩阵演练

上线前稳定性验证的两个自动化演练器（自托管服务栈，编排器不依赖 Workerman，与服务栈故障隔离）：

### 7.1 长跑：`php benchmarks/soak-map.php`

循环驱动 stress-map 波次（真实 WS 客户端登录 → move 循环），每波采样 worker RSS / Redis / 日志体积，
最小二乘评估 **RSS 线性斜率**（内存泄漏哨兵）+ 认证成功率 + 单客户端帧率下限；时间线落
`/tmp/nythros-drill/soak-timeline.jsonl`，末行 `RESULT: PASS|FAIL`。

```bash
# CI 冒烟（2 分钟，宽松 RSS 斜率 256 抵消冷启动 warmup）
php benchmarks/soak-map.php --minutes=2 --clients=10 --wave-seconds=25 --rss-slope=256
# 本机小时级长跑（严格阈值，默认 16KB/采样）
php benchmarks/soak-map.php --minutes=240 --clients=30 --wave-seconds=60
php benchmarks/soak-map.php --self-test
```

### 7.2 故障矩阵：`php benchmarks/fault-drill.php`

四场景「注入 → 行为断言 → 恢复 → 自愈断言」：**redis-down**（worker 存活/新登录被拒/免重启自愈）、
**mysql-down**（主链路无感/恢复自愈）、**kill9**（worker 被 kill -9 后 master 重生）、
**exporter**（导出进程 kill 后登录不受影响 + backlog 心跳停更可观测 + 重启续消费——Workerman
proctitle 双形态全树杀防「只杀 worker 被 master 重生」假绿）。Redis/MySQL
的控制命令可用 `--redis-stop/--redis-start/--mysql-stop/--mysql-start` 覆盖（MySQL 注入需要 root）。
已知边界：网络分区无法单机演练（需 tc/netem 或多机）。自检：`--self-test`。

### 7.3 演练的既有发现（已修复，详见 blueprint/33）

- **心跳定时器无异常边界**：Redis 宕机期间第一次心跳抛异常会打死常驻定时器，注册表从此不再回填；
- **心跳 meta 不完整**：Redis 数据丢失（无持久化重启）后，仅 playerCount 的心跳合并产出缺
  mapId/wsAddress 的残缺 meta，`selectChannel` 永久拒绝该频道——心跳现携带完整注册 meta，
  首个心跳（≤5s）即可无损重建注册条目。

## 8. 平台量化验收矩阵（「高负载/高性能/高可靠」的可回归承诺）

定标原则：每格要么有**实测记录**（标注来源），要么有**可复跑命令**；不写拍脑袋数字。基线劣化统一由
`tools/bench-gate.php` 监听（阈值 20%，高方差指标只存档不入监听集）。

| 维度 | 指标 | 验收标准 | 验证手段/来源 |
|---|---|---|---|
| 帧成本 | `world.frame_ms` P99 | < 5ms @ 1000 实体 AOI 世界（实测 0.04ms/帧 ×50Hz 预算） | `php benchmarks/engine-bench.php --json` + bench-gate |
| JIT 兼容 | 引擎热函数在 tracing JIT 下行为 | WSL 开发环境（`opcache.jit=tracing`）下 engine 全测试类全绿——长跑常驻进程必然触发热编译，miscompile 属 P0 | `php vendor/bin/phpunit packages/engine/tests/Aoi packages/engine/tests/World`（缺省 ini） |
| 帧漂移 | 24h 全负载后半段斜率 | ≈0（实测 +0.00016ms/波 平台化） | `soak-map.php --play`（blueprint/34） |
| 热路径 IO | tick 文件 IO 客户端引用 | =0（静态）；`listener_error_total` 增速 ≈0（运行期） | `composer io-free` + Prometheus |
| 消息归因 | `network.dispatch_ms` 各桶 | 任何消息类型不越 32ms 桶（越界即现形定位） | perf-stats §3.2 键族 |
| 登录吞吐 | 单 gateway 进程 | ≥45/s（cost 9 = 22.3ms 实测 WSL；调 cost 8 ≈93/s） | security.md §2 三级旋钮 |
| 房间容量 | 30Hz × 6 人 + spawn/AoE | 15 房（90 bot）无顺延：CPU avg 4%/max 6%、worker 求和 RSS 107MB；上限以 30/60 房复测标定 | `stress-rooms`（§6.2/§6.4） |
| 热区扇出 | 60 人聚格带宽/客户端 | < 4KB/s（实测 v2 协议下 2.5KB/s；密度降档把 O(N²) 压平） | `stress-hotzone`（§6.1） |
| 连接规模 | 每连接 CPU / 内存（1 move/s 走廊负载） | 0.33–0.49% 核 / 306–709 KB（高扇出段）；单 worker ~200 连接近饱和、~100 为舒适区；多进程客户端可将测量推至 1000+ 连接 | `php benchmarks/stress-map.php --clients=N --seconds=15 --procs=M --json`（§6.5） |
| 导出延迟 | `nythros_perf_gauge{service="storage-exporter",metric="backlog"}` | < 5000 持续 5min 告警；Stream MAXLEN 100k 双保险丝 | Prometheus + fault-drill exporter 场景 |
| 长跑稳定 | RSS 斜率 / dropped / auth | 0.000 / 0 / 100%（24h 实测 1416 波全过） | `soak-map` + 每小时巡检脚本 |
| 容错 | 故障矩阵四场景 | `RESULT: PASS`（redis/mysql/kill9/exporter） | `php benchmarks/fault-drill.php` |

> 目标硬件复测纪律：以上阈值为 WSL2 开发机实测；生产按 §6.4 硬件选型在 staging 重跑一遍再签字。
