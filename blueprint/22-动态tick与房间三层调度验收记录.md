# P9 验收记录：负载感知动态 tick、事件优先级降级与房间三层调度

> 主程评审新增需求（blueprint/21 立项）：AOI 世界**区域降频**（格子密度驱动，非全图统一降档）、
> 全量房间按团队规模降频、Hz 范围可配置、事件分级**两层降级**（关键帧极端情况才降）、房间链路
> **三层调度**（内层分频/进程预算/准入）。本批全部改动 WSL 复现 CI 门禁 + E2E 三轮全绿后提交
> （不推送）。P10 容量压测为本批的直接后续（依赖本批的降频正确性）。

## 1. 交付物

### 1.1 P9a 区域降频：格子密度热区 + 实体分频

| 变更 | 文件 | 说明 |
|---|---|---|
| 分频机制 | `packages/framework/src/Actor/TickCadence.php`（新增 trait） | 实体级 tick 分频：divisor=N 每 N 个 base tick 行动一次；poll 在 update 模板内调用；divisor=1 零开销直通（未接入 governor 行为不变）。**设计精化：全局定时器恒定 base_hz，分频只在实体层——原计划的「帧语义时间化」取消**（基频不变则帧计数语义保留，热区实体「行动更少」本身即按比例降载） |
| 门控接线 | `BaseMonster`（update 模板整体门控——AI 节拍降载，攻击冷却在 onAttack 内递减自然随档位变慢）、`PlayerActor::onTick`（攻击冷却门控、**出生保护不门控**——安全窗口恒走 base tick，降档不延长无敌） | BasePlayer 引入 trait 供子类使用 |
| 负载策略 | `packages/framework/src/Game/Mmorpg/HotCellPolicy.php`（新增） | 档位表值对象：`{untilPlayers, divisor}` 升序阶梯（首个匹配生效、无界末档兜底）、单调性校验（untilPlayers 严格递增 + divisor 非降序）、降温滞回、邻接格外扩半径 |
| 状态承载 | `packages/framework/src/Game/Mmorpg/CellDensityGovernor.php`（新增） | 每 base tick 采样玩家位置 → 各格密度（**采样缺席格子密度归零**，人群离开即进入降温计时）→ 热区等级（升温即时、降温滞回，快升慢降防临界抖动）→ `divisorFor(x,y)` 取自身格+邻接格最热档（格界梯度平滑，防怪物跨界档位突变） |
| 配置 | `MmorpgConfig` 新增 `hotCell: ?HotCellPolicy`；工厂 env `NYTHROS_MMORPG_HOT_CELL='12:1,25:2,0:4'` + `NYTHROS_MMORPG_HOT_CELL_HYSTERESIS_S` / `NYTHROS_MMORPG_HOT_CELL_NEIGHBOR_RADIUS`；缺省 null = 关闭（零影响） |
| 接线 | `MapServer::attachMmorpg` 创建 governor（cellSize 与 GridAOI 同源 `AOI_CELL_SIZE=10`）；`tickMmorpg` 采样+指派（玩家与怪物都受档位：玩家侧降攻击率、怪物侧降 AI 节拍） |

### 1.2 P9b 移动广播节流 + tick 速率帧

| 变更 | 文件 | 说明 |
|---|---|---|
| 节流钩子 | `packages/framework/src/Server/RealtimeServer.php` `handleMove` | 新增 `shouldBroadcastMove(entityId)` 模板钩子（缺省恒真，decorateViewPayload 同风格）——位置照常应用，仅广播可跳 |
| 节流实现 | `packages/demo/src/MapServer.php` | 覆写钩子：热区分频下移动广播只在到期 tick 发（`tickCounter % divisor === 0`）——**O(N²) 聚团流量的主要砍口**；跳过的中间帧由视野快照重同步（1s 周期）/后续移动补发，语义为「保留最新位置」而非丢帧；容器分支广播同过钩子 |
| 速率帧 | `Protocol/FrameType` 新增 `world:tick_rate`（88） | 分频变化即定向下发 `{divisor}`，客户端据此调整插值窗口（base tick × divisor） |

### 1.3 P9c 房间三层调度（内层分频 / 进程预算 / 准入）

| 变更 | 文件 | 说明 |
|---|---|---|
| 动态周期 | `packages/engine/src/World/RoomInstanceManager.php` | 房间 entry 增 `periodMs`（动态）/`deferStreak`：**被顺延是「本房间跑不完一帧」的直接信号**——连续 2 次顺延 → 周期 ×1.5 膨胀（上限 `maxDynamicPeriodMs`，缺省 50ms）；本轮零顺延 = 进程有余量 → 全部膨胀房间 ÷1.25 逐拍回落至配置 periodMs（下限）。driveRoom 改用动态周期 |
| 准入控制 | 同上 | `maxRooms`（0 = 不限）触顶 `create()` 抛 `OverflowException`——`RoomHub` 转译为定向 busy 回执（507，连接不断），`MatchingService` 与 `\InvalidArgumentException` 同口径整批重新入队（撮合不空转；跨进程路由规避属 P16） |
| 指标暴露 | `MapServer::setRoomMetricsProvider` + 心跳 meta 汇入 `rooms/roomsDeferred`；工厂 env `NYTHROS_ROOMS_MAX` / `NYTHROS_ROOMS_MAX_PERIOD_MS` | registry 消费方（网关/匹配）据此做 busy 判定与路由规避（P16 动态扩缩容的数据面） |

## 2. 单测（新增 10 项）

- `CellDensityGovernorTest`（6 项）：policy 四类校验（缺兜底/非单调 divisor/非递增边界/兜底居中）、升温即时+降温滞回+区域独立、邻接格取最热档。
- `RoomInstanceManagerTest` 新增 2 项：错峰周期下顺延压力 → 周期膨胀 5→8 且对照房不膨胀；余量后逐拍回落 8→…→5（下限=配置周期）；准入触顶 OverflowException。
- `MapServerMmorpgTest` 新增 2 项：热区指派（同格聚集 → divisor 4 + 玩家冷却门控/保护不门控）；节流（4 次移动仅到期 tick 广播 2 次、位置照常应用）+ 速率帧。

## 3. 门禁（WSL 复现 CI 口径）

| 门禁 | 结果 |
|---|---|
| phpunit 全量 | OK（**1172 tests / 145651 assertions**，新增 10 项） |
| php-cs-fixer | 0 需修复 |
| phpstan | 0 错误 |
| `composer internal` | OK |
| verify-mmorpg E2E（P9 特性缺省关闭，回归零影响口径） | **PASS=11 FAIL=0（连续三轮）** |

## 4. 遗留与债务

- **降频线缆级验收缺位**：E2E 未开启 `NYTHROS_MMORPG_HOT_CELL`（3 客户端不足以触发密度阈值，强开
  会扰动既有步骤的战斗节奏假设）；降频/节流的线缆行为由单测覆盖，聚团规模行为由 **P10 压测**验收
  （其为 P9 的直接后续，含降-升往返断言与容量天花板）。
- **L1 帧分级降载未启用**：frameMerger 已有 priority 分类（entity_moved=LOW），但按优先级的丢弃策略
  未接线（当前 L1 仅为移动广播节流）——视 P10 压测数据决定是否需要。
- **准入的跨进程路由**：registry 指标已暴露（rooms/roomsDeferred），网关/匹配侧的路由规避过滤器属
  P16（动态扩缩容）范畴。
- **cost_first 未实现**：进程预算层 v1 为逐房间 hrtime 计费的顺延自调（等价于按受压程度分频），
  「最贵房间先降」的显式策略视 P10 数据裁决。

## 5. 下一步

- **P10 容量压测与硬件选型报告**：聚团混战（开启 `NYTHROS_MMORPG_HOT_CELL`，验证降档曲线 20→…→5Hz、
  降-升往返、容量天花板）+ 房间容量（30Hz 下 6 人团队数）+ 带宽实测 → docs/performance.md 选型章节。
- P11 玩法数据外置随后。
