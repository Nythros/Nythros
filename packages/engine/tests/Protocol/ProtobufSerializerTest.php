<?php

declare(strict_types=1);

namespace Nythros\Protocol\Tests;

use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\MsgpackSerializer;
use Nythros\Protocol\ProtobufSerializer;
use Nythros\Protocol\ProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ProtobufSerializerTest - 手写最小 protobuf wire format 的已知十六进制向量、边界与 Message 语义覆盖。
 * 向量表双向驱动：encode/pack 必须产出已知字节（与 nythros_message.proto IDL 锚定），decode/unpack
 * 必须还原原值；另覆盖 varint 宽度边界（64 位上限/负数二补码）、zigzag 整数键、UTF-8 与二进制分流、
 * 深度上限、截断/错配 wire type 失败路径，以及与 JsonSerializer/MsgpackSerializer 的 Message 语义等价性。
 * ProtobufSerializerTest: known hex vectors for the hand-written minimal protobuf wire format, boundaries and
 * Message semantics. The vector table drives both directions: encode/pack must emit the known bytes (anchored to
 * the nythros_message.proto IDL) and decode/unpack must restore the value; it also covers varint width boundaries
 * (the 64-bit ceiling / two's-complement negatives), zigzag integer keys, the UTF-8 vs binary split, the depth
 * limit, truncated/mismatched-wire-type failure paths, and Message semantic equivalence with JsonSerializer and
 * MsgpackSerializer.
 */
final class ProtobufSerializerTest extends TestCase
{
    private ProtobufSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ProtobufSerializer();
    }

    /** 已知十六进制向量（wire format 规范锚点）：双向表驱动。 Known hex vectors (wire-format anchors): bidirectional table-driven. */
    public static function provideKnownVectors(): iterable
    {
        // null / 布尔（oneof kind 字段 1/2） null / booleans (oneof kind fields 1/2)
        yield 'nil' => [null, '0800'];
        yield 'true' => [true, '1001'];
        yield 'false' => [false, '1000'];

        // 整数各宽度段（字段 3 二补码 varint：单字节 / 多字节 / 负数 10 字节 / int64 极值）
        // Integer width segments (field 3 two's-complement varint: single byte / multi-byte / 10-byte negatives / int64 extremes)
        yield 'int-0' => [0, '1800'];
        yield 'int-1' => [1, '1801'];
        yield 'int-127' => [127, '187f'];
        yield 'int-128' => [128, '188001'];
        yield 'int-300' => [300, '18ac02'];
        yield 'int--1' => [-1, '18ffffffffffffffffff01'];
        yield 'int-min' => [PHP_INT_MIN, '1880808080808080808001'];
        yield 'int-max' => [PHP_INT_MAX, '18ffffffffffffffff7f'];

        // float64（字段 4 fixed64 小端） float64 (field 4 little-endian fixed64)
        yield 'float-1.5' => [1.5, '21000000000000f83f'];
        yield 'float-0.0' => [0.0, '210000000000000000'];
        yield 'float--2.25' => [-2.25, '2100000000000002c0'];

        // 字符串：合法 UTF-8 走 string_value（字段 5），非法 UTF-8 字节串走 bytes_value（字段 6）
        // Strings: valid UTF-8 via string_value (field 5), non-UTF-8 byte strings via bytes_value (field 6)
        yield 'str-empty' => ['', '2a00'];
        yield 'str-a' => ['a', '2a0161'];
        yield 'str-hello' => ['hello', '2a0568656c6c6f'];
        yield 'str-utf8-multibyte' => ['中文', '2a06e4b8ade69687'];
        yield 'bin-invalid-utf8' => ["\x80\x81", '32028081'];

        // list（字段 7 ListValue，每项 0a + 长度前缀）与嵌套 list（字段 7 内嵌字段 7）
        // Lists (field 7 ListValue, each item 0a + length prefix) and nested lists (field 7 inside field 7)
        yield 'list-empty' => [[], '3a00'];
        yield 'list-123' => [[1, 2, 3], '3a0c0a0218010a0218020a021803'];
        yield 'list-nested' => [[[1], [2, [3]]], '3a180a063a040a0218010a0e3a0c0a0218020a063a040a021803'];

        // map（Payload：entries 每项 0a + 长度前缀；字符串键走字段 1，整数键走 sint64 zigzag 字段 2）
        // Maps (Payload: entries each prefixed 0a + length; string keys via field 1, integer keys via sint64-zigzag field 2)
        yield 'map-a-true' => [['a' => true], '42090a070a01611a021001'];
        yield 'map-int-key' => [[5 => 'x'], '42090a07100a1a032a0178'];
        yield 'map-neg-int-key' => [[-3 => 'y'], '42090a0710051a032a0179'];
        yield 'map-int-key-min' => [[PHP_INT_MIN => 'min'], '42140a1210ffffffffffffffffff011a052a036d696e'];
        yield 'map-int-key-max' => [[PHP_INT_MAX => 'max'], '42140a1210feffffffffffffffff011a052a036d6178'];
        yield 'map-int-key-neg1' => [[-1 => 'neg1'], '420c0a0a10011a062a046e656731'];
        yield 'map-int-key-zero' => [[0 => 'zero'], '3a080a062a047a65726f'];
        yield 'map-int-key-one' => [[1 => 'one'], '420b0a0910021a052a036f6e65'];
        yield 'map-nested' => [['a' => ['b' => [1]]], '42160a140a01611a0f420d0a0b0a01621a063a040a021801'];
    }

    #[DataProvider('provideKnownVectors')]
    public function testEncodeMatchesKnownVector(mixed $value, string $expectedHex): void
    {
        self::assertSame($expectedHex, bin2hex($this->serializer->pack($value)));
    }

    #[DataProvider('provideKnownVectors')]
    public function testDecodeRestoresValueFromKnownVector(mixed $value, string $expectedHex): void
    {
        self::assertSame($value, $this->serializer->unpack(hex2bin($expectedHex)));
    }

    public function testEncodeDecodeRoundTripOnPackedBytes(): void
    {
        $values = [
            [1, -1, 3.25, '', 'text', null, true, false],
            ['k' => ['nested' => [1, 2]], 10 => 'int-key'],
        ];

        foreach ($values as $value) {
            self::assertSame($value, $this->serializer->unpack($this->serializer->pack($value)));
        }
    }

    /** varint 长度前缀宽度边界：127/128（1→2 字节）与 16383/16384（2→3 字节）。 Varint length-prefix width boundaries: 127/128 (1→2 bytes) and 16383/16384 (2→3 bytes). */
    public function testStringLengthPrefixBoundaries(): void
    {
        self::assertSame('2a7f' . str_repeat('61', 127), bin2hex($this->serializer->pack(str_repeat('a', 127))));
        self::assertSame('2a8001' . str_repeat('61', 128), bin2hex($this->serializer->pack(str_repeat('a', 128))));
        self::assertSame('2aff7f' . str_repeat('61', 16383), bin2hex($this->serializer->pack(str_repeat('a', 16383))));
        self::assertSame('2a808001' . str_repeat('61', 16384), bin2hex($this->serializer->pack(str_repeat('a', 16384))));

        $long = str_repeat('a', 65536);
        self::assertSame($long, $this->serializer->unpack($this->serializer->pack($long)));
    }

    /** 信封精确向量：type/requestId/timestamp/payload 四字段，与 nythros_message.proto 字段号锚定。 Exact envelope vector: the four fields type/requestId/timestamp/payload, anchored to nythros_message.proto's field numbers. */
    public function testEncodeEnvelopeMatchesKnownVector(): void
    {
        $message = new Message('m', null, 0.0, []);

        // 0a016d = type "m"；requestId 为 null 整字段缺席；19+8×00 = timestamp fixed64 0.0；2200 = 空 Payload
        // 0a016d = type "m"; a null requestId omits the whole field; 19+8×00 = timestamp fixed64 0.0; 2200 = an empty Payload
        $expected = '0a016d'
            . '190000000000000000'
            . '2200';

        self::assertSame($expected, bin2hex($this->serializer->encode($message)->bytes()));
    }

    /** requestId 在场且 timestamp 非零：字段 2 出现、fixed64 小端非零。 A present requestId with a non-zero timestamp: field 2 appears, fixed64 little-endian non-zero. */
    public function testEncodeEnvelopeWithRequestIdMatchesKnownVector(): void
    {
        $message = new Message('move', 'r1', 1.5, []);

        $expected = '0a046d6f7665'          // type "move" type "move"
            . '12027231'                    // request_id "r1" request_id "r1"
            . '19000000000000f83f'          // timestamp 1.5（小端 fixed64） timestamp 1.5 (little-endian fixed64)
            . '2200';                       // payload []

        self::assertSame($expected, bin2hex($this->serializer->encode($message)->bytes()));
    }

    /** 标量负载信封向量：payload = {damage:12, hp:88} 的完整字节展开。 Scalar-payload envelope vector: the full byte expansion of payload = {damage:12, hp:88}. */
    public function testEncodeEnvelopeWithScalarPayloadMatchesKnownVector(): void
    {
        $message = new Message('hit', null, 0.0, ['damage' => 12, 'hp' => 88]);

        $expected = '0a03686974'                                            // type "hit" type "hit"
            . '190000000000000000'                                          // timestamp 0.0 timestamp 0.0
            . '2218'                                                        // payload（24 字节） payload (24 bytes)
            . '0a0c'                                                        //   entry#1（12 字节） entry #1 (12 bytes)
            . '0a0664616d616765'                                            //     string_key "damage" string_key "damage"
            . '1a02180c'                                                    //     value int_value 12 value int_value 12
            . '0a08'                                                        //   entry#2（8 字节） entry #2 (8 bytes)
            . '0a026870'                                                    //     string_key "hp" string_key "hp"
            . '1a021858';                                                   //     value int_value 88 value int_value 88

        self::assertSame($expected, bin2hex($this->serializer->encode($message)->bytes()));
    }

    public function testEncodeDecodeRoundTripPreservesAllFields(): void
    {
        $message = Message::create(
            type: 'move',
            payload: [
                'x' => 1.5,
                'nested' => ['a' => 'b', 'c' => [1, 2, 3]],
                'list' => [true, null, 'text'],
            ],
            requestId: 'req-1',
            timestamp: 1725000000.25,
        );

        $decoded = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($message->type, $decoded->type);
        self::assertSame($message->requestId, $decoded->requestId);
        self::assertSame($message->timestamp, $decoded->timestamp);
        self::assertSame($message->payload, $decoded->payload);
    }

    /** protobuf 特有：整数键在往返后保留（JSON 会强制字符串化）。 protobuf-specific: int keys survive the round trip (JSON would stringify them). */
    public function testRoundTripPreservesIntegerPayloadKeys(): void
    {
        $message = Message::create('move', ['named' => 1, 10 => 'ten'], 'r1', 1.0);

        $decoded = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($message->payload, $decoded->payload);
    }

    /** 空 requestId 与空字符串 requestId 的 presence 区分：后者线上出现空长度字段。 Presence distinction between a null and an empty-string requestId: the latter appears on the wire as a zero-length field. */
    public function testEmptyStringRequestIdStaysPresentOnWire(): void
    {
        $bytes = $this->serializer->encode(new Message('m', '', 0.0, []))->bytes();

        self::assertStringContainsString(hex2bin('1200'), $bytes, '空字符串 requestId 必须以零长字段在场 / an empty-string requestId must appear as a zero-length field');

        $decoded = $this->serializer->decode(new Frame($bytes));
        self::assertSame('', $decoded->requestId);
    }

    /** 非 UTF-8 二进制载荷字节精确保真（bytes 分支）。 Non-UTF-8 binary payloads stay byte-exact (the bytes branch). */
    public function testBinaryPayloadRoundTripIsByteExact(): void
    {
        $binary = random_bytes(32) . "\x00\xff";
        $message = Message::create('blob', ['data' => $binary], null, 1.0);

        $decoded = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($binary, $decoded->payload['data']);
    }

    /** 与 JsonSerializer 的 Message 语义等价性：同一消息经两条管线往返结果一致。 Message semantic equivalence with JsonSerializer: both pipelines round-trip the same message to identical results. */
    public function testSemanticEquivalenceWithJsonSerializer(): void
    {
        $message = Message::create(
            type: 'entity_moved',
            payload: [
                'id' => 'entity-1',
                'position' => ['x' => 3, 'y' => -4],
                'flags' => ['sprint' => true, 'stealth' => false],
                'path' => [[0, 0], [1, 2], [3, 5]],
            ],
            requestId: 'req-42',
            timestamp: 1725000000.5,
        );

        $json = new JsonSerializer();
        $viaJson = $json->decode($json->encode($message));
        $viaProtobuf = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($viaJson->type, $viaProtobuf->type);
        self::assertSame($viaJson->requestId, $viaProtobuf->requestId);
        self::assertSame($viaJson->timestamp, $viaProtobuf->timestamp);
        self::assertSame($viaJson->payload, $viaProtobuf->payload);
    }

    /** 与 MsgpackSerializer 的 Message 语义等价性（ADR-022 双轨对拍）。 Message semantic equivalence with MsgpackSerializer (the ADR-022 dual-track cross-check). */
    public function testSemanticEquivalenceWithMsgpackSerializer(): void
    {
        $message = Message::create(
            type: 'combat:hit',
            payload: [
                'attackerId' => 'player-1',
                'damage' => 12,
                'crit' => true,
                'path' => [[1, 2], [3, 4]],
            ],
            requestId: 'req-7',
            timestamp: 1725000000.75,
        );

        $msgpack = new MsgpackSerializer();
        $viaMsgpack = $msgpack->decode($msgpack->encode($message));
        $viaProtobuf = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($viaMsgpack->type, $viaProtobuf->type);
        self::assertSame($viaMsgpack->requestId, $viaProtobuf->requestId);
        self::assertSame($viaMsgpack->timestamp, $viaProtobuf->timestamp);
        self::assertSame($viaMsgpack->payload, $viaProtobuf->payload);
    }

    public function testEncodeRejectsObject(): void
    {
        $this->expectException(ProtocolException::class);

        $this->serializer->pack(new \stdClass());
    }

    public function testEncodeRejectsResource(): void
    {
        $resource = fopen('php://memory', 'rb');

        try {
            $this->expectException(ProtocolException::class);
            $this->serializer->pack($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testEncodeRejectsExcessiveNestingDepth(): void
    {
        $deep = 1;
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        $this->expectException(ProtocolException::class);

        $this->serializer->pack($deep);
    }

    public function testEncodeRejectsExcessiveNestingDepthInMapValues(): void
    {
        $deep = ['leaf' => 1];
        for ($i = 0; $i < 600; $i++) {
            $deep = ['wrap' => $deep];
        }

        $this->expectException(ProtocolException::class);

        $this->serializer->pack($deep);
    }

    public function testDecodeAcceptsModerateNestingDepth(): void
    {
        $deep = 1;
        for ($i = 0; $i < 200; $i++) {
            $deep = [$deep];
        }

        self::assertSame($deep, $this->serializer->unpack($this->serializer->pack($deep)));
    }

    public function testDecodeRejectsExcessiveNestingDepth(): void
    {
        // 手工构造 600 层嵌套 ListValue（每层 Value{list_value} 包一层 ListValue{values}），
        // 绕过编码侧深度上限直达解码侧守卫。
        // Hand-craft 600 levels of nested ListValue (each level wraps one ListValue inside a Value's list_value)
        // to reach the decode-side guard past the encode-side limit.
        $varint = static function (int $n): string {
            $out = '';
            do {
                $byte = $n & 0x7f;
                $n >>= 7;
                $out .= chr($n === 0 ? $byte : ($byte | 0x80));
            } while ($n !== 0);

            return $out;
        };
        $bytes = hex2bin('1801'); // 最内层 Value{int_value:1} The innermost Value{int_value:1}.
        for ($i = 0; $i < 600; $i++) {
            $listBody = hex2bin('0a') . $varint(strlen($bytes)) . $bytes;   // ListValue.values 单项 One ListValue.values item.
            $bytes = hex2bin('3a') . $varint(strlen($listBody)) . $listBody; // Value.list_value 包裹 Wrapped as Value.list_value.
        }

        $this->expectException(DecodeException::class);

        $this->serializer->unpack($bytes);
    }

    public function testDecodeRejectsEmptyType(): void
    {
        // 手工构造信封：type 字段在场但为空串。 Hand-craft an envelope whose type field is present but empty.
        $frame = new Frame(hex2bin('0a00'));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeRejectsMissingType(): void
    {
        // 只有 timestamp 字段的信封（无 type）。 An envelope carrying only a timestamp field (no type).
        $frame = new Frame(hex2bin('190000000000000000'));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeDefaultsMissingPayloadToEmptyArray(): void
    {
        $message = $this->serializer->decode(new Frame(hex2bin('0a016d')));

        self::assertSame([], $message->payload);
        self::assertNull($message->requestId);
        self::assertSame(0.0, $message->timestamp);
    }

    public function testDecodeRejectsTruncatedDouble(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('2100'));
    }

    public function testDecodeRejectsTruncatedLengthPrefixBody(): void
    {
        // string_value 声明 5 字节但只有 2 字节体。 string_value declares 5 bytes but carries only 2.
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('2a056162'));
    }

    public function testDecodeRejectsVarintWiderThan64Bits(): void
    {
        // 第 10 个 varint 字节 > 1：超出 64 位。 The 10th varint byte exceeds 1: wider than 64 bits.
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('18ffffffffffffffffff02'));
    }

    public function testDecodeRejectsWireTypeMismatchOnKnownField(): void
    {
        // Value.int_value（字段 3）必须 VARINT，此处给 FIXED64（tag=(3<<3)|1=0x19）。
        // Value.int_value (field 3) requires VARINT; FIXED64 given instead (tag=(3<<3)|1=0x19).
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('190000000000000000'));
    }

    public function testDecodeRejectsGroupWireTypes(): void
    {
        // 未知字段 9 携带 SGROUP（wire type 3，tag=(9<<3)|3=0x4b）：skipField 直接拒绝。
        // Unknown field 9 carrying SGROUP (wire type 3, tag=(9<<3)|3=0x4b): skipField rejects it outright.
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('4b'));
    }

    public function testDecodeRejectsFieldNumberZero(): void
    {
        // tag 0x00 = 字段号 0：protobuf 规范禁止。 Tag 0x00 = field number 0: forbidden by the protobuf spec.
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('00'));
    }

    public function testDecodeSkipsUnknownFields(): void
    {
        // Value 体：未知字段 9（VARINT，tag=(9<<3)|0=0x48）后跟合法 bool_value——向前兼容跳过。
        // A Value body: unknown field 9 (VARINT, tag=(9<<3)|0=0x48) followed by a legal bool_value — skipped for forward compatibility.
        $decoded = $this->serializer->unpack(hex2bin('48012a0161'));

        self::assertSame('a', $decoded);
    }

    public function testDecodeMapEntryRejectsMissingKeyOrValue(): void
    {
        // Payload 体：entry 只有 value（string "a"）没有 key。 A Payload body: an entry with only a value (string "a") and no key.
        $payloadBody = hex2bin('0a051a032a0161');
        $envelope = hex2bin('0a016d') . hex2bin('22') . chr(strlen($payloadBody)) . $payloadBody;

        $this->expectException(DecodeException::class);

        $this->serializer->decode(new Frame($envelope));
    }

    public function testDecodeLastValueWinsForDuplicateFields(): void
    {
        // 同一 oneof kind 写两次：后写胜出（protobuf 规范语义）。 The same oneof kind written twice: last wins (protobuf-spec semantics).
        $decoded = $this->serializer->unpack(hex2bin('18011802'));

        self::assertSame(2, $decoded);
    }

    /** zigzag 整数键全范围往返：PHP_INT_MIN/PHP_INT_MAX/-1/0/1。 Zigzag integer-key round-trip: full-range boundary. */
    public static function provideZigzagKeyRoundTrip(): iterable
    {
        yield '0' => [[0 => 'v']];
        yield '1' => [[1 => 'v']];
        yield '-1' => [[-1 => 'v']];
        yield 'PHP_INT_MAX' => [[PHP_INT_MAX => 'v']];
        yield 'PHP_INT_MIN' => [[PHP_INT_MIN => 'v']];
    }

    #[DataProvider('provideZigzagKeyRoundTrip')]
    public function testZigzagKeyRoundTrip(array $map): void
    {
        $encoded = $this->serializer->pack($map);
        $decoded = $this->serializer->unpack($encoded);
        self::assertSame($map, $decoded);
    }
}
