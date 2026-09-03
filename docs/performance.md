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

两个压测脚本位于 \`benchmarks/\`，用法见脚本头注释。热区压测以线缆级 \`world:tick_rate\` 帧观测
区域密度降频的**降-升往返**；房间压测以 \`room:spawn\`/`room:aoe` 施压并观测帧率扇出。

### 6.1 热区混战（stress-hotzone，格子密度档位 3:1/8:2/0:4）

| 规模 | 带宽/客户端 | 帧率/客户端 | attack→hit p50/p95 | 降档观测 | 回温 |
|---|---|---|---|---|---|
| N=10 | 1303 B/s | 25.5 f/s | 24ms / 27ms | max divisor=4 | ✅ 回 1 |
| N=30 | 2932 B/s | 60.6 f/s | 43ms / 49ms | max divisor=4 | ✅ 回 1 |
| N=60 | 3822 B/s | 79.4 f/s | 42ms / 51ms | max divisor=4 | ✅ 回 1 |

解读（WSL2 开发机实测，形态供参考，绝对值以目标硬件复测为准）：

- **带宽/客户端随 N 次线性增长**（1157→2932→3822），显著低于 O(N) 理论值——因为聚格密度越高档位
  越深（divisor 4），移动广播节流把 O(N²) 扇出的增长压平了。60 人聚团时每客户端 ≈3.8KB/s 下行，
  100 人聚团外推 ≈6-8KB/s，千兆网卡支撑 **万级同时在线客户端下行** 无压力。
- **降-升往返自动完成**：tick_rate 时间线呈现 1→2→4（聚格）→…→1（散开+滞回 5s）——双向滞回防抖
  符合设计；边界处 1↔2 振荡是 bot 随机走位跨越格界的真实行为。
- **延迟稳定**：p50 24-43ms（attack→hit 全链路含服务器 tick 粒度），p95 ≤51ms——降档到 5Hz 下
  攻击结算仍随请求到达即时结算（事件驱动），p95 未随规模恶化。

### 6.2 房间容量（stress-rooms，30Hz 房间 tick，每房 6 bot + 周期 spawn/AoE）

| 规模 | 带宽/客户端 | 帧率/客户端 | 备注 |
|---|---|---|---|
| M=5 房（30 bot） | 4857 B/s | 100.7 f/s | 正常 |
| M=15 房（60 bot） | 6204 B/s | 128.8 f/s | 正常；RSS ≈37MB 恒定 |

### 6.3 发现与限制

- **网关登录限速**：\`run-worker.php:309\` 的 \`SimpleTokenBucket(refillPerSecond: 10, capacity: 20)\`
  使并发认证在 ~60 个后阻塞（压测实测 ready=60/90 封顶）——这是登录通道的保护性限速，批量开服
  场景（开新副本潮）需调大容量或改用按连接限速。
- **CPU 采样**：\`/proc\` jiffies 求和口径在低负载下分辨率不足（10-60 bot 均 avg 0%），CPU 容量
  曲线需在目标硬件以更高密度复测；RSS 恒定 ≈37MB（无泄漏迹象）。
- **进程预算层**：预算顺延（deferred）信号已接入心跳指标（rooms/roomsDeferred），本次压测
  未观测到持续顺延（15 房间 30Hz 余量充足）——预算层的降档验证需要更高密度（30+ 活跃房间）。

### 6.4 硬件选型建议（基于上述实测形态）

- 服务端瓶颈为**单 worker 单核**：选型看单核频率/IPC。开发/验证用 Ryzen 5 5600 / i5-12400F 级
  即可；生产按预算选高频档（消费级 Ryzen 9 / X3D 系列，或云上高频睿频 ≥3.8GHz 的通用型实例，
  每 map worker 绑定 1 vCPU）。
- 内存与网卡均为次要项：单 worker RSS ≈40-100MB，32GB 富余；带宽按「每客户端 × 在线数 × 2 倍
  冗余」估算，千兆起。
- 复测清单：在目标硬件以 stress-hotzone N=60/100/150 + stress-rooms M=30/60 重跑本节表格，
  以实测 CPU% 曲线（需修复 jiffies 采样分辨率或改用外部监控）标定单 worker 容量天花板。

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

三场景「注入 → 行为断言 → 恢复 → 自愈断言」：**redis-down**（worker 存活/新登录被拒/免重启自愈）、
**mysql-down**（主链路无感/恢复自愈）、**kill9**（worker 被 kill -9 后 master 重生）。Redis/MySQL
的控制命令可用 `--redis-stop/--redis-start/--mysql-stop/--mysql-start` 覆盖（MySQL 注入需要 root）。
已知边界：网络分区无法单机演练（需 tc/netem 或多机）。自检：`--self-test`。

### 7.3 演练的既有发现（已修复，详见 blueprint/33）

- **心跳定时器无异常边界**：Redis 宕机期间第一次心跳抛异常会打死常驻定时器，注册表从此不再回填；
- **心跳 meta 不完整**：Redis 数据丢失（无持久化重启）后，仅 playerCount 的心跳合并产出缺
  mapId/wsAddress 的残缺 meta，`selectChannel` 永久拒绝该频道——心跳现携带完整注册 meta，
  首个心跳（≤5s）即可无损重建注册条目。
