<?php

declare(strict_types=1);

// 定位：packages/demo/bin/lib/map-codec.php — Map 频道二进制协议客户端辅助（帧构造 + 批量解码），
// 供 verify-*.php 等直连 Map 频道的脚本使用；社交/网关频道仍走 JSON，本文件不介入。
// Located at: packages/demo/bin/lib/map-codec.php — binary-protocol client helpers for the Map channel
// (frame building + batch decoding), used by scripts that connect to the Map channel directly; the
// social/gateway channel stays on JSON and is untouched by this file.

use Nythros\Demo\Protocol\MapCodec;
use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\Message;

/**
 * 构造一条 Map 频道二进制请求包（批量含 1 帧，与服务端入站格式一致）。
 * Builds a Map-channel binary request packet (a batch holding one frame, matching the server's inbound format).
 *
 * @param array<string|int, mixed> $payload 帧负载 Frame payload.
 */
function frameMap(string $type, array $payload, ?string $requestId = null): string
{
    return mapCodec()->encodeBatch([Message::create($type, $payload, $requestId)]);
}

/**
 * 解码一个 Map 频道批量包为帧数组列表（与 JSON 帧同构：type/requestId/timestamp/payload，脚本谓词零改动）。
 * Decodes a Map-channel batch packet into a list of frame arrays (same shape as JSON frames:
 * type/requestId/timestamp/payload — script predicates need no change).
 *
 * @return list<array{type: string, requestId: ?string, timestamp: float, payload: array<string|int, mixed>}>
 */
function decodeMapFrames(string $bytes): array
{
    $out = [];
    foreach (mapCodec()->decodeBatch($bytes) as $message) {
        $out[] = [
            'type' => $message->type,
            'requestId' => $message->requestId,
            'timestamp' => $message->timestamp,
            'payload' => $message->payload,
        ];
    }

    return $out;
}

/** 进程内单例编解码器（脚本单进程，无共享问题）。 A process-local singleton codec (scripts are single-process). */
function mapCodec(): BinaryBatchSerializer
{
    static $codec = null;
    if ($codec === null) {
        $codec = MapCodec::create();
    }

    return $codec;
}
