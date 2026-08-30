# 阶段 5（framework 层 + 完整 Demo：引擎 → 游戏的最后一跳）验收总结

> 文档定位：阶段 5 剩余主线（framework 层 + 完整 Demo 战斗闭环）端到端验收复盘（组 14，ADR-016/ADR-017 的落地验收口径），非设计文档。
> 验收日期 2026-08-16；环境 WSL2 / PHP 8.3.32 / Workerman 5.2.2 / gateway-worker 4.0.1 / Redis（127.0.0.1:6379）。
> 交付物：`packages/framework`（framework 包）、根 `bin/server`（统一编排壳）、`packages/demo/bin/verify-combat.php`（战斗端到端验收脚本）。

## 1. 目标

补齐蓝图阶段 5 的 framework 层 + 完整 Demo——「引擎 → 游戏」的最后一跳：让开发者能基于引擎稳定、高效地写出一个真正能玩的游戏。

- **framework 层**提供「开发体验」：四基类、配置系统、Container、EventDispatcher、插件机制、CLI 脚手架、服务管理命令。
- **完整 Demo**用 framework + 引擎跑通最小 MMO 游戏循环，作为「业务代码不碰 Engine 内部」的标杆样例与验收载体（ADR-016 方向裁决、ADR-017 实施细化）。

## 2. 交付物

### 2.1 framework 包（`packages/framework`，新建）

| 组件 | 职责 |
|---|---|
| `BasePlayer` / `BaseMonster` / `BaseNPC`（四基类） | 继承 `Nythros\Actor\BaseActor`；BasePlayer=连接/uid/hp、BaseMonster=AI 状态机/hp、BaseNPC=静态/交互；战斗状态封装在基类内（铁律 6），业务扩展走钩子 |
| `Damageable` | 战斗最小公共面接口（hp/maxHp/takeDamage/heal/isDead），BasePlayer/BaseMonster 共同实现，使 CombatService 以统一签名承载双向攻击（修复 MAJOR-1） |
| `Container`（+接口） | framework 自实现轻量容器，`ContainerInterface` 含 `remove`（插件 uninstall 运行时卸载语义） |
| `Config` | PHP 数组文件配置，零 yaml 依赖（铁律 8） |
| `Event`（EventDispatcher + 接口） | framework 应用级事件，与引擎 EventBus 职责分层并行；`EventDispatcherInterface` 含 `removeListener` |
| 插件机制 | `PluginInterface`（register/enable/disable/uninstall 四态 + 加载/启用分离）+ `PluginRegistry`；官方起步 Skill/Item/Buff 三插件（AI/Quest/Inventory 继续后置） |
| CLI 脚手架 | `packages/framework/bin/make`：`make:actor` / `make:skill` / `make:event` / `make:map`（手写 argv 解析，不引入 symfony/console） |

依赖边界：`composer.json` 只声明 `php>=8.3` + `nythros/contracts` + `nythros/actor`；不依赖 demo、不依赖任何引擎实现包。

### 2.2 CLI（根 `bin/server`，不属于 framework）

统一编排壳，收敛 `launch.php` + gateway-worker 三脚本为一条命令：

- `bin/server start`：按启动铁序（Redis → Register → Gateway → BusinessWorker → Map）spawn 全链路，前台等待 + 信号转发。
- `bin/server status`：按运行清单逐服务报 pid 与状态。
- `bin/server stop`：逆序优雅停（Map → Business → Gateway → Register）+ 清理运行清单。

### 2.3 完整 Demo 战斗闭环（demo 层改造）

| 组件 | 职责 |
|---|---|
| `Combat/CombatService` | 纯业务：攻击/技能结算 + 死亡掉落 + 拾取（依赖注入 WorldInterface + 三支撑接口 + framework 插件，修复 MAJOR-1/3） |
| `Combat/MonsterActor` | extends BaseMonster：AI 钩子实现（PATROL/CHASE/ATTACK）+ 死亡掉落 + 自清理 |
| `Combat/DropEntity` | implements EntityInterface（组合 Position + itemId/count），掉落物无行为无 Actor |
| `Combat/DropTable` | 掉落表（按权重 roll） |
| `Combat/Inventory` | 玩家背包（itemId => count） |
| `Combat/EntityTypeIndex` | entityId → kind（player/monster/drop）类型索引（修复 MAJOR-4：玩家/怪物同为 BaseEntity，感知侧无法 instanceof 区分） |
| `Combat/VisionBroadcasterInterface` / `ActorLookupInterface` / `RandomSourceInterface` | demo 侧战斗支撑接口（MapServer 实现；修复 MAJOR-3/4/5） |
| `MapServer` 战斗路由 | dispatchSafe 扩展 attack / skill:cast / pickup（前置校验 + 失败回执 combat:error，连接不断，修复 MAJOR-5） |
| `PlayerActor` 改造 | 空 update 占位 → extends BasePlayer（onTick/onDamaged/onDeath 钩子） |
| 持久化接线 | pickup 后背包经 `ArchivePipeline.markDirty` 标脏 → 30s 兜底落库（复用阶段 4 Storage 抽象，不新建存储机制） |

### 2.4 配套配置

- 根 `composer.json`（+`nythros/framework`，path 仓库自动解析）、`composer.lock`、`packages/demo/composer.json`（+framework）、`phpstan.neon`（+`packages/framework/src`）、`.opencode/context/architecture.md`（分层表补 Framework 层）。

## 3. 门禁达成

### 3.1 10 客户端完整 MMO 循环（verify-combat.php：PASS=9 FAIL=0 SKIP=0）

10 客户端（uid 1001-1010）经 Gateway 登录拿 token 后直连同一 map-1#ch-1（18081），覆盖 ADR-017 §8.7 战斗消息协议表的 8 项战斗门禁（0 项前置 + 8 项战斗）：

| # | 验收项 | 结果 | 说明 |
|---|---|---|---|
| 0 | 前置：10 客户端登录 + Map 直连 | PASS | auth_ok 五字段 + Map consume('map') 五态，entityId 均为 uid@ 前缀 |
| 1 | 怪物生成 | PASS | monster:spawned 带 typeId=slime + 跨格 entity_enter |
| 2 | 玩家攻击 | PASS | attack → combat:hit 视野广播（1001/1002 双收），damage ∈ [8,12] |
| 3 | 怪物死亡 | PASS | 集火 → entity_dead + 尸体 attack → combat:error invalid_target（Actor 自清理） |
| 4 | 掉落生成 | PASS | drop:spawned（dropId 前缀 drop-monster-1-）+ 掉落物跨格 entity_enter 附 itemId |
| 5 | 拾取 | PASS | pickup → drop:removed（视野）+ item:added（定向）+ 重复拾取 error |
| 6 | 技能 | PASS | skill:cast 广播 + 技能伤害帧 combat:hit（damage ∈ [12,18]） |
| 7 | 失败回执 | PASS | 无效目标/距离/冷却 → combat:error{code}，连接不断 |
| 8 | 持久化 | PASS | pickup 后背包经 ArchivePipeline 落库，Redis 侧读命中 |

汇总行：`RESULT: PASSED (PASS=9 FAIL=0 SKIP=0)`。

### 3.2 一条命令启动 + 一条命令创建 Actor

- `bin/server start` 单命令 spawn 全链路（register/gateway/business/map 四服务，独立日志 + 运行清单）；`status`/`stop` 配套管理（stop 逆序优雅停 + 清单清理）。
- `php vendor/bin/make make:actor TestActor --kind=player --ns=Nythros\Demo\Game --out=...` 生成可挂载 Actor 骨架文件（含模板类）。

> 环境注：本机 WSL2 保留端口占用 Register 默认端口 1236 与 Gateway 内部 startPort 2300（ADR-015 遗留项），bin/server 验收在绕行端口副本下通过；正式部署端口按 ADR-015 处置。

### 3.3 业务代码不碰 Engine 内部（铁律 1，Grep 验证）

- framework 全部 import 白名单核查：`Nythros\Contracts\*` + `Nythros\Actor\BaseActor` + framework 自身命名空间，零 `@internal` 实现类、零 `Nythros\Demo\*` 引用（正向白名单为硬门禁，ADR-017 §9.3）。
- demo 业务类（Combat/* / PlayerActor 等）只依赖 contracts 接口 + framework 基类 + demo 自身接口；引擎 @internal 实现类收敛到 demo 组装脚本（bin/ 下）。

## 4. 质量门禁（提交前全绿）

| 门禁 | 结果 |
|---|---|
| `vendor/bin/phpunit` | **413 tests / 141445 assertions，7 skipped（环境性），全绿** |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | **0 错误** |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | **0 of 187 文件需修复** |

## 5. reviewer 审查结论

- **结论：PASS（6 MINOR，其中 3 修复 + 3 记录债务）**。
- 3 修复：MINOR-2（怪物死亡五处完整自清理，AOI/entityManager/actorSystem/typeIndex/actorLookup 无残留）、MINOR-7（@internal 清单补全 + 正向白名单判定口径）、MINOR-8（make:actor 生成模板 kind 矛盾）。
- 3 记录债务：MINOR-3（spawnDrops 未 EntityTypeIndex 登记）、MINOR-5（run-worker 未走 PluginRegistry 装配）、MINOR-6（怪物 PATROL 同格位移不广播）——见 §7。

## 6. 关键决策

1. **Damageable 接口（双向战斗）**：framework 自持战斗最小公共面（hp/maxHp/takeDamage/heal/isDead），BasePlayer/BaseMonster 共同实现，CombatService 以统一签名承载「玩家→怪物」与「怪物→玩家」双向攻击（修复 MAJOR-1 签名矛盾）。
2. **takeDamage 模板方法闭环（死亡结算）**：扣血钳制归零，归零时幂等触发 onDeath + enterState(STATE_DEAD)（有且仅有一个定义点）；CombatService 只做 isDead 判断与广播，不再跨类触碰 protected 钩子（修复 MAJOR-2 触发点逃逸）。
3. **CombatService 纯业务依赖注入**：构造注入 WorldInterface + 自建 VisionBroadcaster/ActorLookup/RandomSource 三支撑接口 + framework 插件注册表，可确定性单测，不 import 引擎 @internal 类（修复 MAJOR-3）。
4. **依赖循环规避（attachCombat 回填）**：CombatService 构造时依赖 MapServer（广播/查询实现），装配顺序 new MapServer → new CombatService → `attachCombat` 回填，循环依赖在组装层化解。
5. **实体类型识别契约（EntityTypeIndex）**：玩家/怪物同为 final BaseEntity 无法 instanceof 区分，demo 维护 entityId → kind 索引（auth/spawnMonster 登记、cleanup/死亡摘除），感知侧经索引判定而非 instanceof 引擎类（修复 MAJOR-4）。
6. **失败回执契约（combat:error）**：无效目标/距离/冷却/尸体攻击均定向回 combat:error{code,message}，连接不断（修复 MAJOR-5 缺失败回执）；combat:hit/entity_dead/spawned/enter 双路径语义分工明确、信息等价（ADR-017 §8.7）。
7. **插件生命周期完整（可卸载）**：register/enable/disable/uninstall 四态 + 加载/启用分离；ContainerInterface 增 remove、EventDispatcherInterface 增 removeListener，uninstall 具备运行时卸载语义。

## 7. 遗留项（债务）

- **spawnDrops 未 EntityTypeIndex 登记（reviewer MINOR-3）**：ADR-017 §8.3 与 §8.6 自相矛盾（§8.6 要求 spawnDrops 时 typeIndex->set(drop)，但掉落物为 demo 自身 DropEntity，判定统一走 instanceof），当前以 instanceof DropEntity 为准、不登记 typeIndex，留待统一裁决。
- **run-worker 未走 PluginRegistry 装配（reviewer MINOR-5）**：组装脚本手写全部依赖装配（SkillRepository/ItemRepository/CombatService 直接 new），未走 `PluginRegistry::load` + `Container->get` 插件装配路径，后续统一改为插件装配 + 容器取用。
- **怪物 PATROL 同格位移不广播（reviewer MINOR-6）**：PATROL 随机移动走 entity->move 不广播 entity_moved——demo 阶段怪物同格位移不广播，跨格 enter/leave 已由 World::update 扫描 + handleAoiVisibility 覆盖，留待后续统一帧协议。
- **bin/server 端口环境问题**：Register 1236 与 Gateway 内部 startPort 2300 被 WSL2 保留端口占用（ADR-015 遗留项，环境配置问题而非代码问题）。
- **玩家死亡行为简化**：demo 阶段仅「标记待复活/回出生点」状态粒度（ADR-017 §12 待确认点 4），完整回城流程后置。

## 8. 验收脚本使用说明

```bash
# 前置（ADR-015 §4.1 启动铁序）：Redis 127.0.0.1:6379 可用
php bin/server start                          # 一条命令启动全链路（register/gateway/business/map）
php packages/framework/bin/make make:actor X  # 一条命令创建 Actor
# 端到端验收（战斗闭环，9 项，需临时副本：延迟 spawn + Redis 可观察归档存储）
php packages/demo/bin/verify-combat.php
```

- verify-combat.php 输出契约：每项一行 `[verify] [PASS|FAIL|SKIP]`；末行 `RESULT` 汇总。
- 脚本收尾自动关闭客户端连接、清理 Redis 测试残留，SIGINT 优雅退出。
