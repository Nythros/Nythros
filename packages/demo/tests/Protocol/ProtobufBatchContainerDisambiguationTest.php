<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests\Protocol;

use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\ProtobufSerializer;
use PHPUnit\Framework\TestCase;

/**
 * ProtobufBatchContainerDisambiguationTest - run-worker protobuf 批量容器 type 字段消歧验证。
 * 复刻 run-worker 的 protobuf 分支装配逻辑（packContainerValue / unpackContainerValue），
 * 验证两种输入形态各自正确解析：有 type → 信封（单帧对象），缺 type → Value 树（批量 list 容器）。
 *
 * ProtobufBatchContainerDisambiguationTest — verifies the type-field disambiguation in the protobuf
 * batch-container adapter (run-worker). Replicates the assembly logic (packContainerValue / unpackContainerValue)
 * and validates two shapes: with type → envelope (single-frame object); without type → Value tree (batch list).
 */
final class ProtobufBatchContainerDisambiguationTest extends TestCase
{
    /** @var array{ProtobufSerializer, callable(mixed): string, callable(string): mixed} 复刻 run-worker protobuf 装配三元组 */
    private array $protobufAdapter;

    protected function setUp(): void
    {
        $protobuf = new ProtobufSerializer();

        // packContainerValue：含 type 键的帧形关联数组 → 信封；否则 → Value 树。
        $packContainerValue = static function (mixed $value) use ($protobuf): string {
            if (is_array($value) && !array_is_list($value) && array_key_exists('type', $value)) {
                $type = $value['type'];
                $requestId = $value['requestId'] ?? null;
                $timestamp = $value['timestamp'] ?? null;
                $payload = $value['payload'] ?? [];

                return $protobuf->encode(new Message(
                    is_string($type) ? $type : '',
                    is_string($requestId) ? $requestId : null,
                    is_int($timestamp) || is_float($timestamp) ? (float) $timestamp : 0.0,
                    is_array($payload) ? $payload : [],
                ))->bytes();
            }

            return $protobuf->pack($value);
        };

        // unpackContainerValue（修复后）：有 type → 信封关联数组；缺 type → Value 树。
        $unpackContainerValue = static function (string $bytes) use ($protobuf): mixed {
            try {
                $message = $protobuf->decode(new Frame($bytes));

                return [
                    'type' => $message->type,
                    'requestId' => $message->requestId,
                    'timestamp' => $message->timestamp,
                    'payload' => $message->payload,
                ];
            } catch (DecodeException) {
                return $protobuf->unpack($bytes);
            }
        };

        $this->protobufAdapter = [$protobuf, $packContainerValue, $unpackContainerValue];
    }

    // --- 形态 A：有 type 按信封（客户端直发单帧） ---

    /** 单帧信封字节经 decodeBatch 解析为 1 帧消息（有 type → 信封）。 */
    public function testSingleEnvelopeWithtypeDecodesAsOneMessage(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        // 直接用 ProtobufSerializer 编码信封字节（客户端直发单帧）
        $protobuf = new ProtobufSerializer();
        $envelope = $protobuf->encode(Message::create('auth', ['uid' => 'a'], null, 1.0));

        $decoded = $serializer->decodeBatch($envelope->bytes());

        self::assertCount(1, $decoded);
        self::assertSame('auth', $decoded[0]->type);
        self::assertSame(['uid' => 'a'], $decoded[0]->payload);
    }

    /** 单帧信封含 requestId 的往返。 */
    public function testSingleEnvelopeWithRequestIdDecodesAsOneMessage(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        $protobuf = new ProtobufSerializer();
        $envelope = $protobuf->encode(Message::create('move', ['dx' => 3], 'r1', 2.0));

        $decoded = $serializer->decodeBatch($envelope->bytes());

        self::assertCount(1, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame('r1', $decoded[0]->requestId);
        self::assertSame(['dx' => 3], $decoded[0]->payload);
    }

    // --- 形态 B：缺 type 按 Value 树（批量容器 round-trip） ---

    /** 批量容器 Value 树 round-trip（缺 type → Value 树）。 */
    public function testBatchContainerValueTreeRoundTrip(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        $messages = [
            Message::create('move', ['dx' => 1, 'dy' => -2], 'r1', 1.0),
            Message::create('auth', ['token' => 't'], null, 2.0),
        ];

        $decoded = $serializer->decodeBatch($serializer->encodeBatch($messages));

        self::assertCount(2, $decoded);
        self::assertSame('move', $decoded[0]->type);
        self::assertSame(['dx' => 1, 'dy' => -2], $decoded[0]->payload);
        self::assertSame('auth', $decoded[1]->type);
        self::assertSame('r1', $decoded[0]->requestId);
        self::assertSame(null, $decoded[1]->requestId);
    }

    /** 批量容器含整数键 payload 的 round-trip（验证 zigzag 修复与容器协同）。 */
    public function testBatchContainerWithIntKeysRoundTrip(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        $messages = [
            Message::create('state', [0 => 'zero', PHP_INT_MIN => 'min', PHP_INT_MAX => 'max'], null, 3.0),
        ];

        $decoded = $serializer->decodeBatch($serializer->encodeBatch($messages));

        self::assertCount(1, $decoded);
        self::assertSame('state', $decoded[0]->type);
        self::assertSame([0 => 'zero', PHP_INT_MIN => 'min', PHP_INT_MAX => 'max'], $decoded[0]->payload);
    }

    /** 缺 type 的帧形数组（无 type 键）pack 为 Value 树，unpack 还原关联数组（缺 type → Value 树）。 */
    public function testMissingTypeKeyPackedAsValueTree(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        // 构造不含 type 键的帧形数组 → packContainerValue 走 Value 树分支
        $frameData = ['requestId' => 'r9', 'timestamp' => 4.0, 'payload' => ['x' => 1]];
        $packed = $packContainerValue($frameData);

        $result = $unpackContainerValue($packed);

        // unpack 还原为 Value 树（map），非 list
        self::assertIsArray($result);
        self::assertArrayNotHasKey('type', $result);
    }

    // --- edge case ---

    /** 空批量包返回空列表。 */
    public function testEmptyBatchDecodesToEmptyList(): void
    {
        [, $packContainerValue, $unpackContainerValue] = $this->protobufAdapter;
        $serializer = new JsonBatchSerializer(new ProtobufSerializer(), $packContainerValue, $unpackContainerValue);

        self::assertSame([], $serializer->decodeBatch(''));
    }
}
