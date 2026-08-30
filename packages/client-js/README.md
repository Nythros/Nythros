# nythros-client（JS SDK v0.1）

Nythros 官方 JS 客户端 SDK：零依赖单文件，Node ≥ 22（原生 WebSocket）与浏览器通用。
从早期协议参考实现提炼，码表由 PHP 枚举自动生成保持同源。

## 能力

| 模块 | 说明 |
|---|---|
| `NythrosCodec` | 二进制批量协议编解码器，与 PHP 端 `MapCodec`/`BinaryBatchSerializer` 一一对称（码表由 `FrameType::codeMap()`/`PayloadKey::codeMap()` 同步，88 帧 / 84 字段）。 |
| `NythrosClient` | 登录链路（gateway JSON 文本 auth → token → Map 二进制 auth → entityId）+ 事件订阅（`on/off`）+ 请求回执（`request` 双模式：`replyType` 帧类型匹配 / 缺省 requestId 回显匹配）。 |
| `NythrosInterpolator` | 插值引擎：事件驱动实体（玩家输入）小固定窗口；tick 驱动实体（怪物）按到达间隔 EMA 自适应窗口——区域密度降频下不顿挫；消费 `world:tick_rate` 分频帧放大自身窗口。参考实现说明见 [docs/state-sync.md](../../docs/state-sync.md)。 |

## 快速开始

```js
const { NythrosClient } = require('./nythros-client.js');

const client = new NythrosClient({ username: '1001', password: 'secret' });
const { entityId } = await client.connect();

client.on('monster:spawned', (f) => console.log('怪物出生', f.payload));
await client.request('quest:list');          // 有回执的路由（quest:*/room:*/economy:* ...）
client.send('move', { dx: 100, dy: 100 });   // 火发无回执的路由（move/attack：位置/命中以世界帧为准）
client.send('attack', { targetId: 'monster-1' });

const smoothed = client.interpolator.sample(entityId);   // 插值位置（渲染层用）
const exact = client.interpolator.position(entityId);    // 最新权威位置
client.close();
```

## 测试

```bash
npm test    # node:test（零依赖）：编解码器 roundtrip / 插值引擎 / 回执关联 / 重连事件；协议回归与 PHP 端同源
```

## 示例

- `examples/mmorpg-flow.js`：登录/移动/攻击流（含 quest:list 回执与插值采样展示）；
- `examples/reconnect-demo.js`：断线自动重连 + 转移票据恢复；
- `examples/mmorpg-canvas.html`：图形化示例客户端（浏览器打开，canvas 渲染 + WASD/点击移动 + 攻击最近怪物 + 重连状态）。

验收示例（reconnect-demo）：

```bash
# 服务器（WSL 内）：NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 php bin/server start
node packages/client-js/examples/mmorpg-flow.js
# 预期末行：RESULT: PASS（登录/移动/攻击流全链走通）
```

## 语义约定

- **request vs send**：`move`/`attack`/`logout` 无成功回执帧（仅错误帧带 requestId）——用 `send` 火发并
  订阅世界帧（`entity_moved`/`combat:hit`）确认；有回执的路由用 `request`。**回执匹配双模式**：
  服务器回执语义不统一——部分路由回显 requestId（错误帧、room:ok），部分只发定类型帧不带 requestId
  （`quest:list` → `quest:rows`）；后者必须传 `opts.replyType` 按帧类型匹配，前者用缺省 requestId 匹配。
  多帧结果（如领奖后 item:added + quest:result）请用 `on` 订阅。
- **网关帧形态**：网关回帧实测为二进制帧（PHP 端 json_encode 经 WebSocket 发送），SDK 已做
  Blob/ArrayBuffer 归一；网关校验 `timestamp` 必须为数字（SDK 自动携带）。
- **插值**：渲染层一律读 `interpolator.sample()`；判定逻辑（攻击距离等）读 `position()`（权威位置）。
- **重连**：SDK 已内置自动重连（重连即同图迁移：携转移票据重登 → 恢复实体表，`examples/reconnect-demo.js`
  为验收示例）；断线期间 `pending` 全部 reject，重连成功后世界帧恢复推送。
