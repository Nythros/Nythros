<?php

declare(strict_types=1);

namespace Nythros\Tests\Protocol;

use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Message;
use Nythros\Protocol\ProtocolException;
use Nythros\Protocol\ProtocolVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * BinaryBatchSerializerTest - 二进制批量序列化器的编解码往返、结构与失败路径覆盖。
 * 词汇表使用测试自带的最小集合（引擎不依赖 demo 枚举）；值类型覆盖 string/int/float/position/null/布尔/长串/列表。
 * Binary batch serializer tests: round-trips, structure and failure paths. The vocabulary is a minimal test-local
 * set (the engine does not depend on the demo enums); value types cover string/int/float/position/null/bool/long-string/list.
 */
final class BinaryBatchSerializerTest extends TestCase
{
    private BinaryBatchSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new BinaryBatchSerializer(new ProtocolVocabulary(
            typeCodes: [
                'move' => 1,
                'auth' => 2,
                'entity_moved' => 3,
                'combat:hit' => 4,
            ],
            keyCodes: [
                'id' => 1,
                'position' => 2,
                'x' => 3,
                'y' => 4,
                'dx' => 5,
                'dy' => 6,
                'token' => 7,
                'damage' => 8,
                'hp' => 9,
                'message' => 10,
                'count' => 11,
                'flags' => 12,
            ],
        ));
    }

    public function testSingleMessageRoundTrip(): void
    {
        $message = Message::create('move', ['dx' => 30, 'dy' => -5], 'req-1');
        $batch = $this->serializer->encodeBatch([$message]);

        $decoded = $this->serializer->decodeBatch($batch);
        self::assertCount(1, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame('req-1', $decoded[0]->requestId);
        self::assertSame(['dx' => 30, 'dy' => -5], $decoded[0]->payload);
    }

    public function testMultiMessageBatchPreservesOrder(): void
    {
        $messages = [
            Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 1, 'y' => 2]]),
            Message::create('combat:hit', ['id' => 'm-1', 'damage' => 12, 'hp' => 88]),
            Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 9, 'y' => 9]]),
        ];
        $batch = $this->serializer->encodeBatch($messages);

        $decoded = $this->serializer->decodeBatch($batch);
        self::assertCount(3, $decoded);
        self::assertSame(['entity_moved', 'combat:hit', 'entity_moved'], array_map(static fn (Message $m): string => $m->type, $decoded));
        self::assertSame(['x' => 9, 'y' => 9], $decoded[2]->payload['position']);
        self::assertSame(12, $decoded[1]->payload['damage']);
        self::assertSame(88, $decoded[1]->payload['hp']);
    }

    public function testNullWithoutRequestIdRoundTrip(): void
    {
        $message = Message::create('auth', ['token' => 't-1']); // requestId = null
        $decoded = $this->serializer->decodeBatch($this->serializer->encodeBatch([$message]));
        self::assertCount(1, $decoded);
        self::assertNull($decoded[0]->requestId);
        self::assertSame(['token' => 't-1'], $decoded[0]->payload);
    }

    public function testEmptyBatchDecodesToEmptyList(): void
    {
        self::assertSame([], $this->serializer->decodeBatch($this->serializer->encodeBatch([])));
    }

    public function testAllValueKindsRoundTrip(): void
    {
        $message = Message::create('combat:hit', [
            'id' => 'monster-1',
            'position' => ['x' => -100, 'y' => 200], // 负坐标亦须往返无损 negative coordinates must round-trip losslessly
            'damage' => 3.5,                          // float
            'hp' => 0,                                // int zero
            'message' => '',                          // 空串 empty string
            'flags' => [1, 2, 3],                     // list
            'count' => null,                          // null
        ]);
        $decoded = $this->serializer->decodeBatch($this->serializer->encodeBatch([$message]))[0];

        self::assertSame(['x' => -100, 'y' => 200], $decoded->payload['position']);
        self::assertSame(3.5, $decoded->payload['damage']);
        self::assertSame(0, $decoded->payload['hp']);
        self::assertSame('', $decoded->payload['message']);
        self::assertSame([1, 2, 3], $decoded->payload['flags']);
        self::assertNull($decoded->payload['count']);
    }

    public function testLongStringUsesString32AndRoundTrips(): void
    {
        $long = str_repeat('字', 300); // >255 bytes: STRING32 path (>255 bytes)
        $message = Message::create('auth', ['token' => $long]);
        $decoded = $this->serializer->decodeBatch($this->serializer->encodeBatch([$message]));
        self::assertSame($long, $decoded[0]->payload['token']);
    }

    public function testTimestampEncodingToggle(): void
    {
        // encodeTimestamp=false（默认）：timestamp 不参与编码，解码恢复 0.0
        // encodeTimestamp=false (default): timestamp is omitted; decoding restores 0.0
        $m1 = new Message('move', null, 123.456, ['dx' => 1, 'dy' => 2]);
        self::assertSame(0.0, $this->serializer->decodeBatch($this->serializer->encodeBatch([$m1]))[0]->timestamp);

        // encodeTimestamp=true：浮点时间往返无损（二进制小端双精度）
        // encodeTimestamp=true: the float timestamp round-trips losslessly (little-endian double)
        $withTs = new BinaryBatchSerializer($this->vocab(), true);
        self::assertSame(123.456, $withTs->decodeBatch($withTs->encodeBatch([$m1]))[0]->timestamp);
    }

    public function testUnknownFrameTypeFailsFast(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('未知帧类型');
        $this->serializer->encodeBatch([Message::create('ghost:frame', [])]);
    }

    public function testUnknownPayloadKeyFailsFast(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('未知负载字段');
        $this->serializer->encodeBatch([Message::create('move', ['teleport' => true])]);
    }

    public function testBadMagicThrows(): void
    {
        $this->expectException(DecodeException::class);
        $this->serializer->decodeBatch("NX\x00\x02rest");
    }

    public function testTruncatedPacketThrows(): void
    {
        $packet = $this->serializer->encodeBatch([Message::create('move', ['dx' => 1, 'dy' => 2])]);
        $this->expectException(DecodeException::class);
        $this->serializer->decodeBatch(substr($packet, 0, strlen($packet) - 3));
    }

    public function testUnknownKeyCodeThrows(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('未知 keyCode');
        // 手工构造：魔数 + count=1 + 帧长 + 帧体（fieldCount=1 + keyCode=99 未注册 + EMPTY_STRING 类型）
        // Hand-built packet: magic + count=1 + frame length + body (fieldCount=1 + unregistered keyCode 99 + EMPTY_STRING type)
        $body = pack('n', 1) . pack('nC', 99, 0x07);
        $this->serializer->decodeBatch("NX\x00\x01" . pack('N', 1) . pack('N', strlen($body)) . $body);
    }

    public function testSingleFrameDecode(): void
    {
        $message = Message::create('auth', ['token' => 'abc'], 'req-x');
        $frame = $this->serializer->encode($message);
        self::assertSame('auth', $this->serializer->decode($frame)->type);
        self::assertSame('req-x', $this->serializer->decode($frame)->requestId);
    }

    private function vocab(): ProtocolVocabulary
    {
        return new ProtocolVocabulary(
            typeCodes: ['move' => 1],
            keyCodes: ['dx' => 5, 'dy' => 6],
        );
    }
}
