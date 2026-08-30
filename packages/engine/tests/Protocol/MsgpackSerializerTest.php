<?php

declare(strict_types=1);

namespace Nythros\Protocol\Tests;

use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\MsgpackSerializer;
use Nythros\Protocol\ProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MsgpackSerializerTest - 纯 PHP MessagePack 编解码器的已知十六进制向量、边界与 Message 语义覆盖。
 * 向量表双向驱动：encode(value) 必须产出已知字节，decode(字节) 必须还原原值；
 * 另覆盖长度段边界（str8/16/32、array16/32）、UTF-8 多字节、二进制（bin 族）、
 * 深度上限、截断/尾随/保留码失败路径，以及与 JsonSerializer 的 Message 语义等价性。
 * Msgpack serializer tests: known hex vectors for the pure-PHP MessagePack codec, boundaries and Message semantics.
 * The vector table drives both directions: encode must emit the known bytes and decode must restore the value;
 * it also covers width-segment boundaries (str8/16/32, array16/32), multi-byte UTF-8, binary (bin family),
 * the depth limit, truncated/trailing/reserved-code failure paths, and Message semantic equivalence with JsonSerializer.
 */
final class MsgpackSerializerTest extends TestCase
{
    private MsgpackSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new MsgpackSerializer();
    }

    /** 已知十六进制向量（MessagePack 规范锚点）：双向表驱动。 Known hex vectors (spec anchors): bidirectional table-driven. */
    public static function provideKnownVectors(): iterable
    {
        // nil / 布尔 nil / booleans
        yield 'nil' => [null, 'c0'];
        yield 'true' => [true, 'c3'];
        yield 'false' => [false, 'c2'];

        // 非负整数各长度段（fixint / uint8 / uint16 / uint32 / uint64）
        // Non-negative integer width segments (fixint / uint8 / uint16 / uint32 / uint64)
        yield 'int-0' => [0, '00'];
        yield 'int-1' => [1, '01'];
        yield 'int-127' => [127, '7f'];
        yield 'uint8-128' => [128, 'cc80'];
        yield 'uint8-255' => [255, 'ccff'];
        yield 'uint16-256' => [256, 'cd0100'];
        yield 'uint16-65535' => [65535, 'cdffff'];
        yield 'uint32-65536' => [65536, 'ce00010000'];
        yield 'uint32-max' => [4294967295, 'ceffffffff'];
        yield 'uint64-2pow32' => [4294967296, 'cf0000000100000000'];
        yield 'int64-max' => [PHP_INT_MAX, 'cf7fffffffffffffff'];

        // 负整数各长度段（negative fixint / int8 / int16 / int32 / int64）
        // Negative integer width segments (negative fixint / int8 / int16 / int32 / int64)
        yield 'negfixint--1' => [-1, 'ff'];
        yield 'negfixint--32' => [-32, 'e0'];
        yield 'int8--33' => [-33, 'd0df'];
        yield 'int8-min' => [-128, 'd080'];
        yield 'int16--129' => [-129, 'd1ff7f'];
        yield 'int16-min' => [-32768, 'd18000'];
        yield 'int32--32769' => [-32769, 'd2ffff7fff'];
        yield 'int32-min' => [-2147483648, 'd280000000'];
        yield 'int64--2147483649' => [-2147483649, 'd3ffffffff7fffffff'];
        yield 'int64-min' => [PHP_INT_MIN, 'd38000000000000000'];

        // float64（0xcb + 大端双精度） float64 (0xcb + big-endian double)
        yield 'float-1.5' => [1.5, 'cb3ff8000000000000'];
        yield 'float-0.0' => [0.0, 'cb0000000000000000'];
        yield 'float--2.25' => [-2.25, 'cbc002000000000000'];

        // string 各长度段（fixstr / str8 / str16）+ UTF-8 多字节 + 二进制走 bin8
        // String width segments (fixstr / str8 / str16) + multi-byte UTF-8 + binary via bin8
        yield 'str-empty' => ['', 'a0'];
        yield 'str-a' => ['a', 'a161'];
        yield 'str-hello' => ['hello', 'a568656c6c6f'];
        yield 'str-utf8-multibyte' => ['中文', 'a6e4b8ade69687'];
        yield 'str-fixmax-31' => [str_repeat('a', 31), 'bf' . str_repeat('61', 31)];
        yield 'str8-32' => [str_repeat('a', 32), 'd920' . str_repeat('61', 32)];
        yield 'str8-255' => [str_repeat('a', 255), 'd9ff' . str_repeat('61', 255)];
        yield 'str16-256' => [str_repeat('a', 256), 'da0100' . str_repeat('61', 256)];
        yield 'bin-invalid-utf8' => ["\x80\x81", 'c4028081'];

        // list 各形态（fixarray / 嵌套） List shapes (fixarray / nested)
        yield 'list-empty' => [[], '90'];
        yield 'list-123' => [[1, 2, 3], '93010203'];
        yield 'list-ab' => [['a', 'b'], '92a161a162'];
        yield 'list-scalars' => [[null, true, false], '93c0c3c2'];
        yield 'list-nested' => [[[1], [2, [3]]], '92910192029103'];

        // map（字符串键 / 整数键 / 嵌套） Maps (string keys / int keys / nested)
        yield 'map-a-true' => [['a' => true], '81a161c3'];
        yield 'map-ab' => [['a' => 1, 'b' => 2], '82a16101a16202'];
        yield 'map-int-key' => [[5 => 'x'], '8105a178'];
        yield 'map-nested' => [['a' => ['b' => [1]]], '81a16181a1629101'];
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

    /** 超大边界：str16 上限 / str32 起点。 Large boundaries: str16 ceiling / str32 onset. */
    public function testStringWidthSegmentBoundaries(): void
    {
        self::assertSame('daffff' . str_repeat('61', 65535), bin2hex($this->serializer->pack(str_repeat('a', 65535))));
        self::assertSame('db00010000' . str_repeat('61', 65536), bin2hex($this->serializer->pack(str_repeat('a', 65536))));

        $long = str_repeat('a', 65536);
        self::assertSame($long, $this->serializer->unpack($this->serializer->pack($long)));
    }

    /** 超大边界：array16 起点 / array32 起点。 Large boundaries: array16 onset / array32 onset. */
    public function testListWidthSegmentBoundaries(): void
    {
        $sixteen = range(0, 15);
        self::assertSame('dc0010' . bin2hex(pack('C*', ...$sixteen)), bin2hex($this->serializer->pack($sixteen)));

        $huge = [];
        for ($i = 0; $i < 65536; $i++) {
            $huge[] = $i % 128;
        }
        $packed = $this->serializer->pack($huge);
        self::assertSame('dd00010000', bin2hex(substr($packed, 0, 5)));
        self::assertSame($huge, $this->serializer->unpack($packed));
    }

    public function testFloatInfinityRoundTrip(): void
    {
        $decoded = $this->serializer->unpack($this->serializer->pack(INF));

        self::assertSame(INF, $decoded);
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
        // 手工构造 600 层 fixarray 嵌套（91 01 = [1]），绕过编码侧深度上限直达解码侧守卫。
        // Hand-craft 600 levels of fixarray nesting (91 01 = [1]) to reach the decode-side guard past the encode-side limit.
        $bytes = str_repeat("\x91", 600) . "\x01";

        $this->expectException(DecodeException::class);

        $this->serializer->unpack($bytes);
    }

    public function testDecodeRejectsEmptyInput(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack('');
    }

    public function testDecodeRejectsTruncatedFloat(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('cb3ff8'));
    }

    public function testDecodeRejectsTrailingBytes(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack(hex2bin('0102'));
    }

    public function testDecodeRejectsNeverUsedCode(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack("\xc1");
    }

    public function testDecodeRejectsFixExtCode(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack("\xd4\x01\x00");
    }

    public function testDecodeRejectsExt8Code(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->unpack("\xc7\x01\x00\x01");
    }

    public function testDecodeRejectsNonStringMapKey(): void
    {
        // {1.5: "x"}：浮点键非法（PHP 数组键只允许 int|string）。 {1.5: "x"}: float keys are invalid (PHP array keys are int|string only).
        $bytes = hex2bin('81cb3ff8000000000000') . hex2bin('a178');

        $this->expectException(DecodeException::class);

        $this->serializer->unpack($bytes);
    }

    /** 信封精确向量：4 键 map（type/requestId/timestamp/payload），与 JsonSerializer 字段语义同构。 Exact envelope vector: a 4-key map (type/requestId/timestamp/payload), field semantics mirroring JsonSerializer. */
    public function testEncodeEnvelopeMatchesKnownVector(): void
    {
        $message = new Message('m', null, 0.0, []);

        $expected = '84'
            . 'a474797065' . 'a16d'                       // type: "m"
            . 'a9726571756573744964' . 'c0'               // requestId: nil
            . 'a974696d657374616d70' . 'cb0000000000000000' // timestamp: 0.0
            . 'a77061796c6f6164' . '90';                  // payload: []

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

    /** msgpack 特有：整数键在往返后保留（JSON 会强制字符串化）。 msgpack-specific: int keys survive the round trip (JSON would stringify them). */
    public function testRoundTripPreservesIntegerPayloadKeys(): void
    {
        $message = Message::create('move', ['named' => 1, 10 => 'ten'], 'r1', 1.0);

        $decoded = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($message->payload, $decoded->payload);
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
        $viaMsgpack = $this->serializer->decode($this->serializer->encode($message));

        self::assertSame($viaJson->type, $viaMsgpack->type);
        self::assertSame($viaJson->requestId, $viaMsgpack->requestId);
        self::assertSame($viaJson->timestamp, $viaMsgpack->timestamp);
        self::assertSame($viaJson->payload, $viaMsgpack->payload);
    }

    public function testDecodeRejectsGarbageBytes(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->decode(new Frame('not-msgpack-bytes'));
    }

    public function testDecodeRejectsNonMapTopLevel(): void
    {
        $this->expectException(DecodeException::class);

        $this->serializer->decode(new Frame($this->serializer->pack(42)));
    }

    public function testDecodeRejectsMissingType(): void
    {
        $frame = new Frame($this->serializer->pack(['timestamp' => 1.0, 'payload' => []]));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeRejectsEmptyType(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => '', 'timestamp' => 1.0, 'payload' => []]));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeRejectsNonStringType(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => 123, 'timestamp' => 1.0, 'payload' => []]));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeRejectsInvalidRequestId(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => 'move', 'requestId' => 7, 'timestamp' => 1.0, 'payload' => []]));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeRejectsNonArrayPayload(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => 'move', 'timestamp' => 1.0, 'payload' => 'oops']));

        $this->expectException(DecodeException::class);

        $this->serializer->decode($frame);
    }

    public function testDecodeDefaultsMissingPayloadToEmptyArray(): void
    {
        $message = $this->serializer->decode(new Frame($this->serializer->pack(['type' => 'move', 'timestamp' => 1.0])));

        self::assertSame([], $message->payload);
    }

    public function testDecodeCastsNumericStringTimestamp(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => 'move', 'timestamp' => '1234.5', 'payload' => []]));

        $message = $this->serializer->decode($frame);

        self::assertSame(1234.5, $message->timestamp);
    }

    public function testDecodeCastsIntegerTimestamp(): void
    {
        $frame = new Frame($this->serializer->pack(['type' => 'move', 'timestamp' => 1725000000, 'payload' => []]));

        $message = $this->serializer->decode($frame);

        self::assertSame(1725000000.0, $message->timestamp);
    }
}
