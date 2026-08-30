# 状态同步指南：帧语义、插值与快照重同步

> 面向读者：写 Nythros 客户端（或调试状态表现）的开发者。读完你能：区分两类协议帧的可靠性语义、
> 为实体选对插值策略（事件驱动 vs tick 驱动）、正确消费 `world:tick_rate` 分频帧、理解视野快照
> 重同步的自愈机制。参考实现：官方 JS SDK `packages/client-js/nythros-client.js`（NythrosInterpolator）。
> 线上格式权威文档：[docs/protocol.md](protocol.md)；帧/字段码表：`FrameType.php` / `PayloadKey.php`。

## 1. 服务器权威模型（先立总纲）

**服务器是唯一位置权威**：实体坐标只在服务器上演进，客户端收到的是「权威位置快照 + 结构变化通知」，
客户端渲染层做的是**表现平滑**，不是权威推演——没有客户端预测/回滚（demo 阶段的明确边界，
blueprint/21 §1.2）。由此推出两条铁律：

- **判定逻辑读权威位置，渲染读插值位置**：攻击距离/拾取判定等游戏逻辑用最新权威位置
  （SDK `interpolator.position(id)`）；渲染帧采样平滑位置（SDK `interpolator.sample(id, now)`）。
- **绝对坐标**：所有位置帧都是绝对 `{x, y}`（int16 网格坐标），不是增量——丢一帧不累积误差，
  下一帧自动纠偏，插值实现因此可以无状态地向前看。

## 2. 两类帧的语义

| 帧类 | 例子 | 广播语义 | 可靠性约定 |
|---|---|---|---|
| STATE 状态帧 | `entity_moved` / `player:stats` | **跳过自身**——单人客户端收不到自己的位移帧 | 可丢可合并：帧分级下 low 档（远处/无仇恨实体的移动刷新）优先丢/合并，只保最新位置 |
| EVENT 事件帧 | `combat:hit` / `entity_dead` / `monster:spawned` / `quest:result` | **含自身**（以中心实体为通告对象，query 含 self） | 关键帧不丢不迟：帧分级 priority.critical（combat:hit / entity_dead / player:revive / quest:*）任何档位不降 |
| STRUCTURE 结构帧 | `entity_enter` / `entity_leave` | 进入/离开视野差分 | 永不因分频丢弃（帧分级红线），是实体表增删的唯一事实源 |

客户端推论：

- **不要假设能收到自己的 `entity_moved`**（STATE 帧跳过自身）——自己的位移以输入回执/服务器权威为准。
- **实体表只增删于 `entity_enter` / `entity_leave`**：`entity_moved` 对未知 id 静默忽略（SDK 同语义），
  避免差分乱序时把已离开实体复活。
- **`monster:spawned` 是怪物的出生通告**（EVENT 帧，含自身）：连接建立前已出生的怪物不会重播，
  进入视野时以 `entity_enter` 到达——实体表两条路径都要登记。

## 3. 插值：事件驱动与 tick 驱动分开（区域密度语义）

服务器 base tick 50ms（AOI 世界 20Hz）。区域密度降频后，**不同实体的移动广播周期不再一致**：
热区外怪物按密度档位降到 10/5Hz（周期 100/200ms），热区内玩家移动广播另有节流。单一固定插值窗口
必然顾此失彼（窗口小 → 降频实体顿挫；窗口大 → 全画面延迟）。因此按实体的驱动方式分两类：

### 3.1 事件驱动实体（玩家输入）

玩家 `move` 是输入事件驱动：`entity_moved` 到达节奏取决于玩家操作，不随 tick 周期。

- 插值窗口：**小而固定**（SDK 缺省 `eventWindowMs = 100ms`）——玩家位移是相邻格单步，窗口只需
  盖住一帧网络抖动；
- 目的：把「瞬移一格」平滑成 ~100ms 的过渡，不追求预测。

### 3.2 tick 驱动实体（怪物 AI）

怪物移动由服务器 AI tick 驱动：`entity_moved` 到达间隔 = 服务器广播周期（base tick × 分频）。

- 插值窗口：**到达间隔的 EMA 实测值 × gamma（>1 留缓冲）**（SDK：`intervalEma × 1.5`）——
  怪物被区域降频时到达间隔自动变长，窗口自适应放大，不顿挫；怪物回暖时窗口自动收窄；
- 为什么不直接用 `world:tick_rate`：该帧是 **directed 帧**（只发给分频变化的实体自己的连接，
  负载 `{divisor}`），客户端只知道自己的分频，不知道别人的——怪物窗口只能靠到达间隔实测。

### 3.3 `world:tick_rate`：自身分频的消费

速率帧：实体所在格子的密度档位变化时，服务器定向推送 `world:tick_rate {divisor}` 给**该实体**
（即客户端自己的实体）。语义：该实体的更新周期 = divisor × base tick（50ms）。

- 收到后把**自身实体**的插值窗口放大到 `divisor × baseTickMs`（混战极端降档 5Hz 时即 200ms 粒度）；
- 自己的位移自己权威（STATE 帧跳过自身），这个窗口主要用于把「服务器确认前的本地表现」与降频
  节奏对齐，以及给插值系统一个明确的档位信号。

### 3.4 参考实现要点（NythrosInterpolator）

`packages/client-js/nythros-client.js` 的状态机（逐条对应实现）：

1. 实体表登记：`monster:spawned` → kind='tick'；`entity_enter` → 新实体 kind='event'，已登记实体 =
   周期快照纠偏（权威位置吸附）；`entity_leave` → 删除；
2. `entity_moved`：先 `sample(now)` 把当前平滑位置固化为 prev（**过渡不跳变**），再更新目标位置
   与时间戳；tick 实体同时更新到达间隔 EMA（`ema×0.7 + dt×0.3`，异常间隔 >5s 丢弃）；
3. `sample(id, now)`：`t = clamp((now - at) / window, 0, 1)` 线性插值，越界收敛到权威位置；
4. 渲染循环每帧调 `sample`；逻辑判定调 `position`。

> **为什么 prev 取平滑值而不是上一目标**：两条移动帧到达间隔小于窗口时，从「当前渲染位置」出发
> 的过渡不会反向跳变——这是插值实现里最容易写错的一处。

## 4. 快照重同步（漂移自愈）

绝对坐标帧天然纠偏，但仍有两类漂移需要服务器主动兜底：

| 机制 | 服务器行为（快照与帧分级机制） | 客户端对策 |
|---|---|---|
| **周期视野快照**（`snapshotResyncIntervalSeconds`，demo 1.0s） | 周期性对每个已认证连接**重发视野全量 `entity_enter`**（含未变动实体，入 outbox 帧末批量发送） | 已登记实体收到 entity_enter = 快照纠偏：按权威位置吸附（过渡从当前平滑位置出发，不跳变——SDK 同语义）。**不要**把「已存在实体的 entity_enter」当幂等信号忽略 |
| **传送即时差分**（P6b） | 传送跨 AOI 格时立即双向补发 entity_enter/leave（不等下一帧 World::update） | 实体表随差分即时增删；被传送者自己会收到新邻居的 entity_enter |
| **帧分级降载**（L1 帧分级） | low 档移动帧可丢/合并（只保最新），关键帧永不 | 插值系统对丢帧无感（绝对坐标 + 窗口收敛）；不要对 entity_moved 做「每帧必达」假设 |

**自愈链总结**：丢帧/乱序 → 下一绝对位置帧纠偏 → 周期快照（entity_enter 重发）兜底吸附 →
enter/leave 保证实体表最终一致。客户端唯一不能做的是**本地外推**（没有权威速度语义，外推必然漂移）。

## 5. 客户端 checklist（对接清单）

- [ ] WebSocket `binaryType = 'arraybuffer'`（Map 直连为二进制批量包；网关回帧实测也是二进制，
      JSON 解析前做 Blob/ArrayBuffer 归一——SDK `openGateway` 的 readText）
- [ ] 网关 auth 携带数字 `timestamp`（服务端校验必填）
- [ ] 实体表只由 entity_enter / entity_leave / monster:spawned 增删
- [ ] 渲染读 `sample()`，逻辑读 `position()`
- [ ] 监听 `world:tick_rate` 调整自身插值窗口
- [ ] 有回执的路由（quest:*/room:*/economy:*）与回显 requestId 的路由（错误帧/room:ok）用
      `request` 的对应匹配模式；move/attack 火发 + 世界帧确认
- [ ] 断线重连：SDK 已内置自动重连（重连即同图迁移：实体表清空重建 + 转移票据恢复，见
      `packages/client-js/examples/reconnect-demo.js`）；自研客户端重连后同样必须清空实体表重建
