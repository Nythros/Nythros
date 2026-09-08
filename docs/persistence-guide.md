# 持久化指南（Persistence）

> 面向读者：为 Nythros 游戏做存档/读档/选型的程序。读完你能：选对存储适配器、理解归档管线的
> 双模式（export 缺省 / mysql 回退）与读写路径、建立 schema、按迁移约定演进表结构。能力面：
> engine 持久化契约 + 两个存储适配器 + framework 管线契约（PersistPipelineInterface 双实现）+
> storage-exporter 导出进程；Redis 同时承担「带 TTL 的临时共享」与 export 模式下的「会话热状态权威」
> （背包 `nythros:bag:*`，选型边界见 §1）。

## 1. 存储选型：什么时候用哪个

| 数据 | 落点 | 理由 |
|---|---|---|
| 账号/角色/背包/货币（永久） | export 缺省：Redis 会话权威 + Stream 导出 + storage-exporter 落 MySQL；mysql 回退：`StorageInterface` 适配器 + `ArchivePipeline` worker 直写 | 要保留、要跨会话；缺省路线让游戏 worker 零 PDO |
| token/组队快照/位置/转移票据/排行 | Redis（各自 store，带 TTL 或原生结构） | 临时共享或需要原生结构（HLL/ZSET） |
| 战斗帧级状态（AOI/血量/位置） | Map 进程内 | 高频，落存储即压垮（best-practices §2） |

关键区分（新模型下已改写）：Redis 不只是「TTL 临时态宿主」——export 模式下它是**会话热状态的权威存储**
（背包快照无 TTL、attach 恢复首读、崩溃后在线态可复原），MySQL 退居冷归档（报表/回档/Redis 灾难兜底）；
游戏 worker 进程内因此零 PDO（`composer io-free` 门禁守护）。「Redis 不放永久数据」仅在 mysql 回退
模式与运维清理策略语境下成立——给 Redis 键配 AOF + 积压/内存治理见 deployment §7。

## 2. 存储契约与适配器

引擎只暴露契约（`packages/engine/src/Persistence/`），实现标 `@internal`：

- `StorageInterface`：按 collection 分桶的 KV 语义——`save/load/delete/saveBatch`；
- `RepositoryInterface`：领域仓库语义——`find/persist/remove/findBy`。

适配器：

| 适配器 | 说明 |
|---|---|
| `InMemoryStorage` | 进程内数组，零依赖——测试与单机验证缺省 |
| `MySqlStorage` | PDO 单表 upsert（`collection` 分区列 + JSON 载荷），构造注入 `callable $pdoFactory` 与表名（默认 `nythros_archive`） |

生产替换姿势（组装层一行注入）：把 `MySqlStorage` 实例经 `StorageInterface` 绑进容器/装配点，
`InMemoryStorage` 立即整体退役；业务代码不感知（只依赖契约）。

### 2.1 持久化管线双模式（PersistPipelineInterface，framework/Persistence）

游戏侧统一面向 `PersistPipelineInterface`（markDirty/scheduleFlushId/flushId/load/flush/periodicFlush/bindTimer），
装配层由 `NYTHROS_PERSIST_MODE` 选实现：

| 模式 | 管线 | 写路径 | 读路径（attach 恢复） |
|---|---|---|---|
| `export`（缺省） | `RedisExportPipeline` | 冲刷点一条 pipeline：背包快照写 `nythros:bag:{uid}`（权威）+ 脏记录 XADD `nythros:export:players`；worker 零 PDO | 读 Redis 背包 hash（缺省开，`NYTHROS_ARCHIVE_RESTORE=0` 关） |
| `mysql`（回退） | `ArchivePipeline` | 标脏 → 断连/登出 `scheduleFlushId` 合并窗批量 `saveBatch` → worker 直写 MySQL | 读 MySQL 归档（`NYTHROS_ARCHIVE_RESTORE=1` 才开） |

export 模式下 MySQL 落盘由独立 **storage-exporter** 进程完成（`type: storage`，`run-exporter.php`）：
消费组单消费者、at-least-once（失败不 ack→PEL 重放、毒消息 ack+日志）、backlog/心跳上报
`nythros:perf:storage-exporter:*` 键族供告警（deployment §4）。建表 DDL 亦归 exporter 启动期执行。

## 3. Schema 建立与迁移约定

**建表**：`MySqlStorage::createSchema(PDO $pdo, string $table = 'nythros_archive')`——幂等静态方法；
export 缺省模式下由 storage-exporter 启动期自动执行（worker 不再建表），mysql 回退模式与离线
预建库场景手动调一次：

```bash
php -r 'require "vendor/autoload.php";
$pdo = new PDO("mysql:host=127.0.0.1;dbname=nythros", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
Nythros\Persistence\MySqlStorage::createSchema($pdo);
echo "schema ok\n";'
```

**迁移现状与约定**（诚实声明）：仓库未引入 phinx/doctrine-migrations 迁移工具——当前唯一持久表
是自包含的单表 upsert，`createSchema` 即全部 DDL。演进约定：

1. **载荷演进免迁移**：数据是 JSON 载荷，新增字段读方自行兼容（缺省值），不需要 DDL；
2. **表结构变更**（如分表、加索引）：在 `blueprint/` 立一条 ADR，扩展 `createSchema` 的幂等语句
   （`CREATE TABLE IF NOT EXISTS` + 条件索引），保持「一次调用到位」；
3. **collection 语义变更**（分桶重划）：写双读切换（新 collection 写入 + 读迁移脚本跑存量），
   不要原地改写。

若项目规模到了需要正式迁移工具的临界点（多表、多环境、回滚需求），引入 phinx 并把本文 §3 改写为
其使用说明——在那之前不要为单表引入依赖。

## 4. 归档管线契约（PersistPipelineInterface，framework）

写路径是**脏标记 + 合并回写**，读路径与写路径全闭环（早期只有只写半闭环，读路径为后续补齐）。
双实现共用同一契约（§2.1），API 语义逐点对齐：

| API | 语义 |
|---|---|
| `markDirty(id, data)` | 标脏（游戏循环内调用，零 IO；同 id 覆盖写并清零失败计数） |
| `scheduleFlushId(id)` | 断连/登出入口：登记紧急队列，0.2s 合并窗并成一次批量（风暴不放大；无定时器装配回落 flushId 同步点） |
| `flushId(id)` | 单实体强制同步点（立即回写该记录） |
| `flush()` | 全量回写（停机前调用） |
| `periodicFlush()` | 周期批量兜底（缺省 30s；失败重试有上限 + 日志放弃，不抛进游戏主循环） |
| `load(id)` | 读路径：export 读 Redis 背包权威、mysql 读归档表；进图恢复用 |
| `bindTimer(timer)` | fork 后由 onWorkerStart 绑定：同时启用紧急合并窗与 30s 兜底（幂等）——Workerman 定时器不可 fork 前挂 |

消费模式（demo `MapServer`/拾取链路同款；会话态能力块另走 `SessionParticipantInterface`
的 open/close 生命周期，见 actor-guide/best-practices）：

```text
游戏循环：item:added → pipeline.markDirty(uid, backpack)        # 只标脏,零 IO
连接关闭：pipeline.scheduleFlushId(uid)                          # 0.2s 合并窗批量
定时器  ：pipeline.periodicFlush()（30s 兜底,bindTimer 挂载）
进图时  ：pipeline.load(uid) 恢复背包(export 模式缺省开)+票据快路径
```

## 5. 新增一个存储适配器（步骤化）

1. 实现 `Nythros\Contracts` 下的 `StorageInterface`（四个方法 + `saveBatch` 批量语义，
   返回失败 id 列表）；放 engine 之外（组装层/自有包）——除非它对所有人通用；
2. 处理好**幂等 upsert** 与**连接失败语义**（抛异常让组装层决定去留，不静默吞）;
3. 照 `packages/engine/tests/Persistence/MySqlStorageTest.php` 的形状写集成测试
   （testing-guide §4：本地/CI 有真实服务才跑，否则 skip）；
4. 在 [api-reference](api-reference.md) 生成口径下决定是否公开（不通用则 `@internal`）。

## 6. 反模式

- 把帧级状态写进任何存储（§1 第一行反例）；
- 绕过 `PersistPipelineInterface` 管线在游戏循环里同步写库/写 Redis（`composer io-free` 门禁拦截）；
- export 模式下给会话权威键（`nythros:bag:*`）配短 TTL 或指望 MySQL 兜热数据——权威错位；
  离线长尾的 Redis 内存治理是运维课题（deployment §7），不是热路径设计；
- 用 `findBy` 做高频查询（全表扫描语义——它是仓库辅助，不是索引服务）。
