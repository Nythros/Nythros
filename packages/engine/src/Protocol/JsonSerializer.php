<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * JSON 序列化器：Message 与帧字节之间的双向转换。
 * JSON serializer: bidirectional conversion between Message and frame bytes.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class JsonSerializer implements SerializerInterface
{
    /**
     * 将消息编码为 JSON 帧。
     * Encode a message into a JSON frame.
     *
     * @param Message $message 待编码的协议消息 Protocol message to encode.
     * @return FrameInterface 编码后的帧 Encoded frame.
     * @throws ProtocolException JSON 编码失败 JSON encoding failed.
     */
    public function encode(Message $message): FrameInterface
    {
        try {
            // 结构化字段序列化，UNICODE/斜杠不转义，出错抛 JsonException Structured field serialization; keep Unicode and slashes unescaped, throw JsonException on error
            $json = json_encode(
                [
                    'type' => $message->type,
                    'requestId' => $message->requestId,
                    'timestamp' => $message->timestamp,
                    'payload' => $message->payload,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new ProtocolException('JSON 编码失败：' . $e->getMessage(), 0, $e);
        }

        return new Frame($json);
    }

    /**
     * 将帧字节解码为消息，并逐字段校验结构。
     * Decode frame bytes into a message, validating each field along the way.
     *
     * @param FrameInterface $frame 待解码的帧 Frame to decode.
     * @return Message 解码后的协议消息 Decoded protocol message.
     * @throws DecodeException 字节串不是合法协议包 Byte string is not a valid protocol packet.
     */
    public function decode(FrameInterface $frame): Message
    {
        try {
            // 关联数组解码，深度上限 512，出错抛 JsonException Decode as associative array with depth limit 512; throw JsonException on error
            $data = json_decode($frame->bytes(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DecodeException('非法 JSON：' . $e->getMessage(), 0, $e);
        }

        // 顶层必须是 JSON 对象（关联数组），拒绝标量/列表 Top level must be a JSON object (associative array); reject scalars/lists
        if (!is_array($data)) {
            throw new DecodeException('协议包顶层结构必须是 JSON 对象');
        }

        // 校验 type：必须是非空字符串 type must be a non-empty string
        $type = $data['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new DecodeException('字段 type 必须是非空字符串');
        }

        // 校验 timestamp：接受数字或数字字符串 timestamp must be numeric or a numeric string
        $timestamp = $data['timestamp'] ?? null;
        if (!is_int($timestamp) && !is_float($timestamp)) {
            if (!is_string($timestamp) || !is_numeric($timestamp)) {
                throw new DecodeException('字段 timestamp 必须是数字');
            }
        }

        // 校验 requestId：必须是字符串或 null requestId must be a string or null
        $requestId = $data['requestId'] ?? null;
        if ($requestId !== null && !is_string($requestId)) {
            throw new DecodeException('字段 requestId 必须是字符串或 null');
        }

        // 校验 payload：必须是数组，缺省空数组 payload must be an array; defaults to empty array
        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new DecodeException('字段 payload 必须是数组');
        }

        return new Message($type, $requestId, (float) $timestamp, $payload);
    }
}
