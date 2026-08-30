# 27 · P15 跨 map 实体迁移验收记录

> 批次：P15 跨 map 实体迁移（blueprint/21 阶段 C，预研 → ADR → 实现）。
> 基准：commit 8db456f（阶段 B 收官）。
> 协议裁决：**ADR-025**（`.opencode/context/decisions/ADR-025-跨map实体迁移协议.md`）——
> 客户端驱动换线 + 转移票据（经典 zone-transfer 方案），否决连接代理接力与无缝世界分片。

## 一、方案概要（ADR-025 摘录）

- **迁移时序**：客户端 `map:enter{mapId}` → 网关选频道（负载感知）+ 重签一次性 token → `map:entered` →
  客户端断开旧连（**源端 detach 导出快照进转移票据**）→ 凭新 token 连目的图（**attach 原子消费票据重建实体**）
  → `map:join` 确认（既有路径）。全链复用 token 一次性语义 / closeConnection detach 钩子 / auth attach 点，
  **零新增协议帧**（词表零改动，规避 blueprint/23 发现 2）。
- **快照契约**（`nythros:transfer:{uid}`，SETEX 30s，Lua GET+DEL 原子单消费）：
  `{fromMapId, position{x,y}, hp(≥1), inventory{itemId:count}}`。
- **重建规则**：位置仅同图恢复（异图落目的图缺省入场点——经典换线语义）；背包/血量同图异图均恢复
  （clamp 进 [1, 合成 maxHp]）；死亡态不迁移（clamp=1，跨图入场即存活）；出生保护照常启用（防落地集火）。
- **故障方向**：消费失败/TTL 过期回落「全新入场」——变保守不变错（P9 fail-open 同哲学）。

## 二、交付内容

| 模块 | 说明 |
|---|---|
| `PlayerTransferStoreInterface`（framework，新） | 票据存储契约：export（覆盖写）/ consume（原子单次）。 |
| `RedisPlayerTransferStore`（framework，新） | Redis 实现：SETEX 导出 + Lua GET+DEL 原子消费（不依赖 Redis 6.2 GETDEL）；键族 `nythros:transfer:` 与 token/registry 严格分离；工厂闭包注入（fork 后 lazy 建连，RedisTokenStore 同范式）。 |
| `InMemoryPlayerTransferStore`（framework，新） | 单进程形态（单测/纯消息模式），InMemoryTokenStore 同范式。 |
| `PlayerActor::importHp`（framework） | 快照血量导入：clamp 进 [1, 合成 maxHp]。 |
| `MapServer` | detach 导出（`exportTransferSnapshot`：fromMapId/位置/hp clamp≥1/背包全量，挂在 onEntityCleanedUp——closeConnection/evict/kick 全部断连路径汇入）+ attach 导入（`consumeTransferSnapshot`：同图恢复坐标、背包/血量重建；handleAuthMessage 的 entity 装配点消费）；构造参数 `transfers`（null = 未装配，接入前语义）。 |
| `MapChannelFactory` | Redis store 注入（redisFactory 复用）。 |
| `bin/verify-transfer.php`（新） | 迁移 E2E（消费 P14 公共库 verify-framework）：登录（社交连接保持）→ 承伤致死 → map:enter 跨图迁移 → 首击即死恢复断言。 |

## 三、验收结果

| 项 | 结果 |
|---|---|
| CI 四门禁 | php-cs-fixer 0 / phpstan level 8 0 / composer internal OK / phpunit **1232 tests**（4 失败为 engine WorkermanWebSocketServerTest 的 Windows 环境预存失败，P11-P13 基线一致，与本批无关） |
| 存储单测 | PlayerTransferStoreTest 4 tests：InMemory 全语义（往返/覆盖/单消费/无票 null）+ Redis 集成（原子 GET+DEL、覆盖导出、坏票 JSON 容错、工厂闭包 lazy 建连）——无 Redis 环境整体跳过（CI 有 Redis 真跑） |
| MapServer 单测 | MapServerTransferTest 5 tests：导出契约（fromMapId/位置/hp/背包）、同图恢复、异图入场点回落、hp clamp=1 与坏行背包容错、store 未装配零操作、无 uid 零导出 |
| **迁移 E2E** | `verify-transfer.php` **PASS=4 FAIL=0**：登录 → 承伤致死（combat:hit hp=0 确认）→ map:enter 跨图迁移（map-2 auth_ok）→ 迁移后首击 hp=0（hp=1 经票据恢复的唯一解释；全新入场首击为 88-92，区间分离断言确定） |
| 回归 E2E | verify-mmorpg **PASS=11 FAIL=0**（auth 导入路径影响所有登录——零回归确认） |

## 四、实测踩坑记录（E2E 调试过程固化）

1. **网关全消息校验 timestamp**：auth 之外的 `map:enter` 同样要求数字 timestamp（缺省拒绝 400）——
   verify 脚本 sendSocial 已带；SDK/客户端接入需知晓（docs/js-client-example.js 未覆盖此点）。
2. **迁移验收的断言设计**：hp 快照基线若取「首击 hp」会因 wolf 追击持续掉血而失配（detach 导出的是
   断连时刻的最终 hp）——死亡迁移方案（禁用 auto-revive，死亡态 clamp=1，迁移后首击即死）把断言变成
   区间分离的确定判定；同时回归了「死亡态不迁移」的 ADR 语义。
3. **步骤谓词完备性**：ownDamageFrame 的 combat:hit 分支漏掉 `hp ≤ 0` 条件时，首击即被误判为死亡
   （曾致 91/79/80 等一系列假阳性）——谓词必须与帧语义逐字段对齐。

## 五、边界与后续

- 不迁移的状态：房间归属/匹配队列（走既有 Redis 持久化路径重进）、任务进度（RedisQuestStore 本就跨进程）、装备挂载（重登重建）。
- 背包的 MySQL 归档仍为只写（ADR-013 设计口径）——迁移票据是唯一的跨进程状态读路径；后续若做
  「掉线重进恢复背包」，票据 TTL 过期后的兜底读归档可作为增量立项。
- 位置跨图传送点图元、迁移时在途请求排空窗口：见 ADR-025 §5 演进项。
