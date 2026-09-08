# 部署指南（Deployment）

> 面向读者：把 Nythros 从「本机能跑」推进到「能上线」的工程/运维。读完你能：构建官方镜像、
> 用 compose 或裸机部署完整服务、接入 Prometheus 监控、按生产 checklist 上线。
> 拓扑事实源始终是 `packages/demo/config/deploy.yaml`（键名与校验规则见 [mmorpg-mode](mmorpg-mode.md) §2）。

## 1. 官方镜像

根目录 `Dockerfile` 基于 `php:8.3-cli`，补齐基础镜像缺失的扩展后打入 monorepo 源码与生产 vendor：

```bash
docker build -t nythros/server .
```

- 扩展：`pdo_mysql`（归档存储）、`ext-redis`（token/注册发现/快照/采样）；`pcntl`/`posix` CLI 基镜像自带。
- 入口 `php bin/server start`；`NYTHROS_CONFIG_DIR` 缺省指向 `/app/packages/demo/config`。
- EXPOSE：18285-18287（social 三角色）、18081-18084（map/副本）、19100（metrics）。

## 2. compose 部署（依赖 + 应用一键起）

根目录 `compose.yaml` 的缺省行为不变（只起 Redis + MySQL 依赖栈）；容器化应用挂在 `app` profile：

```bash
# ① 只起依赖（与裸机部署组合用）
docker compose up -d

# ② 依赖 + 应用全部容器化
docker compose --profile app up -d --build
```

容器内访问依赖走 compose 服务名：**先把 `packages/demo/config/deploy.yaml` 的 redis/mysql host 从
`127.0.0.1` 改为 `redis`/`mysql`**（该目录已挂载为卷，改宿主机文件即时生效、免重建镜像）。

验证：

```bash
docker compose ps
php packages/demo/bin/verify-phase5.php   # 宿主机跑验收（端口已映射）
curl -s localhost:19100/metrics | head    # metrics 端点（见 §4）
```

## 3. 裸机 / 虚拟机部署

沿用 quick-start 步骤（依赖栈可来自 compose），生产要点：

1. **启动铁序**：Redis → social 单元 → map 单元 → storage 导出单元；`php bin/server start` 已内置探测（Redis 不可用即中止）。
2. **进程管理**：`bin/server` 前台运行 + `status/stop` 子命令；生产建议 systemd unit
   （`Restart=on-failure`，`ExecStart=php /path/bin/server start`）或等价 supervisor。
3. **持久化模型**：缺省 `NYTHROS_PERSIST_MODE=export`——会话热状态权威在 Redis（背包 `nythros:bag:{uid}` 等），
   脏快照经 `nythros:export:players` Stream 由 `run-exporter.php`（`type: storage`，单消费者）落 MySQL，
   游戏 worker 零 PDO；设 `mysql` 回退旧直写口径（见 quick-start §3.1）。
4. **玩法开关**：环境变量 `NYTHROS_CONFIG_DIR` / `NYTHROS_MMORPG` / `NYTHROS_GAMEPLAY` 等
   按 mmorpg-mode §2 的开关表配置。
5. **Map 有状态，不能 reload**——更新走滚动更新（§5）。

## 4. 监控：Prometheus 指标端点

```bash
php packages/demo/bin/metrics-exporter.php --addr=0.0.0.0:19100
```

只读 Redis 中 PerfSampler 写入的 `nythros:perf:{serviceId}:*` 键，翻译为 Prometheus 文本格式
（语义与 [performance.md](performance.md) §3 完全一致）：

| 指标 | 类型 | 说明 |
|---|---|---|
| `nythros_perf_counter{service,event}` | counter | 事件计数（`world.envelope_published`、`network.out_bytes` 等，单调累计） |
| `nythros_perf_hist_bucket{service,metric,le}` | histogram | `world.frame_ms` 帧耗时标准累积桶（le 单位 ms，含 `+Inf`）——直接配 Grafana heatmap |
| `nythros_perf_hist{service,metric,bucket}` | gauge | 其他直方图的原始桶计数 |
| `nythros_perf_total_ms{service,metric}` | gauge | 累计毫秒（均值 = total / 同名 counter） |
| `nythros_perf_last_sample_timestamp_seconds{service}` | gauge | 各实例最近采样时间（判断实例活性） |
| `nythros_perf_scrape_errors_total` | counter | 导出器自身抓取失败计数 |

Prometheus 抓取配置示例：

```yaml
scrape_configs:
  - job_name: nythros
    static_configs:
      - targets: ["map-1.internal:19100", "map-2.internal:19100"]
```

告警建议（初版阈值，按实测校准）：`nythros_perf_hist_bucket{le="64"}` 增速 > 0（帧耗时触顶）、
`eventbus.dropped_total` 增速 > 0（事件总线拥塞丢弃）、`last_sample_timestamp_seconds` 停滞 > 60s
（采样器或实例失联）、`nythros_perf_gauge{service="storage-exporter",metric="backlog"}` 持续 > 5000
（导出积压，落盘老化）、storage-exporter 的 `last_sample_timestamp_seconds` 停滞 > 30s（exporter 失联，
数据不丢但报表停摆——重启归 systemd/编排，PEL 未 ack 条目自动重放）。自检：`php packages/demo/bin/metrics-exporter.php --self-test`；
故障演练：`php benchmarks/fault-drill.php --scenario=exporter`。

## 5. 滚动更新

Map 是有状态进程（一频道一进程一 World），**禁止原地 reload**；标准流程：

```bash
php packages/demo/bin/map-rolling.php mark-stopping map-1#ch-1   # 旧实例标记 stopping
php packages/demo/bin/map-rolling.php watch map-1#ch-1           # 等 playerCount 归零（可选 --timeout=600）
# 社交层 discover 已过滤 stopping 实例（不分配新玩家）→ 旧实例自然退出 → 启动新实例
```

social 三角色无状态，可直接替换进程。容量准入/draining 语义见 [mmorpg-mode](mmorpg-mode.md) §5。

## 6. 生产 checklist

- [ ] deploy.yaml：redis/mysql host 指向生产地址；端口无冲突（DeployConfig 启动即校验）
- [ ] Redis：开启认证（`NYTHROS_REDIS_PASSWORD`，见 ADR-028）+ 网络隔离（token/转移票据/位置快照都在里面）；MySQL 最小权限账号
- [ ] TLS 前置终结（反向代理/LB），明文凭据只到 gateway（见 [security.md](security.md) §1）
- [ ] 账号体系：`NYTHROS_ACCOUNTS_FILE` 替代明文 env（哈希表形态，见 [security.md](security.md) §5）；
      防爆破阈值按预期账号规模调校（`NYTHROS_AUTH_MAX_ATTEMPTS`/`NYTHROS_AUTH_LOCKOUT_SECONDS`）
- [ ] 协议版本守卫：设置 `NYTHROS_MIN_CLIENT_VERSION`（ADR-027），老客户端在握手层被拒
- [ ] 演示账号已下线，`StaticGmAuthorizer` 已替换为生产权限体系
- [ ] metrics-exporter 部署并接入 Prometheus（§4，同样注入 `NYTHROS_REDIS_PASSWORD`），关键告警已配置
- [ ] 滚动更新流程演练过一次（§5），`map-rolling.php mark-stopping/watch` 可用
- [ ] 备份/恢复演练过一次（§7）
- [ ] 容量压测在目标硬件复测过（[performance.md](performance.md) §6.4 复测清单）
- [ ] 归档链路（export 模式，缺省）：`type: storage` 已声明、exporter 存活（启动日志 `[run-exporter] started`；
      离线自检 `php packages/demo/bin/run-exporter.php --self-test`）、`XLEN nythros:export:players` 有界（无持续增长）；
      mysql 回退模式：worker 直写归档生效（`MySqlStorage` + 幂等 `createSchema` + 30s 兜底 + 合并窗 flush）；
      两种模式都在 staging 验证建表与恢复（§7）

## 7. 备份与恢复演练

上线前**至少完整演练一次**，把「能恢复」变成记录在案的事实而不是假设。

### 7.1 备份对象与策略

| 对象 | 内容 | 策略建议 |
|---|---|---|
| MySQL `nythros_archive` 表 | 玩家归档（背包/任务等快照，export 模式由 storage-exporter 写入、mysql 模式由 worker 直写） | 每日全量 dump + binlog 增量；保留 ≥7 天 |
| Redis | token/转移票据（短 TTL，可不备份）、队伍/帮派/好友/任务/邮件/拍卖/排行/**背包 `nythros:bag:*`（export 模式权威）**、导出 Stream（积压上限=保险丝值） | 开 AOF（everysec）+ 每日 RDB；队伍/帮派等业务键与 token 分库（`NYTHROS_REDIS_DB`）便于差异化管理 |
| 配置 | deploy.yaml + 玩法三表 + 账号文件 | 随代码版本管理；账号文件**永不入库**（明文纪律，见 security.md §5） |

### 7.2 恢复演练步骤（staging 执行并记录）

1. **MySQL 恢复**：空库 → dump 导入 → `MySqlStorage::createSchema` 幂等校验 → 启动 map worker →
   抽样 `ArchivePipeline::load(uid)` 核对若干已知玩家归档；
2. **Redis 恢复**：AOF 重放 → 核对队伍/帮派/好友快照与 TTL 语义（token/票据允许全失，短 TTL 本来
   就是设计假设——**在线玩家全掉重登**，这是已记录的行为而非事故）；
3. **票据丢失专项**：Redis 清空后让一个持有转移票据的客户端重连——预期走默认入场点 + 恢复读兜底
   （export 模式读 `nythros:bag:*` 背包权威、无键即全新；mysql 回退模式走 `NYTHROS_ARCHIVE_RESTORE=1` 归档读），
   记录实际表现；
4. **演练产物**：把以上步骤的实际命令、耗时、偏差写进当次发布记录（blueprint/ 附录或内部 runbook）。

### 7.3 已知边界

- 丢失窗口契约（export 模式）：游戏 worker 崩溃时，最后 ≤30s 未冲刷的脏快照随进程内存消失——但在线态可从
  Redis 即时恢复（背包权威已在 `nythros:bag:*`），真正丢的是未冲刷窗口的增量；这是吞吐与持久性的既有取舍
  （裁决 4），运维用「宕机即公告 + 补偿邮件」承接，不要试图用加锁消除；
- exporter 失联 = 报表老化不回档：发布侧 MAXLEN 保险丝 + 消费侧 XTRIM 双治理；Redis 崩溃时未落 MySQL 的
  增量回退到最近归档——监控必须对 exporter 存活、`XLEN` 积压与 `[run-exporter]` 日志告警，必要时重启 exporter
  续消费（PEL 未 ack 条目自动重放，at-least-once）；
- MySQL 长时间不可用时 exporter 存活但 upsert 持续失败（条目滞留 PEL，恢复后重放）——不影响游戏侧帧延迟。

## 8. 发布与仓库形态

**开发只有一个仓库**：[Nythros/Nythros](https://github.com/Nythros/Nythros)（monorepo，含
`packages/engine|framework|skeleton|demo|client-js`）。用户可见的三个 Composer 包是它的**发布镜像**（ADR-019 决策 B）：

| 镜像仓 | 来源子树 | 发布渠道 |
|---|---|---|
| [Nythros/engine](https://github.com/Nythros/engine) | `packages/engine` | Packagist `nythros/engine` |
| [Nythros/framework](https://github.com/Nythros/framework) | `packages/framework` | Packagist `nythros/framework` |
| [Nythros/skeleton](https://github.com/Nythros/skeleton) | `packages/skeleton` | Packagist `nythros/skeleton`（create-project 模板） |

发布流程（全部自动，人工只有一个动作——在 monorepo 打 tag）：

```bash
git tag v0.1.1 && git push origin v0.1.1
```

`.github/workflows/release.yml` 随即执行：质量门禁（phpunit/phpstan）→ GitHub Release（engine/framework
zip 附件）→ **git subtree split** 把三个 `packages/*` 子树强推镜像仓 `main` + 同名版本 tag（skeleton 拆分时
自动把依赖约束对齐到 tag 次版本）→ Packagist 通知 → npm（@nythros/client，配了 token 才启用）。

三条纪律：

1. **镜像仓只读**：直接向 Nythros/engine|framework|skeleton 的提交会在下一个 tag 被强推覆盖。所有改动
   （含 skeleton 文档）都发生在 monorepo `packages/` 下。
2. **skeleton 只在稳定 tag 同步**：engine/framework 的日常 dev 迭代不流入 skeleton；每次发布同时刷新
   skeleton 的 Packagist 冒烟（其仓库 CI：create-project 组合 → launch → client 断言）。
3. **Secret 前置**（仓库 Settings → Secrets and variables → Actions）：`SUBSPLIT_TOKEN`（对三个镜像仓有
   Contents: write 的 PAT）、可选 `PACKAGIST_USERNAME`/`PACKAGIST_TOKEN`（拆分仓未配 Packagist webhook 时
   的双保险）、可选 `NPM_TOKEN`。缺哪个对应 job 跳过哪个，不阻塞 GitHub Release。

> 历史注记：ADR-019 当时按「两包（engine/framework）」编写，skeleton 纳入发布矩阵为后续演进（见 CHANGELOG 与
> blueprint/21）。blueprint 是决策记录，不回改。

