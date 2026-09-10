# ADR-030：协议 v2 一次切换与 type 词表压缩（含清单协商地基）

> 状态：已接受

## 背景

带宽审计（`benchmarks/probe-wire-audit.php`，热区画像）发现：二进制帧线上每帧 14-16B 在传**明文帧类型名**
（如 `"entity_moved"`），而词表 `typeCode` 早已存在、编码时只用于校验从未上线——类头宣称「对帧类型做枚举
压缩」与实现不符。热区 60 人单帧批量 2218B 中 type 明文占 720B（32.5%）。同时，ADR-027 的版本守卫只做了
「拒绝」，auth_ok 不回传任何协商结果，`version` 读完即弃——未来协议演进缺一个可校验的对齐面。

## 决策

1. **v2 = 唯一线上形态，一次切换不留 v1**（0.x 无外部存量客户端的窗口期执行）：魔数升 `NX\0\x02`，
   v1 包一律 `DecodeException` 拒绝。协议不养双栈——「同时伺候两形态」的复杂度大于重连风暴的运维成本。
2. **type 字段改 0x08 TYPE_CODE**：keyCode 0xF3 保留、valueType 新增 `0x08`，负载为 1B typeCode
   （词表反查；typeCode≤255，当前 36/88 余量充足）。收益实测：单帧 `entity_moved` 45→33B，
   60 帧批量 2218→1498B（**-32.5%**），decode 吞吐监听项相对提交基线 +290%（含机器漂移，wire 变小为实因）。
3. **协商地基补全（A 模型：拒绝而非适配）**：auth 的 `version` 存下并回显；auth_ok 新增 `version` +
   `manifestVersion`（`MapCodec::manifestVersion()` = typeCodes+keyCodes 双码表 CRC32）；客户端与编译期
   生成物比对，不一致**断开升级**。清单只在编译期进客户端（`generate-definitions.php` 派生 .d.ts/TS +
   JS 码表手工同步铁律），**不做运行时下发**——B 模型（动态清单）被否决：协议变更必然伴随客户端业务代码
   变更，「能解码 ≠ 会处理」，把解码层单独热更只会制造静默裂缝（裁决过程见 CHANGELOG 与本轮对话记录）。
4. **跨语言黄金向量**：同一条 163B hex 钉死在 PHP 侧 `testV2GoldenBytesMatchClientJsCrossEncoder` 与
   JS 侧 `codec.test.mjs`，任何 wire 改动必须两端同步重生成；E2E `verify-phase5` 11/11 通过为切换验收。
5. **码值纪律自 v2 起全量适用**：typeCode/keyCode/valueType 一经发布不得复用、不得改义；
   加事件 = 枚举末尾追加 → manifestVersion 变 → 客户端随版本同步。

## 理由

- 审计先行：TYPE_CODE 是「改一处、全服每帧受益、字节可证」的最大单点；VARINT/1B-keyCode/位图收益更小或
  动结构，各留后续 ADR（位图需重设计 FrameMerger 合并语义，见对话裁决）。
- 一次切与 ADR-027 的「能/不能两态」哲学一致：协议兼容性不做区间协商，只做版本对齐。
- manifestVersion 用码表 CRC32 而非人工号：不可能忘记 bump——码表变指纹必变，守卫不可能失配于漏更版本号。

## 影响

- **破坏性**：v1 客户端/旧包全部失效（预期内）；`NYTHROS_MIN_CLIENT_VERSION=2` 成为推荐装配；
  demo 默认 auth_ok 载荷新增两字段（JSON 侧同样携带，客户端需容忍新增字段——SDK 已对齐）。
- PayloadKey 新增 `manifestVersion=85`，码表 84→85 字段，.d.ts 已再生成。
- 遗留（本 ADR 明确不做）：INT varint 化、keyCode 1B 化、EVENT_BUNDLE 位图、`getPosition` 返对象
  （`EntityInterface` 冻结契约，1.0 议题）。

## 关联

- 前序：[ADR-027 协议版本协商](ADR-027-协议版本协商.md)、[ADR-022 序列化双轨制](ADR-022-序列化双轨制.md)
- 线格式权威：[docs/protocol.md](../../docs/protocol.md) §2-§7
- 证据：`benchmarks/probe-wire-audit.php`、`probe-batch-amort.php`、`results/wire-baseline-v1.json`、
  `testV2GoldenBytesMatchClientJsCrossEncoder`、`verify-phase5`
