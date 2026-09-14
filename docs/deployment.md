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

## 6. Redis 高可用：哨兵（可选，ADR-031）

单实例 Redis 是全服唯一跨进程事实源（token/注册表/位置快照/经济域），本仓库内置哨兵客户端支持：
**不配置即直连**（开发零影响），配置后主库故障自动切换、worker 无重启自愈。

### 6.1 服务端拓扑

1 主 + 1 从 + **3 哨兵**（quorum 2），哨兵可与应用混部（进程很轻）：

```conf
# 主库 redis.conf
requirepass <pw>
appendonly yes
min-replicas-to-write 1        # 脑裂写护栏：无健康从库即拒写（NOREPLICAS）
min-replicas-max-lag 10

# 从库 redis.conf
replicaof <master-ip> 6379
masterauth <pw>                # 必配：升主后要能连上新主，漏配会在切换后卡死
requirepass <pw>

# 哨兵 sentinel.conf ×3（端口/目录不同）
port 26379
sentinel monitor nythros <master-ip> 6379 2
sentinel auth-pass nythros <pw>
sentinel down-after-milliseconds nythros 5000
sentinel failover-timeout nythros 60000
sentinel parallel-syncs nythros 1
```

要点：**数据端口（6379/从库端口）必须对应用放通**——哨兵只回答「谁是主」，数据是应用直连主从的；
哨兵配置文件必须可写（运行时会改写自身）；容器/多网卡环境给数据节点配 `replica-announce-ip/port`，
否则哨兵上报容器内网地址。

开发/演练用 `deploy/redis-ha/`（脚本化同构栈，端口 16379/16380 + 26379-81，与 6379 单实例共存）：

```bash
bash deploy/redis-ha/start.sh            # 启动（就绪校验：主 PONG → 从 link up → 哨兵认主）
bash deploy/redis-ha/status.sh           # 角色与主库地址
bash deploy/redis-ha/failover-drill.sh   # 端到端切换演练（见 §8.4）
bash deploy/redis-ha/stop.sh             # 停止
```

### 6.2 应用侧接入

| 环境变量 | 作用 | 缺省 |
|---|---|---|
| `NYTHROS_REDIS_SENTINELS` | 哨兵端点列表（逗号分隔 `host:port`）；**不设置 = 直连** | 空（直连） |
| `NYTHROS_REDIS_MASTER` | 哨兵监控组名 | `nythros`（配置哨兵时） |
| `NYTHROS_REDIS_SENTINEL_PASSWORD` | 哨兵自身认证密码（哨兵开 requirepass 时） | 空 |
| `NYTHROS_REDIS_AWAIT_REPLICAS` | `1` = 经济域权威写 `WAIT 1` 等副本确认（耐久屏障） | 关闭 |

```bash
export NYTHROS_REDIS_SENTINELS=10.0.0.11:26379,10.0.0.12:26379,10.0.0.13:26379
export NYTHROS_REDIS_MASTER=nythros
export NYTHROS_REDIS_AWAIT_REPLICAS=1        # 有健康从库时开启（无副本环境会拖慢每次写）
```

**自愈语义**：worker 每 5s 向哨兵核对主库地址，切换完成后把本进程全部 Redis 连接**原地重指向**新主；
Redis 重启/闪断导致的失活连接同周期自动重连（无需重启 worker）。切换窗口内请求走既有 500 兜底。
**丢失窗口**：开屏障的写收敛为「主库已提交、副本未确认」的毫秒级；未开屏障的键族（队伍/帮派/好友/
任务/排行/位置快照/票据）按快照语义允许丢最后一次写。

## 7. 生产 checklist

- [ ] deploy.yaml：redis/mysql host 指向生产地址；端口无冲突（DeployConfig 启动即校验）
- [ ] Redis：开启认证（`NYTHROS_REDIS_PASSWORD`，见 ADR-028）+ 网络隔离（token/转移票据/位置快照都在里面）；MySQL 最小权限账号
- [ ] Redis 哨兵（可选，ADR-031）：3 哨兵 / quorum 2 / 从库 `masterauth` / `min-replicas-to-write 1`；
      `NYTHROS_REDIS_SENTINELS` + `NYTHROS_REDIS_MASTER` 已注入每个服务实例；主从数据端口已放通
- [ ] 经济域耐久：HA 部署设 `NYTHROS_REDIS_AWAIT_REPLICAS=1`，且确认从库健康（无副本时该开关会拖慢每次写）
- [ ] 主从切换演练执行过并记录耗时（§8.4；`deploy/redis-ha/failover-drill.sh` 为本地等价演练）
- [ ] TLS 前置终结（反向代理/LB），明文凭据只到 gateway（见 [security.md](security.md) §1）
- [ ] 账号体系：`NYTHROS_ACCOUNTS_FILE` 替代明文 env（哈希表形态，见 [security.md](security.md) §5）；
      防爆破阈值按预期账号规模调校（`NYTHROS_AUTH_MAX_ATTEMPTS`/`NYTHROS_AUTH_LOCKOUT_SECONDS`）
- [ ] 协议版本守卫：设置 `NYTHROS_MIN_CLIENT_VERSION`（ADR-027），老客户端在握手层被拒
- [ ] 演示账号已下线，`StaticGmAuthorizer` 已替换为生产权限体系
- [ ] metrics-exporter 部署并接入 Prometheus（§4，同样注入 `NYTHROS_REDIS_PASSWORD`），关键告警已配置
- [ ] 滚动更新流程演练过一次（§5），`map-rolling.php mark-stopping/watch` 可用
- [ ] 备份/恢复演练过一次（§8）
- [ ] 容量压测在目标硬件复测过（[performance.md](performance.md) §6.4 复测清单）
- [ ] 归档链路（export 模式，缺省）：`type: storage` 已声明、exporter 存活（启动日志 `[run-exporter] started`；
      离线自检 `php packages/demo/bin/run-exporter.php --self-test`）、`XLEN nythros:export:players` 有界（无持续增长）；
      mysql 回退模式：worker 直写归档生效（`MySqlStorage` + 幂等 `createSchema` + 30s 兜底 + 合并窗 flush）；
      两种模式都在 staging 验证建表与恢复（§8）

## 8. 备份与恢复演练

上线前**至少完整演练一次**，把「能恢复」变成记录在案的事实而不是假设。

### 8.1 备份对象与策略

| 对象 | 内容 | 策略建议 |
|---|---|---|
| MySQL `nythros_archive` 表 | 玩家归档（背包/任务等快照，export 模式由 storage-exporter 写入、mysql 模式由 worker 直写） | 每日全量 dump + binlog 增量；保留 ≥7 天 |
| Redis | token/转移票据（短 TTL，可不备份）、队伍/帮派/好友/任务/邮件/拍卖/排行/**背包 `nythros:bag:*`（export 模式权威）**、导出 Stream（积压上限=保险丝值） | 开 AOF（everysec）+ 每日 RDB；队伍/帮派等业务键与 token 分库（`NYTHROS_REDIS_DB`）便于差异化管理 |
| 配置 | deploy.yaml + 玩法三表 + 账号文件 | 随代码版本管理；账号文件**永不入库**（明文纪律，见 security.md §5） |

### 8.2 恢复演练步骤（staging 执行并记录）

1. **MySQL 恢复**：空库 → dump 导入 → `MySqlStorage::createSchema` 幂等校验 → 启动 map worker →
   抽样 `ArchivePipeline::load(uid)` 核对若干已知玩家归档；
2. **Redis 恢复**：AOF 重放 → 核对队伍/帮派/好友快照与 TTL 语义（token/票据允许全失，短 TTL 本来
   就是设计假设——**在线玩家全掉重登**，这是已记录的行为而非事故）；
3. **票据丢失专项**：Redis 清空后让一个持有转移票据的客户端重连——预期走默认入场点 + 恢复读兜底
   （export 模式读 `nythros:bag:*` 背包权威、无键即全新；mysql 回退模式走 `NYTHROS_ARCHIVE_RESTORE=1` 归档读），
   记录实际表现；
4. **演练产物**：把以上步骤的实际命令、耗时、偏差写进当次发布记录（blueprint/ 附录或内部 runbook）。

### 8.3 已知边界

- 丢失窗口契约（export 模式）：游戏 worker 崩溃时，最后 ≤30s 未冲刷的脏快照随进程内存消失——但在线态可从
  Redis 即时恢复（背包权威已在 `nythros:bag:*`），真正丢的是未冲刷窗口的增量；这是吞吐与持久性的既有取舍
  （裁决 4），运维用「宕机即公告 + 补偿邮件」承接，不要试图用加锁消除；
- exporter 失联 = 报表老化不回档：发布侧 MAXLEN 保险丝 + 消费侧 XTRIM 双治理；Redis 崩溃时未落 MySQL 的
  增量回退到最近归档——监控必须对 exporter 存活、`XLEN` 积压与 `[run-exporter]` 日志告警，必要时重启 exporter
  续消费（PEL 未 ack 条目自动重放，at-least-once）；
- MySQL 长时间不可用时 exporter 存活但 upsert 持续失败（条目滞留 PEL，恢复后重放）——不影响游戏侧帧延迟。

### 8.4 Redis 主从切换演练（哨兵，ADR-031）

上线前**至少完整演练一次**（与 §8.2 的恢复演练并列），把「切换后应用自愈」变成记录在案的事实：

```bash
# 本地等价演练（deploy/redis-ha/ 同构栈：1 主 1 从 3 哨兵）
bash deploy/redis-ha/start.sh
bash deploy/redis-ha/failover-drill.sh        # 失败退出码非 0；默认演练后自动复位拓扑

# 生产/预发：直接对真实哨兵触发，观察各服务实例日志
redis-cli -p <sentinel-port> sentinel failover <master-name>
```

演练断言链（`failover-drill.sh` 自动执行）：① 写入标记并 `WAIT 1` 获副本确认（耐久屏障语义）→
② 触发真实切换后**同一连接**恢复读写（连接原地重指向）→ ③ 独立连接直连新主复核标记存在。

记录项：切换完成耗时（哨兵日志 `+switch-master` 到应用日志 `[RedisConnector] 主库切换`）、
自愈耗时（应 ≤ 5s 刷新周期 + 切换耗时）、演练期间业务失败面（预期：切换窗口内请求 500、新会话
不可进入，无进程重启、无数据异常）。WSL 环境注意：哨兵会因子系统时钟跳变持续进入 tilt 模式，
切换可能延迟至 30s（应用自愈仍成立）——该现象仅限 WSL，生产物理机/云主机无此问题。

## 9. 发布与仓库形态

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

四条纪律：

1. **镜像仓只读**：直接向 Nythros/engine|framework|skeleton 的提交会在下一个 tag 被强推覆盖。所有改动
   （含 skeleton 文档）都发生在 monorepo `packages/` 下。
2. **skeleton 只在稳定 tag 同步**：engine/framework 的日常 dev 迭代不流入 skeleton；每次发布同时刷新
   skeleton 的 Packagist 冒烟（其仓库 CI：create-project 组合 → launch → client 断言）。
3. **Secret 与开关前置**（仓库 Settings → Secrets and variables → Actions）：`SUBSPLIT_TOKEN`（对三个镜像仓有
   Contents: write 的 PAT）、可选 `PACKAGIST_USERNAME`/`PACKAGIST_TOKEN`（拆分仓未配 Packagist webhook 时
   的双保险）、可选 `NPM_TOKEN`。**启用开关走 repository variables**（与 secret 分开配置）：
   `SUBSPLIT_ENABLED` / `PACKAGIST_ENABLED` / `NPM_PUBLISH_ENABLED`，置为字符串 `true` 才启用对应 job
   （未设即整个 job 跳过，不阻塞 GitHub Release）。开关用 variable 而非 secret 判断是 GitHub 的硬约束：
   `secrets` 上下文在 job 级 `if` 中不被允许（可用仅 `github`/`needs`/`vars`/`inputs`），而 secrets 注入
   job 级 `env` 后未定义项不存在（非空串），`env.X != ''` 判断恒真会误启用 job。

4. **手工补发（token/开关未配时的等效通道）**：subsplit 未启用（缺 `SUBSPLIT_ENABLED` 变量或
   `SUBSPLIT_TOKEN` secret）时，GitHub Release 仍正常产出，但三个镜像仓不会更新。此时可用本地 SSH
   凭证手工执行与 workflow 等价的三条命令（以 v0.2.0 / engine 为例）：

   ```bash
   git subtree split -P packages/engine -b subsplit-v0.2.0-engine v0.2.0
   git push git@github.com:Nythros/engine.git refs/heads/subsplit-v0.2.0-engine:refs/heads/main
   git tag -a mirror-engine-v0.2.0 -m "Nythros v0.2.0" subsplit-v0.2.0-engine
   git push git@github.com:Nythros/engine.git refs/tags/mirror-engine-v0.2.0:refs/tags/v0.2.0
   ```

   framework/skeleton 同理（skeleton 无额外依赖对齐步骤：monorepo 里的约束本就是 `^0.2`）。
   推送后 Packagist 经 webhook 自动抓取（已注册的包通常数秒内可见新版本）；skeleton 镜像仓自带 CI
   会做 create-project 组合冒烟复核。**注意镜像仓 `main` 必须与 tag 同步推进**——只推 tag 会让
   `dev-main` 与发行版内容脱节。

### 9.1 全自动发布配置（一次性，照抄清单）

目标：打 tag 后四段全自动跑完，人工零干预。以下每项都写明**在哪个库、哪个页面、填什么**。
唯一必须配置的是两组（第 1、2 步）；Packagist 当前已自动（第 3 步仅当失效时启用）；npm 暂缓
（第 4 步，前置就绪后再开）。

> 动手前先确认：**三组开关/secret 都配在 monorepo 仓库 `Nythros/Nythros` 上**，不是镜像仓。
> 镜像仓只读，不配任何东西。

#### 第 1 步：创建 PAT（推三个镜像仓的凭证）

| 项 | 内容 |
|---|---|
| 在哪里创建 | GitHub 个人账号：右上头像 → **Settings** → **Developer settings**（最左栏底部）→ **Personal access tokens** → **Fine-grained tokens** → **Generate new token** |
| Token name | `nythros-release-subsplit`（名字随意，仅自己可见） |
| Expiration | 建议 90 天或自定义；到期前重新生成并在 secret 里更新同名字段即可，不影响流程 |
| Resource owner | **Nythros**（组织；下拉里选组织而非个人） |
| Repository access | 选 **Only select repositories** → 勾选 `Nythros/engine`、`Nythros/framework`、`Nythros/skeleton` |
| Permissions | 展开 **Repository permissions** → 只改一项：**Contents = Read and write**；**Workflows = Read and write**（镜像仓根有 `.github/workflows/`，缺它会推不上去）；其余保持 No access |
| 产出 | 点 **Generate token**，复制 `github_pat_...`（只显示一次，离开页面不可再查） |

备选（不想用 fine-grained 时）：**Tokens (classic)** → Generate new token (classic) → 勾 `repo` 全量
scope 即可（权限更宽，够用但不如 fine-grained 最小化）。

#### 第 2 步：在 monorepo 存 secret + 开开关

两个都在同一个页面：`Nythros/Nythros` → **Settings** → 左栏 **Secrets and variables** → **Actions**。

| 页签 | 操作 | Name | Value |
|---|---|---|---|
| **Secrets** | **New repository secret** | `SUBSPLIT_TOKEN` | 粘贴第 1 步的 PAT |
| **Variables** | **New repository variable** | `SUBSPLIT_ENABLED` | `true`（小写字符串，不要引号） |

配完这两项，下一次打 tag 就会自动完成：GitHub Release → 三个镜像仓 main + tag 强推 → Packagist
自动抓取（webhook 已在镜像仓侧配好，实测秒级到分钟级生效）。

**验证（不改任何代码）**：等下次正常发版，或临时验证时执行

```bash
git tag v0.2.1-verify && git push github v0.2.1-verify
```

然后看 `https://github.com/Nythros/Nythros/actions/workflows/release.yml`：`Release` job 绿 →
`Subsplit packages` 三个矩阵 job（engine/framework/skeleton）全绿，即为成功。验证完记得清理：
monorepo 与三个镜像仓删掉该 tag（镜像仓 `git push --delete <url> v0.2.1-verify`），本地
`git tag -d v0.2.1-verify`。

#### 第 3 步：Packagist（通常无需配置）

三个镜像仓的 Packagist webhook **已经工作**（v0.2.0 实测：推送后数分钟内三包均自动出现 v0.2.0），
因此默认**不需要** `PACKAGIST_ENABLED`。只有出现「镜像仓已更新但 Packagist 长时间不刷新」时才启用
双保险（同在 `Nythros/Nythros` → Settings → Secrets and variables → Actions）：

| 页签 | Name | Value |
|---|---|---|
| Secrets | `PACKAGIST_USERNAME` | packagist.org 的用户名 |
| Secrets | `PACKAGIST_TOKEN` | packagist.org → Profile → **API Token** 页显示的 token |
| Variables | `PACKAGIST_ENABLED` | `true` |

#### 第 4 步：npm 发布 @nythros/client（可选，前置未就绪时保持关闭）

当前 `packages/client-js/package.json` 已是 `0.1.0` 正式版号（不再是 `dev-main`），npm 侧只差组织与
token。启用前先满足两个前置，再配置三处：

1. **前置 A**：在 [npmjs.com](https://www.npmjs.com) 注册 `@nythros` 组织（或改用你已有的 scope，
   同步改 `packages/client-js/package.json` 的 `name`）。
2. **前置 B**：npmjs.com → 头像 → **Access Tokens** → **Generate New Token** → 选 **Automation**
   （Automation 类型专为 CI，绕过 2FA 交互），复制 `npm_...`。
3. **配置**（`Nythros/Nythros` → Settings → Secrets and variables → Actions）：

| 页签 | Name | Value |
|---|---|---|
| Secrets | `NPM_TOKEN` | 前置 B 的 Automation token |
| Variables | `NPM_PUBLISH_ENABLED` | `true` |

**版本纪律**：workflow 发布的是 `package.json` 里的 `version`，不会随 tag 自动改。每次发版前需手动
把 `packages/client-js/package.json` 升到与 tag 对应的版本（npm 不允许重复版本号，重复发布直接失败）。

#### 附：故障对照表

| 症状 | 原因 | 处理 |
|---|---|---|
| 镜像仓没更新，Actions 里没有 `Subsplit` job | `SUBSPLIT_ENABLED` 未设或不是小写 `true` | 检查 Variables 页 |
| `Subsplit` job 红，报 `SUBSPLIT_ENABLED=true 但 SUBSPLIT_TOKEN secret 未配置` | secret 漏配 | 补第 2 步 |
| `Subsplit` job 红，push 被拒（403 / `Permission denied`） | PAT 权限缺 Contents: write、或没勾全三个仓、或已过期 | 重做第 1 步并更新 secret |
| 镜像仓 main 更新了但 tag 没有 | 推送顺序中断（极少） | 在该 run 页点 **Re-run failed jobs** |
| Packagist 长时间不刷新 | 镜像仓 webhook 失效 | 启用第 3 步，或去 Packagist 该包页点 **Update** |
| npm 报 `E403` / `EPUBLISHCONFLICT` | token 类型不对（需 Automation）、或版本号未升 | 检查第 4 步前置 B 与版本纪律 |

> 历史注记：ADR-019 当时按「两包（engine/framework）」编写，skeleton 纳入发布矩阵为后续演进（见 CHANGELOG 与
> blueprint/21）。blueprint 是决策记录，不回改。
>
> v0.2.0 注记：本次发版即经「手工补发」通道完成（当时 `SUBSPLIT_ENABLED`/`SUBSPLIT_TOKEN` 尚未配置）。
> 三个镜像仓 main + v0.2.0 tag 已就位，Packagist 三包均显示 v0.2.0，`composer require nythros/framework:^0.2`
> 与 `composer create-project nythros/skeleton` 实测通过。后续发版走第 9.1 节配置的全自动通道。

