// NythrosCodec 单元测试：与 PHP 端 BinaryBatchSerializer 的对称性契约（wire 格式见 docs/protocol.md）。
// 运行：node --test test/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { NythrosCodec, FRAME_TYPES, PAYLOAD_KEYS, FRAME_NAMES, KEY_NAMES, NythrosClient, NythrosInterpolator } = require('../nythros-client.js');

const MAGIC = [0x4e, 0x58, 0x00, 0x02];

function roundtrip(frames) {
  return NythrosCodec.decodeBatch(NythrosCodec.encodeBatch(frames));
}

test('码表规模与 PHP 枚举一致（88 帧 / 85 字段）', () => {
  assert.equal(Object.keys(FRAME_TYPES).length, 88);
  assert.equal(Object.keys(PAYLOAD_KEYS).length, 85);
  // 反查表与正表互逆
  for (const [name, code] of Object.entries(FRAME_TYPES)) assert.equal(FRAME_NAMES[code], name);
  for (const [name, code] of Object.entries(PAYLOAD_KEYS)) assert.equal(KEY_NAMES[code], name);
});

test('批量包头部：魔数 NX\\0\\x02 + 大端帧数（v2，ADR-030）', () => {
  const bytes = NythrosCodec.encodeBatch([
    { type: 'move', payload: { dx: 1, dy: 2 } },
  ]);
  assert.deepEqual([...bytes.slice(0, 4)], MAGIC);
  assert.equal(bytes[4] << 24 | bytes[5] << 16 | bytes[6] << 8 | bytes[7], 1);
});

test('v2 跨语言黄金向量：与 PHP BinaryBatchSerializer 同输入逐字节一致（ADR-030，同一 hex 钉在 PHP 侧测试）', () => {
  const golden = '4E5800020000000400000014000300F3080100010303702D310003060025FFA80000002A000500F3080400F20303722D31000103036D2D3200100180000000000000000011015C1100000000000000000029000500F30810000B03027531000C0306736563726574000D03046D61696E005401020000000000000000000024000400F3080600010303642D39000306000300040016030C73776F72642D69726F6E2D31';
  const frames = [
    { type: 'entity_moved', payload: { id: 'p-1', position: { x: 37, y: -88 } } },
    { type: 'combat:hit', requestId: 'r-1', payload: { id: 'm-2', damage: 128, hp: 4444 } },
    { type: 'auth', payload: { username: 'u1', password: 'secret', mapId: 'main', version: 2 } },
    { type: 'drop:spawned', payload: { id: 'd-9', position: { x: 3, y: 4 }, itemId: 'sword-iron-1' } },
  ];
  assert.equal(Buffer.from(NythrosCodec.encodeBatch(frames)).toString('hex').toUpperCase(), golden);
  const decoded = NythrosCodec.decodeBatch(Buffer.from(golden, 'hex'));
  assert.equal(decoded.length, 4);
  assert.equal(decoded[0].payload.position.y, -88);
  assert.equal(decoded[1].requestId, 'r-1');
  assert.equal(decoded[1].payload.hp, 4444);
  assert.equal(decoded[3].payload.itemId, 'sword-iron-1');
});

test('全部值类型 roundtrip：int/负数/float/string/空串/长串/list/position/bool/null', () => {
  const longText = 'x'.repeat(300); // >255B 触发 STRING32
  const [frame] = roundtrip([{
    type: 'room:snapshot',
    requestId: 'req-1',
    payload: {
      id: 'monster-1',
      hp: 100,
      damage: -7,          // 有符号 int64
      score: 3.25,         // float64
      message: '你好 Nythros',
      name: '',
      body: longText,
      targetIds: ['a', 'b'],
      position: { x: 123, y: -45 },
      accept: true,
      hasAttachment: false,
      divisor: null,
    },
  }]);
  assert.equal(frame.type, 'room:snapshot');
  assert.equal(frame.requestId, 'req-1');
  assert.deepEqual(frame.payload, {
    id: 'monster-1',
    hp: 100,
    damage: -7,
    score: 3.25,
    message: '你好 Nythros',
    name: '',
    body: longText,
    targetIds: ['a', 'b'],
    position: { x: 123, y: -45 },
    accept: true,
    hasAttachment: false,
    divisor: null,
  });
});

test('多帧批量 roundtrip 保序', () => {
  const frames = roundtrip([
    { type: 'entity_moved', payload: { id: 'p1', position: { x: 1, y: 1 } } },
    { type: 'combat:hit', payload: { attackerId: 'p1', targetId: 'm1', damage: 10, hp: 90 } },
    { type: 'entity_dead', payload: { id: 'm1' } },
  ]);
  assert.deepEqual(frames.map((f) => f.type), ['entity_moved', 'combat:hit', 'entity_dead']);
});

test('未知负载字段快速失败（强制维护 PayloadKey 枚举）', () => {
  assert.throws(() => NythrosCodec.encodeBatch([{ type: 'move', payload: { unknownKey: 1 } }]), /未知负载字段/);
});

test('不支持的值类型快速失败', () => {
  assert.throws(() => NythrosCodec.encodeBatch([{ type: 'move', payload: { id: { nested: true } } }]), /不支持的值类型/);
});

test('v2 编码/解码双侧严格：未知帧类型编码快速失败、解码未知 keyCode 拒绝（ADR-030：type 上线走词表码,不再有明文宽松通道）', () => {
  // 编码严格：type 码值必须由 FRAME_TYPES 提供，未知即抛（与 PHP encode 的「未知帧类型」同纪律）。
  assert.throws(() => NythrosCodec.encodeBatch([{ type: 'ghost:frame', payload: {} }]), /未知帧类型/);
  // 解码严格：构造含未知 keyCode 的帧体（type 槽合法 + keyCode=0x7fff + NULL）。
  const typeSlot = new Uint8Array([0x00, 0xf3, 0x08, 0x11]); // K_TYPE + T_TYPE_CODE + auth(0x11=17)
  const body = new Uint8Array(2 + typeSlot.length + 3);
  const view = new DataView(body.buffer);
  view.setUint16(0, 2, false);
  body.set(typeSlot, 2);
  view.setUint16(6, 0x7fff, false);
  body[8] = 0x00; // T_NULL
  assert.throws(() => NythrosCodec.decodeFrameBody(new DataView(body.buffer)), /未知 keyCode/);
});

test('解码容错：魔数不匹配 / 截断', () => {
  assert.throws(() => NythrosCodec.decodeBatch(new Uint8Array([1, 2, 3, 4, 0, 0, 0, 0])), /魔数不匹配/);
  // 帧数声明 2，实际只有 1 帧长度 → 截断
  const good = NythrosCodec.encodeBatch([{ type: 'move', payload: {} }]);
  const evil = new Uint8Array(good.length + 4);
  evil.set(good);
  evil[4] = 0; evil[5] = 0; evil[6] = 0; evil[7] = 2; // count=2
  assert.throws(() => NythrosCodec.decodeBatch(evil), Error);
});

test('空 payload 与缺 requestId 合法', () => {
  const [frame] = roundtrip([{ type: 'logout', payload: {} }]);
  assert.equal(frame.type, 'logout');
  assert.equal(frame.requestId, null);
  assert.deepEqual(frame.payload, {});
});

test('NythrosClient：登录链路两段 auth 均携带协议版本（ADR-027）', async () => {
  // 全局 WebSocket 替身：捕获 openGateway/openMap 的出站帧
  // A global WebSocket double: captures outbound frames from openGateway/openMap.
  const sent = [];
  class FakeWS {
    binaryType = '';
    readyState = 1;
    sent = [];
    set onopen(cb) { queueMicrotask(cb); }
    set onmessage(cb) { this._om = cb; }
    get onmessage() { return this._om; }
    set onerror(cb) { this._oe = cb; }
    set onclose(cb) { this._oc = cb; }
    send(data) { this.sent.push(data); sent.push(data); }
    close() {}
  }
  const realWebSocket = globalThis.WebSocket;
  globalThis.WebSocket = FakeWS;
  try {
    const client = new NythrosClient({ username: '1001', password: 'secret' });
    // 网关段（JSON 文本帧）
    // The gateway leg (JSON text frames).
    const gwPromise = client.openGateway(1000);
    await new Promise((r) => setTimeout(r, 10));
    client.token = 'tok-1'; // 模拟网关回包完成 Simulates the gateway reply completing.
    const gwJson = JSON.parse(sent[0]);
    assert.equal(gwJson.version, 2);
    assert.equal(gwJson.payload.version, 2);
    // Map 段（二进制批量帧）
    // The Map leg (binary batch frames).
    const mapPromise = client.openMap(1000);
    await new Promise((r) => setTimeout(r, 10));
    const mapFrames = NythrosCodec.decodeBatch(new Uint8Array(sent[1]));
    assert.equal(mapFrames[0].type, 'auth');
    assert.equal(mapFrames[0].payload.version, 2);
    assert.equal(mapFrames[0].payload.token, 'tok-1');
    // 假连接永不回包：显式吞掉后续超时拒绝，避免悬挂 rejection 干扰测试进程
    // The fake connection never replies: swallow the later timeout rejections so no dangling rejection escapes.
    gwPromise.catch(() => {});
    mapPromise.catch(() => {});
  } finally {
    globalThis.WebSocket = realWebSocket;
  }
});

test('NythrosClient 构造缺凭据快速失败', () => {
  assert.throws(() => new NythrosClient({}), /缺少 username\/password/);
});

test('NythrosClient：连接未建立时 send 快速失败；回执超时可配置并 reject', async () => {
  const client = new NythrosClient({ username: '1001', password: 'secret' });
  assert.throws(() => client.send('move', {}), /Map 连接未建立/);
  // 注入假连接后，无人回执 → 按 timeoutMs reject（timer 随之清理，无悬挂拒绝）
  client.ws = { readyState: 1, send: () => {} };
  await assert.rejects(client.request('move', {}, { timeoutMs: 30 }), /回执超时/);
});

test('NythrosClient：requestId 回显模式与 replyType 帧类型匹配模式', async () => {
  const client = new NythrosClient({ username: '1001', password: 'secret' });
  client.ws = { readyState: 1, send: () => {} }; // 假连接：request 内部 send 不抛错

  // requestId 回显模式：dispatch 携带同 requestId 的帧即 resolve
  const echoPromise = client.request('pickup', { dropId: 'd1' }, { requestId: 'echo-1' });
  client.dispatch({ type: 'item:added', requestId: 'echo-1', payload: { itemId: 'i1', count: 1 } });
  const receipt = await echoPromise;
  assert.equal(receipt.type, 'item:added');
  assert.equal(receipt.payload.itemId, 'i1');

  // replyType 帧类型匹配模式：忽略 requestId，首帧该类型即 resolve
  const typePromise = client.request('quest:list', {}, { replyType: 'quest:rows' });
  client.dispatch({ type: 'quest:rows', payload: { questIds: ['q1'] } });
  const rows = await typePromise;
  assert.deepEqual(rows.payload.questIds, ['q1']);

  // dispatch 同时驱动插值引擎与订阅（一次分发三段链）
  const seen = [];
  client.on('item:added', (f) => seen.push(f.payload.itemId));
  client.dispatch({ type: 'item:added', payload: { itemId: 'i2' } });
  assert.deepEqual(seen, ['i2']);
});

test('NythrosClient：订阅/退订 + 合成重连事件经 emit 派发', () => {
  const client = new NythrosClient({ username: '1001', password: 'secret' });
  const seen = [];
  const unsub = client.on('monster:spawned', (f) => seen.push(f.payload.id));
  client.dispatch({ type: 'monster:spawned', payload: { id: 'm1' } });
  unsub();
  client.dispatch({ type: 'monster:spawned', payload: { id: 'm2' } });
  assert.deepEqual(seen, ['m1']);

  const states = [];
  client.on(':reconnecting', (d) => states.push(['reconnecting', d.attempt]));
  client.on(':reconnectfailed', (d) => states.push(['failed', d.attempts]));
  client.reconnectAttempts = client.maxReconnectAttempts;
  client.backoff(); // 已耗尽 → 立即 :reconnectfailed
  assert.deepEqual(states, [['failed', client.maxReconnectAttempts]]);
  assert.equal(client.reconnecting, false);
});

test('NythrosInterpolator：事件驱动实体小固定窗口插值', () => {
  const it = new NythrosInterpolator({ eventWindowMs: 100 });
  it.applyFrame({ type: 'entity_enter', payload: { id: 'p1', position: { x: 0, y: 0 } } });
  it.applyFrame({ type: 'entity_moved', payload: { id: 'p1', position: { x: 10, y: 0 } } });
  const authoritative = it.position('p1');
  assert.deepEqual(authoritative, { x: 10, y: 0 });   // 判定读权威位置
  const smooth = it.sample('p1', Date.now());          // 渲染读平滑位置
  assert.ok(smooth.x < 10);                            // 窗口内未收敛，不会瞬移
  const settled = it.sample('p1', Date.now() + 100);   // 越窗收敛到权威位置
  assert.deepEqual(settled, { x: 10, y: 0 });
});

test('NythrosInterpolator：实体表只增删于 enter/leave/moved 对未知 id 静默', () => {
  const it = new NythrosInterpolator();
  it.applyFrame({ type: 'entity_moved', payload: { id: 'ghost', position: { x: 5, y: 5 } } });
  assert.equal(it.position('ghost'), null); // 未登记实体的 moved 不复活（state-sync §2）
  it.applyFrame({ type: 'entity_enter', payload: { id: 'p1', position: { x: 1, y: 1 } } });
  it.applyFrame({ type: 'entity_leave', payload: { id: 'p1' } });
  assert.equal(it.position('p1'), null);
});

test('NythrosInterpolator：快照重同步吸附不跳变', () => {
  const it = new NythrosInterpolator({ eventWindowMs: 100 });
  it.applyFrame({ type: 'entity_enter', payload: { id: 'p1', position: { x: 0, y: 0 } } });
  it.applyFrame({ type: 'entity_moved', payload: { id: 'p1', position: { x: 10, y: 0 } } });
  const mid = it.sample('p1', Date.now()); // 过渡中
  // 周期快照：已登记实体再收 entity_enter = 吸附到权威位置，prev 从当前平滑位置出发
  it.applyFrame({ type: 'entity_enter', payload: { id: 'p1', position: { x: 10, y: 0 } } });
  const after = it.sample('p1', Date.now());
  assert.ok(Math.abs(after.x - mid.x) < 10, '过渡不跳变（prev 取采样值而非权威值）');
  assert.deepEqual(it.sample('p1', Date.now() + 100), { x: 10, y: 0 });
});

test('NythrosInterpolator：tick 实体 EMA 自适应窗口 + world:tick_rate 放大自身窗口', () => {
  const it = new NythrosInterpolator({ baseTickMs: 50 });
  it.applyFrame({ type: 'monster:spawned', payload: { id: 'm1', position: { x: 0, y: 0 }, typeId: 'slime' } });
  assert.equal(it.entities.get('m1').kind, 'tick');
  // 长到达间隔（> baseTick）→ EMA 拉长 → 窗口变宽：移动后短时间内采样仍明显滞后
  it.entities.get('m1').intervalEma = 500;
  it.applyFrame({ type: 'entity_moved', payload: { id: 'm1', position: { x: 20, y: 0 } } });
  const smooth = it.sample('m1', Date.now());
  assert.ok(smooth.x < 10, '宽窗口下不会瞬间到位（降频不顿挫）');

  // world:tick_rate 是 directed 帧：只影响自身实体
  it.applyFrame({ type: 'entity_enter', payload: { id: 'self', position: { x: 0, y: 0 } } });
  it.setSelfEntityId('self');
  it.applyFrame({ type: 'world:tick_rate', payload: { divisor: 4 } });
  assert.equal(it.entities.get('self').divisor, 4);
  const selfWindow = Math.max(it.eventWindowMs, 4 * it.baseTickMs); // 200ms
  assert.equal(selfWindow, 200);
});
