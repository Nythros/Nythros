# Unity/C# 客户端接入指南

> 面向读者：用 Unity（或其他 .NET 环境）对接 Nythros 服务器的客户端程序。读完你能：把参考客户端
> 跑通冒烟链路（登录 → 进图 → 移动 → attack→combat:hit）、理解协议对接的硬约定、知道哪些能力
> 需要参考 JS SDK 移植。

## 1. 定位与现状

Nythros 官方完整 SDK 目前只有 JS/TS（`@nythros/client`，见 [client-js README](https://github.com/nythros/nythros/tree/master/packages/client-js/README.md)）；
Unity 侧提供的是**参考实现**：[clients/unity/NythrosClient.cs](https://github.com/nythros/nythros/tree/master/clients/unity/NythrosClient.cs)
（单文件、零外部依赖，覆盖二进制协议编解码 + 登录链路 + 事件订阅 + requestId 回执）。

明确的能力边界（诚实声明）：

- 参考实现**未进 CI 编译验证**（仓库无 C# 工具链）——接入时先跑 §4 冒烟，发现问题按 §5 提 issue/PR；
- 插值引擎、自动重连未包含——按 [state-sync](state-sync.md) §3/§4 的规则与 JS SDK 的参考实现移植；
- 码表只内置冒烟链路所需子集——正式接入前先做 §3 的码表补全。

## 2. 协议对接的硬约定（客户端无关）

无论什么客户端，这五条是协议契约（权威文档：[protocol](protocol.md)）：

| 约定 | 内容 |
|---|---|
| 双通道 | 网关（18285）JSON 文本帧：登录换 token；Map（18081~18084）二进制批量帧：全部游戏内容 |
| 批量包布局 | `[4B 魔数 "NX\0\x01"][4B 帧数]{逐帧: [4B 帧长][帧体]}`，长度字段**大端** |
| 帧体布局 | `[2B 字段数]{逐字段: [2B keyCode][1B valueType][值负载]}`；`type`=0xF3、`requestId`=0xF2 保留键 |
| 字节序 | 长度大端；int64/double **小端**（PHP `pack('q'/'d')` 机器序）；POS 两个 int16 **大端** |
| 码表权威 | 帧名/字段名→码值由 `FrameType::codeMap()` / `PayloadKey::codeMap()` 生成，**一经发布不得复用改义** |

请求语义（与 JS SDK 一致）：`move`/`attack`/`logout` 无成功回执帧——火发 + 订阅世界帧确认；
错误帧/`room:ok` 回显 requestId；`quest:list` 这类只回定类型帧（`quest:rows`，不带 requestId）。

## 3. 码表补全（接入前必做）

`NythrosClient.cs` 只内置了 20 帧 / 15 字段的冒烟子集。正式接入按所用玩法补全：

```bash
# TS 码表的生成脚本（C# 侧同源可仿写）：从 PHP 枚举 codeMap() 生成
php packages/client-js/scripts/generate-definitions.php
```

把 `packages/demo/src/Protocol/FrameType.php` / `PayloadKey.php` 的 `codeMap()` 输出补进 C# 的
`FrameTypes` / `PayloadKeys` 两个字典（或仿照该脚本写一个 C# 生成器——欢迎 PR）。
缺帧会在编码时抛 `NythrosProtocolException`，不会静默错码。

## 4. 三步冒烟（接入首日验收）

服务器启动与账号见 [quick-start](quick-start.md)（演示账号 `1001/secret`）。

```csharp
var client = new Nythros.NythrosClient("1001", "secret");
client.On("combat:hit", f => UnityEngine.Debug.Log($"hit: {f.Payload["damage"]}"));

var (entityId, token) = await client.ConnectAsync();          // ① 登录链路（gateway → token → Map）
await client.SendAsync("move", new() { ["dx"] = 100, ["dy"] = 100 });   // ② 火发移动（无回执）
await client.SendAsync("attack", new() { ["targetId"] = "monster-1" }); // ③ 火发攻击 → On("combat:hit") 到达
await client.RequestAsync("quest:list", new(), replyType: ...) // 有回执路由见 §6
client.Dispose();
```

验收点：① `ConnectAsync` 返回非空 entityId；② 服务器视野出现 `entity_moved`/`entity_enter` 流；
③ `combat:hit` 事件到达且 damage 合理。三步全过即协议层对接成功。

Unity 传输层注意：`System.Net.WebSockets.ClientWebSocket` 在部分平台（WebGL）不可用——
WebGL 目标换 NativeWebSocket 包，只替换传输，`NythrosCodec` 编解码层与传输解耦、直接复用。

## 5. 插值与重连（移植指引）

- **插值**：按 [state-sync](state-sync.md) §3——玩家实体用小固定窗口（100ms），怪物用到达间隔 EMA × 1.5，
  自身实体消费 `world:tick_rate` 放大窗口；渲染读 `sample`，判定读权威位置。逐行参考实现：
  `packages/client-js/nythros-client.js` 的 `NythrosInterpolator`。
- **实体表纪律**：只增删于 `entity_enter`/`entity_leave`/`monster:spawned`；未知 id 的 `entity_moved`
  静默忽略；已登记实体再收 `entity_enter` = 快照吸附（不跳变）（state-sync §2/§4）。
- **重连**：JS SDK 的语义是「重连即同图迁移」——断线整链重登，服务端 detach 时导出转移票据，
  attach 恢复位置/血量/背包；重连后实体表必须清空重建。C# 侧按此规则实现即可与服务器语义对齐。

## 6. 回执双模式（C# 侧现状与扩展）

参考实现内置 **requestId 回显模式**（`RequestAsync`，适用于错误帧、room:ok 等回显路由）。
**replyType 帧类型匹配模式**（`quest:list` → `quest:rows` 这类不带 requestId 的回执）需自行扩展：
订阅该类型首帧即 resolve（参考 JS SDK `request(type, payload, {replyType})` 的实现——
临时订阅、首帧即退订）。

## 7. 已知差异与风险清单

- ⚠ 未编译验证：参考实现按 protocol.md/state-sync.md 逐条编写，但没有 CI 编译——首个接入团队
  请把发现的问题回馈上游（`clients/unity/` 修改 + 一条冒烟用例说明即可）。
- ⚠ 码表子集：未补全 §3 前只能跑冒烟链路。
- ⚠ WebSocket 分帧：ReadLoop 按 EndOfMessage 聚合，服务端单帧 ≤ 64KB（批量包设计上限）；若服务器
  未来出现超包，需要改成分块缓冲（协议层已预留 STRING32 长度字段）。
- 二进制帧 `binaryType`：JS 侧需要 `arraybuffer`；C# ClientWebSocket 收到的就是 binary 消息，无需处理。
