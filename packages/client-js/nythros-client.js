/**
 * nythros-client —— Nythros 官方 JS SDK v0.1（零依赖，Node ≥ 22 原生 WebSocket / 浏览器通用）。
 * nythros-client — the official Nythros JS SDK v0.1 (zero-dependency; Node >= 22 native WebSocket / browser compatible).
 *
 * 从早期协议参考实现提炼，补齐三块能力：
 * Extracted from the early protocol reference implementation with three capability blocks added:
 *  ① 登录链路：gateway(JSON 文本) auth → token → Map(二进制批量) auth → entityId；
 *     The login chain: gateway (JSON text) auth -> token -> Map (binary batch) auth -> entityId.
 *  ② 事件订阅 + request 回执关联（requestId 逐帧回显，首帧匹配即 resolve）；
 *     Event subscription + request-receipt correlation (requestId echoes per frame; the first match resolves).
 *  ③ 插值引擎（消费 world:tick_rate，事件驱动/tick 驱动实体分开插值——见 docs/state-sync.md）。
 *     The interpolation engine (consumes world:tick_rate; event-driven and tick-driven entities interpolate
 *     separately — see docs/state-sync.md).
 *
 * 码表同步铁律：FRAME_TYPES/PAYLOAD_KEYS 由 packages/demo/src/Protocol/FrameType.php / PayloadKey.php
 * 的 codeMap() 生成（一经发布不得复用，新增帧/字段必须同步 PHP 枚举与本表）。
 * Code-table rule: FRAME_TYPES/PAYLOAD_KEYS are generated from FrameType.php / PayloadKey.php's codeMap()
 * (released codes are never reused; new frames/keys must update the PHP enums AND these tables in lockstep).
 *
 * 字节序：int64/float 小端（PHP pack('q')/pack('d') 机器序）；u16/u32 长度字段大端（pack('n')/pack('N')）。
 * Byte order: int64/float little-endian (PHP pack('q')/pack('d') machine order); u16/u32 lengths big-endian.
 */
'use strict';

// ── 帧类型码表（与 FrameType::codeMap() 一致，新增帧同步两处） ──
// ── Frame-type codes (mirrors FrameType::codeMap(); add frames in both places) ──
const FRAME_TYPES = {
  "entity_moved": 1, "entity_enter": 2, "entity_leave": 3, "combat:hit": 4,
  "skill:cast": 5, "drop:spawned": 6, "drop:removed": 7, "item:added": 8,
  "entity_dead": 9, "monster:spawned": 10, "player:stats": 11, "combat:error": 12,
  "error": 13, "auth_ok": 14, "auth_failed": 15, "auth": 16,
  "move": 17, "attack": 18, "pickup": 19, "logout": 20,
  "combat:aoe": 21, "drop:spawned_batch": 22, "room:snapshot": 23, "room:closed": 24,
  "room:member_enter": 25, "room:member_leave": 26, "room:left": 27, "room:ok": 28,
  "room:create": 29, "room:join": 30, "room:spawn": 31, "room:aoe": 32,
  "room:settle": 33, "room:close": 34, "gm:exec": 35, "gm:result": 36,
  "gm:broadcast": 37, "equip": 38, "unequip": 39, "economy:result": 40,
  "mail:new": 41, "mail:list": 42, "mail:claimed": 43, "auction:sell": 44,
  "auction:buy": 45, "auction:cancel": 46, "economy:deposit": 47, "mail:claim": 48,
  "mail:delete": 49, "friend:apply": 50, "friend:accept": 51, "friend:reject": 52,
  "friend:remove": 53, "friend:list": 54, "friend:ok": 55, "friend:error": 56,
  "friend:notify": 57, "guild:create": 58, "guild:disband": 59, "guild:kick": 60,
  "guild:promote": 61, "guild:notice": 62, "guild:apply": 63, "guild:approve": 64,
  "guild:ok": 65, "guild:error": 66, "guild:notify": 67, "leaderboard:top": 68,
  "leaderboard:rows": 69, "leaderboard:rank": 70, "leaderboard:ranked": 71, "buff:apply": 72,
  "buff:remove": 73, "buff:applied": 74, "buff:expired": 75, "matching:enqueue": 76,
  "matching:cancel": 77, "matching:matched": 78, "matching:ok": 79, "quest:list": 80,
  "quest:rows": 81, "quest:claim": 82, "quest:talk": 83, "quest:result": 84,
  "entity_dead_batch": 85, "player:revive": 86, "skill:cast_aoe": 87, "world:tick_rate": 88,
};

const PAYLOAD_KEYS = {
  "id": 1, "uid": 2, "position": 3, "x": 4,
  "y": 5, "dx": 6, "dy": 7, "token": 8,
  "code": 9, "message": 10, "username": 11, "password": 12,
  "mapId": 13, "attackerId": 14, "targetId": 15, "damage": 16,
  "hp": 17, "maxHp": 18, "casterId": 19, "skillId": 20,
  "dropId": 21, "itemId": 22, "count": 23, "typeId": 24,
  "reason": 25, "targetIds": 26, "damages": 27, "hps": 28,
  "dropIds": 29, "itemIds": 30, "positions": 31, "roomId": 32,
  "memberIds": 33, "op": 34, "cx": 35, "cy": 36,
  "r": 37, "command": 38, "slot": 39, "price": 40,
  "auctionId": 41, "mailId": 42, "title": 43, "body": 44,
  "hasAttachment": 45, "attachments": 46, "mailIds": 47, "titles": 48,
  "bodies": 49, "hasAttachments": 50, "targetUid": 51, "fromUid": 52,
  "action": 53, "type": 54, "name": 55, "maxMembers": 56,
  "role": 57, "notice": 58, "accept": 59, "uids": 60,
  "boardId": 61, "n": 62, "offset": 63, "ranks": 64,
  "scores": 65, "rank": 66, "score": 67, "guildId": 68,
  "buffId": 69, "stacks": 70, "durationSeconds": 71, "queueId": 72,
  "level": 73, "questIds": 74, "required": 75, "completed": 76,
  "rewarded": 77, "npcId": 78, "ids": 79, "types": 80,
  "counts": 81, "questId": 82, "divisor": 83, "version": 84,
};

// ── 保留固定字段 keyCode（高位段，与 BinaryBatchSerializer 一致） ──
// ── Reserved fixed key codes (high segment, matching BinaryBatchSerializer) ──
const K_TIMESTAMP = 0xf1, K_REQUEST_ID = 0xf2, K_TYPE = 0xf3;

// ── 值类型码（与 BinaryBatchSerializer 一致） ──
// ── Value-type codes (matching BinaryBatchSerializer) ──
const T_NULL = 0x00, T_INT = 0x01, T_FLOAT = 0x02, T_STRING = 0x03, T_STRING32 = 0x04,
  T_LIST = 0x05, T_POS = 0x06, T_EMPTY_STRING = 0x07, T_TRUE = 0xf0, T_FALSE = 0xf1;

const MAGIC = [0x4e, 0x58, 0x00, 0x01]; // "NX\0\x01"
const FRAME_NAMES = Object.fromEntries(Object.entries(FRAME_TYPES).map(([k, v]) => [v, k]));
const KEY_NAMES = Object.fromEntries(Object.entries(PAYLOAD_KEYS).map(([k, v]) => [v, k]));

/**
 * 二进制编解码器：encodeBatch/decodeBatch 与 PHP 端 BinaryBatchSerializer/MapCodec 一一对称。
 * Binary codec: encodeBatch/decodeBatch strictly symmetric with the PHP BinaryBatchSerializer/MapCodec.
 */
class NythrosCodec {
  static readI64(view, off) {
    // 走 BigInt 读取再收窄为 Number（保持 d.ts 的 number 契约）：浮点拼接法在负数/高位丢失低 32 位
    // （-7 会错成 0）。协议值（id/计数/坐标）实际都在 ±2^53 安全区内，超出该区间的值精度受损。
    // Read via BigInt then narrow to Number (keeping the d.ts number contract): float composition loses
    // low bits (e.g. -7 corrupted to 0). Protocol values (ids/counts/coords) live within ±2^53.
    return Number(view.getBigInt64(off, true));
  }

  static readU16(view, off) { return view.getUint16(off, false); }
  static readU32(view, off) { return view.getUint32(off, false); }

  /**
   * 编码一批帧为一个二进制批量包。
   * Encodes a list of frames into one binary batch packet.
   * @param {Array<{type: string, requestId?: ?string, payload: object}>} frames
   * @returns {Uint8Array}
   */
  static encodeBatch(frames) {
    const bodies = frames.map((f) => NythrosCodec.encodeFrameBody(f));
    const total = 8 + bodies.reduce((n, b) => n + 4 + b.length, 0);
    const out = new Uint8Array(total);
    const view = new DataView(out.buffer);
    out.set(MAGIC, 0);
    view.setUint32(4, bodies.length, false);
    let off = 8;
    for (const body of bodies) {
      view.setUint32(off, body.length, false);
      out.set(body, off + 4);
      off += 4 + body.length;
    }
    return out;
  }

  /** 编码帧体：[2B 字段数]{ [2B keyCode][1B valueType][负载] }。 Encodes one frame body. */
  static encodeFrameBody(frame) {
    const fields = [[K_TYPE, NythrosCodec.encString(frame.type)]];
    if (frame.requestId != null) {
      fields.push([K_REQUEST_ID, NythrosCodec.encString(String(frame.requestId))]);
    }
    for (const [key, value] of Object.entries(frame.payload ?? {})) {
      const keyCode = PAYLOAD_KEYS[key];
      if (keyCode === undefined) {
        throw new Error('Nythros 协议：未知负载字段 ' + key + '（未登记进 PayloadKey 枚举）');
      }
      fields.push([keyCode, NythrosCodec.encValue(value)]);
    }
    const body = new Uint8Array(2 + fields.reduce((n, [, b]) => n + 3 + b.data.length, 0));
    const view = new DataView(body.buffer);
    view.setUint16(0, fields.length, false);
    let off = 2;
    for (const [keyCode, valueBytes] of fields) {
      view.setUint16(off, keyCode, false);
      body[off + 2] = valueBytes.type;
      body.set(valueBytes.data, off + 3);
      off += 3 + valueBytes.data.length;
    }
    return body;
  }

  static encString(s) {
    const bytes = new TextEncoder().encode(s);
    if (bytes.length === 0) return { type: T_EMPTY_STRING, data: new Uint8Array(0) };
    if (bytes.length <= 255) {
      const out = new Uint8Array(1 + bytes.length);
      out[0] = bytes.length;
      out.set(bytes, 1);
      return { type: T_STRING, data: out };
    }
    const out = new Uint8Array(4 + bytes.length);
    new DataView(out.buffer).setUint32(0, bytes.length, false);
    out.set(bytes, 4);
    return { type: T_STRING32, data: out };
  }

  static encValue(value) {
    if (value === null) return { type: T_NULL, data: new Uint8Array(0) };
    if (value === true) return { type: T_TRUE, data: new Uint8Array(0) };
    if (value === false) return { type: T_FALSE, data: new Uint8Array(0) };
    if (typeof value === 'number' && Number.isInteger(value)) {
      const out = new Uint8Array(8);
      new DataView(out.buffer).setBigInt64(0, BigInt(value), true);
      return { type: T_INT, data: out };
    }
    if (typeof value === 'number') {
      const out = new Uint8Array(8);
      new DataView(out.buffer).setFloat64(0, value, true);
      return { type: T_FLOAT, data: out };
    }
    if (typeof value === 'string') return NythrosCodec.encString(value);
    if (NythrosCodec.isPosition(value)) {
      const out = new Uint8Array(4);
      const v = new DataView(out.buffer);
      v.setInt16(0, value.x, false);
      v.setInt16(2, value.y, false);
      return { type: T_POS, data: out };
    }
    if (Array.isArray(value)) return NythrosCodec.encList(value);
    throw new Error('Nythros 协议：不支持的值类型（字段值 ' + JSON.stringify(value) + '）');
  }

  static encList(items) {
    const elems = items.map((v) => {
      const e = NythrosCodec.encValue(v);
      const out = new Uint8Array(1 + e.data.length);
      out[0] = e.type;
      out.set(e.data, 1);
      return out;
    });
    const out = new Uint8Array(4 + elems.reduce((n, b) => n + b.length, 0));
    new DataView(out.buffer).setUint32(0, elems.length, false);
    let off = 4;
    for (const e of elems) { out.set(e, off); off += e.length; }
    return { type: T_LIST, data: out };
  }

  /** 解码批量包 → 帧列表。 Decodes a batch packet into a list of frames. */
  static decodeBatch(bytes) {
    if (bytes.length === 0) return [];
    for (let i = 0; i < 4; i++) {
      if (bytes[i] !== MAGIC[i]) throw new Error('Nythros 协议：魔数不匹配（非本协议二进制包）');
    }
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const count = NythrosCodec.readU32(view, 4);
    const frames = [];
    let off = 8;
    for (let i = 0; i < count; i++) {
      const len = NythrosCodec.readU32(view, off);
      frames.push(NythrosCodec.decodeFrameBody(new DataView(bytes.buffer, bytes.byteOffset + off + 4, len)));
      off += 4 + len;
    }
    return frames;
  }

  static decodeFrameBody(view) {
    const fieldCount = NythrosCodec.readU16(view, 0);
    const frame = { type: null, requestId: null, payload: {} };
    let off = 2;
    for (let i = 0; i < fieldCount; i++) {
      if (off + 3 > view.byteLength) throw new Error('Nythros 协议：字段槽位越界');
      const keyCode = NythrosCodec.readU16(view, off);
      const valueType = view.getUint8(off + 2);
      off += 3;
      if (keyCode === K_TYPE) { const r = NythrosCodec.decString(view, off, valueType); frame.type = r.value; off += r.consumed; continue; }
      if (keyCode === K_REQUEST_ID) { const r = NythrosCodec.decString(view, off, valueType); frame.requestId = r.value; off += r.consumed; continue; }
      if (keyCode === K_TIMESTAMP) {
        if (valueType !== T_FLOAT) throw new Error('Nythros 协议：timestamp 字段类型错误');
        off += 8;
        continue;
      }
      const key = KEY_NAMES[keyCode];
      if (key === undefined) throw new Error('Nythros 协议：未知 keyCode ' + keyCode + '（未登记进 PayloadKey 枚举）');
      const res = NythrosCodec.decValue(view, off, valueType);
      off += res.consumed;
      frame.payload[key] = res.value;
    }
    if (frame.type === null) throw new Error('Nythros 协议：帧体缺少 type 字段');
    return frame;
  }

  static decValue(view, off, type) {
    switch (type) {
      case T_NULL: return { value: null, consumed: 0 };
      case T_TRUE: return { value: true, consumed: 0 };
      case T_FALSE: return { value: false, consumed: 0 };
      case T_INT: return { value: NythrosCodec.readI64(view, off), consumed: 8 };
      case T_FLOAT: return { value: view.getFloat64(off, true), consumed: 8 };
      case T_STRING: { const r = NythrosCodec.decString(view, off, T_STRING); return { value: r.value, consumed: r.consumed }; }
      case T_STRING32: { const r = NythrosCodec.decString(view, off, T_STRING32); return { value: r.value, consumed: r.consumed }; }
      case T_EMPTY_STRING: return { value: '', consumed: 0 };
      case T_POS: return { value: { x: view.getInt16(off, false), y: view.getInt16(off + 2, false) }, consumed: 4 };
      case T_LIST: {
        const count = NythrosCodec.readU32(view, off);
        const base = off;
        off += 4;
        const list = [];
        for (let i = 0; i < count; i++) {
          const et = view.getUint8(off); off += 1;
          const r = NythrosCodec.decValue(view, off, et);
          off += r.consumed;
          list.push(r.value);
        }
        return { value: list, consumed: off - base };
      }
      default: throw new Error('Nythros 协议：未知值类型 0x' + type.toString(16));
    }
  }

  static decString(view, off, type) {
    if (type === T_STRING) {
      const len = view.getUint8(off);
      const bytes = new Uint8Array(view.buffer, view.byteOffset + off + 1, len);
      return { value: new TextDecoder().decode(bytes), consumed: 1 + len };
    }
    if (type === T_STRING32) {
      const len = NythrosCodec.readU32(view, off);
      const bytes = new Uint8Array(view.buffer, view.byteOffset + off + 4, len);
      return { value: new TextDecoder().decode(bytes), consumed: 4 + len };
    }
    if (type === T_EMPTY_STRING) return { value: '', consumed: 0 };
    throw new Error('Nythros 协议：字符串字段类型错误');
  }

  static isPosition(v) {
    return v !== null && typeof v === 'object' && Number.isInteger(v.x) && Number.isInteger(v.y)
      && Object.keys(v).length === 2;
  }
}

/**
 * 插值引擎（状态同步指南的参考实现，docs/state-sync.md）：
 * 维护视野内实体的「上一权威位置 → 最新权威位置」过渡，sample(now) 输出平滑位置。
 * The interpolation engine (the reference implementation of the state-sync guide, docs/state-sync.md):
 * maintains per-entity "previous authoritative position -> latest authoritative position" transitions;
 * sample(now) returns the smoothed position.
 *
 * 两类实体分开插值（区域密度语义）：
 * - 事件驱动（玩家输入 entity_moved）：窗口小而固定（eventWindowMs，缺省 100ms）；
 * - tick 驱动（monster:spawned 登记的怪物）：到达间隔的 EMA 实测周期为窗口——区域密度降频会拉长
 *   怪物移动广播周期，固定窗口会来回顿挫，实测周期自适应；world:tick_rate（本实体分频 directed 帧）
 *   到达时把自身窗口放大 divisor × baseTickMs。
 * Two entity classes interpolate separately (the region-density semantics):
 * - Event-driven (player-input entity_moved): a small fixed window (eventWindowMs, default 100ms).
 * - Tick-driven (monsters registered via monster:spawned): the window is an EMA of measured arrival
 *   intervals — Region-density downgrading lengthens monster move broadcasts, so a fixed window would stutter;
 *   a directed world:tick_rate frame (own divisor) widens the owner's window to divisor × baseTickMs.
 *
 * 快照重同步：服务器周期（demo 1s）重发视野全量 entity_enter（含未变动实体）——已登记实体收到
 * entity_enter 时按权威位置吸附（过渡从当前平滑位置出发，不跳变）。
 * Snapshot resync: the server periodically re-sends the full-view entity_enter set (every ~1s in the demo,
 * including unchanged entities) — an entity_enter for a registered entity snaps onto the authoritative
 * position (the transition starts from the current smoothed position, never jumping).
 */
class NythrosInterpolator {
  /**
   * @param {object} [options]
   * @param {number} [options.baseTickMs=50] 服务端 base tick 周期（全局定时器恒定 base_hz）。
   * @param {number} [options.eventWindowMs=100] 事件驱动实体的插值窗口。
   * @param {number} [options.windowGamma=1.5] tick 驱动窗口 = EMA 间隔 × gamma（>1 留缓冲防抖）。
   */
  constructor(options = {}) {
    this.baseTickMs = options.baseTickMs ?? 50;
    this.eventWindowMs = options.eventWindowMs ?? 100;
    this.windowGamma = options.windowGamma ?? 1.5;
    /** @type {Map<string, object>} id => 实体插值状态 id => entity interpolation state. */
    this.entities = new Map();
    this.selfEntityId = null;
  }

  /** 帧入口：喂服务器帧维护实体表。 Frame entry: feed server frames to maintain the entity table. */
  applyFrame(frame) {
    const p = frame.payload ?? {};
    switch (frame.type) {
      case 'monster:spawned':
        this.entities.set(p.id, {
          id: p.id, kind: 'tick', typeId: p.typeId ?? null,
          x: p.position?.x ?? 0, y: p.position?.y ?? 0,
          prevX: p.position?.x ?? 0, prevY: p.position?.y ?? 0,
          at: Date.now(), intervalEma: this.baseTickMs, divisor: 1,
        });
        break;
      case 'entity_enter': {
        const existing = this.entities.get(p.id);
        if (existing) {
          // 已登记实体收到 entity_enter = 周期视野快照重同步（服务器 1s 周期重发视野全量）：
          // 按权威位置吸附（与 entity_moved 同路径，不做怪物 EMA 更新）
          // An entity_enter for a registered entity = the periodic vision-snapshot resync (the server
          // re-sends the full view every ~1s): snap onto the authoritative position (the same path as
          // entity_moved, without the monster EMA update).
          const now = Date.now();
          const sampled = this.sample(p.id, now);
          existing.prevX = sampled.x;
          existing.prevY = sampled.y;
          existing.x = p.position?.x ?? existing.x;
          existing.y = p.position?.y ?? existing.y;
          existing.at = now;
          break;
        }
        this.entities.set(p.id, {
          id: p.id, kind: 'event', typeId: null,
          x: p.position?.x ?? 0, y: p.position?.y ?? 0,
          prevX: p.position?.x ?? 0, prevY: p.position?.y ?? 0,
          at: Date.now(), intervalEma: this.baseTickMs, divisor: 1,
        });
        break;
      }
      case 'entity_moved': {
        const e = this.entities.get(p.id);
        if (!e) break;
        const now = Date.now();
        // tick 驱动实体：到达间隔 EMA（降频拉长周期 → 窗口自适应放大）
        // Tick-driven entities: arrival-interval EMA (a downgraded cadence widens the window adaptively).
        if (e.kind === 'tick') {
          const dt = now - e.at;
          if (dt > 0 && dt < 5000) e.intervalEma = e.intervalEma * 0.7 + dt * 0.3;
        }
        const sampled = this.sample(e.id, now);
        e.prevX = sampled.x;
        e.prevY = sampled.y;
        e.x = p.position?.x ?? e.x;
        e.y = p.position?.y ?? e.y;
        e.at = now;
        break;
      }
      case 'entity_leave':
        this.entities.delete(p.id);
        break;
      case 'world:tick_rate': {
        // directed 帧（发给本实体连接）：分频变化即调整自身窗口（divisor × base tick）
        // A directed frame (to this entity's own connection): a divisor change adjusts the owner's window.
        const selfEntity = this.selfEntityId ? this.entities.get(this.selfEntityId) : null;
        if (selfEntity) selfEntity.divisor = p.divisor ?? 1;
        break;
      }
      default:
        break;
    }
  }

  /**
   * 平滑位置采样：t = (now - at) / window 线性插值，越界收敛到权威位置。
   * Smooth position sampling: t = (now - at) / window linear interpolation, clamped onto the authoritative position.
   */
  sample(id, now = Date.now()) {
    const e = this.entities.get(id);
    if (!e) return { x: 0, y: 0 };
    const window = e.kind === 'tick'
      ? Math.max(e.intervalEma * this.windowGamma, this.baseTickMs)
      : Math.max(this.eventWindowMs, e.divisor * this.baseTickMs);
    const t = Math.min(1, Math.max(0, (now - e.at) / window));
    return {
      x: Math.round(e.prevX + (e.x - e.prevX) * t),
      y: Math.round(e.prevY + (e.y - e.prevY) * t),
    };
  }

  /** 最新权威位置（不做插值）。 The latest authoritative position (no interpolation). */
  position(id) {
    const e = this.entities.get(id);
    return e ? { x: e.x, y: e.y } : null;
  }

  /** 观察自身 entityId（auth_ok 后由 client 回填，world:tick_rate 消费）。 Observes the own entityId (back-filled by the client after auth_ok; consumed by world:tick_rate). */
  setSelfEntityId(id) { this.selfEntityId = id; }
}

/**
 * Nythros 客户端：登录链路 + 事件订阅 + request 回执 + 世界状态（内嵌插值引擎）。
 * The Nythros client: the login chain + event subscription + request receipts + world state (with the interpolation engine inside).
 *
 * @example
 * const client = new NythrosClient({ username: '1001', password: 'secret' });
 * const session = await client.connect();
 * client.on('monster:spawned', (f) => console.log('monster', f.payload));
 * await client.request('move', { dx: 15, dy: 15 });
 * await client.request('attack', { targetId: 'monster-1' });
 * client.close();
 */
class NythrosClient {
  /**
   * @param {object} options
   * @param {string} options.username 登录 uid（网关账号表） The login uid (the gateway account table).
   * @param {string} options.password 登录密码 The login password.
   * @param {string} [options.gatewayUrl='ws://127.0.0.1:18285'] 网关地址（JSON 文本协议） The gateway URL (JSON text protocol).
   * @param {string} [options.mapUrl='ws://127.0.0.1:18081'] Map 直连地址（二进制批量协议） The Map URL (binary batch protocol).
   * @param {string} [options.mapId='map-1'] 期望落图的地图 id The expected map id.
   * @param {number} [options.baseTickMs=50] 服务端 base tick 周期（插值引擎用） The server base tick period (for the interpolation engine).
     * @param {number} [options.protocolVersion=1] 客户端协议版本（auth 帧携带，ADR-027 版本协商）。
     *   The client protocol version (carried by the auth frames, version negotiation ADR-027).
     * @param {boolean} [options.autoReconnect=false] 断线自动重连（整链重登：gateway → token → Map；服务端在
   *   detach 时自动导出转移票据，重连 attach 即恢复位置/血量/背包——重连即同图迁移）。
   *   Auto-reconnect on disconnect (a full re-login chain: gateway -> token -> Map; the server exports the
   *   transfer ticket at detach and the reconnect's attach restores position/hp/inventory — a reconnect IS a
   *   same-map migration).
   * @param {number} [options.maxReconnectAttempts=5] 重连尝试上限（耗尽后派发 :reconnectfailed）。
   *   The reconnect attempt cap (:reconnectfailed dispatched when exhausted).
   * @param {number} [options.reconnectDelayMs=2000] 重连基础间隔（逐次翻倍退避，上限 30s）。
   *   The base reconnect delay (doubling backoff, capped at 30s).
   * @param {Function} [options.logger] 调试日志钩子（缺省静默） The debug-log hook (silent by default).
   */
  constructor(options = {}) {
    if (!options.username || !options.password) {
      throw new Error('NythrosClient：缺少 username/password（网关账号表凭据）');
    }
    this.username = options.username;
    this.password = options.password;
    this.mapId = options.mapId ?? 'map-1';
    this.gatewayUrl = options.gatewayUrl ?? 'ws://127.0.0.1:18285';
    this.mapUrl = options.mapUrl ?? 'ws://127.0.0.1:18081';
    this.autoReconnect = options.autoReconnect ?? false;
    this.protocolVersion = options.protocolVersion ?? 1;
    this.maxReconnectAttempts = options.maxReconnectAttempts ?? 5;
    this.reconnectDelayMs = options.reconnectDelayMs ?? 2000;
    this.token = null;
    this.entityId = null;
    this.ws = null;
    this.seq = 0;
    this.closedByUser = false;
    this.reconnecting = false;
    this.reconnectTimer = null;
    /** @type {Map<string, {resolve: Function, reject: Function, timer: any}>} requestId => pending receipt. */
    this.pending = new Map();
    /** @type {Map<string, Array<Function>>} frameType => 订阅回调列表 frameType => subscribed callbacks. */
    this.handlers = new Map();
    this.interpolator = new NythrosInterpolator({ baseTickMs: options.baseTickMs ?? 50 });
    this.logger = options.logger ?? null;
  }

  /**
   * 登录链路：gateway auth(JSON) → token → Map auth(二进制) → entityId。
   * The login chain: gateway auth (JSON) -> token -> Map auth (binary) -> entityId.
   * @param {number} [timeoutMs=10000]
   * @returns {Promise<{entityId: string, token: string}>}
   */
  connect(timeoutMs = 10000) {
    return this.openGateway(timeoutMs).then(() => this.openMap(timeoutMs));
  }

  /**
   * 网关登录（JSON 文本帧；网关回帧实测为二进制帧，Blob/ArrayBuffer 双形态归一后再解析）。
   * Gateway login (JSON text; the gateway's reply arrives as a binary frame in practice — normalize
   * Blob/ArrayBuffer before parsing).
   */
  openGateway(timeoutMs) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('NythrosClient：gateway 登录超时')), timeoutMs);
      const ws = new WebSocket(this.gatewayUrl);
      ws.binaryType = 'arraybuffer';
      const readText = async (data) => {
        if (typeof data === 'string') return data;
        if (data instanceof ArrayBuffer) return new TextDecoder().decode(data);
        if (typeof Blob !== 'undefined' && data instanceof Blob) return await data.text();
        if (data && typeof data.arrayBuffer === 'function') return await data.arrayBuffer().then((b) => new TextDecoder().decode(b));
        return String(data);
      };
      ws.onopen = () => {
        ws.send(JSON.stringify({
          type: 'auth',
          requestId: 'login:' + this.username,
          // 网关校验 timestamp 必须是数字（verify 脚本同口径：microtime 秒）
          // The gateway requires a numeric timestamp (same convention as the verify script: microtime seconds).
          timestamp: Date.now() / 1000,
          // 协议版本协商（ADR-027）：服务器启用最低版本守卫时，过低/缺失版本被 auth_failed 拒绝
          // Protocol version negotiation (ADR-027): with the minimum-version guard enabled, a too-old or
          // missing version is rejected with auth_failed.
          version: this.protocolVersion,
          payload: { username: this.username, password: this.password, mapId: this.mapId, version: this.protocolVersion },
        }));
      };
      ws.onmessage = async (ev) => {
        let msg;
        try { msg = JSON.parse(await readText(ev.data)); } catch { return; }
        if (msg.type !== 'auth_ok') return;
        const token = msg.payload?.token;
        if (typeof token !== 'string') {
          clearTimeout(timer);
          reject(new Error('NythrosClient：gateway auth_ok 缺少 token'));
          return;
        }
        this.token = token;
        clearTimeout(timer);
        ws.close();
        resolve(token);
      };
      ws.onerror = () => { clearTimeout(timer); reject(new Error('NythrosClient：gateway 连接失败 ' + this.gatewayUrl)); };
    });
  }

  /** Map 直连（二进制批量协议；建立后所有帧进插值引擎与订阅分发）。 Map direct connect (binary batch protocol). */
  openMap(timeoutMs) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('NythrosClient：Map 登录超时')), timeoutMs);
      const ws = new WebSocket(this.mapUrl);
      ws.binaryType = 'arraybuffer';
      this.ws = ws;
      ws.onopen = () => {
        ws.send(NythrosCodec.encodeBatch([
          { type: 'auth', requestId: 'map-auth:t', payload: { token: this.token, version: this.protocolVersion } },
        ]));
      };
      ws.onmessage = (ev) => {
        let frames;
        try { frames = NythrosCodec.decodeBatch(new Uint8Array(ev.data)); } catch (e) { this.log('decode', e.message); return; }
        for (const frame of frames) this.dispatch(frame);
        const authOk = frames.find((f) => f.type === 'auth_ok');
        const authFailed = frames.find((f) => f.type === 'auth_failed');
        if (authFailed) {
          clearTimeout(timer);
          reject(new Error('NythrosClient：Map auth_failed ' + JSON.stringify(authFailed.payload)));
          return;
        }
        if (authOk) {
          this.entityId = authOk.payload?.id ?? null;
          this.interpolator.setSelfEntityId(this.entityId);
          clearTimeout(timer);
          resolve({ entityId: this.entityId, token: this.token });
        }
      };
      ws.onerror = () => { clearTimeout(timer); reject(new Error('NythrosClient：Map 连接失败 ' + this.mapUrl)); };
      ws.onclose = () => {
        for (const p of this.pending.values()) p.reject(new Error('NythrosClient：连接已关闭'));
        this.pending.clear();
        // 自动重连：用户主动 close 不触发；重连整链重登（服务端 detach 已导出转移票据，
        // 重连 attach 即恢复状态——重连即同图迁移）。
        // Auto-reconnect: a user-initiated close never triggers it; the reconnect re-logins the whole
        // chain (the server exported the transfer ticket at detach, so the reconnect's attach restores state —
        // a reconnect IS a same-map migration).
        if (this.autoReconnect && !this.closedByUser) {
          this.scheduleReconnect();
        }
      };
    });
  }

  /**
   * 断线自动重连调度（指数退避：delay × 2^n，上限 30s）：清空实体表（attach 后由视野快照全量重建）、
   * 派发 :reconnecting，整链重登成功派发 :reconnected、耗尽尝试派发 :reconnectfailed。
   * Schedules the auto-reconnect (exponential backoff: delay × 2^n capped at 30s): clears the entity table
   * (rebuilt wholesale from the vision snapshot after attach), dispatches :reconnecting, and on a successful
   * full re-login dispatches :reconnected — or :reconnectfailed when the attempts are exhausted.
   */
  scheduleReconnect() {
    if (this.reconnecting) {
      return; // 已在重连中（幂等） Already reconnecting (idempotent).
    }
    this.reconnecting = true;
    this.reconnectAttempts = 0;
    this.backoff();
  }

  backoff() {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      this.reconnecting = false;
      this.emit(':reconnectfailed', { attempts: this.reconnectAttempts });
      this.log('reconnect', '重连尝试耗尽 / reconnect attempts exhausted');

      return;
    }
    const delay = Math.min(this.reconnectDelayMs * Math.pow(2, this.reconnectAttempts), 30000);
    this.reconnectAttempts++;
    this.emit(':reconnecting', { attempt: this.reconnectAttempts, delayMs: delay });
    this.reconnectTimer = setTimeout(() => {
      this.openGateway(10000)
        .then(() => new Promise((resolve, reject) => {
          // 复用 openMap 的 attach 语义；auth_failed（票据过期等）视作本次失败继续退避重试
          // Reuses openMap's attach semantics; an auth_failed (e.g. an expired ticket) counts as this attempt's failure and keeps backing off.
          this.openMap(10000).then(resolve).catch(reject);
        }))
        .then(() => {
          this.reconnecting = false;
          this.interpolator.entities.clear(); // 实体表随 attach 全量重建 The entity table rebuilds wholesale at attach.
          this.emit(':reconnected', { entityId: this.entityId, attempt: this.reconnectAttempts });
          this.log('reconnect', '已恢复连接 entityId=' + this.entityId);
        })
        .catch((e) => {
          this.log('reconnect', '第 ' + this.reconnectAttempts + ' 次失败: ' + e.message);
          this.backoff();
        });
    }, delay);
  }

  /** 本地合成事件派发（:reconnecting/:reconnected/:reconnectfailed，非线上帧）。 Local synthetic-event dispatch (:reconnecting/:reconnected/:reconnectfailed, never wire frames). */
  emit(type, data) {
    const list = this.handlers.get(type);
    if (list) for (const cb of [...list]) cb(data);
  }

  /**
   * 发送请求并等待回执。回执匹配两种模式（服务器回执语义不统一——部分路由回显 requestId，如错误帧
   * 与 room:ok；部分只发定类型帧，如 quest:list → quest:rows 不带 requestId）：
   * - opts.replyType 给定：resolve 于发送后**首帧该类型帧**（帧类型匹配，忽略 requestId）；
   * - 缺省：resolve 于**首帧同 requestId 帧**（服务端回显口径）。
   * Sends a request and awaits its receipt, in one of two match modes (server receipt semantics are not
   * uniform — some routes echo the requestId, like error frames and room:ok; others only emit a typed frame,
   * like quest:list -> quest:rows without a requestId):
   * - with opts.replyType: resolves on the FIRST frame of that type after the send (type match, requestId ignored);
   * - default: resolves on the first frame carrying the same requestId (the server-echo convention).
   * @param {string} type 帧类型（如 move/attack/pickup/quest:list） The frame type (move/attack/pickup/quest:list ...).
   * @param {object} payload 负载 The payload.
   * @param {{timeoutMs?: number, requestId?: string, replyType?: string}} [opts]
   * @returns {Promise<object>} 回执帧 The receipt frame.
   */
  request(type, payload = {}, opts = {}) {
    const requestId = opts.requestId ?? ('req-' + this.username + '-' + (++this.seq));
    const replyType = opts.replyType ?? null;

    if (replyType !== null) {
      // 帧类型匹配模式：临时订阅回执类型，首帧即 resolve（多帧结果请用 on 订阅）
      // The type-match mode: a temporary subscription to the reply type; the first frame resolves (subscribe with on for multi-frame results).
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          unsubscribe();
          reject(new Error('NythrosClient：' + type + ' 回执超时（等待 ' + replyType + '）'));
        }, opts.timeoutMs ?? 10000);
        const unsubscribe = this.on(replyType, (frame) => {
          clearTimeout(timer);
          unsubscribe();
          resolve(frame);
        });
        this.send(type, payload, requestId);
      });
    }

    // requestId 回显模式
    // The requestId-echo mode.
    const promise = new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(requestId);
        reject(new Error('NythrosClient：' + type + ' 回执超时（' + requestId + '）'));
      }, opts.timeoutMs ?? 10000);
      this.pending.set(requestId, { resolve, reject, timer });
    });
    this.send(type, payload, requestId);
    return promise;
  }

  /** 发送一帧（不等回执）。 Sends one frame (no receipt wait). */
  send(type, payload = {}, requestId = null) {
    if (!this.ws || this.ws.readyState !== 1) throw new Error('NythrosClient：Map 连接未建立');
    this.ws.send(NythrosCodec.encodeBatch([{ type, payload, requestId }]));
  }

  /** 订阅服务器帧；返回退订函数。 Subscribes to server frames; returns an unsubscribe function. */
  on(type, cb) {
    const list = this.handlers.get(type) ?? [];
    list.push(cb);
    this.handlers.set(type, list);
    return () => this.off(type, cb);
  }

  off(type, cb) {
    const list = this.handlers.get(type);
    if (!list) return;
    const i = list.indexOf(cb);
    if (i >= 0) list.splice(i, 1);
  }

  /** 主动登出并关闭连接（用户主动 close 不触发自动重连）。 Logs out and closes the connection (a user-initiated close never triggers auto-reconnect). */
  close() {
    this.closedByUser = true;
    if (this.reconnectTimer !== null) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
      this.reconnecting = false;
    }
    try { this.send('logout', {}); } catch { /* 已关闭静默 Already closed; silent. */ }
    setTimeout(() => this.ws?.close(), 50);
  }

  /** 帧分发：插值引擎 → request 回执 → 订阅回调。 Frame dispatch: interpolator -> receipts -> subscriptions. */
  dispatch(frame) {
    this.interpolator.applyFrame(frame);
    if (frame.requestId != null) {
      const p = this.pending.get(frame.requestId);
      if (p) {
        this.pending.delete(frame.requestId);
        clearTimeout(p.timer);
        p.resolve(frame);
      }
    }
    const list = this.handlers.get(frame.type);
    if (list) for (const cb of [...list]) cb(frame);
    if (frame.type === 'error') this.log('error-frame', JSON.stringify(frame.payload));
  }

  log(tag, message) { if (this.logger) this.logger('[' + tag + '] ' + message); }
}

// Node / 浏览器双形态导出。 Dual Node/browser export.
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { NythrosClient, NythrosCodec, NythrosInterpolator, FRAME_TYPES, PAYLOAD_KEYS, FRAME_NAMES, KEY_NAMES };
}
if (typeof globalThis !== 'undefined') {
  globalThis.NythrosClient = NythrosClient;
  globalThis.NythrosCodec = NythrosCodec;
  globalThis.NythrosInterpolator = NythrosInterpolator;
}
