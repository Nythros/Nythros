# ADR-028：Redis 单点风险与 HA 路线

> 状态：已接受（HA 能力分期，本 ADR 记录决策与边界）

## 背景

Redis 是全服唯一跨进程事实源：多 scope token、服务注册与发现、组队/位置/帮派/好友快照、
转移票据、PerfSampler 指标、任务/邮件/拍卖 store。Redis 不可用 = 登录链路全断、在线玩家
无法换线/跨服共享状态（Map 进程内战斗帧不受影响，但新会话无法进入）。

## 决策（分期）

**第一期（已交付）：单实例 + 认证 + 故障自愈语义**

- 认证与库选择：`NYTHROS_REDIS_PASSWORD` / `NYTHROS_REDIS_DB` 环境变量注入
  （run-worker 连接工厂与 metrics-exporter 同口径）；compose 缺省无密码栈仅限开发。
- 故障语义分层（既有设计，本 ADR 显式记录）：建连/认证失败 → 请求级 500 兜底，**worker 不退出**
  （exit(1) 会引发 master 重启风暴）；Redis 恢复后无需重启即自愈。
- 网络隔离：Redis 只在内网可达（部署清单见 docs/deployment.md §6）。

**第二期（已交付，见 [ADR-031](ADR-031-哨兵HA与连接自愈.md)）：哨兵 HA 与连接自愈**

- 交付：`RedisConnector`（哨兵解析 + 连接追踪 + 主变原地重指向 + 失活重连）、`ReplicaBarrier`
  （经济域权威写 `WAIT 1`）、6 个入口接入 + worker 5s 刷新定时器、开发/演练栈 `deploy/redis-ha/`
  与端到端切换演练脚本（`failover-drill.sh`）。
- 实测修正（原「连接工厂闭包是唯一改造点」需补两条，详见 ADR-031 背景表）：
  ① phpredis **不会**自动重连已断开的连接——存量缺陷「Redis 重启后已建立连接的 worker 永久失联」；
  ② 故除工厂外还需「连接追踪 + refresh() 重指向/重连」与 worker 侧定时器两处配套。
- Lua 原子脚本在主从切换下的语义：短 TTL 键族（token 墓碑/位置快照/转移票据）允许失最后写（本 ADR 已记录）；
  托管资产（货币/背包/邮件/拍卖）以 `ReplicaBarrier` 的 `WAIT 1` 收窄窗口，未及确认的写仍在丢失范围内。
- 明确不做：客户端分片集群（hash tag 已在 blueprint/12 遗留项记录）。

## 理由

- 票据/token 都是短 TTL 快照，Redis 短暂不可用的实际爆炸半径是"新会话不可进入"而非数据丢失；
  把 HA 的复杂度压到有真实 SLA 需求时再引入，符合"先单机后集群"的演进铁律（ADR-005/009）。
- 认证先行是因为它是零成本的安全基线（compose 开发栈除外）。

## 影响 / 后果

- 生产部署 checklist 新增 Redis 认证项（docs/deployment.md §6）；
- 备份与恢复演练（docs/deployment.md §8）覆盖 Redis 持久化选择；
- 第二期动工时以本 ADR 为基线立实现 ADR。

## 关联

- token 存储：[ADR-012](ADR-012-RedisTokenStore提前落地.md)
- 备份演练：[docs/deployment.md](../../docs/deployment.md) §7
