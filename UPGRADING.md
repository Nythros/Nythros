# 升级指南（UPGRADING）

本文件记录 **破坏性变更的迁移说明**。变更明细见 [CHANGELOG.md](CHANGELOG.md)；设计决策见
[blueprint/adr/](blueprint/adr/README.md)。版本策略：0.x 期间 minor 版本可携带 breaking change
（本文件逐项给出迁移路径）；进入 1.0 后 breaking 只允许出现在 major 版本。

> 面向：从旧版本 Nythros 升级的服务端装配方与客户端接入方。
> `Nythros\Contracts` 契约接口自 v0.1.0 起冻结（变更须走 ADR）；engine/framework 的
> `@internal` 符号不构成 API 承诺（CI 门禁 `composer internal` 强制），升级时可能无预告变动。

## 0.1.0 → 0.2.0（协议 v2 一次切换）

唯一破坏性变更：**线协议 v2 一次切换（ADR-030）**。v1 帧不再被接受，服务端/客户端必须同步升级。

### 服务端

- 无代码改动：demo 装配已默认 v2；升级后 v1 客户端连接将被 `DecodeException` 拒绝（预期行为）。
- **推荐装配**：设置 `NYTHROS_MIN_CLIENT_VERSION=2`，在 authenticate 之前拒绝旧版本客户端
  （不给旧客户端任何认证计算量；未设置 = 版本守卫不启用）。

### 客户端（JS/TS / Unity / 自研）

1. **魔数**：`NX\0\x01` → `NX\0\x02`。旧魔数与 v1 包一律 `DecodeException` 拒绝——协议不养双栈。
2. **type 字段改 1B 词表码（0x08 TYPE_CODE）**：线上不再传明文类型名（如 `"entity_moved"`），
   改传 1B typeCode（词表反查）。收益实测：单帧 `entity_moved` 45→33B，热区 60 帧批量 -32.5%。
3. **auth 载荷新增 `version` 字段**：客户端自报协议版本；auth_ok 新增回显 `version` +
   `manifestVersion`（双码表 CRC32 指纹）。客户端须与编译期码表比对，**不一致即断开升级**
   （A 模型：拒绝而非适配；运行时下发清单被裁决否决——「能解码」≠「会处理」）。
   JSON 侧同样携带新增字段，客户端解析须容忍未知字段。
4. **STRING 边界 255**：Unity 参考实现同步修正了 v1 时代预存的 256 边界笔误——自研客户端
   自查字符串长度字段是否按 255 上限编解码（见 protocol.md）。

对接清单：

- 官方 JS SDK：[@nythros/client](packages/client-js/README.md) 已对齐 v2（`protocolVersion` 缺省升 2）。
- Unity：参考实现 [clients/unity/NythrosClient.cs](clients/unity/NythrosClient.cs) 已同步 v2
  （MAGIC 0x02、1B TYPE_CODE 编/解）。
- 自研客户端：按 [docs/protocol.md](docs/protocol.md) §2-§4/§7 新表接入；跨语言黄金向量（163B hex）
  钉死在 PHP `testV2GoldenBytesMatchClientJsCrossEncoder` 与 JS `codec.test.mjs`，改 wire 必两端同步重生成。

## 0.1.0 → 0.2.0（可选新增，非破坏）

以下为增量能力，按需采用；不 adopting 时行为与 0.1.0 一致：

- **Redis 哨兵 HA 与连接自愈（ADR-031）**：设置 `NYTHROS_REDIS_SENTINELS` 后 worker 直连切换为
  哨兵解析 + 主变原地重指向 + 失活自动重连（自愈上界 = 切换完成 + 5s）；未配置即直连，行为不变。
  经济域权威写可另开 `NYTHROS_REDIS_AWAIT_REPLICAS=1`（副本确认屏障，缺省关闭）。
  详见 [docs/deployment.md](docs/deployment.md) §6/§8.4。

## 开发者迁移（测试基建 0.2.0）

- demo/engine 测试共用的测试替身迁入独立开发包 **nythros/testing**（`Nythros\Testing` 命名空间，
  PSR-4 单类文件）：测试代码删除 `require_once` 引入，改为 autoload + `use Nythros\Testing\...;`。
  该包仅 monorepo `require-dev` 消费，不进入任何运行时依赖链。
