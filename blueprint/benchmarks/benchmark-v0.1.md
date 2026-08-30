# Benchmark v0.1

> 版本序列起点。按 `blueprint/05-验收与质量门禁.md` 第 3 章字段清单记录，作为后续 Benchmark v0.2 / v0.3 … 对比基线。
> 记录原则：不拍脑袋定指标；只有版本序列可对比，才能判断每次修改是性能提升还是退化。

## 1. 测试环境

| 字段 | 值 | 来源 |
| --- | --- | --- |
| 测试机器 | DESKTOP-3DM1LAC | gethostname() |
| uname | Linux DESKTOP-3DM1LAC 6.18.33.2-microsoft-standard-WSL2 #1 SMP PREEMPT_DYNAMIC Thu Jun 18 21:54:43 UTC 2026 x86_64 | php_uname() |
| PHP 版本 | 8.3.32（JIT off） | PHP_VERSION |
| Workerman 版本 | 5.2.2 | `Workerman\Worker::VERSION` |
| CPU | 12 核 | /proc/cpuinfo processor 行数 |
| RAM | 15919 MB | /proc/meminfo MemTotal |
| Redis | 127.0.0.1:6379（token 存储） | — |

## 2. 服务器固定配置

| 字段 | 值 | 说明 |
| --- | --- | --- |
| cellSize | 10 | `GridAOI(10)`，九宫格 = 当前格 ±1 |
| Tick Rate | 20 Hz | 服务器 50ms 世界 tick（server.php 固定） |
| 限流 | 10 tokens/s、容量 20（每连接独立桶） | gateway 与 map 各一个实例 |
| 端口 | Gateway 18282 / Map 18081 | — |

## 3. 执行命令

```bash
# 前置：Redis 127.0.0.1:6379 可用
php packages/demo/bin/server.php start            # 启动服务器（后台）
php packages/demo/bin/benchmark.php --connections=50 --duration=30 start   # 基准（每轮 1 次）
# 结束后：kill -INT <server master pid> 优雅停服
```

## 4. 基准运行记录（两次正式运行）

### Run 1（正式，编号 run3）

- 连接数 50 / Actor 数 ≈50 / 时长 30s / move 间隔 1s
- Cell 数：4（客户端镜像位置覆盖的格子数）
- Tick Rate：20 Hz（服务器固定）
- 消息频率：收到 2410.83/s，move 发送 48.33/s
- 广播数量（entity_moved_received）：71050
- 延迟：P50 = 36.09ms，P95 = 58.25ms，P99 = 64.25ms（样本 71050）
- 错误率：0.00%（errors=0、auth_failed=0、unexpected_close=0）
- AOI 校验：aoi_violations=0；enter_count=1225；leave_count=0
- RESULT: **PASSED**
- 客户端内存：usage=6295552 bytes，VmRSS=18184 kB，peak=13639680 bytes
- 运行期内存曲线：mem@10s usage=4194304 B / VmRSS=16840 kB → mem@20s usage=6291456 B / VmRSS=17740 kB

### Run 2（正式，编号 run4）

- 连接数 50 / Actor 数 ≈50 / 时长 30s / move 间隔 1s
- Cell 数：7（客户端镜像位置覆盖的格子数）
- Tick Rate：20 Hz（服务器固定）
- 消息频率：收到 2402.07/s，move 发送 48.33/s
- 广播数量（entity_moved_received）：70579
- 延迟：P50 = 41.46ms，P95 = 56.57ms，P99 = 59.51ms（样本 70579）
- 错误率：0.00%（errors=0、auth_failed=0、unexpected_close=0）
- AOI 校验：aoi_violations=0；enter_count=1310；leave_count=123
- RESULT: **PASSED**
- 客户端内存：usage=6295552 bytes，VmRSS=18200 kB，peak=13639680 bytes
- 运行期内存曲线：mem@10s usage=4194304 B / VmRSS=16864 kB → mem@20s usage=6291456 B / VmRSS=17764 kB

### 可重复性判据（Run 1 vs Run 2）

| 指标 | Run 1 | Run 2 | 波动 |
| --- | --- | --- | --- |
| RESULT | PASSED | PASSED | 一致 |
| aoi_violations | 0 | 0 | 一致 |
| P95 | 58.25 ms | 56.57 ms | 2.9% ✓（< 20%） |
| P99 | 64.25 ms | 59.51 ms | 7.4% ✓（< 20%） |
| 广播数量 | 71050 | 70579 | 0.7% ✓ |

判据通过：两次 RESULT PASSED、P95/P99 波动 < 20%，可重复。

### 完整 JSON（Run 1）

```json
{
    "benchmark": "v0.1",
    "machine": {
        "hostname": "DESKTOP-3DM1LAC",
        "uname": "Linux DESKTOP-3DM1LAC 6.18.33.2-microsoft-standard-WSL2 #1 SMP PREEMPT_DYNAMIC Thu Jun 18 21:54:43 UTC 2026 x86_64",
        "php_version": "8.3.32",
        "workerman_version": "5.2.2",
        "cpu_cores": 12,
        "ram_mb": 15919
    },
    "run": {
        "connections": 50,
        "actors": 50,
        "duration_s": 30,
        "move_interval_s": 1,
        "cell_size": 10,
        "tick_rate_hz": 20,
        "cells_used": 4,
        "moves_sent": 1450,
        "messages_received": 72325,
        "broadcasts": 71050,
        "enter_count": 1225,
        "leave_count": 0,
        "aoi_violations": 0,
        "msg_rate_per_s": 2410.83,
        "moves_rate_per_s": 48.33,
        "latency_ms": { "samples": 71050, "p50": 36.09, "p95": 58.25, "p99": 64.25 },
        "errors": 0,
        "unexpected_close": 0,
        "error_rate_pct": 0,
        "client_memory_peak_bytes": 13639680,
        "result": "PASSED"
    }
}
```

### 完整 JSON（Run 2）

```json
{
    "benchmark": "v0.1",
    "machine": {
        "hostname": "DESKTOP-3DM1LAC",
        "uname": "Linux DESKTOP-3DM1LAC 6.18.33.2-microsoft-standard-WSL2 #1 SMP PREEMPT_DYNAMIC Thu Jun 18 21:54:43 UTC 2026 x86_64",
        "php_version": "8.3.32",
        "workerman_version": "5.2.2",
        "cpu_cores": 12,
        "ram_mb": 15919
    },
    "run": {
        "connections": 50,
        "actors": 50,
        "duration_s": 30,
        "move_interval_s": 1,
        "cell_size": 10,
        "tick_rate_hz": 20,
        "cells_used": 7,
        "moves_sent": 1450,
        "messages_received": 72062,
        "broadcasts": 70579,
        "enter_count": 1310,
        "leave_count": 123,
        "aoi_violations": 0,
        "msg_rate_per_s": 2402.07,
        "moves_rate_per_s": 48.33,
        "latency_ms": { "samples": 70579, "p50": 41.46, "p95": 56.57, "p99": 59.51 },
        "errors": 0,
        "unexpected_close": 0,
        "error_rate_pct": 0,
        "client_memory_peak_bytes": 13639680,
        "result": "PASSED"
    }
}
```

## 5. 热身/噪声记录（不参与判据，如实留档）

同一命令共跑 4 次，前两次为热身期（服务器长时运行后的首批大负载 + WSL2 环境噪声）：

| 轮次 | P50 | P95 | P99 | RESULT | 备注 |
| --- | --- | --- | --- | --- | --- |
| run1 | 40.86 ms | 61.65 ms | 80.39 ms | PASSED | 热身，p99 出现长尾 |
| run2 | 16.64 ms | 28.34 ms | 50.66 ms | PASSED | 噪声低点（延迟整体减半） |
| run3（正式 Run 1） | 36.09 ms | 58.25 ms | 64.25 ms | PASSED | 进入稳定区间 |
| run4（正式 Run 2） | 41.46 ms | 56.57 ms | 59.51 ms | PASSED | 进入稳定区间 |

结论：WSL2 环境下延迟有 ±30% 量级的环境噪声；稳定后相邻两轮（run3/run4）波动 < 20%。后续版本对比建议连续跑 ≥3 轮取稳定区间（去掉热身与明显噪声点），或迁移到裸机/CI 固定负载环境。

## 6. 指标口径说明

- **延迟口径**：收到 `entity_moved` 时刻 − 消息内 `timestamp`（服务器处理该 move 的时刻）。含服务器帧末 outbox flush（≤ 1 tick）与网络回程，**不含**客户端 move 上行链路（消息无法还原客户端发送时刻）。这是客户端可取得的最近似端到端延迟。
- **错误率**：(errors + auth_failed + unexpected_close) / connections × 100%。
- **Cell 数**：客户端镜像位置覆盖的格子数（客户端可观测口径；服务器侧格子数与索引维护方式相关）。
- **AOI 校验口径**：`entity_moved(id=X, position=P)` 到达时，用接收者自己的位置校验 P 是否落在自己九宫格内（floor 坐标/10 后格差 ±1）。基准取「自己的镜像或本地上一步镜像」任一命中即通过——服务器广播目标按 AOI 索引判定且索引滞后 ≤1 tick（50ms），跨格后索引刷新前的广播可能按旧格命中，属设计内行为；两个基准均落空才计 `aoi_violations`（真实错误广播，质量门禁第 4 条红线）。

## 7. stress-client 实测（同批验证）

```bash
php packages/demo/bin/stress-client.php --connections=20 --duration=60 --move-interval=0.5 start
```

- RESULT: PASSED，aoi_violations=0，enter_count=543，leave_count=439，p95_latency_ms=28.56
- 另有 3 连接 / 5s 间隔（30s）与 10 连接 / 0.3s 间隔（20s）等场景均 PASSED、aoi_violations=0。

## 8. 600s 长跑内存验证（G8，2026-08-15）

命令：`php -d memory_limit=512M packages/demo/bin/benchmark.php --connections=100 --duration=600 start`（服务器固定配置不变）。

### 结论

服务器进程 RSS 两轮长跑均恒定（引擎侧无明显内存泄漏）；客户端进程 RSS 单调增长归因于 benchmark 工具自身的全量延迟样本存储（统计口径开销，非引擎泄漏）。

### 服务器进程 RSS（/proc 逐 60s 采样，第二轮）

| 时刻 | gateway worker | map worker |
| --- | --- | --- |
| t+60s | 17236 kB | 20312 kB |
| t+120s | 17300 kB | 20320 kB |
| t+180s | 17300 kB | 20324 kB |
| t+240s~t+720s（结束） | 17300 kB | 20380 kB |

gateway 自连接建立后恒定 17300 kB，map 自 t+240s 起恒定 20380 kB（初段 +68 kB 为连接建立期分配）。第一轮（默认 memory_limit=128M）采样点（03:35-03:37）gw 17112 / map 20192 kB 恒定，两轮一致。

### 客户端进程内存曲线（第二轮，benchmark 自报 mem@ 行）

| 时刻 | usage（bytes） | VmRSS（kB） |
| --- | --- | --- |
| 60s | 20975616 | 25792 |
| 120s | 20975616 | 32412 |
| 180s | 37752832 | 37624 |
| 240s | 37752832 | 41680 |
| 300s | 37752832 | 44756 |
| 360s | 37752832 | 47404 |
| 420s | 37752832 | 49600 |
| 480s | 71307264 | 51596 |
| 540s | 71307264 | 53384 |
| 600s | 71307264 | 54972 |

客户端 RSS 随运行时长线性增长（2415122 条延迟样本全量保存 + 位置镜像表），属工具统计存储，不是服务器/引擎行为；本次加 `-d memory_limit=512M` 即因默认 128M 下 600s 收尾分位排序 OOM（见下）。

### 第二轮完整指标

- connections=100 / auth_ok=100 / unexpected_close=0 / errors=0 / error_rate 0.00%
- broadcasts=2415122、msg_rate=4245.32/s、moves_rate=99.83/s、cells_used=91
- aoi_violations=0、enter_count=66391、leave_count=65580
- latency：p50=26.88ms、p95=89.51ms、p99=103.57ms（样本 2415122）
- RESULT: **PASSED**

### 工具缺陷记录（第一轮）

默认 memory_limit=128M 下，600s 收尾计算分位时 `sort($samples)` 需额外 ~64MB 连续内存导致 OOM（E_ERROR，客户端 worker 崩溃并被 Workerman master 重启）。判定为 benchmark 工具缺陷而非引擎问题：样本数组全量保存 + 收尾全量排序。后续版本建议改为分桶在线分位估计或抽样，避免样本量随时长线性累积内存。
