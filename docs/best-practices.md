# 最佳实践（Best Practices）

> 面向读者：在 Nythros 上开发游戏玩法的所有服务端程序。读完你能：守住 Actor 并发纪律、把状态放进正确的落点、
> 避开性能与安全的常见红线。机制讲解见 [actor-guide](actor-guide.md)/[cell-guide](cell-guide.md)/[state-sync](state-sync.md)；
> 本文只讲「纪律与红线」——每条都对应真实踩过或审查发现过的坑（来源：blueprint/ 各阶段审查记录）。

## 1. Actor 并发纪律

Nythros 的并发模型是 **Actor 单线程假设**：同一 Actor 的 `update()` 与消息处理在单进程内顺序执行，
不存在并行访问。由此推出全部纪律：

- **不要跨 Actor 共享可变状态**。Actor 之间通信只经实体/事件/Redis 快照，任何「两个 Actor 都直接改一个
  数组/单例」的写法在多 worker 拓扑下必然出竞态。跨进程共享状态落点见 §2。
- **tick 回调内禁止阻塞**：`update()`（含 `onTick/onPatrol/onChase` 等钩子）内不做同步 Redis/MySQL 调用、
  不做 `sleep`、不做大循环。耗时操作交给 `TaskQueue`（engine Scheduler 模块）或滚动分帧（每帧处理一部分）。
- **事件订阅先订阅后发布**：`SimpleEventBus` 在帧末统一 flush（见 architecture.md §5.3），帧内发布、帧末才送达；
  不要假设事件处理器同步执行。
- **异常边界**：Actor update 抛异常会中断该帧处理链——在钩子内自行 try/catch 业务性失败，
  让异常只表达「不该发生的内部错误」。

## 2. 状态落点（先想清楚再写）

沿用 architecture.md §2.3 的判定口诀，展开成表：

| 你要存的东西 | 正确落点 | 反例 |
|---|---|---|
| 账号/角色/货币/背包（要保留） | DB/Redis 持久存储 + `ArchivePipeline` 归档 | 存在连接对象或 Map 进程内 |
| 组队/token/掉线标记（临时共享） | Redis + TTL | 存成永久键、或不设 TTL（泄漏） |
| 谁在哪个分组/频道（在线会话） | Social 连接表（`bindUid`/`joinGroup`） | 落 Redis（onClose 清不掉，产生幽灵成员） |
| AOI/位置/血量/战斗帧级状态 | Map 进程内（一频道一进程一 World） | 落 Redis（每帧写入压垮存储） |

铁律：**帧级高频状态永不落 Redis**；**永久状态绝不只存进程内**。中间地带（跨 Map 但低频，如转移票据）
用 Redis 原子操作 + TTL（参考转移票据实现：Lua/原子单消费，读后即毁）。

## 3. 服务端权威边界

- **客户端输入只是意图**：位置、伤害、拾取、购买全部服务器判定。MapServer 的 `attack` 前置校验
  （目标有效/存活/非自身/九宫格距离/冷却）是标准姿势——新路由照抄这个形状，缺一项就是作弊面。
- **不要下发任何「只有服务器该知道」的数据**（其他玩家血量全量、掉落表权重、GM 名单）。
- **错误回执显式化**：失败用带 requestId 的错误帧回执（400/422 语义见 protocol.md §5），不要静默丢弃——
  客户端无法区分「被拒绝」和「丢包」。

## 4. 性能红线

实测依据见 [performance.md](performance.md)（60 人热区混战、15 房间 30Hz 实测数据）。

- **每帧预算 50ms（20Hz）**，实测 P99 帧耗 1.23ms——余量很大，但单帧新增 O(全实体) 的逻辑要警惕：
  广播走 AOI 视野差分，不要自建全量遍历广播。
- **出站必经 Outbox/FrameMerger 批量**：帧末一次 flush，逐帧逐连接直接 send 是禁手（批量包布局见 protocol.md）。
- **STATE 帧可丢可合并，EVENT/STRUCTURE 帧不丢**（state-sync.md §2）：给广播分级时，移动类标 low、
  combat:hit/entity_dead 标 critical；新帧类型先问自己「丢了会怎样」再定级。
- **登录通道限速是保护性的**：`SimpleTokenBucket(refillPerSecond: 10, capacity: 20)`（`run-worker.php`
  缺省装配）在批量开服场景会排队，不是 bug——调大前先读 performance.md §6.3。
- **基准回归**：改了 World/AOI/协议热路径，跑 `php benchmarks/engine-bench.php --json` 对比基线
  （门禁阈值 CI 50%、本地 20%，见 testing-guide §4）。

## 5. 配置与数值

- **数值一律外置三表**（gameplay/skills/drops，`NYTHROS_CONFIG_DIR` 启用），禁止硬编码进类。
  坏表启动即拒绝、热载改坏自动回滚（quick-start §3.3）——直接信任这套校验，不要绕过 schema 自行读文件。
- **feature 行**按 `NYTHROS_*` 开关装配，新玩法内容记得标注 feature，否则会在所有模式装配。
- **新增帧/字段必须同步两端枚举**（FrameType/PayloadKey 与 client-js 码表，`generate-definitions.php`
  再生成 TS）；编码一经发布不得复用、不得改义（protocol.md 开头铁律）。

## 6. 分层与依赖

- **业务代码只依赖 Contracts 接口**（`Nythros\Contracts\*`），绝不 import 引擎 `@internal` 实现
  ——CI 的 `composer internal` 门禁双向拦截（engine 标注 + framework use 扫描）。
- **Demo 是组装层**：可复用的机制一律下沉 framework（判别标准见 blueprint/adr 与
  [blueprint/32-架构分层审计报告.md](https://github.com/nythros/nythros/tree/master/blueprint/32-架构分层审计报告.md) 的「机制 vs 玩法」三问），
  Demo 里只留装配与示例。判断标准：「换一个游戏还需要它吗？」需要 → framework。
- **公开 API 面以 [api-reference](api-reference.md) 为准**：新增公开符号后运行
  `php tools/generate-api-docs.php` 再生成（CI 校验一致性）。

## 7. 安全基线（速查）

完整版见 [security.md](security.md)。速记四条：token 永不回传给第三方连接、scope 按需最小签发、
GM 命令必须过 `GmPermissionInterface`、限流器挂在认证入口。

## 8. 提交前 checklist

- [ ] `composer cs && composer stan && composer internal && composer test` 全绿
- [ ] 新公开符号 → API 一览已再生成（`composer api`）
- [ ] 动了热路径 → 基准对比过（§4）
- [ ] 新协议帧 → 两端枚举 + TS 码表同步（§5）
- [ ] 新玩法内容 → 三表外置 + feature 标注（§5）
- [ ] 有 E2E 覆盖 → verify-* 脚本或新用例（testing-guide）
