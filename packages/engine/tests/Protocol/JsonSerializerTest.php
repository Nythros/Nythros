<?php

declare(strict_types=1);

namespace Nythros\Protocol\Tests;

use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use PHPUnit\Framework\TestCase;

/**
 * JsonSerializerTest - 覆盖 JsonSerializer 的编解码往返、非法输入拒绝与默认值契约。
 * Tests covering JsonSerializer encode/decode round-trips, invalid input rejection, and default-value contracts.
 */
final class JsonSerializerTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $serializer = new JsonSerializer();
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

        $decoded = $serializer->decode($serializer->encode($message));

        self::assertSame($message->type, $decoded->type);
        self::assertSame($message->requestId, $decoded->requestId);
        self::assertSame($message->timestamp, $decoded->timestamp);
        self::assertSame($message->payload, $decoded->payload);
    }

    public function testDecodeRejectsBrokenJson(): void
    {
        $serializer = new JsonSerializer();

        $this->expectException(DecodeException::class);

        $serializer->decode(new Frame('{broken'));
    }

    public function testDecodeRejectsMissingType(): void
    {
        $serializer = new JsonSerializer();

        $this->expectException(DecodeException::class);

        $serializer->decode(new Frame('{"timestamp": 1.0, "payload": []}'));
    }

    public function testDecodeRejectsNonStringType(): void
    {
        $serializer = new JsonSerializer();

        $this->expectException(DecodeException::class);

        $serializer->decode(new Frame('{"type": 123, "timestamp": 1.0, "payload": []}'));
    }

    public function testDecodeDefaultsMissingPayloadToEmptyArray(): void
    {
        $serializer = new JsonSerializer();

        $message = $serializer->decode(new Frame('{"type": "move", "timestamp": 1.0}'));

        self::assertSame([], $message->payload);
    }

    public function testDecodeCastsNumericStringTimestamp(): void
    {
        $serializer = new JsonSerializer();

        $message = $serializer->decode(new Frame('{"type": "move", "timestamp": "1234.5", "payload": []}'));

        self::assertSame(1234.5, $message->timestamp);
    }

    public function testDecodeAcceptsUnknownTypeSemantics(): void
    {
        $serializer = new JsonSerializer();

        $message = $serializer->decode(new Frame('{"type": "no_such_type", "timestamp": 1.0, "payload": []}'));

        self::assertSame('no_such_type', $message->type);
    }
}
