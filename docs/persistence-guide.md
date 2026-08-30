# 持久化指南（Persistence）

> 面向读者：为 Nythros 游戏做存档/读档/选型的程序。读完你能：选对存储适配器、理解归档管线的
> 写路径与读路径、建立 schema、按迁移约定演进表结构。能力面：engine 持久化契约 + 两个存储适配器 +
> framework 归档管线；Redis 承担「带 TTL 的临时共享状态」（选型边界见 §1）。

## 1. 存储选型：什么时候用哪个

| 数据 | 落点 | 理由 |
|---|---|---|
| 账号/角色/背包/货币（永久） | `StorageInterface` 适配器（MySQL/InMemory）+ `ArchivePipeline` | 要保留、要跨会话 |
| token/组队快照/位置/转移票据/排行 | Redis（各自 store，带 TTL 或原生结构） | 临时共享或需要原生结构（HLL/ZSET） |
| 战斗帧级状态（AOI/血量/位置） | Map 进程内 | 高频，落存储即压垮（best-practices §2） |

关键区分：**Redis 在 Nythros 里不是 StorageInterface 适配器**——它是各业务 store 的宿主
（`RedisTokenStore`/`RedisTeamStore`/`RedisFriendStore`/`RedisQuestStore` 等），语义是 TTL 快照；
永久存档走 §2 的存储适配器。

## 2. 存储契约与适配器

引擎只暴露契约（`packages/engine/src/Persistence/`），实现标 `@internal`：

- `StorageInterface`：按 collection 分桶的 KV 语义——`save/load/delete/saveBatch`；
- `RepositoryInterface`：领域仓库语义——`find/persist/remove/findBy`。

适配器：

| 适配器 | 说明 |
|---|---|
| `InMemoryStorage` | 进程内数组，零依赖——测试与单机验证缺省 |
| `MySqlStorage` | PDO 单表 upsert（`collection` 分区列 + JSON 载荷），构造注入 `callable $pdoFactory` 与表名 |

生产替换姿势（组装层一行注入）：把 `MySqlStorage` 实例经 `StorageInterface` 绑进容器/装配点，
`InMemoryStorage` 立即整体退役；业务代码不感知（只依赖契约）。

## 3. Schema 建立与迁移约定

**建表**：`MySqlStorage::createSchema(PDO $pdo, string $table = 'nythros_storage')`——幂等静态方法，
启动前调一次即可（部署清单见 [deployment.md](deployment.md) §6）：

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

## 4. 归档管线（ArchivePipeline，framework）

写路径是**脏标记 + 双通道回写**，读路径与写路径全闭环（早期只有只写半闭环，读路径为后续补齐）：

| API | 语义 |
|---|---|
| `markDirty(id, data)` | 标脏（游戏循环内调用，零 IO） |
| `flushId(id)` | 单实体立即落库（断线回写用） |
| `flush()` | 全量落库（停机前调用） |
| `periodicFlush()` | 周期批量落库（缺省 30s；失败重试有上限 + 日志，不抛进游戏主循环） |
| `load(id)` | 读路径：归档存在则返回存档（进图恢复/离线结算） |

消费模式（demo `MapServer`/拾取链路同款）：

```text
游戏循环：item:added → ArchivePipeline.markDirty(uid, backpack)     # 只标脏
连接关闭：flushId(uid)                                             # 立即落库
定时器  ：periodicFlush()（30s）                                    # 批量兜底
进图时  ：load(uid) 恢复位置/血量/背包
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
- 绕过 `ArchivePipeline` 直接在游戏循环里同步写库；
- Redis 里造「永久数据」（无 TTL 的快照会随清理策略/遗忘而变成幽灵状态）；
- 用 `findBy` 做高频查询（全表扫描语义——它是仓库辅助，不是索引服务）。
