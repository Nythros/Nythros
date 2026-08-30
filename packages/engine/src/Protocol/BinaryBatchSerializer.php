<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 二进制批量序列化器：把 N 条 Message 编码为一条 WebSocket 二进制包，并对帧类型/字段名做枚举压缩。
 *
 * 批量包布局（全部大端，pack('N')）：
 *   [4B 魔数 "NX\0\x01"][4B 帧数 count][ 逐帧：4B 长度 len + len 字节帧体 ... ]
 * 帧体布局：
 *   [2B 字段数][ 逐字段：2B keyCode + 1B valueType + 负载 ... ]
 *
 * 字段 keyCode 语义（保留给本实现的固定字段，处于高位 0xF1-0xF3，负载字段从 1 起自由分配，互不冲突）：
 *   0xF3 = type（STRING，恒有）  0xF2 = requestId（STRING，可选）  0xF1 = timestamp（FLOAT，可选）
 *
 * valueType（1 字节）：
 *   0x00 NUL 空值        0x01 INT 有符号 64 位 (pack('q'))   0x02 FLOAT 双精度
 *   0x03 STRING 短串（1B 长度 + UTF-8）  0x04 STRING32 长串（4B 长度）
 *   0x05 LIST 长度前缀列表（4B 元素数 + 每元素 1B 元素类型 + 负载）
 *   0x06 POS 定长坐标（2B int16 x + 2B int16 y）
 *   0x07 EMPTY_STRING 空串（无负载）   0xF0 TRUE / 0xF1 FALSE
 *
 * 核心目标：把自描述 JSON（type/requestId/timestamp/payload + 长字段名）压缩为词表驱动的紧凑结构；
 * requestId=null、timestamp=0.0 等默认值跳过以省字节。未知帧类型/字段/值类型抛 ProtocolException（单一来源，严格）。
 * 该二进制路径与 JsonSerializer/JsonBatchSerializer 并存——JSON 路径继续服务社交/网关层，互不影响。
 * 中英双语注释遵循仓库规范。
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class BinaryBatchSerializer implements BatchSerializerInterface
{
    /** 批量包魔数：'NX' + 协议版本字节。 Batch magic: 'NX' + protocol version. */
    private const MAGIC = "\x4e\x58\x00\x01";

    // 值类型码。 Value-type codes (1 byte each).
    private const T_NULL = 0x00;
    private const T_INT = 0x01;
    private const T_FLOAT = 0x02;
    private const T_STRING = 0x03;
    private const T_STRING32 = 0x04;
    private const T_LIST = 0x05;
    private const T_POS = 0x06;
    private const T_EMPTY_STRING = 0x07;
    private const T_TRUE = 0xF0;
    private const T_FALSE = 0xF1;

    /** 保留的固定字段 keyCode（高位段，避开负载字段从 1 起的编码空间）：type 恒有；requestId/timestamp 有值才编码。
     *  Reserved fixed key codes (high segment, clear of the payload-key space that starts at 1): type is always
     *  present; requestId/timestamp are encoded only when they carry a value. */
    private const K_TIMESTAMP = 0xF1;
    private const K_REQUEST_ID = 0xF2;
    private const K_TYPE = 0xF3;

    /** 每字段固定槽位字节：2B keyCode + 1B valueType。 */
    private const FIELD_SLOT = 3;

    /**
     * @param ProtocolVocabulary $vocab 帧类型与负载字段名的词编码映射 Type/key vocabulary.
     * @param bool $encodeTimestamp 是否编码 timestamp 字段（false 则省略） Whether to encode the timestamp field.
     */
    public function __construct(
        private readonly ProtocolVocabulary $vocab,
        private readonly bool $encodeTimestamp = false,
    ) {
    }

    /** 单帧编码（布局与批量帧体一致），供单包单帧路径使用。 @throws ProtocolException */
    public function encode(Message $message): FrameInterface
    {
        return new Frame($this->encodeFrameBody($message));
    }

    /** 多帧批量编码。 @throws ProtocolException */
    public function encodeBatch(array $messages): string
    {
        $packet = self::MAGIC . pack('N', count($messages));
        foreach ($messages as $message) {
            $body = $this->encodeFrameBody($message);
            $packet .= pack('N', strlen($body)) . $body;
        }

        return $packet;
    }

    /** 单帧解码。 @throws DecodeException */
    public function decode(FrameInterface $frame): Message
    {
        return $this->decodeFrameBody($frame->bytes());
    }

    /** 批量包解码；空包返回空列表。 @throws DecodeException */
    public function decodeBatch(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        $this->assertMagic($bytes);
        $offset = strlen(self::MAGIC);
        $count = $this->u32($bytes, $offset, 'count');
        $offset += 4;

        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $len = $this->u32($bytes, $offset, 'frame length');
            $offset += 4;
            $messages[] = $this->decodeFrameBody(substr($bytes, $offset, $len));
            $offset += $len;
        }

        return $messages;
    }

    /**
     * 编码一帧帧体。
     * @throws ProtocolException 未知类型/未知字段/不支持的值类型。
     */
    private function encodeFrameBody(Message $message): string
    {
        $typeCode = $this->vocab->typeCode($message->type);
        if ($typeCode === null) {
            throw new ProtocolException(sprintf('未知帧类型: %s。Unknown frame type: %s.', $message->type, $message->type));
        }

        // 固定字段段：type(必)、requestId(有值)、timestamp(可选开启且有值)
        $fixed = $this->encString(self::K_TYPE, $message->type);
        $fieldCount = 1;
        if ($message->requestId !== null) {
            $fixed .= $this->encString(self::K_REQUEST_ID, $message->requestId);
            $fieldCount++;
        }
        if ($this->encodeTimestamp && $message->timestamp !== 0.0) {
            $fixed .= pack('nC', self::K_TIMESTAMP, self::T_FLOAT) . pack('d', $message->timestamp);
            $fieldCount++;
        }

        // 负载字段段：字段名经词汇表映射为 keyCode；fieldCount 按真实字段数累加（每个字段 = 2B key + 1B 类型 + 值负载）
        $payload = '';
        foreach ($message->payload as $key => $value) {
            $keyCode = $this->vocab->keyCode((string) $key);
            if ($keyCode === null) {
                throw new ProtocolException(sprintf('未知负载字段: %s。Unknown payload key: %s.', (string) $key, (string) $key));
            }
            $payload .= $this->encodeValue($keyCode, $value);
            $fieldCount++;
        }

        return pack('n', $fieldCount) . $fixed . $payload;
    }

    /** 解码一帧帧体。 @throws DecodeException */
    private function decodeFrameBody(string $bytes): Message
    {
        $fieldCount = $this->u16($bytes, 0, 'fieldCount');
        $offset = 2;
        $type = null;
        $requestId = null;
        $timestamp = 0.0;
        $payload = [];

        for ($i = 0; $i < $fieldCount; $i++) {
            if ($offset + self::FIELD_SLOT > strlen($bytes)) {
                throw new DecodeException('字段槽位越界。Field slot out of bounds.');
            }
            $keyCode = $this->u16($bytes, $offset, 'keyCode');
            $valueType = ord($bytes[$offset + 2]);
            $offset += self::FIELD_SLOT;

            if ($keyCode === self::K_TYPE) {
                $type = $this->decString($bytes, $offset, $valueType);
                $offset += $this->stringByteLen($bytes, $offset, $valueType);
                continue;
            }
            if ($keyCode === self::K_REQUEST_ID) {
                $requestId = $this->decString($bytes, $offset, $valueType);
                $offset += $this->stringByteLen($bytes, $offset, $valueType);
                continue;
            }
            if ($keyCode === self::K_TIMESTAMP) {
                if ($valueType !== self::T_FLOAT) {
                    throw new DecodeException('timestamp 字段类型错误。Timestamp field type mismatch.');
                }
                $timestamp = $this->f64($bytes, $offset);
                $offset += 8;
                continue;
            }

            // 普通负载字段：通过词汇表恢复字段名
            $key = $this->vocab->keyName($keyCode);
            if ($key === null) {
                throw new DecodeException(sprintf('未知 keyCode: %d。Unknown key code: %d.', $keyCode, $keyCode));
            }
            [$value, $consumed] = $this->decodeValue($bytes, $offset, $valueType);
            $offset += $consumed;
            $payload[$key] = $value;
        }

        if ($type === null) {
            throw new DecodeException('帧体缺少 type 字段。Frame body lacks the type field.');
        }

        return new Message($type, $requestId, $timestamp, $payload);
    }

    /** 编码一个字段：keyCode + valueType + 负载，自动按值类型选择编码。 */
    private function encodeValue(int $keyCode, mixed $value): string
    {
        if (is_string($value)) {
            if ($value === '') {
                return pack('nC', $keyCode, self::T_EMPTY_STRING);
            }

            return strlen($value) <= 255
                ? pack('nC', $keyCode, self::T_STRING) . pack('C', strlen($value)) . $value
                : pack('nC', $keyCode, self::T_STRING32) . pack('N', strlen($value)) . $value;
        }

        return match (true) {
            $value === null => pack('nC', $keyCode, self::T_NULL),
            $value === true => pack('nC', $keyCode, self::T_TRUE),
            $value === false => pack('nC', $keyCode, self::T_FALSE),
            is_int($value) => pack('nC', $keyCode, self::T_INT) . pack('q', $value),
            is_float($value) => pack('nC', $keyCode, self::T_FLOAT) . pack('d', $value),
            $this->isPositionList($value) => pack('nC', $keyCode, self::T_POS) . pack('nn', $value['x'], $value['y']),
            is_array($value) => $this->encodeList($keyCode, $value),
            default => throw new ProtocolException(sprintf('不支持的值类型（字段 %d）。Unsupported value type for key %d.', $keyCode, $keyCode)),
        };
    }

    /**
     * 编码 LIST 字段：4B 元素数 + 每元素 1B 类型 + 负载。
     *
     * @param array<int, mixed> $value 列表值 List value.
     */
    private function encodeList(int $keyCode, array $value): string
    {
        $out = pack('nC', $keyCode, self::T_LIST) . pack('N', count($value));
        foreach ($value as $element) {
            if (is_int($element)) {
                $out .= chr(self::T_INT) . pack('q', $element);
            } elseif (is_float($element)) {
                $out .= chr(self::T_FLOAT) . pack('d', $element);
            } elseif (is_string($element)) {
                $out .= strlen($element) <= 255
                    ? chr(self::T_STRING) . pack('C', strlen($element)) . $element
                    : chr(self::T_STRING32) . pack('N', strlen($element)) . $element;
            } elseif (is_bool($element)) {
                $out .= $element ? chr(self::T_TRUE) : chr(self::T_FALSE);
            } elseif ($element === null) {
                $out .= chr(self::T_NULL);
            } elseif (is_array($element) && $this->isPositionList($element)) {
                $out .= chr(self::T_POS) . pack('nn', $element['x'], $element['y']);
            } else {
                throw new ProtocolException('LIST 元素类型不支持。Unsupported LIST element type.');
            }
        }

        return $out;
    }

    /**
     * 解码一个值，返回 [值, 消费字节数]（消费字节数自函数入口基准确算，含长度前缀等）。
     * Decodes one value as [value, consumed bytes] (consumed is measured from the entry base, length prefixes included).
     *
     * @return array{0: mixed, 1: int}
     */
    private function decodeValue(string $bytes, int $offset, int $valueType): array
    {
        $base = $offset;
        switch ($valueType) {
            case self::T_NULL:
                return [null, 0];
            case self::T_TRUE:
                return [true, 0];
            case self::T_FALSE:
                return [false, 0];
            case self::T_INT:
                $this->need($bytes, $offset, 8);
                $u = unpack('q', substr($bytes, $offset, 8));

                return [$u === false ? throw new DecodeException('非法 q 负载。Invalid q payload.') : $u[1], 8];
            case self::T_FLOAT:
                $this->need($bytes, $offset, 8);
                $u = unpack('d', substr($bytes, $offset, 8));

                return [$u === false ? throw new DecodeException('非法 d 负载。Invalid d payload.') : $u[1], 8];
            case self::T_STRING:
                $len = ord((string) ($bytes[$offset] ?? "\x00"));
                $this->need($bytes, $offset, 1 + $len);
                return [substr($bytes, $offset + 1, $len), 1 + $len];
            case self::T_STRING32:
                $len = $this->u32($bytes, $offset, 'string length');
                $this->need($bytes, $offset, 4 + $len);
                return [substr($bytes, $offset + 4, $len), 4 + $len];
            case self::T_EMPTY_STRING:
                return ['', 0];
            case self::T_POS:
                $this->need($bytes, $offset, 4);
                return [['x' => $this->i16($bytes, $offset), 'y' => $this->i16($bytes, $offset + 2)], 4];
            case self::T_LIST:
                $count = $this->u32($bytes, $offset, 'list count');
                $offset += 4;
                $list = [];
                for ($i = 0; $i < $count; $i++) {
                    $elemType = ord((string) ($bytes[$offset] ?? "\x00"));
                    $offset += 1;
                    [$v, $consumed] = $this->decodeValue($bytes, $offset, $elemType);
                    $offset += $consumed;
                    $list[] = $v;
                }
                // consumed 必须从函数入口基准确算（含 4B 元素计数），否则上层指针会偏移 4 字节
                // consumed is measured from the entry base (including the 4B element count), or the caller's pointer drifts by 4 bytes
                return [$list, $offset - $base];
            default:
                throw new DecodeException(sprintf('未知值类型: 0x%02x。Unknown value type: 0x%02x.', $valueType, $valueType));
        }
    }

    /** 编码一个 string 固定字段（keyCode + valueType + 负载）。 */
    private function encString(int $keyCode, string $value): string
    {
        if ($value === '') {
            return pack('nC', $keyCode, self::T_EMPTY_STRING);
        }

        return strlen($value) <= 255
            ? pack('nC', $keyCode, self::T_STRING) . pack('C', strlen($value)) . $value
            : pack('nC', $keyCode, self::T_STRING32) . pack('N', strlen($value)) . $value;
    }

    /** 解码一个 string 字段（用于 type/requestId）。 */
    private function decString(string $bytes, int $offset, int $valueType): string
    {
        switch ($valueType) {
            case self::T_STRING:
                $len = ord((string) ($bytes[$offset] ?? "\x00"));
                $this->need($bytes, $offset, 1 + $len);
                return substr($bytes, $offset + 1, $len);
            case self::T_STRING32:
                $len = $this->u32($bytes, $offset, 'string length');
                $this->need($bytes, $offset, 4 + $len);
                return substr($bytes, $offset + 4, $len);
            case self::T_EMPTY_STRING:
                return '';
            default:
                throw new DecodeException('字符串字段类型错误。String field type mismatch.');
        }
    }

    /** 返回一个 string 字段负载的字节数（供解码指针推进）。 */
    private function stringByteLen(string $bytes, int $offset, int $valueType): int
    {
        switch ($valueType) {
            case self::T_STRING:
                return 1 + ord((string) ($bytes[$offset] ?? "\x00"));
            case self::T_STRING32:
                return 4 + $this->u32($bytes, $offset, 'string length');
            case self::T_EMPTY_STRING:
                return 0;
            default:
                throw new DecodeException('字符串字段类型错误。String field type mismatch.');
        }
    }

    /** 校验魔数。 @throws DecodeException */
    private function assertMagic(string $bytes): void
    {
        if (strlen($bytes) < strlen(self::MAGIC) || substr($bytes, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new DecodeException('非本协议二进制包（魔数不匹配）。Not a valid binary packet (magic mismatch).');
        }
    }

    /** 校验缓冲长度足够。 @throws DecodeException */
    private function need(string $bytes, int $offset, int $length): void
    {
        if ($offset + $length > strlen($bytes)) {
            throw new DecodeException('包体截断。Packet truncated.');
        }
    }

    private function u32(string $bytes, int $offset, string $label): int
    {
        $this->need($bytes, $offset, 4);
        $u = unpack('N', substr($bytes, $offset, 4));

        return $u === false ? throw new DecodeException('非法 u32 负载。Invalid u32 payload.') : $u[1];
    }

    private function u16(string $bytes, int $offset, string $label): int
    {
        $this->need($bytes, $offset, 2);
        $u = unpack('n', substr($bytes, $offset, 2));

        return $u === false ? throw new DecodeException('非法 u16 负载。Invalid u16 payload.') : $u[1];
    }

    private function i16(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 2);
        $u = unpack('n', substr($bytes, $offset, 2));
        if ($u === false) {
            throw new DecodeException('非法 i16 负载。Invalid i16 payload.');
        }
        $raw = $u[1];

        return $raw > 0x7fff ? $raw - 0x10000 : $raw;
    }

    private function f64(string $bytes, int $offset): float
    {
        $this->need($bytes, $offset, 8);
        $u = unpack('d', substr($bytes, $offset, 8));

        return $u === false ? throw new DecodeException('非法 f64 负载。Invalid f64 payload.') : $u[1];
    }

    /**
     * 判断是否为 position 形状（键恰为 ['x','y'] 且均为 int）。
     * Detects a position shape (keys exactly ['x','y'], both ints).
     *
     * @param array<string, mixed> $value
     */
    private function isPositionList(array $value): bool
    {
        return isset($value['x'], $value['y']) && is_int($value['x']) && is_int($value['y']) && array_keys($value) === ['x', 'y'];
    }
}
