# 测试指南（Testing Guide）

> 面向读者：为 Nythros 及其上玩法编写测试的程序。读完你能：选对测试层（单测/集成/E2E/基准）、
> 仿照仓库既有形状写出新用例、接上 CI 门禁。测试现状：124 个 PHPUnit 测试类
> （engine 43 / framework 65 / demo 16）+ verify-* E2E 脚本族 + benchmark 回归门禁。

## 1. 四层测试模型

| 层 | 载体 | 依赖 | 例子 |
|---|---|---|---|
| L1 单元 | PHPUnit | 无（内存桩） | `GridAOI` 查询、`CombatService` 结算 |
| L2 集成 | PHPUnit | 真实 Redis/MySQL | `RedisTokenStoreTest`、`MySqlStorageTest` |
| L3 E2E | `packages/demo/bin/verify-*.php` | 完整服务栈 | `verify-phase5.php`（登录→进图→战斗直连） |
| L4 基准 | `benchmarks/*.php` + `bench-gate` | 无/完整服务 | `engine-bench.php` 帧耗时回归 |

选择原则：**逻辑正确性放 L1**（跑到 Actor/协议层的 bug 不该在单测层漏掉），
**跨进程协作放 L3**（拓扑/鉴权/迁移类缺陷只有真实链路能暴露），L2 只为存储实现保真，L4 守性能不守功能。

## 2. 目录与命名

```text
packages/<pkg>/tests/<Module>/<ClassName>Test.php   # 与 src 的 Module 目录镜像对应
```

- 一个被测类一个测试文件，命名 `<被测类>Test`；跨类行为（如 MapServer 装配后的完整路由）允许
  按行为命名（`MapServerCombatTest`、`MapServerTransferTest`）。
- phpunit 单一 testsuite `nythros`（`phpunit.xml.dist`），三个包的 tests 目录全部纳入，
  跑全量：`composer test`；只跑一包：`vendor/bin/phpunit packages/framework/tests`。

## 3. 写单测（L1）

以 engine 的 AOI 与 framework 的战斗为例，仓库惯用形状：

- **内存桩优先**：网络/时钟/存储全部用内存实现（`InMemoryTokenStore`、`InMemoryStorage`、
  `SystemClock` 固定时刻），不做 IO。找不到契约的内存实现时先补一个（它本身也是对契约的可用性检验）。
- **种子化随机**：战斗随机数走种子化 RNG（framework Combat 模块）——测试里给固定种子断言确定性结果，
  不要在测试里 mock 掉随机源。
- **断言行为而非实现**：断言「视野内收到 entity_enter」而不是「GridAOI 内部数组长度为 N」。

## 4. 集成测试（L2）与 CI 服务容器

- Redis/MySQL 集成测试直连 `127.0.0.1`（root 空密码、预建 `nythros` 库），与
  `.github/workflows/ci.yml` 的 service 容器对齐；本地用根目录 `compose.yaml` 拉起同样的栈。
- 本地无 Redis/MySQL 时相关用例应 skip 而非 FAIL（CI 保证真跑，quick-start §1.1 保证本地可复现）。

## 5. E2E 验收脚本（L3）

`packages/demo/bin/verify-*.php` 是真实 WebSocket 客户端驱动完整服务栈的验收脚本族：

| 脚本 | 覆盖 |
|---|---|
| `verify-phase5.php` | 社交层端到端：登录、进图凭证、战斗直连铁律、聊天、组队、掉线重连（可直接跑） |
| `verify-combat.php` | 战斗端到端：生成/攻击/死亡/掉落/拾取/技能/持久化（需临时副本，见 quick-start §6.2） |
| `verify-economy.php` / `verify-matching.php` / `verify-room.php` | 经济 / 匹配 / 房间 |
| `verify-transfer.php` / `verify-scale.php` | 跨 map 迁移 / 容量准入与扩缩容 |
| `verify-mmorpg.php` | MMORPG 模式综合 |

约定：

- **输出契约**：每项一行 `[verify] [PASS|FAIL|SKIP]`，末行 `RESULT` 汇总——新脚本必须遵守，
  上游工具靠这个格式判定。
- **先起服务再跑脚本**：`php bin/server start` 后另开终端执行；脚本自带断言与失败详情，
  不需要人工比对。
- 新玩法模式的验收脚本放进 `packages/demo/bin/`，命名 `verify-<mode>.php`，
  并在本文档表格与本仓库 README 收录。

## 6. 基准回归门禁（L4）

```bash
# 本地自查（阈值 20%）：
php benchmarks/engine-bench.php --json > /tmp/cur.json
php tools/bench-gate.php benchmarks/results/engine-bench.json /tmp/cur.json
# 门禁自检：
php tools/bench-gate.php --self-test
```

- CI 阈值放宽到 50%（跨硬件方差 ±30%，紧阈值必误报；仍可拦截 O(n²) 级灾难劣化）。
- **改基线要慎重**：确认是「有意的性能变化」后更新 `benchmarks/results/*.json` 并在提交信息里注明
  硬件环境（基线为 WSL 实测）。
- 覆盖范围：engine（World/AOI/事件总线/序列化/调度）、framework（插件/仓库/AI update）、
  demo（FrameMerger/MapServer 消息链路）；压测脚本（stress-map/hotzone/rooms）见 performance.md。

## 7. 静态门禁

| 命令 | 拦什么 |
|---|---|
| `composer cs` | 代码风格（@PSR12 + strict_types） |
| `composer stan` | phpstan level 8（四包 src 全量，含 skeleton） |
| `composer internal` | @internal 公开符号门禁（ADR-023/024，双向：engine 标注 + framework use 扫描） |
| `composer api` | docs/api-reference.md 与代码一致性 |

四项全部在 CI 强制。新公开类必须过 `internal`（该标 @internal 的标）并再生成 API 一览。

## 8. 新玩法的测试策略（建议路径）

1. 先写 L1：新 Combat/Social/数值逻辑逐类单测（种子化随机 + 内存桩）。
2. 再补 L3：demo 装配一个 `verify-<mode>.php`，真实客户端走完整链路。
3. 动了广播/移动热路径 → 跑 L4 基准；动了鉴权/限流 → 补 security 相关用例
   （engine Security 与 demo `StaticAuthenticatorTest` 是现成形状）。
4. PR 描述里贴 `RESULT: PASS` 输出行——仓库惯例是验收记录进 `blueprint/`（阶段总结可引用）。
