<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman\Tests;

use Nythros\Network\ConnectionClosedException;
use Nythros\NetworkWorkerman\ConnectionManager;
use Nythros\NetworkWorkerman\WorkermanConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

/**
 * WorkermanConnectionTest - 覆盖 WorkermanConnection 的 sendBatch 顺序、空数组空操作与关闭连接异常语义。
 * Tests covering WorkermanConnection sendBatch ordering, empty-array no-op, and closed-connection exception semantics.
 */
final class WorkermanConnectionTest extends TestCase
{
    public function testSendBatchSendsPayloadsInOrder(): void
    {
        $tcp = $this->createStub(TcpConnection::class);
        $tcp->method('getStatus')->willReturn(TcpConnection::STATUS_ESTABLISHED);

        $sent = [];
        $tcp->method('send')->willReturnCallback(static function (mixed $payload) use (&$sent): ?bool {
            $sent[] = $payload;

            return null;
        });

        $conn = new WorkermanConnection($tcp, new ConnectionManager(null));

        $conn->sendBatch(['first', 'second', 'third']);

        self::assertSame(['first', 'second', 'third'], $sent);
    }

    public function testSendBatchWithEmptyArrayIsNoOp(): void
    {
        $tcp = $this->createStub(TcpConnection::class);
        $tcp->method('getStatus')->willReturn(TcpConnection::STATUS_ESTABLISHED);
        $tcp->method('send')->willReturnCallback(static function (mixed $payload): ?bool {
            Assert::fail('send() should not be called for an empty batch');

            return null;
        });

        $conn = new WorkermanConnection($tcp, new ConnectionManager(null));

        $conn->sendBatch([]);

        self::addToAssertionCount(1);
    }

    public function testSendBatchThrowsOnClosedConnection(): void
    {
        $tcp = $this->createStub(TcpConnection::class);
        $tcp->method('getStatus')->willReturn(TcpConnection::STATUS_CLOSED);

        $conn = new WorkermanConnection($tcp, new ConnectionManager(null));

        $this->expectException(ConnectionClosedException::class);
        $conn->sendBatch(['payload']);
    }
}
