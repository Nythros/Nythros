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

**第二期（触发条件后置）：哨兵/集群支持**

- 触发条件：单实例内存/连接数到达标定上限，或可用性要求提升到"Redis 宕机分钟级自动切换"。
- 路线：phpredis Sentinel 支持（`Redis::sentinel()` 哨兵发现 + 主从切换重连），
  连接工厂闭包是唯一改造点（各 store 消费工厂，接口不变）；Lua 原子脚本（token 墓碑/转移票据/
  拍卖扣款）在主从切换下的语义需专项回归。
- 明确不做：客户端分片集群（hash tag 已在 blueprint/12 遗留项记录）。

## 理由

- 票据/token 都是短 TTL 快照，Redis 短暂不可用的实际爆炸半径是"新会话不可进入"而非数据丢失；
  把 HA 的复杂度压到有真实 SLA 需求时再引入，符合"先单机后集群"的演进铁律（ADR-005/009）。
- 认证先行是因为它是零成本的安全基线（compose 开发栈除外）。

## 影响 / 后果

- 生产部署 checklist 新增 Redis 认证项（docs/deployment.md §6）；
- 备份与恢复演练（docs/deployment.md §7）覆盖 Redis 持久化选择；
- 第二期动工时以本 ADR 为基线立实现 ADR。

## 关联

- token 存储：[ADR-012](ADR-012-RedisTokenStore提前落地.md)
- 备份演练：[docs/deployment.md](../../docs/deployment.md) §7
