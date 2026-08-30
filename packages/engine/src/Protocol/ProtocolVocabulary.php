<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 协议词汇表：维护「帧类型 ↔ 编码」与「负载字段名 ↔ 编码」的双向映射，供二进制序列化器使用。
 * 词汇表只描述映射关系，不持有编解码逻辑；游戏语义的枚举定义在业务层（如 demo 的 FrameType/PayloadKey），
 * 由组装层从枚举构建本词汇表注入序列化器——引擎保持通用，不感知具体帧类型。
 * Protocol vocabulary: the bidirectional "frame type ↔ code" and "payload key ↔ code" maps consumed by the binary
 * serializer. It only carries mappings, never codec logic; game-semantic enums live in the business layer (e.g. the
 * demo's FrameType/PayloadKey), from which the assembly layer builds this vocabulary and injects it into the
 * serializer — the engine stays generic and unaware of concrete frame types.
 */
final class ProtocolVocabulary
{
    /** @var array<int, string> typeCode => 帧类型名 frame-type name. */
    private readonly array $typeNames;

    /** @var array<int, string> keyCode => 负载字段名 payload-key name. */
    private readonly array $keyNames;

    /**
     * 构造词汇表并构建反向映射。
     * Creates the vocabulary and builds the reverse maps.
     *
     * @param array<string, int> $typeCodes 帧类型名 => 编码 frame-type name => code.
     * @param array<string, int> $keyCodes 负载字段名 => 编码 payload-key name => code.
     */
    public function __construct(
        private readonly array $typeCodes,
        private readonly array $keyCodes,
    ) {
        $this->typeNames = array_flip($typeCodes);
        $this->keyNames = array_flip($keyCodes);
    }

    /**
     * 帧类型名 → 编码；未知类型返回 null（调用方决定抛错或兜底）。
     * Frame-type name → code; null for unknown types (the caller decides to throw or fall back).
     */
    public function typeCode(string $type): ?int
    {
        return $this->typeCodes[$type] ?? null;
    }

    /**
     * 编码 → 帧类型名；未知编码返回 null。
     * Code → frame-type name; null for unknown codes.
     */
    public function typeName(int $code): ?string
    {
        return $this->typeNames[$code] ?? null;
    }

    /**
     * 负载字段名 → 编码；未知字段返回 null。
     * Payload-key name → code; null for unknown keys.
     */
    public function keyCode(string $key): ?int
    {
        return $this->keyCodes[$key] ?? null;
    }

    /**
     * 编码 → 负载字段名；未知编码返回 null。
     * Code → payload-key name; null for unknown codes.
     */
    public function keyName(int $code): ?string
    {
        return $this->keyNames[$code] ?? null;
    }
}
