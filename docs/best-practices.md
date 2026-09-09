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
- **每 tick/每消息热路径零同步 IO（纪律，门禁强制）**：战斗结算、移动广播、视野重同步等每帧/每消息必经路径
  不得持有同步 IO 客户端——`composer io-free` 静态扫描热路径文件，出现 `\Redis`/`\PDO`/`Redis::` 客户端引用即
  CI 失败（口径注释见 tools/check-io-free-path.php）。需要「每高频事件更新、但持久化后端有往返成本」的
  状态（任务进度、玩家归档），用**写回缓冲**范式：内存持有权威值 + 标脏 + 每连接/周期点批量回写，热路径读写
  只走内存。现成实现见 `CachedQuestStore`（任务进度：attach 预热 / detach 冲刷 / 30s 兜底）与 `ArchivePipeline`
  （玩家归档：断连/登出走 0.2s 合并窗 `scheduleFlushId` 批量化、30s 定时兜底）。代价是**有界丢失窗口**
  （崩溃回退到上次冲刷），与 §2 持久化裁决 4 同口径，写业务前先确认能接受。
  **例外区（强一致，不可缓冲化）**：货币扣减、竞拍成交、转移票据消费等「操作本身即答案、写错不可回滚」的路径
  保持同步单次往返（localhost Redis 亚毫秒）——它们落在每消息经济/每连接级，不在每 tick 战斗热路径，
  由门禁扫描清单天然排除；不要为了过门禁把它们也塞进缓冲，那是把强一致降级为最终一致。
- **事件订阅先订阅后发布**：`SimpleEventBus` 在帧末统一 flush（见 architecture.md §5.3），帧内发布、帧末才送达；
  不要假设事件处理器同步执行。
- **异常边界**：Actor update 抛异常会中断该帧处理链——在钩子内自行 try/catch 业务性失败，
  让异常只表达「不该发生的内部错误」。

## 2. 状态落点（先想清楚再写）

沿用 architecture.md §2.3 的判定口诀，展开成表：

| 你要存的东西 | 正确落点 | 反例 |
|---|---|---|
| 账号/角色/货币/背包（要保留） | Redis 会话权威（背包 `nythros:bag:*`、货币 `CurrencyLedger`）+ 脏快照 Stream 导出，由 storage-exporter 落 MySQL（`ArchivePipeline` 为 mysql 回退口径） | 游戏 worker 直连 MySQL、存在连接对象或 Map 进程内 |
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
- **GridAOI 格子键勿换整数打包**（实测否决的优化方向，勿再尝试）：`"cx:cy"` 字符串键在 PHP 8.3 无稳定性能
  劣势（WSL 同机 A/B query 持平），而整数打包算术在 `opcache.jit=tracing`（WSL 开发环境缺省）下触发
  tracing JIT miscompile——28 个 GridAOI 回归 9 红、`jit=off` 全绿；常驻进程必然热编译，属 P0 风险。
  GridAOITest/AoiCorrectnessTest 与 performance.md §8 JIT 兼容行是这条红线的现形网。

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

- [ ] `composer cs && composer stan && composer internal && composer io-free && composer test` 全绿
- [ ] 新公开符号 → API 一览已再生成（`composer api`）
- [ ] 动了热路径 → 基准对比过（§4）
- [ ] 新协议帧 → 两端枚举 + TS 码表同步（§5）
- [ ] 新玩法内容 → 三表外置 + feature 标注（§5）
- [ ] 有 E2E 覆盖 → verify-* 脚本或新用例（testing-guide）
