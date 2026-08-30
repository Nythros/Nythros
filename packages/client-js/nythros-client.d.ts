/**
 * nythros-client 类型定义（**生成文件，勿手改**）。
 * nythros-client type definitions (**GENERATED — do not edit by hand**).
 *
 * 生成器：packages/client-js/scripts/generate-definitions.php（从 PHP 权威码表渲染，P19）。
 * Generator: packages/client-js/scripts/generate-definitions.php (rendered from the PHP authoritative code tables, the P19).
 * 同步铁律：新增帧/字段必须同步 PHP 枚举后重新生成——一经发布的码永不复用。
 * Sync rule: new frames/keys must update the PHP enums first, then regenerate — released codes are never reused.
 */

/** 帧类型码表（FrameType::codeMap() 生成）。 The frame-type codes (generated from FrameType::codeMap()). */
export declare const FRAME_TYPES: FrameCodeTable;

export interface FrameCodeTable {
  readonly "entity_moved": 1;
  readonly "entity_enter": 2;
  readonly "entity_leave": 3;
  readonly "combat:hit": 4;
  readonly "skill:cast": 5;
  readonly "drop:spawned": 6;
  readonly "drop:removed": 7;
  readonly "item:added": 8;
  readonly "entity_dead": 9;
  readonly "monster:spawned": 10;
  readonly "player:stats": 11;
  readonly "combat:error": 12;
  readonly "error": 13;
  readonly "auth_ok": 14;
  readonly "auth_failed": 15;
  readonly "auth": 16;
  readonly "move": 17;
  readonly "attack": 18;
  readonly "pickup": 19;
  readonly "logout": 20;
  readonly "combat:aoe": 21;
  readonly "drop:spawned_batch": 22;
  readonly "room:snapshot": 23;
  readonly "room:closed": 24;
  readonly "room:member_enter": 25;
  readonly "room:member_leave": 26;
  readonly "room:left": 27;
  readonly "room:ok": 28;
  readonly "room:create": 29;
  readonly "room:join": 30;
  readonly "room:spawn": 31;
  readonly "room:aoe": 32;
  readonly "room:settle": 33;
  readonly "room:close": 34;
  readonly "gm:exec": 35;
  readonly "gm:result": 36;
  readonly "gm:broadcast": 37;
  readonly "equip": 38;
  readonly "unequip": 39;
  readonly "economy:result": 40;
  readonly "mail:new": 41;
  readonly "mail:list": 42;
  readonly "mail:claimed": 43;
  readonly "auction:sell": 44;
  readonly "auction:buy": 45;
  readonly "auction:cancel": 46;
  readonly "economy:deposit": 47;
  readonly "mail:claim": 48;
  readonly "mail:delete": 49;
  readonly "friend:apply": 50;
  readonly "friend:accept": 51;
  readonly "friend:reject": 52;
  readonly "friend:remove": 53;
  readonly "friend:list": 54;
  readonly "friend:ok": 55;
  readonly "friend:error": 56;
  readonly "friend:notify": 57;
  readonly "guild:create": 58;
  readonly "guild:disband": 59;
  readonly "guild:kick": 60;
  readonly "guild:promote": 61;
  readonly "guild:notice": 62;
  readonly "guild:apply": 63;
  readonly "guild:approve": 64;
  readonly "guild:ok": 65;
  readonly "guild:error": 66;
  readonly "guild:notify": 67;
  readonly "leaderboard:top": 68;
  readonly "leaderboard:rows": 69;
  readonly "leaderboard:rank": 70;
  readonly "leaderboard:ranked": 71;
  readonly "buff:apply": 72;
  readonly "buff:remove": 73;
  readonly "buff:applied": 74;
  readonly "buff:expired": 75;
  readonly "matching:enqueue": 76;
  readonly "matching:cancel": 77;
  readonly "matching:matched": 78;
  readonly "matching:ok": 79;
  readonly "quest:list": 80;
  readonly "quest:rows": 81;
  readonly "quest:claim": 82;
  readonly "quest:talk": 83;
  readonly "quest:result": 84;
  readonly "entity_dead_batch": 85;
  readonly "player:revive": 86;
  readonly "skill:cast_aoe": 87;
  readonly "world:tick_rate": 88;
}

/** 帧名字面量联合（可作 on()/request() 的类型守卫）。 The frame-name literal union (usable as a type guard for on()/request()). */
export type FrameName =
  | "entity_moved"
  | "entity_enter"
  | "entity_leave"
  | "combat:hit"
  | "skill:cast"
  | "drop:spawned"
  | "drop:removed"
  | "item:added"
  | "entity_dead"
  | "monster:spawned"
  | "player:stats"
  | "combat:error"
  | "error"
  | "auth_ok"
  | "auth_failed"
  | "auth"
  | "move"
  | "attack"
  | "pickup"
  | "logout"
  | "combat:aoe"
  | "drop:spawned_batch"
  | "room:snapshot"
  | "room:closed"
  | "room:member_enter"
  | "room:member_leave"
  | "room:left"
  | "room:ok"
  | "room:create"
  | "room:join"
  | "room:spawn"
  | "room:aoe"
  | "room:settle"
  | "room:close"
  | "gm:exec"
  | "gm:result"
  | "gm:broadcast"
  | "equip"
  | "unequip"
  | "economy:result"
  | "mail:new"
  | "mail:list"
  | "mail:claimed"
  | "auction:sell"
  | "auction:buy"
  | "auction:cancel"
  | "economy:deposit"
  | "mail:claim"
  | "mail:delete"
  | "friend:apply"
  | "friend:accept"
  | "friend:reject"
  | "friend:remove"
  | "friend:list"
  | "friend:ok"
  | "friend:error"
  | "friend:notify"
  | "guild:create"
  | "guild:disband"
  | "guild:kick"
  | "guild:promote"
  | "guild:notice"
  | "guild:apply"
  | "guild:approve"
  | "guild:ok"
  | "guild:error"
  | "guild:notify"
  | "leaderboard:top"
  | "leaderboard:rows"
  | "leaderboard:rank"
  | "leaderboard:ranked"
  | "buff:apply"
  | "buff:remove"
  | "buff:applied"
  | "buff:expired"
  | "matching:enqueue"
  | "matching:cancel"
  | "matching:matched"
  | "matching:ok"
  | "quest:list"
  | "quest:rows"
  | "quest:claim"
  | "quest:talk"
  | "quest:result"
  | "entity_dead_batch"
  | "player:revive"
  | "skill:cast_aoe"
  | "world:tick_rate";

/** 负载字段码表（PayloadKey::codeMap() 生成）。 The payload-key codes (generated from PayloadKey::codeMap()). */
export declare const PAYLOAD_KEYS: PayloadCodeTable;

export interface PayloadCodeTable {
  readonly "id": 1;
  readonly "uid": 2;
  readonly "position": 3;
  readonly "x": 4;
  readonly "y": 5;
  readonly "dx": 6;
  readonly "dy": 7;
  readonly "token": 8;
  readonly "code": 9;
  readonly "message": 10;
  readonly "username": 11;
  readonly "password": 12;
  readonly "mapId": 13;
  readonly "attackerId": 14;
  readonly "targetId": 15;
  readonly "damage": 16;
  readonly "hp": 17;
  readonly "maxHp": 18;
  readonly "casterId": 19;
  readonly "skillId": 20;
  readonly "dropId": 21;
  readonly "itemId": 22;
  readonly "count": 23;
  readonly "typeId": 24;
  readonly "reason": 25;
  readonly "targetIds": 26;
  readonly "damages": 27;
  readonly "hps": 28;
  readonly "dropIds": 29;
  readonly "itemIds": 30;
  readonly "positions": 31;
  readonly "roomId": 32;
  readonly "memberIds": 33;
  readonly "op": 34;
  readonly "cx": 35;
  readonly "cy": 36;
  readonly "r": 37;
  readonly "command": 38;
  readonly "slot": 39;
  readonly "price": 40;
  readonly "auctionId": 41;
  readonly "mailId": 42;
  readonly "title": 43;
  readonly "body": 44;
  readonly "hasAttachment": 45;
  readonly "attachments": 46;
  readonly "mailIds": 47;
  readonly "titles": 48;
  readonly "bodies": 49;
  readonly "hasAttachments": 50;
  readonly "targetUid": 51;
  readonly "fromUid": 52;
  readonly "action": 53;
  readonly "type": 54;
  readonly "name": 55;
  readonly "maxMembers": 56;
  readonly "role": 57;
  readonly "notice": 58;
  readonly "accept": 59;
  readonly "uids": 60;
  readonly "boardId": 61;
  readonly "n": 62;
  readonly "offset": 63;
  readonly "ranks": 64;
  readonly "scores": 65;
  readonly "rank": 66;
  readonly "score": 67;
  readonly "guildId": 68;
  readonly "buffId": 69;
  readonly "stacks": 70;
  readonly "durationSeconds": 71;
  readonly "queueId": 72;
  readonly "level": 73;
  readonly "questIds": 74;
  readonly "required": 75;
  readonly "completed": 76;
  readonly "rewarded": 77;
  readonly "npcId": 78;
  readonly "ids": 79;
  readonly "types": 80;
  readonly "counts": 81;
  readonly "questId": 82;
  readonly "divisor": 83;
  readonly "version": 84;
}

/** 负载键名字面量联合。 The payload-key name literal union. */
export type PayloadKeyName =
  | "id"
  | "uid"
  | "position"
  | "x"
  | "y"
  | "dx"
  | "dy"
  | "token"
  | "code"
  | "message"
  | "username"
  | "password"
  | "mapId"
  | "attackerId"
  | "targetId"
  | "damage"
  | "hp"
  | "maxHp"
  | "casterId"
  | "skillId"
  | "dropId"
  | "itemId"
  | "count"
  | "typeId"
  | "reason"
  | "targetIds"
  | "damages"
  | "hps"
  | "dropIds"
  | "itemIds"
  | "positions"
  | "roomId"
  | "memberIds"
  | "op"
  | "cx"
  | "cy"
  | "r"
  | "command"
  | "slot"
  | "price"
  | "auctionId"
  | "mailId"
  | "title"
  | "body"
  | "hasAttachment"
  | "attachments"
  | "mailIds"
  | "titles"
  | "bodies"
  | "hasAttachments"
  | "targetUid"
  | "fromUid"
  | "action"
  | "type"
  | "name"
  | "maxMembers"
  | "role"
  | "notice"
  | "accept"
  | "uids"
  | "boardId"
  | "n"
  | "offset"
  | "ranks"
  | "scores"
  | "rank"
  | "score"
  | "guildId"
  | "buffId"
  | "stacks"
  | "durationSeconds"
  | "queueId"
  | "level"
  | "questIds"
  | "required"
  | "completed"
  | "rewarded"
  | "npcId"
  | "ids"
  | "types"
  | "counts"
  | "questId"
  | "divisor"
  | "version";

// ── 以下为手写模板段（与 nythros-client.js 的运行时一一对应） ──
// ── The hand-written template below (one-to-one with nythros-client.js's runtime) ──

/** 协议帧：type/requestId/负载。 A protocol frame: type/requestId/payload. */
export interface NythrosFrame {
  type: FrameName;
  requestId: string | null;
  payload: Record<string, unknown>;
  [key: string]: unknown;
}

/** 插值位置（网格坐标取整）。 An interpolated position (grid coordinates, rounded). */
export interface Vec2 {
  x: number;
  y: number;
}

/** 插值引擎：事件驱动/tick 驱动实体分窗 + world:tick_rate 分频 + 快照吸附（docs/state-sync.md）。 The interpolation engine: separate windows for event/tick-driven entities + world:tick_rate divisors + snapshot snapping (docs/state-sync.md). */
export declare class NythrosInterpolator {
  constructor(options?: { baseTickMs?: number; eventWindowMs?: number; windowGamma?: number });
  applyFrame(frame: NythrosFrame): void;
  sample(id: string, now?: number): Vec2;
  position(id: string): Vec2 | null;
  setSelfEntityId(id: string): void;
}

/** Nythros 客户端：登录链路 + 事件订阅 + 双模式 request 回执 + 可选自动重连（重连即同图迁移）。 The Nythros client: the login chain + event subscription + dual-mode request receipts + optional auto-reconnect (a reconnect IS a same-map migration). */
export declare class NythrosClient {
  readonly username: string;
  readonly mapId: string;
  token: string | null;
  entityId: string | null;
  readonly interpolator: NythrosInterpolator;

  constructor(options: {
    username: string;
    password: string;
    gatewayUrl?: string;
    mapUrl?: string;
    mapId?: string;
    baseTickMs?: number;
    autoReconnect?: boolean;
    maxReconnectAttempts?: number;
    reconnectDelayMs?: number;
    logger?: (line: string) => void;
  });

  connect(timeoutMs?: number): Promise<{ entityId: string; token: string }>;
  request(type: string, payload?: Record<string, unknown>, opts?: { timeoutMs?: number; requestId?: string; replyType?: string }): Promise<NythrosFrame>;
  send(type: string, payload?: Record<string, unknown>, requestId?: string | null): void;
  on(type: string, cb: (frame: NythrosFrame | Record<string, unknown>) => void): () => void;
  off(type: string, cb: (frame: NythrosFrame | Record<string, unknown>) => void): void;
  /** 本地合成事件：':reconnecting' / ':reconnected' / ':reconnectfailed'（autoReconnect 开启时）。 Local synthetic events: ':reconnecting' / ':reconnected' / ':reconnectfailed' (with autoReconnect on). */
  emit(type: string, data: Record<string, unknown>): void;
  close(): void;
}

/** 二进制编解码器（与 PHP MapCodec/BinaryBatchSerializer 一一对称）。 The binary codec (strictly symmetric with the PHP MapCodec/BinaryBatchSerializer). */
export declare class NythrosCodec {
  static encodeBatch(frames: Array<{ type: string; requestId?: string | null; payload: object }>): Uint8Array;
  static decodeBatch(bytes: Uint8Array): NythrosFrame[];
}