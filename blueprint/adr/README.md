# ADR（架构决策记录）目录

> 全部架构决策（ADR-001~026）统一存放在本目录，每份一个文件；
> [blueprint/06-风险与决策记录.md](../06-风险与决策记录.md) 保留风险登记与摘录索引。
> 编号连续、单一权威源：新决策先在本目录落文件，再在 blueprint/06 索引表追加一行。

## 文件命名与结构

- 命名：`ADR-NNN-标题.md`（NNN 三位数字，标题中文短横线连接）。
- 内容结构：背景 → 决策 → 理由（铁律 / Benchmark / 复盘教训）→ 影响/后果 → 状态（提议/已接受/已废弃/被取代）→ 关联文档。
- 被取代或废弃的决策不改编号，在文件头部标注状态与取代者（如 ADR-014 被 ADR-021 取代）。

## 索引

| ADR | 主题 | 状态 |
|---|---|---|
| [ADR-001](ADR-001-Engine与Framework分离.md) | Engine 与 Framework 分离 | 已接受 |
| [ADR-002](ADR-002-Actor作为核心业务执行模型.md) | Actor 作为核心业务执行模型 | 已接受 |
| [ADR-003](ADR-003-Cell作为空间划分基础.md) | Cell 作为空间划分基础 | 已接受 |
| [ADR-004](ADR-004-Gateway与MapServer分离.md) | Gateway 与 MapServer 分离 | 已接受 |
| [ADR-005](ADR-005-先单机后集群.md) | 先单机后集群 | 已接受 |
| [ADR-006](ADR-006-Entity不等于Actor.md) | Entity ≠ Actor | 已接受 |
| [ADR-007](ADR-007-Scheduler为调度核心.md) | Scheduler 为调度核心 | 已接受 |
| [ADR-008](ADR-008-AOI抽象化.md) | AOI 抽象化 | 已接受 |
| [ADR-009](ADR-009-Cluster后置.md) | Cluster 后置 | 已接受 |
| [ADR-010](ADR-010-网络引擎选型.md) | 网络引擎选型：仅 workerman/workerman | 已接受 |
| [ADR-011](ADR-011-开发验收环境Windows迁移WSL.md) | 开发/验收环境 Windows → WSL | 已接受 |
| [ADR-012](ADR-012-RedisTokenStore提前落地.md) | RedisTokenStore 提前落地 | 已接受 |
| [ADR-013](ADR-013-阶段4架构设计.md) | 阶段 4 架构设计 | 已接受 |
| [ADR-014](ADR-014-gateway-worker混合架构.md) | gateway-worker 混合架构 | 被取代（ADR-021） |
| [ADR-015](ADR-015-阶段5社交层细化.md) | 阶段 5 社交层细化 | 已接受 |
| [ADR-016](ADR-016-framework与Demo方向.md) | framework 与 Demo 方向 | 已接受 |
| [ADR-017](ADR-017-framework层设计.md) | framework 层设计 | 已接受 |
| [ADR-018](ADR-018-阶段6发布与生态规划.md) | 阶段 6 发布与生态规划 | 已接受 |
| [ADR-019](ADR-019-包拆分与发布形态.md) | 包拆分与发布形态 | 已接受 |
| [ADR-020](ADR-020-三层产品定位与结构重划.md) | 三层产品定位与结构重划 | 已接受 |
| [ADR-021](ADR-021-移除gateway-worker统一网关栈.md) | 移除 gateway-worker 统一网关栈（自研单栈） | 已接受（取代 ADR-014） |
| [ADR-022](ADR-022-序列化双轨制.md) | 序列化双轨制（Map 二进制 / Social JSON） | 已接受 |
| [ADR-023](ADR-023-engine可见性标注政策.md) | engine 可见性标注政策（@internal 门禁） | 已接受 |
| [ADR-024](ADR-024-RoomInstance与AoE批量管线设计.md) | RoomInstance 与 AoE 批量管线设计 | 已接受 |
| [ADR-025](ADR-025-跨map实体迁移协议.md) | 跨 map 实体迁移协议 | 已接受 |
| [ADR-026](ADR-026-热更层裁决.md) | 热更层裁决（不立项，配置热载 + rolling + GM 三件套） | 已接受 |
| [ADR-027](ADR-027-协议版本协商.md) | 协议版本协商（auth 携带 version + 最低版本守卫） | 已接受 |
| [ADR-028](ADR-028-Redis单点风险与HA路线.md) | Redis 单点风险与 HA 路线（认证已交付，哨兵按需立项） | 已接受 |

## 给新贡献者的约定

1. 影响公开 API、分层依赖、协议编码、性能基线的改动必须先落 ADR 再实现；
2. 提案阶段即可提 PR 到本目录（状态：提议），合并实现时改为已接受；
3. 分层判别口诀见 [`32-架构分层审计报告.md`](../32-架构分层审计报告.md) §6。
