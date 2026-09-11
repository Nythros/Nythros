# ADR-031：哨兵 HA 与连接自愈（RedisConnector + 耐久屏障）

> 状态：已接受（兑现 ADR-028 第二期；触发条件：可用性要求提升到「Redis 宕机分钟级自动切换」）

## 背景

ADR-028 把 Redis HA 分期：第一期交付单实例 + 认证 + 故障自愈语义，第二期（哨兵/集群）留触发条件，
并预判「连接工厂闭包是唯一改造点」。本轮在 WSL 上搭真哨兵环境（1 主 1 从 3 哨兵）逐项实测后，发现
该预判需要两处修正，且暴露出一个**存量缺陷**：

| 实测项 | 结果 | 含义 |
|---|---|---|
| phpredis 对非 persistent 连接断线后的行为 | **不自动重连**；旧对象永久抛 `Redis server ... went away` | 既有「Redis 恢复后无需重启即自愈」只在「建连期失败」成立；**已建立连接在 Redis 重启后 worker 永不恢复**（存量缺陷） |
| 对已建立的 `\Redis` 对象再次 `connect(新地址)` | 成功，对象原地指向新主，读写正常 | 连接可在不重建对象的前提下重指向 → store 的永久缓存不再是障碍 |
| `RedisSentinel::getMasterAddrByName()` 返回形态 | 数字索引数组 `["ip","port"]`（phpredis 6.3） | 解析代码按索引取值 |
| `RedisSentinel` 构造参数 | 只认 `host/port/persistent/auth/database`；`timeout/read_timeout/retry_interval` 报 unknown | 哨兵查询超时只能经 `default_socket_timeout` 生效（实测 1s 干净失败；不设则挂到 60s） |
| 切换窗口 | 哨兵宣布新主后，旧主仍自称 master 约 10s（含 tilt 可更久）；此窗口内客户端写旧主「成功」 | 静默数据丢失路径 → 需主库侧 `min-replicas-to-write` 护栏 |
| WSL2 时钟 | 墙钟每 ~34s 向前跳 ~1.85s（实测）；哨兵因此持续进入 tilt 模式，切换可延迟 30s | 一切间隔测量（节流/超时/演练计时）必须用单调钟；演练需容忍慢切换 |
| DrvFs（`/mnt/*`）上的磁盘型复制 | 从库同步失败 `Failed trying to load the MASTER synchronization DB from disk` | HA 栈运行目录必须落原生 Linux 存储 |

## 决策

1. **客户端侧唯一新增组件 `RedisConnector`**（`Nythros\Framework\Cluster`）：维持 `\Redis|\Closure(): \Redis`
   契约不变，各 store 零改动。职责三件：
   - **解析**：哨兵模式（`NYTHROS_REDIS_SENTINELS` + `NYTHROS_REDIS_MASTER`）下向哨兵问主库地址；
     未配置 = 直连，行为与接入前逐字节一致（开发零影响）；
   - **追踪 + 重指向**：追踪本对象创建的每条连接，`refresh()` 检测到主变后对全部连接**原地重指向**
     （探测新主成功才切，失败保留现有连接等下轮）；
   - **失活重连**：地址未变时对 `isConnected() === false` 的连接原地重连——**修复上述存量缺陷**。
2. **刷新节奏 5s**（worker 进程内定时器，`WorkermanTimer`），自愈上界 = 切换完成 + 5s。哨兵全挂时
   保留现有连接（数据面不因哨兵故障中断）；切换窗口内的请求仍走既有 500 兜底，worker 不退出。
   节流与限流一律用**单调钟**（`hrtime`），不受墙钟跳变影响。
3. **耐久屏障 `ReplicaBarrier`**：经济域权威写（`CurrencyLedger` / `RedisInventoryStore` /
   `RedisMailStore` / `AuctionStore`）在返回前 `WAIT 1 <100ms>` 等至少一个副本确认，由
   `NYTHROS_REDIS_AWAIT_REPLICAS=1` 显式开启（缺省关闭——单实例环境启用会让每次写阻塞满 timeout）。
   屏障失败**不改变写入结果**（写已提交主库），仅 5s 限流日志。边界：它是窗口收窄，不是事务——
   不保证零丢失，也不保证该副本必被提升。
4. **主库侧护栏**：`min-replicas-to-write 1` + `min-replicas-max-lag 10`——旧主被降级/失联期间
   **拒写**（`NOREPLICAS`），把「静默写进将被清空重同步的节点」换成「短暂报错」，符合「宁 500 不脏写」。
5. **入口接入（6 个生产路径）**：`run-worker.php`（含 map/社交三角色，挂刷新定时器）、`bin/start-maps.php`
   （每频道 worker 挂定时器）、`map-rolling.php`（watch 轮询内刷新）、`perf-stats.php`、`metrics-exporter.php`
   （每次抓取前节流刷新）、`run-exporter.php`（消费轮内节流刷新）。verify/benchmark 等开发工具保持直连。
6. **开发/演练环境 `deploy/redis-ha/`**：脚本化 1 主 1 从 3 哨兵（与生产同构：quorum 2 / down-after 5s /
   3 哨兵），端口与开发单实例（6379）错开可共存；运行目录默认 `${TMPDIR}/nythros-redis-ha`（原生盘）。
   `failover-drill.sh` 端到端断言：写标记 + `WAIT 1` 副本确认 → 触发真实 failover → **同一连接**恢复读写
   → 独立连接直连新主复核 → 自动复位拓扑。
7. **明确不做**：Redis Cluster（ADR-028 既有裁决：客户端换 `RedisCluster` + 多键脚本 hash tag，收益为零）；
   直连模式下的自动拓扑发现（静态哨兵列表足够）；哨兵侧任何改动（tilt 是时钟保护，不绕过）。

## 理由

- **契约不变是硬约束**：14 个 store 的 `redis()` 访问器把工厂产物永久缓存，改造点若下沉到 store 就是
  14 处签名/缓存语义变更；连接器「追踪 + 原地重指向」把改动收敛到 1 个新类 + 6 个入口各 2-3 行。
- **实测优先于假设**：phpredis 不自动重连、`connect()` 可重指向、`getMasterAddrByName` 的数字索引、
  无超时开关——四条都是先验证再定架构的直接依据；反例（凭直觉写「工厂是唯一改造点」）会导致切换后
  worker 永久失联。
- **耐久边界按资产价值分级**：token/位置快照/转移票据是短 TTL 快照（ADR-028 已记录可失），
  金钱/背包/邮件/拍卖是托管资产；只给后者加屏障，避免给热路径加无差别成本。
- **演练是交付物**：没演练过的 HA 等于没有 HA（照 blueprint/33 长跑与故障演练的门禁文化）；
  演练脚本同时是「切换耗时/tilt 影响」的观测器。

## 影响 / 后果

- **SLA 口径**：Redis 主库故障 → 哨兵 `down-after` 5s + 选举/降级（实测 0.8-19s，视 tilt）→ worker 在
  下一个 5s 刷新周期内重指向 → 全程无重启。切换窗口内请求 500（既有语义），新会话不可进入（同前）。
- **数据丢失窗口**：开启屏障的写收敛为「主库已提交但副本未确认」的毫秒级窗口；未开启屏障的键族
  （队伍/帮派/好友/任务/排行/位置快照/转移票据）仍按 ADR-028 的快照语义允许丢最后一次写。
- **运维增项**（已并入 docs/deployment.md §6/§8）：数据端口（6379/16379）必须放通（哨兵只给地址）、
  从库必须配 `masterauth`、哨兵配置文件必须可写、每实例备份/哨兵冗余。
- **开发零影响**：不设 `NYTHROS_REDIS_SENTINELS` 时，连接路径与接入前一致（直连单实例）；
  新增的读超时（3s）是唯一行为变化——把「网络分区下无限挂起」换成「报错 + 自愈」。
- **测试与门禁**：`RedisConnectorTest`（11 例 45 断言）+ `ReplicaBarrierTest`（4 例 16 断言）随
  phpunit 常跑（Redis 不可用即跳过）；跨进程真实切换由 `deploy/redis-ha/failover-drill.sh` 覆盖。

## 关联

- 前置决策：[ADR-028](ADR-028-Redis单点风险与HA路线.md)（分期与触发条件）、
  [ADR-027](ADR-027-协议版本协商.md)（Redis 认证/库选择口径）、[ADR-012](ADR-012-RedisTokenStore提前落地.md)（token 存储）
- 实现：`packages/framework/src/Cluster/RedisConnector.php`、`ReplicaBarrier.php`、
  `deploy/redis-ha/`（start/stop/status/failover-drill + drill-probe.php）
- 运维：`docs/deployment.md` §6（checklist）/ §7（恢复与切换演练）
