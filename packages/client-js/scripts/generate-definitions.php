<?php

declare(strict_types=1);

/**
 * TS 类型定义生成器（P19 客户端生态）：从 PHP 权威码表（FrameType::codeMap / PayloadKey::codeMap）
 * 渲染 nythros-client.d.ts 的码表段——与 JS SDK 的「码表同步铁律」同源：PHP 枚举是唯一事实源，
 * 生成物不得手改（手写内容在本生成器的模板里）。
 * The TS definitions generator (the P19 client ecosystem): renders nythros-client.d.ts's code-table section
 * from the PHP authoritative tables (FrameType::codeMap / PayloadKey::codeMap) — the same source of truth as
 * the JS SDK's code-table rule: the PHP enums are canonical and generated output must never be hand-edited
 * (hand-written content lives in this generator's template).
 *
 * 用法：php packages/client-js/scripts/generate-definitions.php（仓库根运行）
 * Usage: php packages/client-js/scripts/generate-definitions.php (from the repo root)
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\Demo\Protocol\FrameType;
use Nythros\Demo\Protocol\PayloadKey;

const OUT = __DIR__ . '/../nythros-client.d.ts';

$frames = FrameType::codeMap();
$keys = PayloadKey::codeMap();

/** interface 段渲染：readonly 键值对。 Renders an interface section: readonly key-value pairs. */
$renderInterface = static function (string $name, array $pairs): string {
    $entries = [];
    foreach ($pairs as $key => $code) {
        $entries[] = sprintf('  readonly %s: %d;', json_encode($key, JSON_UNESCAPED_UNICODE), $code);
    }

    return sprintf("export interface %s {\n%s\n}", $name, implode("\n", $entries));
};

/** 字面量联合渲染。 Renders a literal union. */
$renderUnion = static function (string $name, array $pairs): string {
    $literals = array_map(static fn ($n): string => json_encode($n, JSON_UNESCAPED_UNICODE), array_keys($pairs));

    return sprintf("export type %s =\n  | %s;", $name, implode("\n  | ", $literals));
};

$template = <<<'TSD'
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

%FRAMES_TABLE%

/** 帧名字面量联合（可作 on()/request() 的类型守卫）。 The frame-name literal union (usable as a type guard for on()/request()). */
%FRAMES_UNION%

/** 负载字段码表（PayloadKey::codeMap() 生成）。 The payload-key codes (generated from PayloadKey::codeMap()). */
export declare const PAYLOAD_KEYS: PayloadCodeTable;

%KEYS_TABLE%

/** 负载键名字面量联合。 The payload-key name literal union. */
%KEYS_UNION%

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
TSD;

$rendered = str_replace(
    ['%FRAMES_TABLE%', '%FRAMES_UNION%', '%KEYS_TABLE%', '%KEYS_UNION%'],
    [
        $renderInterface('FrameCodeTable', $frames),
        $renderUnion('FrameName', $frames),
        $renderInterface('PayloadCodeTable', $keys),
        $renderUnion('PayloadKeyName', $keys),
    ],
    $template,
);

file_put_contents(OUT, $rendered);
printf("[generate-definitions] 已生成 %s（%d 帧 / %d 字段）\n", OUT, count($frames), count($keys));
