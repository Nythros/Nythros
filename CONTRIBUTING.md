# 贡献指南（Contributing）

感谢关注 Nythros！本文描述如何搭建开发环境、遵守哪些工程纪律、以及提交什么。设计决策与历史脉络见
[blueprint/README.md](blueprint/README.md)（架构规范、ADR、全部阶段验收记录）。

## 开发环境

| 依赖 | 要求 |
|---|---|
| PHP | ≥ 8.3，扩展 `redis`、`pdo_mysql`、`pcntl`/`posix`（Linux/WSL2） |
| Redis / MySQL | `docker compose up -d` 一键拉起（或自备 127.0.0.1 缺省实例） |
| Node | ≥ 22（client-js SDK 测试用） |
| Composer | 2.x |

```bash
composer install
docker compose up -d
php vendor/bin/make            # 脚手架 CLI 自检
php bin/server start           # 启动 demo 服务（详见 docs/quick-start.md）
```

## 提交前的门禁（CI 全部强制）

五项门禁（cs / stan / internal / api / phpunit）与 benchmark 回归的完整说明、各层测试的写法、
基线对比方法见 [docs/testing-guide.md](docs/testing-guide.md) §4~§7。速查：

```bash
composer cs && composer stan && composer internal && composer api && composer test
npm test   # packages/client-js
```

动了热路径（World/AOI/协议/广播）请跑 `php benchmarks/engine-bench.php --json` 与基线对比
（门禁细节见 [docs/testing-guide.md](docs/testing-guide.md) §6）；E2E 行为变化请补/改 `verify-*` 脚本。

## 工程纪律（先读再写代码）

1. **分层铁律**：业务代码只依赖 `Nythros\Contracts` 接口；引擎不 import framework/demo；
   判别口诀与审计方法见 [blueprint/32-架构分层审计报告.md](blueprint/32-架构分层审计报告.md)。
2. **协议编码一经发布不得复用/改义**：新增帧/字段必须同步 PHP 枚举与 client-js 码表
   （`php packages/client-js/scripts/generate-definitions.php` 再生成 TS）。
3. **影响公开 API / 分层依赖 / 协议编码 / 性能基线的改动，先落 ADR 再实现**
   （[blueprint/adr/README.md](blueprint/adr/README.md)——提案 PR 即可，状态标「提议」）。
4. **数值外置**：玩法数值进 gameplay/skills/drops 三表，不硬编码。
5. 完整清单见 [docs/best-practices.md](docs/best-practices.md)（并发纪律/状态落点/性能红线）。

## PR 流程

1. Fork + 分支（`feat/xxx` / `fix/xxx` / `docs/xxx`）；
2. 门禁全绿 + 新行为有测试覆盖（单测优先，E2E 用 `verify-*.php` 补）；
3. PR 描述写清：动机、方案、验证方式（贴关键测试/verify 输出行）；
4. 涉及 ADR 的改动请同时提交 ADR 文件或在 PR 里说明决策依据。

## 提 Issue

- Bug：附 PHP 版本/OS/复现步骤/关键日志；
- 功能建议：说明玩法场景与期望的接口形状；涉及架构的先看 blueprint 是否已有决策。

## 行为准则

保持专业与善意：对事不对人，讨论聚焦技术方案。项目使用中文与英文双语交流，中文为主。
