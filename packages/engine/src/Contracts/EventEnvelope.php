<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 事件信封：结构化事件描述，携带来源、类型、时间戳、目标域、可靠性与丢弃策略及负载。
 * Event envelope: a structured event description carrying source, type, timestamp, target scope, reliability and drop policy, plus a payload.
 */
final readonly class EventEnvelope
{
    /**
     * AOI 进入事件类型。
     * AOI enter event type.
     */
    public const TYPE_AOI_ENTER = 'aoi.enter';

    /**
     * AOI 离开事件类型。
     * AOI leave event type.
     */
    public const TYPE_AOI_LEAVE = 'aoi.leave';

    /**
     * 构造事件信封。
     * Constructs the event envelope.
     *
     * @param string $source 事件来源标识（如实体 ID 或系统名） Event source identifier (e.g. entity ID or system name).
     * @param string $type 事件类型（建议使用 TYPE_* 常量） Event type (prefer the TYPE_* constants).
     * @param float $timestamp 事件产生时间戳（秒） Timestamp when the event was produced, in seconds.
     * @param null|string $targetScope 目标域（null 表示全局广播） Target scope; null means global broadcast.
     * @param bool $reliable 是否可靠投递（可靠事件不允许丢失） Whether delivery is reliable (reliable events must not be lost).
     * @param bool $droppable 是否允许在拥塞时丢弃 Whether the event may be dropped under congestion.
     * @param array<string, mixed> $payload 事件负载（键值对） Event payload (key-value pairs).
     */
    public function __construct(
        public string $source,
        public string $type,
        public float $timestamp,
        public ?string $targetScope,
        public bool $reliable,
        public bool $droppable,
        public array $payload,
    ) {
    }
}
