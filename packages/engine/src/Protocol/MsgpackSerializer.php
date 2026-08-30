<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * MessagePack 序列化器：Message 与帧字节之间的双向转换（ADR-022 双轨制之 MessagePack 轨）。
 * 纯 PHP 实现、零外部依赖：手写最小 MessagePack 编解码器，覆盖协议实际用到的类型——
 * nil/bool/int（正负各长度段）/float64/string（fixstr/str8/16/32；非法 UTF-8 字节串走 bin8/16/32）/
 * list（fixarray/array16/32）/map（fixmap/map16/32）；ext/时间戳扩展类型不支持。
 * 帧结构与 JsonSerializer 同构（Frame 边界不变，仅编码段替换）：顶层 msgpack map，
 * 键为 type/requestId/timestamp/payload。
 *
 * 热路径扩展点（architecture.md §5）：序列化替换点 = SerializerInterface + 装配层选择
 * （如把本类构造注入 JsonBatchSerializer，批量容器格式随单条序列化器切换）；
 * AOI 邻域/AoE 批命中替换点 = AOIProviderInterface::queryShape（已接口化）。
 *
 * MessagePack serializer: bidirectional conversion between Message and frame bytes (the MessagePack track of the
 * ADR-022 dual-track decision). Pure PHP with zero external dependencies: a hand-written minimal MessagePack codec
 * covering the types the protocol actually uses — nil/bool/int (all signed and unsigned width segments)/float64/
 * string (fixstr/str8/16/32; byte strings that are not valid UTF-8 travel as bin8/16/32)/lists (fixarray/array16/32)/
 * maps (fixmap/map16/32); ext/timestamp extension types are unsupported. The frame layout mirrors JsonSerializer
 * (Frame boundaries unchanged, only the encoding segment swapped): a top-level msgpack map keyed
 * type/requestId/timestamp/payload.
 *
 * Hot-path extension point (architecture.md §5): the serialization swap point is SerializerInterface plus
 * assembly-layer selection (e.g. constructor-inject this class into JsonBatchSerializer so the batch container format
 * follows the single serializer); the AOI neighborhood / AoE batch-hit swap point is AOIProviderInterface::queryShape
 * (already interfaced).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class MsgpackSerializer implements SerializerInterface
{
    /** 嵌套深度上限（与 json_decode 缺省 512 对齐）。 Nesting depth limit (aligned with json_decode's default of 512). */
    private const MAX_DEPTH = 512;

    /**
     * 将消息编码为 MessagePack 帧。
     * Encode a message into a MessagePack frame.
     *
     * @param Message $message 待编码的协议消息 Protocol message to encode.
     * @return FrameInterface 编码后的帧 Encoded frame.
     * @throws ProtocolException 编码失败（不支持的值类型/深度超限） Encoding failed (unsupported value type or depth overflow).
     */
    public function encode(Message $message): FrameInterface
    {
        return new Frame($this->pack([
            'type' => $message->type,
            'requestId' => $message->requestId,
            'timestamp' => $message->timestamp,
            'payload' => $message->payload,
        ]));
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
        $data = $this->unpack($frame->bytes());

        // 顶层必须是 msgpack map（关联数组），拒绝标量/list Top level must be a msgpack map (associative array); reject scalars/lists
        if (!is_array($data)) {
            throw new DecodeException('协议包顶层结构必须是 msgpack map');
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

    /**
     * 原始值编码：任意受支持的 PHP 值 → MessagePack 字节。
     * Raw value encoding: any supported PHP value → MessagePack bytes.
     *
     * @param mixed $value nil/bool/int/float/string/list/map Value to encode.
     * @return string MessagePack 字节 MessagePack bytes.
     * @throws ProtocolException 不支持的值类型或深度超限 Unsupported value type or depth overflow.
     */
    public function pack(mixed $value): string
    {
        return $this->packValue($value, 0);
    }

    /**
     * 原始值解码：MessagePack 字节 → PHP 值（严格单值：尾随字节即失败）。
     * Raw value decoding: MessagePack bytes → PHP value (strict single value: trailing bytes fail).
     *
     * @param string $bytes MessagePack 字节 MessagePack bytes.
     * @return mixed 解码后的值 Decoded value.
     * @throws DecodeException 非法/截断/尾随字节或保留类型码 Illegal/truncated/trailing bytes or reserved type codes.
     */
    public function unpack(string $bytes): mixed
    {
        [$value, $consumed] = $this->unpackValue($bytes, 0, 0);
        if ($consumed !== strlen($bytes)) {
            throw new DecodeException('值尾存在多余字节。Trailing bytes after value.');
        }

        return $value;
    }

    /** 按深度递归编码一个值。 Encode one value, recursing by depth. @throws ProtocolException */
    private function packValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ProtocolException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }

        return match (true) {
            $value === null => "\xc0",
            $value === true => "\xc3",
            $value === false => "\xc2",
            is_int($value) => $this->packInt($value),
            is_float($value) => "\xcb" . pack('E', $value),
            is_string($value) => $this->packString($value),
            is_array($value) => $this->packArray($value, $depth),
            default => throw new ProtocolException(sprintf('不支持的值类型：%s。Unsupported value type: %s.', get_debug_type($value), get_debug_type($value))),
        };
    }

    /** 编码整数：非负走 unsigned 各段，负数走 negative fixint 与 signed 各段。 Encode an integer: non-negative via unsigned segments, negatives via negative fixint and signed segments. */
    private function packInt(int $value): string
    {
        if ($value >= 0) {
            return match (true) {
                $value <= 0x7f => chr($value),
                $value <= 0xff => "\xcc" . chr($value),
                $value <= 0xffff => "\xcd" . pack('n', $value),
                $value <= 0xffffffff => "\xce" . pack('N', $value),
                default => "\xcf" . pack('NN', $value >> 32, $value & 0xffffffff),
            };
        }

        return match (true) {
            $value >= -32 => chr($value & 0xff),
            $value >= -128 => "\xd0" . chr($value & 0xff),
            $value >= -32768 => "\xd1" . pack('n', $value & 0xffff),
            $value >= -2147483648 => "\xd2" . pack('N', $value & 0xffffffff),
            default => "\xd3" . pack('NN', ($value >> 32) & 0xffffffff, $value & 0xffffffff),
        };
    }

    /** 编码字符串：合法 UTF-8 走 str 族，否则走 bin 族（二进制载荷字节精确保真）。 Encode a string: valid UTF-8 via str family, otherwise bin family (byte-exact for binary payloads). */
    private function packString(string $value): string
    {
        $length = strlen($value);
        if (preg_match('//u', $value) === 1) {
            return match (true) {
                $length <= 31 => chr(0xa0 | $length) . $value,
                $length <= 0xff => "\xd9" . chr($length) . $value,
                $length <= 0xffff => "\xda" . pack('n', $length) . $value,
                default => "\xdb" . pack('N', $length) . $value,
            };
        }

        return match (true) {
            $length <= 0xff => "\xc4" . chr($length) . $value,
            $length <= 0xffff => "\xc5" . pack('n', $length) . $value,
            default => "\xc6" . pack('N', $length) . $value,
        };
    }

    /**
     * 编码数组：list 走 array 族，关联数组走 map 族（键 int|string）。
     * Encode an array: lists via the array family, associative arrays via the map family (int|string keys).
     *
     * @param array<int|string, mixed> $value 待编码数组 Array to encode.
     * @throws ProtocolException
     */
    private function packArray(array $value, int $depth): string
    {
        $count = count($value);
        if (array_is_list($value)) {
            $header = match (true) {
                $count <= 15 => chr(0x90 | $count),
                $count <= 0xffff => "\xdc" . pack('n', $count),
                default => "\xdd" . pack('N', $count),
            };
            $body = '';
            foreach ($value as $item) {
                $body .= $this->packValue($item, $depth + 1);
            }

            return $header . $body;
        }

        $header = match (true) {
            $count <= 15 => chr(0x80 | $count),
            $count <= 0xffff => "\xde" . pack('n', $count),
            default => "\xdf" . pack('N', $count),
        };
        $body = '';
        foreach ($value as $key => $item) {
            $body .= $this->packValue(is_int($key) ? $key : (string) $key, $depth + 1);
            $body .= $this->packValue($item, $depth + 1);
        }

        return $header . $body;
    }

    /**
     * 按深度递归解码一个值，返回 [值, 自 offset 起消费的字节数（含头字节）]。
     * Decode one value recursing by depth; returns [value, bytes consumed from offset (header included)].
     *
     * @return array{0: mixed, 1: int}
     * @throws DecodeException
     */
    private function unpackValue(string $bytes, int $offset, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DecodeException(sprintf('嵌套深度超过上限 %d。Nesting depth exceeds the limit %d.', self::MAX_DEPTH, self::MAX_DEPTH));
        }
        $this->need($bytes, $offset, 1);
        $code = ord($bytes[$offset]);

        // 各分支返回的消费数一律含头字节。 Every branch's consumed count includes the header byte.
        if ($code <= 0x7f) {
            return [$code, 1];          // positive fixint Positive fixint
        }
        if ($code >= 0xe0) {
            return [$code - 0x100, 1];  // negative fixint Negative fixint
        }

        $offset++;
        if ($code <= 0x8f) {
            [$value, $consumed] = $this->unpackMap($bytes, $offset, $code & 0x0f, $depth);   // fixmap Fixmap

            return [$value, 1 + $consumed];
        }
        if ($code <= 0x9f) {
            [$value, $consumed] = $this->unpackList($bytes, $offset, $code & 0x0f, $depth);  // fixarray Fixarray

            return [$value, 1 + $consumed];
        }
        if ($code <= 0xbf) {
            $length = $code & 0x1f;     // fixstr Fixstr
            $this->need($bytes, $offset, $length);

            return [substr($bytes, $offset, $length), 1 + $length];
        }

        switch ($code) {
            case 0xc0:
                return [null, 1];
                // 0xc1 从不使用（规范保留） 0xc1 is never used (reserved by the spec)
            case 0xc1:
                throw new DecodeException('保留类型码 0xc1。Reserved type code 0xc1.');
            case 0xc2:
                return [false, 1];
            case 0xc3:
                return [true, 1];
            case 0xc4:                  // bin8 Bin8
                return $this->unpackSizedStr($bytes, $offset, 1);
            case 0xc5:                  // bin16 Bin16
                return $this->unpackSizedStr($bytes, $offset, 2);
            case 0xc6:                  // bin32 Bin32
                return $this->unpackSizedStr($bytes, $offset, 4);
            case 0xca:
                return [$this->f32($bytes, $offset), 5];
            case 0xcb:
                return [$this->f64($bytes, $offset), 9];
            case 0xcc:
                return [$this->u8($bytes, $offset), 2];
            case 0xcd:
                return [$this->u16($bytes, $offset), 3];
            case 0xce:
                return [$this->u32($bytes, $offset), 5];
            case 0xcf:
                return [$this->i64($bytes, $offset), 9];
            case 0xd0:
                return [$this->i8($bytes, $offset), 2];
            case 0xd1:
                return [$this->i16($bytes, $offset), 3];
            case 0xd2:
                return [$this->i32($bytes, $offset), 5];
            case 0xd3:
                return [$this->i64($bytes, $offset), 9];
            case 0xd9:                  // str8 Str8
                return $this->unpackSizedStr($bytes, $offset, 1);
            case 0xda:                  // str16 Str16
                return $this->unpackSizedStr($bytes, $offset, 2);
            case 0xdb:                  // str32 Str32
                return $this->unpackSizedStr($bytes, $offset, 4);
            case 0xdc:
                $count = $this->u16($bytes, $offset);
                [$value, $consumed] = $this->unpackList($bytes, $offset + 2, $count, $depth);

                return [$value, 3 + $consumed];
            case 0xdd:
                $count = $this->u32($bytes, $offset);
                [$value, $consumed] = $this->unpackList($bytes, $offset + 4, $count, $depth);

                return [$value, 5 + $consumed];
            case 0xde:
                $count = $this->u16($bytes, $offset);
                [$value, $consumed] = $this->unpackMap($bytes, $offset + 2, $count, $depth);

                return [$value, 3 + $consumed];
            case 0xdf:
                $count = $this->u32($bytes, $offset);
                [$value, $consumed] = $this->unpackMap($bytes, $offset + 4, $count, $depth);

                return [$value, 5 + $consumed];
                // 0xc7-0xc9 ext8/16/32 与 0xd4-0xd8 fixext 家族 The ext8/16/32 and fixext families
            default:
                throw new DecodeException(sprintf('不支持的类型码：0x%02x。Unsupported type code: 0x%02x.', $code, $code));
        }
    }

    /**
     * 解码带长度前缀的字节串（str8/16/32 与 bin8/16/32 共用），消费数含头字节与长度前缀。
     * Decode a length-prefixed byte string (shared by str8/16/32 and bin8/16/32); consumed includes header and prefix.
     *
     * @return array{0: string, 1: int}
     * @throws DecodeException
     */
    private function unpackSizedStr(string $bytes, int $offset, int $prefixLength): array
    {
        $length = match ($prefixLength) {
            1 => $this->u8($bytes, $offset),
            2 => $this->u16($bytes, $offset),
            default => $this->u32($bytes, $offset),
        };
        $this->need($bytes, $offset + $prefixLength, $length);

        return [substr($bytes, $offset + $prefixLength, $length), 1 + $prefixLength + $length];
    }

    /**
     * 解码 list，返回 [list, 消费字节数]。
     * Decode a list; returns [list, consumed bytes].
     *
     * @return array{0: list<mixed>, 1: int}
     * @throws DecodeException
     */
    private function unpackList(string $bytes, int $offset, int $count, int $depth): array
    {
        $base = $offset;
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            [$item, $consumed] = $this->unpackValue($bytes, $offset, $depth + 1);
            $list[] = $item;
            $offset += $consumed;
        }

        return [$list, $offset - $base];
    }

    /**
     * 解码 map，返回 [map, 消费字节数]；键必须解码为 int|string。
     * Decode a map; returns [map, consumed bytes]; keys must decode to int|string.
     *
     * @return array{0: array<int|string, mixed>, 1: int}
     * @throws DecodeException
     */
    private function unpackMap(string $bytes, int $offset, int $count, int $depth): array
    {
        $base = $offset;
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            [$key, $keyConsumed] = $this->unpackValue($bytes, $offset, $depth + 1);
            if (!is_int($key) && !is_string($key)) {
                throw new DecodeException('非法 map 键类型。Invalid map key type.');
            }
            $offset += $keyConsumed;
            [$value, $valueConsumed] = $this->unpackValue($bytes, $offset, $depth + 1);
            $map[$key] = $value;
            $offset += $valueConsumed;
        }

        return [$map, $offset - $base];
    }

    /** 校验缓冲长度足够。 Assert the buffer holds enough bytes. @throws DecodeException */
    private function need(string $bytes, int $offset, int $length): void
    {
        if ($offset + $length > strlen($bytes)) {
            throw new DecodeException('包体截断。Packet truncated.');
        }
    }

    /** @throws DecodeException */
    private function u8(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 1);

        return ord($bytes[$offset]);
    }

    /** @throws DecodeException */
    private function u16(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 2);
        $u = unpack('n', substr($bytes, $offset, 2));

        return $u === false ? throw new DecodeException('非法 u16 负载。Invalid u16 payload.') : $u[1];
    }

    /** @throws DecodeException */
    private function u32(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 4);
        $u = unpack('N', substr($bytes, $offset, 4));

        return $u === false ? throw new DecodeException('非法 u32 负载。Invalid u32 payload.') : $u[1];
    }

    /** 64 位模式重解释为有符号（PHP int 即 64 位有符号）。 Reinterpret as signed in 64-bit mode (PHP ints are 64-bit signed). @throws DecodeException */
    private function i64(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 8);
        $u = unpack('Nhi/Nlo', substr($bytes, $offset, 8));
        if ($u === false) {
            throw new DecodeException('非法 i64 负载。Invalid i64 payload.');
        }

        return ($u['hi'] << 32) | $u['lo'];
    }

    /** @throws DecodeException */
    private function i8(string $bytes, int $offset): int
    {
        $raw = $this->u8($bytes, $offset);

        return $raw > 0x7f ? $raw - 0x100 : $raw;
    }

    /** @throws DecodeException */
    private function i16(string $bytes, int $offset): int
    {
        $raw = $this->u16($bytes, $offset);

        return $raw > 0x7fff ? $raw - 0x10000 : $raw;
    }

    /** @throws DecodeException */
    private function i32(string $bytes, int $offset): int
    {
        $raw = $this->u32($bytes, $offset);

        return $raw > 0x7fffffff ? $raw - 0x100000000 : $raw;
    }

    /** big-endian 单精度浮点 Big-endian single-precision float. @throws DecodeException */
    private function f32(string $bytes, int $offset): float
    {
        $this->need($bytes, $offset, 4);
        $u = unpack('G', substr($bytes, $offset, 4));

        return $u === false ? throw new DecodeException('非法 f32 负载。Invalid f32 payload.') : $u[1];
    }

    /** big-endian 双精度浮点 Big-endian double-precision float. @throws DecodeException */
    private function f64(string $bytes, int $offset): float
    {
        $this->need($bytes, $offset, 8);
        $u = unpack('E', substr($bytes, $offset, 8));

        return $u === false ? throw new DecodeException('非法 f64 负载。Invalid f64 payload.') : $u[1];
    }
}
