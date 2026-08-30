<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 协议消息：序列化前/反序列化后的内存表示。
 * Protocol message: the in-memory representation before serialization / after deserialization.
 */
final readonly class Message
{
    /**
     * 构造协议消息。
     * Create a protocol message.
     *
     * @param string $type 消息类型 Message type
     * @param ?string $requestId 请求-响应对应 id，通知类消息为 null Correlation id for request-response; null for notifications
     * @param float $timestamp 产生时间（microtime） Creation time (microtime)
     * @param array<string|int, mixed> $payload 消息负载 Message payload
     */
    public function __construct(
        public string $type,          // 消息类型：login / auth / move / entity_moved / error ... Message type: login / auth / move / entity_moved / error ...
        public ?string $requestId,    // 请求-响应对应 id，通知类消息为 null Correlation id for request-response; null for notifications
        public float $timestamp,      // 产生时间（microtime） Creation time (microtime)
        /** @var array<string|int, mixed> */
        public array $payload,        // array<string|int, mixed> 消息负载 Message payload
    ) {
    }

    /**
     * 便捷工厂：缺省 timestamp 用 microtime(true)。
     * Convenience factory: timestamp defaults to microtime(true).
     *
     * @param string $type 消息类型 Message type
     * @param array<string|int, mixed> $payload 消息负载 Message payload
     * @param ?string $requestId 请求-响应对应 id，通知类消息为 null Correlation id for request-response; null for notifications
     * @param ?float $timestamp 产生时间，缺省 = microtime(true) Creation time; defaults to microtime(true)
     * @return self 新构造的协议消息 Newly created protocol message.
     */
    public static function create(
        string $type,
        array $payload = [],
        ?string $requestId = null,
        ?float $timestamp = null,     // 缺省 = microtime(true) Defaults to microtime(true)
    ): self {
        return new self($type, $requestId, $timestamp ?? microtime(true), $payload);
    }
}
