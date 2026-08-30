<?php

declare(strict_types=1);

namespace Nythros\Protocol\Tests;

use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\MsgpackSerializer;
use PHPUnit\Framework\TestCase;

/**
 * JsonBatchSerializerTest - 批量序列化器的可替换装配覆盖。
 * 缺省构造（JSON 容器）必须与既往行为逐字节一致；注入 MsgpackSerializer 后，
 * 单帧路径与批量容器均切换为 MessagePack（容器格式跟随单条序列化器，ADR-022 双轨制装配点），
 * 且多帧解码复用注入实例（原每帧 new JsonSerializer 的 P2 泄漏点回归防护）。
 * Batch serializer tests for the swappable assembly. The default constructor (JSON container) must stay
 * byte-identical to the previous behavior; with a MsgpackSerializer injected, both the single-frame path and the
 * batch container switch to MessagePack (the container format follows the single serializer — the ADR-022 dual-track
 * assembly point), and multi-frame decoding reuses the injected instance (regression guard for the former per-frame
 * `new JsonSerializer()` P2 leak).
 */
final class JsonBatchSerializerTest extends TestCase
{
    public function testDefaultEncodeBatchKeepsJsonArrayShape(): void
    {
        $serializer = new JsonBatchSerializer();
        $message = Message::create('move', ['dx' => 30], 'r1', 1725000000.25);

        $bytes = $serializer->encodeBatch([$message]);

        self::assertSame(
            '[{"type":"move","requestId":"r1","timestamp":1725000000.25,"payload":{"dx":30}}]',
            $bytes,
        );
    }

    public function testDefaultDecodeBatchRoundTrip(): void
    {
        $serializer = new JsonBatchSerializer();
        $messages = [
            Message::create('move', ['dx' => 1, 'dy' => -2], 'r1', 1.5),
            Message::create('auth', ['token' => 't'], null, 2.0),
        ];

        $decoded = $serializer->decodeBatch($serializer->encodeBatch($messages));

        self::assertCount(2, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame(['dx' => 1, 'dy' => -2], $decoded[0]->payload);
        self::assertSame(null, $decoded[1]->requestId);
    }

    public function testDefaultDecodeBatchAcceptsSingleFrameObject(): void
    {
        $serializer = new JsonBatchSerializer();

        $decoded = $serializer->decodeBatch('{"type":"move","requestId":"r9","timestamp":1.0,"payload":{}}');

        self::assertCount(1, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame('r9', $decoded[0]->requestId);
    }

    public function testMsgpackInjectedEncodeBatchProducesMsgpackContainer(): void
    {
        $msgpack = new MsgpackSerializer();
        $serializer = new JsonBatchSerializer($msgpack, $msgpack->pack(...), $msgpack->unpack(...));
        $message = Message::create('move', ['dx' => 30], 'r1', 1725000000.25);

        $bytes = $serializer->encodeBatch([$message]);

        // 一帧批量 = msgpack 数组（fixarray 0x91），元素为帧 map。 A one-frame batch is a msgpack array (fixarray 0x91) of frame maps.
        self::assertSame('91', bin2hex(substr($bytes, 0, 1)));
        $decoded = $serializer->decodeBatch($bytes);
        self::assertCount(1, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame(['dx' => 30], $decoded[0]->payload);
        self::assertSame(1725000000.25, $decoded[0]->timestamp);
    }

    public function testMsgpackInjectedMultiFrameBatchReusesInjectedInstance(): void
    {
        $msgpack = new MsgpackSerializer();
        $serializer = new JsonBatchSerializer($msgpack, $msgpack->pack(...), $msgpack->unpack(...));
        $messages = [
            Message::create('move', ['dx' => 1], 'r1', 1.0),
            Message::create('entity_moved', ['id' => 'e-1'], 'r2', 2.0),
            Message::create('auth', ['token' => 't'], null, 3.0),
        ];

        $decoded = $serializer->decodeBatch($serializer->encodeBatch($messages));

        self::assertCount(3, $decoded);
        foreach ($messages as $index => $message) {
            self::assertSame($message->type, $decoded[$index]->type);
            self::assertSame($message->requestId, $decoded[$index]->requestId);
            self::assertSame($message->timestamp, $decoded[$index]->timestamp);
            self::assertSame($message->payload, $decoded[$index]->payload);
        }
    }

    public function testMsgpackInjectedDecodeBatchAcceptsSingleMsgpackFrame(): void
    {
        $msgpack = new MsgpackSerializer();
        $serializer = new JsonBatchSerializer($msgpack, $msgpack->pack(...), $msgpack->unpack(...));

        // 客户端以「批量包含 1 帧」发送单条请求：原始字节即单个 msgpack 帧 map。
        // Clients send single requests as a batch holding one frame: the raw bytes are one msgpack frame map.
        $frameBytes = $msgpack->pack(['type' => 'auth', 'requestId' => 'r7', 'timestamp' => 9.5, 'payload' => ['uid' => 'a']]);

        $decoded = $serializer->decodeBatch($frameBytes);

        self::assertCount(1, $decoded);
        self::assertSame('auth', $decoded[0]->type);
        self::assertSame('r7', $decoded[0]->requestId);
        self::assertSame(['uid' => 'a'], $decoded[0]->payload);
    }

    public function testMsgpackInjectedSingleFrameDelegation(): void
    {
        $msgpack = new MsgpackSerializer();
        $serializer = new JsonBatchSerializer($msgpack, $msgpack->pack(...), $msgpack->unpack(...));
        $message = Message::create('ping', [], null, 4.0);

        $frame = $serializer->encode($message);

        self::assertSame($message->type, $serializer->decode($frame)->type);
        // 单帧编码即纯 msgpack 信封（非 JSON）。 Single-frame encoding is a pure msgpack envelope (not JSON).
        self::assertSame(bin2hex($msgpack->encode($message)->bytes()), bin2hex($frame->bytes()));
    }

    public function testEmptyBatchPacketDecodesToEmptyList(): void
    {
        self::assertSame([], (new JsonBatchSerializer())->decodeBatch(''));
    }
}
