<?php

declare(strict_types=1);

namespace Nythros\Protocol\Tests;

use Nythros\Protocol\Message;
use PHPUnit\Framework\TestCase;

/**
 * MessageTest - 覆盖 Message::create 的核心行为测试（默认时间戳、默认 requestId、显式参数保留）。
 * Tests covering the core behaviors of Message::create (default timestamp, default requestId, explicit arguments).
 */
final class MessageTest extends TestCase
{
    public function testCreateDefaultsTimestampToCurrentMicrotime(): void
    {
        $before = microtime(true);
        $message = Message::create('login');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $message->timestamp);
        self::assertLessThanOrEqual($after, $message->timestamp);
    }

    public function testCreateDefaultsRequestIdToNull(): void
    {
        $message = Message::create('login');

        self::assertNull($message->requestId);
    }

    public function testCreateKeepsExplicitArguments(): void
    {
        $message = Message::create(
            type: 'move',
            payload: ['x' => 1, 'y' => 2],
            requestId: 'req-1',
            timestamp: 1725000000.25,
        );

        self::assertSame('move', $message->type);
        self::assertSame('req-1', $message->requestId);
        self::assertSame(1725000000.25, $message->timestamp);
        self::assertSame(['x' => 1, 'y' => 2], $message->payload);
    }
}
