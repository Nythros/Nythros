# Map 频道二进制协议（阶段 2）

> 线上传输格式说明。帧类型/负载字段的**语义名与编码的权威字典**是业务层两枚枚举：
> \`packages/demo/src/Protocol/FrameType.php\`（帧类型）与 \`packages/demo/src/Protocol/PayloadKey.php\`（负载字段）。
> 编码一经发布**不得复用**（客户端与服务器共享契约）；新增帧/字段必须同步两端。

## 1. 双通道并存

| 频道 | 序列化 | 用途 |
|---|---|---|
| Map（18081 战斗直连） | \`BinaryBatchSerializer\`（二进制，枚举压缩） | 移动/战斗/视野/拾取 |
| Social（Gateway 18285） | \`JsonSerializer\`（自描述 JSON） | 登录/账号/社交 |

- 服务器到 Map 客户端：每连接每帧**恰好一个二进制批量包**（\`FrameMerger::drain\` 一包多帧）。
- 客户端到服务器：请求以「批量含 1 帧」的批量包发送；\`MapServer::dispatchSafe\` 校验恰好 1 帧，否则 422。
- 单测/降级路径可用 \`JsonBatchSerializer\`（JSON 数组批量或单帧对象，与二进制同构）：harness 与 JSON 社交层互不干扰。

## 2. 批量包布局（全部大端）

\`\`\`
[4B 魔数 "NX" + 0x00 0x01] [4B 帧数 count] { 逐帧: [4B 帧长 len] [len 字节帧体] }
\`\`\`

## 3. 帧体布局

\`\`\`
[2B 字段数] { 逐字段: [2B keyCode] [1B valueType] [值负载] }
\`\`\`

保留 keyCode（高位段，负载字段从 1 起自由分配）：

| keyCode | 字段 | 类型 | 说明 |
|---|---|---|---|
| 0xF3 | type | STRING | 恒有；帧类型名（如 \`entity_moved\`） |
| 0xF2 | requestId | STRING | 可选；有值才编码 |
| 0xF1 | timestamp | FLOAT | 可选；当前默认不编码（客户端以帧边界为时间基准） |

## 4. 值类型（valueType）

| 码 | 类型 | 负载 |
|---|---|---|
| 0x00 | NULL | 无 |
| 0x01 | INT | 有符号 64 位（pack('q')，机器序） |
| 0x02 | FLOAT | 双精度（pack('d')） |
| 0x03 | STRING | [1B 长度] [UTF-8]（≤255B） |
| 0x04 | STRING32 | [4B 长度] [UTF-8]（>255B） |
| 0x05 | LIST | [4B 元素数] { 每元素 [1B 元素类型] [值负载] } |
| 0x06 | POS | [2B int16 x] [2B int16 y]（坐标专用，仅 payload['position'] 形状 \`{x:int, y:int}\`） |
| 0x07 | EMPTY_STRING | 无（空串） |
| 0xF0 / 0xF1 | TRUE / FALSE | 无 |

## 5. 失败路径（快速失败，强制维护枚举）

- 编码时未知帧类型/未知负载字段 → \`ProtocolException\`（业务代码必须扩展对应枚举）。
- 解码时未知 keyCode/值类型/魔数不匹配/截断 → \`DecodeException\`；\`MapServer\` 回 400/422 错误帧。

## 6. 实现位置

- 引擎：\`packages/engine/src/Protocol/BinaryBatchSerializer.php\`（编解码）、\`ProtocolVocabulary.php\`（词表）、
  \`BatchSerializerInterface.php\`（批量契约，extends SerializerInterface）、\`JsonBatchSerializer.php\`（JSON 兼容批量）。
- 业务字典：\`packages/demo/src/Protocol/FrameType.php\`、\`PayloadKey.php\`（中英双语注释）。
- 工厂：\`packages/demo/src/Protocol/MapCodec.php\`（由两枚枚举组装词表，返回二进制序列化器）。
- 传输：\`WorkermanWebSocketServer::handleConnect\` 设置 \`websocketType = BINARY_TYPE_ARRAYBUFFER\`（二进制 WebSocket 帧）。
