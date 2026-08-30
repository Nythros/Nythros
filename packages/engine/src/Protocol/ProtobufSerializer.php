<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * Protobuf 序列化器：Message 与帧字节之间的双向转换（ADR-022 双轨制之 Protobuf 轨）。
 * 纯 PHP 实现、零外部依赖：手写最小 protobuf wire format——varint / fixed64 / length-delimited 三种
 * wire type，未知字段按规范跳过（向前兼容），group 类型（SGROUP/EGROUP）不支持。协议信封四字段
 * （type/requestId/timestamp/payload）与 nythros_message.proto 一一对应：proto3 IDL 即协议文档唯一事实源，
 * 本类是该 IDL 的参考实现。payload 为通用 Value 树（null/bool/int/float/string/bytes/list/map），
 * 非法 UTF-8 字节串走 bytes 分支（合法 UTF-8 走 string，与 MsgpackSerializer 的 str/bin 分流同口径）；
 * 整数数组键经 sint64 zigzag 承载，往返保留键类型。
 *
 * 帧结构与 JsonSerializer 同构（Frame 边界不变，仅编码段替换）。热路径扩展点（architecture.md §5）：
 * 序列化替换点 = SerializerInterface + 装配层选择（如把本类构造注入 JsonBatchSerializer，
 * 批量容器格式随单条序列化器切换）；类型安全由生成的 message 类承担时，本类仅做字节搬运（ADR-022 §3）。
 *
 * Protobuf serializer: bidirectional conversion between Message and frame bytes (the Protobuf track of the
 * ADR-022 dual-track decision). Pure PHP with zero external dependencies: a hand-written minimal protobuf wire
 * format — three wire types (varint/fixed64/length-delimited), unknown fields skipped per spec (forward
 * compatibility), group types (SGROUP/EGROUP) unsupported. The four envelope fields (type/requestId/timestamp/
 * payload) map one-to-one onto nythros_message.proto: the proto3 IDL is the single source of truth for the
 * protocol documentation and this class is its reference implementation. The payload is a generic Value tree
 * (null/bool/int/float/string/bytes/list/map); non-UTF-8 byte strings ride the bytes branch (valid UTF-8 rides
 * string, matching MsgpackSerializer's str/bin split); integer array keys travel as sint64 zigzag and keep their
 * key type across round trips.
 *
 * The frame layout mirrors JsonSerializer (Frame boundaries unchanged, only the encoding segment swapped).
 * Hot-path extension point (architecture.md §5): the serialization swap point is SerializerInterface plus
 * assembly-layer selection (e.g. constructor-inject this class into JsonBatchSerializer so the batch container
 * format follows the single serializer); when generated message classes carry the typing, this class only moves
 * bytes (ADR-022 §3).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class ProtobufSerializer implements SerializerInterface
{
    /** 嵌套深度上限（与 json_decode 缺省 512 对齐）。 Nesting depth limit (aligned with json_decode's default of 512). */
    private const MAX_DEPTH = 512;

    /** wire type：变长整数。 Wire type: varint. */
    private const WT_VARINT = 0;
    /** wire type：64 位定长（double）。 Wire type: 64-bit fixed (double). */
    private const WT_FIXED64 = 1;
    /** wire type：长度前缀字节串（string/bytes/子消息）。 Wire type: length-delimited (string/bytes/sub-message). */
    private const WT_LEN = 2;

    /**
     * 将消息编码为 protobuf 帧。
     * Encode a message into a protobuf frame.
     *
     * @param Message $message 待编码的协议消息 Protocol message to encode.
     * @return FrameInterface 编码后的帧 Encoded frame.
     * @throws ProtocolException 编码失败（不支持的值类型/深度超限） Encoding failed (unsupported value type or depth overflow).
     */
    public function encode(Message $message): FrameInterface
    {
        // 信封恒显式写出 type/timestamp/payload；requestId 为 null 时整字段缺席（proto3 optional 语义）。
        // The envelope always writes type/timestamp/payload explicitly; a null requestId omits the whole field
        // (proto3 optional semantics).
        $out = $this->lenField(1, $message->type);
        if ($message->requestId !== null) {
            $out .= $this->lenField(2, $message->requestId);
        }
        $out .= "\x19" . pack('e', $message->timestamp); // 字段 3 double：tag=(3<<3)|WT_FIXED64=0x19 field 3 double
        $payload = $this->packMap($message->payload, 0);
        $out .= "\x22" . $this->varint(strlen($payload)) . $payload; // 字段 4 Payload：tag=(4<<3)|WT_LEN=0x22 field 4

        return new Frame($out);
    }

    /**
     * 将帧字节解码为消息，并逐字段校验结构（与 JsonSerializer 同一口径）。
     * Decode frame bytes into a message, validating each field along the way (same contract as JsonSerializer).
     *
     * @param FrameInterface $frame 待解码的帧 Frame to decode.
     * @return Message 解码后的协议消息 Decoded protocol message.
     * @throws DecodeException 字节串不是合法协议包 Byte string is not a valid protocol packet.
     */
    public function decode(FrameInterface $frame): Message
    {
        $bytes = $frame->bytes();
        $end = strlen($bytes);
        $offset = 0;
        $type = null;
        $requestId = null;
        $timestamp = 0.0;
        $payload = [];

        while ($offset < $end) {
            $tag = $this->readVarint($bytes, $offset, $end);
            $field = $tag >> 3;
            $wireType = $tag & 0x07;
            if ($field === 0) {
                throw new DecodeException('非法字段号 0。Invalid field number 0.');
            }
            switch ($field) {
                case 1: // type（string） type (string)
                    $this->expectWireType($wireType, self::WT_LEN, 'type');
                    $type = $this->readLenBytes($bytes, $offset, $end);
                    break;
                case 2: // requestId（string，缺席即 null） requestId (string; absence means null)
                    $this->expectWireType($wireType, self::WT_LEN, 'requestId');
                    $requestId = $this->readLenBytes($bytes, $offset, $end);
                    break;
                case 3: // timestamp（fixed64 小端 double） timestamp (little-endian fixed64 double)
                    $this->expectWireType($wireType, self::WT_FIXED64, 'timestamp');
                    $timestamp = $this->readDouble($bytes, $offset, $end);
                    break;
                case 4: // payload（Payload 子消息） payload (a Payload sub-message)
                    $this->expectWireType($wireType, self::WT_LEN, 'payload');
                    $payload = $this->parseMap($this->readLenBytes($bytes, $offset, $end), 0);
                    break;
                default:
                    $this->skipField($bytes, $offset, $end, $wireType); // 未知字段跳过（向前兼容） Unknown fields are skipped (forward compatibility).
            }
        }

        // 校验 type：必须是非空字符串（与 JsonSerializer 同口径） type must be a non-empty string (same contract as JsonSerializer)
        if (!is_string($type) || $type === '') {
            throw new DecodeException('字段 type 必须是非空字符串');
        }
        // requestId 只经字符串分支赋值，无需再校验类型 requestId is only ever assigned from the string branch, no further type check needed

        return new Message($type, $requestId, $timestamp, $payload);
    }

    /**
     * 原始值编码：任意受支持的 PHP 值 → Value 子消息字节（供批量容器复用，见 JsonBatchSerializer）。
     * Raw value encoding: any supported PHP value → Value sub-message bytes (reused by the batch container, see JsonBatchSerializer).
     *
     * @param mixed $value null/bool/int/float/string/list/map Value to encode.
     * @return string Value 子消息字节 Value sub-message bytes.
     * @throws ProtocolException 不支持的值类型或深度超限 Unsupported value type or depth overflow.
     */
    public function pack(mixed $value): string
    {
        return $this->packValue($value, 0);
    }

    /**
     * 原始值解码：Value 子消息字节 → PHP 值（严格单值：整段缓冲必须恰为一个 Value）。
     * Raw value decoding: Value sub-message bytes → PHP value (strict single value: the whole buffer must be exactly one Value).
     *
     * @param string $bytes Value 子消息字节 Value sub-message bytes.
     * @return mixed 解码后的值（空 Value 即 null） Decoded value (an empty Value decodes to null).
     * @throws DecodeException 非法/截断字节或保留 wire type Illegal/truncated bytes or reserved wire types.
     */
    public function unpack(string $bytes): mixed
    {
        return $this->parseValue($bytes, 0);
    }

    /**
     * 编码一个值（Value 子消息体）：oneof kind 按值类型分流。
     * Encode one value (the Value sub-message body): the oneof kind splits by value type.
     *
     * @throws ProtocolException
     */
    private function packValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ProtocolException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }

        // 各分支返回完整 Value 体（kind 字段 tag + 载荷），由调用方加长度前缀。
        // Every branch returns the full Value body (kind-field tag + payload); callers add the length prefix.
        return match (true) {
            $value === null => "\x08\x00",                      // null_value=0（enum VARINT） null_value=0 (enum varint)
            $value === true => "\x10\x01",                      // bool_value=true bool_value=true
            $value === false => "\x10\x00",                     // bool_value=false bool_value=false
            is_int($value) => "\x18" . $this->varint($value),   // int_value（二补码 varint） int_value (two's-complement varint)
            is_float($value) => "\x21" . pack('e', $value),     // double_value（fixed64 小端） double_value (little-endian fixed64)
            is_string($value) => $this->packString($value),
            is_array($value) => $this->packArray($value, $depth),
            default => throw new ProtocolException(sprintf('不支持的值类型：%s。Unsupported value type: %s.', get_debug_type($value), get_debug_type($value))),
        };
    }

    /** 编码字符串：合法 UTF-8 走 string_value，否则走 bytes_value（二进制载荷字节精确保真）。 Encode a string: valid UTF-8 via string_value, otherwise bytes_value (byte-exact for binary payloads). */
    private function packString(string $value): string
    {
        // 合法 UTF-8 → string_value（字段 5，tag=0x2a）；否则 bytes_value（字段 6，tag=0x32）。
        // Valid UTF-8 → string_value (field 5, tag=0x2a); otherwise bytes_value (field 6, tag=0x32).
        if (preg_match('//u', $value) === 1) {
            return "\x2a" . $this->varint(strlen($value)) . $value;
        }

        return "\x32" . $this->varint(strlen($value)) . $value;
    }

    /**
     * 编码数组：list 走 ListValue，关联数组走 Payload（键 int|string）。
     * Encode an array: lists via ListValue, associative arrays via Payload (int|string keys).
     *
     * @param array<int|string, mixed> $value 待编码数组 Array to encode.
     * @throws ProtocolException
     */
    private function packArray(array $value, int $depth): string
    {
        if (array_is_list($value)) {
            // ListValue.values（字段 1，每项 tag=0x0a + varint 长度前缀） ListValue.values (field 1, each item prefixed tag=0x0a + varint length)
            $body = '';
            foreach ($value as $item) {
                $itemBytes = $this->packValue($item, $depth + 1);
                $body .= "\x0a" . $this->varint(strlen($itemBytes)) . $itemBytes;
            }

            return "\x3a" . $this->varint(strlen($body)) . $body; // list_value（字段 7，tag=0x3a） list_value (field 7, tag=0x3a)
        }

        $mapBody = $this->packMap($value, $depth);

        return "\x42" . $this->varint(strlen($mapBody)) . $mapBody; // map_value（字段 8，tag=0x42） map_value (field 8, tag=0x42)
    }

    /**
     * 编码关联数组为 Payload 体（repeated MapEntry 的拼接，不含外层长度前缀）；
     * 信封 payload 字段与嵌套 map_value 共用本方法。
     * Encode an associative array as a Payload body (concatenated repeated MapEntry, without the outer length
     * prefix); shared by the envelope payload field and nested map_value.
     *
     * @param array<int|string, mixed> $map 待编码映射 Map to encode.
     * @throws ProtocolException
     */
    private function packMap(array $map, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ProtocolException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }

        $body = '';
        foreach ($map as $key => $value) {
            // 键 oneof：整数键走 sint64 zigzag（字段 2，tag=0x10），字符串键走 length-delimited（字段 1，tag=0x0a）。
            // Key oneof: integer keys via sint64 zigzag (field 2, tag=0x10), string keys length-delimited (field 1, tag=0x0a).
            $keyPart = is_int($key)
                // 左移溢出位丢弃 + 右移 63 算术扩展：PHP_INT_MIN << 1 = 0（丢弃符号位），>> 63 = -1（全 1），XOR 还原 0xFFFFFFFFFFFFFFFF。
                // Left-shift discards overflow + arithmetic right-shift 63: PHP_INT_MIN << 1 = 0 (drops sign bit), >> 63 = -1 (all-ones), XOR yields 0xFFFFFFFFFFFFFFFF.
                ? "\x10" . $this->varint(($key << 1) ^ ($key >> 63))
                : "\x0a" . $this->varint(strlen((string) $key)) . (string) $key;
            $valueBytes = $this->packValue($value, $depth + 1);
            $entry = $keyPart . "\x1a" . $this->varint(strlen($valueBytes)) . $valueBytes; // value（字段 3，tag=0x1a） value (field 3, tag=0x1a)
            $body .= "\x0a" . $this->varint(strlen($entry)) . $entry;                       // entries（字段 1，tag=0x0a） entries (field 1, tag=0x0a)
        }

        return $body;
    }

    /**
     * 解析 Payload 体为关联数组（repeated MapEntry；键缺一即失败，未知字段跳过）。
     * Parse a Payload body into an associative array (repeated MapEntry; a missing key or value fails, unknown fields skip).
     *
     * @return array<int|string, mixed>
     * @throws DecodeException
     */
    private function parseMap(string $bytes, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DecodeException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }
        $end = strlen($bytes);
        $offset = 0;
        $map = [];

        while ($offset < $end) {
            $tag = $this->readVarint($bytes, $offset, $end);
            if (($tag >> 3) !== 1 || ($tag & 0x07) !== self::WT_LEN) {
                // 非 entries 字段：字段号 0 拒绝，其余按 wire type 跳过（向前兼容）
                // Not an entries field: field number 0 is rejected, the rest skip by wire type (forward compatibility).
                if (($tag >> 3) === 0) {
                    throw new DecodeException('非法字段号 0。Invalid field number 0.');
                }
                $this->skipField($bytes, $offset, $end, $tag & 0x07);

                continue;
            }

            $entry = $this->readLenBytes($bytes, $offset, $end);
            $entryEnd = strlen($entry);
            $entryOffset = 0;
            $hasKey = false;
            $key = null;
            $hasValue = false;
            $value = null;

            while ($entryOffset < $entryEnd) {
                $fieldTag = $this->readVarint($entry, $entryOffset, $entryEnd);
                $field = $fieldTag >> 3;
                $wireType = $fieldTag & 0x07;
                if ($field === 0) {
                    throw new DecodeException('非法字段号 0。Invalid field number 0.');
                }
                if ($field === 1 && $wireType === self::WT_LEN) {           // string_key String key
                    $key = $this->readLenBytes($entry, $entryOffset, $entryEnd);
                    $hasKey = true;
                } elseif ($field === 2 && $wireType === self::WT_VARINT) {  // int_key（sint64 zigzag） int_key (sint64 zigzag)
                    $zigzag = $this->readVarint($entry, $entryOffset, $entryEnd);
                    // 逻辑右移 1 位（掩掉算术右移的符号扩展位）+ LSB 符号位还原；覆盖 PHP_INT_MIN..PHP_INT_MAX 全范围。
                    // Logical right-shift 1 (mask sign-extension from arithmetic shift) plus LSB sign-restore; covers full PHP_INT_MIN..PHP_INT_MAX.
                    $key = (($zigzag >> 1) & PHP_INT_MAX) ^ -($zigzag & 1);
                    $hasKey = true;
                } elseif ($field === 3 && $wireType === self::WT_LEN) {     // value Value
                    $value = $this->parseValue($this->readLenBytes($entry, $entryOffset, $entryEnd), $depth + 1);
                    $hasValue = true;
                } else {
                    $this->skipField($entry, $entryOffset, $entryEnd, $wireType);
                }
            }

            if (!$hasKey || !$hasValue) {
                throw new DecodeException('MapEntry 缺少键或值。MapEntry is missing its key or value.');
            }
            /** @var int|string $key */
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * 解析 Value 体为 PHP 值（oneof kind 后写胜出；未设任何 kind 即 null；未知字段跳过）。
     * Parse a Value body into a PHP value (the oneof kind is last-wins; no kind set means null; unknown fields skip).
     *
     * @throws DecodeException
     */
    private function parseValue(string $bytes, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DecodeException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }
        $end = strlen($bytes);
        $offset = 0;
        $value = null;

        while ($offset < $end) {
            $tag = $this->readVarint($bytes, $offset, $end);
            $field = $tag >> 3;
            $wireType = $tag & 0x07;
            if ($field === 0) {
                throw new DecodeException('非法字段号 0。Invalid field number 0.');
            }
            switch ($field) {
                case 1: // null_value（enum VARINT） null_value (enum varint)
                    $this->expectWireType($wireType, self::WT_VARINT, 'null_value');
                    $this->readVarint($bytes, $offset, $end);
                    $value = null;
                    break;
                case 2: // bool_value（VARINT，非零即 true） bool_value (varint, non-zero is true)
                    $this->expectWireType($wireType, self::WT_VARINT, 'bool_value');
                    $value = $this->readVarint($bytes, $offset, $end) !== 0;
                    break;
                case 3: // int_value（二补码 varint） int_value (two's-complement varint)
                    $this->expectWireType($wireType, self::WT_VARINT, 'int_value');
                    $value = $this->readVarint($bytes, $offset, $end);
                    break;
                case 4: // double_value（fixed64 小端） double_value (little-endian fixed64)
                    $this->expectWireType($wireType, self::WT_FIXED64, 'double_value');
                    $value = $this->readDouble($bytes, $offset, $end);
                    break;
                case 5: // string_value（合法 UTF-8） string_value (valid UTF-8)
                case 6: // bytes_value（原始字节） bytes_value (raw bytes)
                    $this->expectWireType($wireType, self::WT_LEN, 'string_value/bytes_value');
                    $value = $this->readLenBytes($bytes, $offset, $end);
                    break;
                case 7: // list_value（ListValue 子消息） list_value (a ListValue sub-message)
                    $this->expectWireType($wireType, self::WT_LEN, 'list_value');
                    $value = $this->parseList($this->readLenBytes($bytes, $offset, $end), $depth + 1);
                    break;
                case 8: // map_value（Payload 子消息） map_value (a Payload sub-message)
                    $this->expectWireType($wireType, self::WT_LEN, 'map_value');
                    $value = $this->parseMap($this->readLenBytes($bytes, $offset, $end), $depth + 1);
                    break;
                default:
                    $this->skipField($bytes, $offset, $end, $wireType);
            }
        }

        return $value;
    }

    /**
     * 解析 ListValue 体为 list 数组（repeated Value，保序）。
     * Parse a ListValue body into a list array (repeated Value, order-preserving).
     *
     * @return list<mixed>
     * @throws DecodeException
     */
    private function parseList(string $bytes, int $depth): array
    {
        $end = strlen($bytes);
        $offset = 0;
        $list = [];

        while ($offset < $end) {
            $tag = $this->readVarint($bytes, $offset, $end);
            if (($tag >> 3) !== 1 || ($tag & 0x07) !== self::WT_LEN) {
                if (($tag >> 3) === 0) {
                    throw new DecodeException('非法字段号 0。Invalid field number 0.');
                }
                $this->skipField($bytes, $offset, $end, $tag & 0x07);

                continue;
            }
            $list[] = $this->parseValue($this->readLenBytes($bytes, $offset, $end), $depth + 1);
        }

        return $list;
    }

    /** 长度前缀字段编码：单字节 tag（字段号 ≤ 15）+ varint 长度 + 内容。 Length-delimited field encoding: single-byte tag (field number ≤ 15) + varint length + content. */
    private function lenField(int $field, string $bytes): string
    {
        return chr(($field << 3) | self::WT_LEN) . $this->varint(strlen($bytes)) . $bytes;
    }

    /**
     * 无符号 64 位 varint 编码：负数按二补码位型展开为 10 字节（算术右移符号扩展 + 末字节截位）。
     * Unsigned 64-bit varint encoding: negatives expand to 10 bytes via their two's-complement bit pattern
     * (arithmetic right-shift sign extension + masked final byte).
     */
    private function varint(int $value): string
    {
        if ($value < 0) {
            $out = '';
            for ($i = 0; $i < 10; $i++) {
                // 前 9 字节带续位；第 10 字节只剩位 63，掩去符号扩展进位 Only bit 63 remains for the 10th byte; mask off sign-extension carry.
                $out .= chr($i === 9 ? ($value & 0x01) : (($value & 0x7f) | 0x80));
                $value >>= 7;
            }

            return $out;
        }

        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $out .= chr($value === 0 ? $byte : ($byte | 0x80));
        } while ($value !== 0);

        return $out;
    }

    /**
     * 无符号 64 位 varint 解码：最多 10 字节，第 10 字节只允许 0/1（超出 64 位即失败）。
     * Unsigned 64-bit varint decoding: at most 10 bytes; the 10th may only be 0/1 (anything wider fails).
     *
     * @throws DecodeException
     */
    private function readVarint(string $bytes, int &$offset, int $end): int
    {
        $result = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($offset >= $end) {
                throw new DecodeException('包体截断。Packet truncated.');
            }
            $byte = ord($bytes[$offset++]);
            if ($i === 9) {
                if ($byte > 1) {
                    throw new DecodeException('varint 超过 64 位。Varint exceeds 64 bits.');
                }
                $result |= $byte << 63;

                return $result;
            }
            $result |= ($byte & 0x7f) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $result;
            }
        }

        throw new DecodeException('非法 varint。Invalid varint.'); // 循环内必返回，此处仅为静态分析兜底 Unreachable (the loop always returns); a static-analysis backstop only.
    }

    /** 读 fixed64 小端 double。 Read a little-endian fixed64 double. @throws DecodeException */
    private function readDouble(string $bytes, int &$offset, int $end): float
    {
        if ($offset + 8 > $end) {
            throw new DecodeException('包体截断。Packet truncated.');
        }
        $u = unpack('e', substr($bytes, $offset, 8));
        $offset += 8;

        return $u === false ? throw new DecodeException('非法 double 负载。Invalid double payload.') : $u[1];
    }

    /** 读长度前缀字节串（varint 长度 + 内容，含越界校验）。 Read a length-prefixed byte string (varint length + content, bounds-checked). @throws DecodeException */
    private function readLenBytes(string $bytes, int &$offset, int $end): string
    {
        $length = $this->readVarint($bytes, $offset, $end);
        if ($length < 0 || $offset + $length > $end) {
            throw new DecodeException('包体截断。Packet truncated.');
        }
        $chunk = substr($bytes, $offset, $length);
        $offset += $length;

        return $chunk;
    }

    /** 已知字段的 wire type 校验（错配即畸形包）。 Wire-type validation for known fields (a mismatch means a malformed packet). @throws DecodeException */
    private function expectWireType(int $actual, int $expected, string $field): void
    {
        if ($actual !== $expected) {
            throw new DecodeException(sprintf('字段 %s wire type 错配。Wire-type mismatch on field %s.', $field, $field));
        }
    }

    /**
     * 按规范跳过未知字段（varint/fixed64/length-delimited/fixed32）；group（3/4）与保留（6/7）不支持。
     * Skip unknown fields per spec (varint/fixed64/length-delimited/fixed32); groups (3/4) and reserved (6/7) unsupported.
     *
     * @throws DecodeException
     */
    private function skipField(string $bytes, int &$offset, int $end, int $wireType): void
    {
        switch ($wireType) {
            case self::WT_VARINT:
                $this->readVarint($bytes, $offset, $end);
                return;
            case self::WT_FIXED64:
                if ($offset + 8 > $end) {
                    throw new DecodeException('包体截断。Packet truncated.');
                }
                $offset += 8;
                return;
            case self::WT_LEN:
                $this->readLenBytes($bytes, $offset, $end);
                return;
            case 5: // fixed32 fixed32
                if ($offset + 4 > $end) {
                    throw new DecodeException('包体截断。Packet truncated.');
                }
                $offset += 4;
                return;
            default:
                throw new DecodeException(sprintf('不支持的 wire type：%d。Unsupported wire type: %d.', $wireType, $wireType));
        }
    }
}
