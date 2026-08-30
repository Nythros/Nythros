<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman\Tests;

use Nythros\Contracts\TimerInterface;
use Nythros\NetworkWorkerman\ConnectionManager;
use Nythros\NetworkWorkerman\WorkermanConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

/**
 * ConnectionManagerTest - 覆盖 ConnectionManager 的连接挂载/摘除、认证标记与认证超时扫描关闭行为。
 * Tests covering ConnectionManager attach/detach, authentication flagging, and auth-timeout scan close behavior.
 */
final class ConnectionManagerTest extends TestCase
{
    public function testAttachSetsInitialState(): void
    {
        $now = 100.0;
        $manager = new ConnectionManager(null, $this->clock($now));
        [$conn] = $this->createConnection($manager, 1);

        $manager->attach($conn);

        self::assertFalse($manager->isAuthenticated($conn));
        self::assertSame(100.0, $manager->getLastMessageTime($conn));
        self::assertCount(1, $manager->allConnections());
    }

    public function testTouchUpdatesLastMessageTime(): void
    {
        $now = 100.0;
        $manager = new ConnectionManager(null, $this->clock($now));
        [$conn] = $this->createConnection($manager, 1);

        $manager->attach($conn);
        $now = 125.5;
        $manager->touch($conn);

        self::assertSame(125.5, $manager->getLastMessageTime($conn));
    }

    public function testMarkAuthenticatedFlipsFlag(): void
    {
        $manager = new ConnectionManager(null);
        [$conn] = $this->createConnection($manager, 1);

        $manager->attach($conn);
        self::assertFalse($manager->isAuthenticated($conn));

        $manager->markAuthenticated($conn);

        self::assertTrue($manager->isAuthenticated($conn));
    }

    public function testAuthTimeoutScanClosesExpiredUnauthenticatedConnection(): void
    {
        $now = 100.0;
        $timer = new FakeTimer();
        $manager = new ConnectionManager($timer, $this->clock($now));

        [$expired, $expiredTcp] = $this->createConnection($manager, 1, closeExpectation: 'once');
        [$active, $activeTcp] = $this->createConnection($manager, 2, closeExpectation: 'never');
        [$authenticated, $authenticatedTcp] = $this->createConnection($manager, 3, closeExpectation: 'never');

        $manager->attach($expired);
        $manager->attach($active);
        $manager->attach($authenticated);
        $manager->markAuthenticated($authenticated);

        $now = 109.0;
        $manager->touch($active);

        $manager->startAuthTimeoutScan(5, 1);
        self::assertCount(1, $timer->callbacks);

        $now = 110.0;
        $timer->fireLast();

        self::assertTrue($expiredTcp->closeCalled);
        self::assertFalse($activeTcp->closeCalled);
        self::assertFalse($authenticatedTcp->closeCalled);
    }

    public function testDetachRemovesConnection(): void
    {
        $manager = new ConnectionManager(null);
        [$conn] = $this->createConnection($manager, 1);

        $manager->attach($conn);
        self::assertCount(1, $manager->allConnections());

        $manager->detach($conn);

        self::assertCount(0, $manager->allConnections());
    }

    /**
     * @param 'once'|'never' $closeExpectation
     *
     * @return array{0: WorkermanConnection, 1: FakeTcpConnection}
     */
    private function createConnection(ConnectionManager $manager, int $id, string $closeExpectation = 'never'): array
    {
        $tcp = new FakeTcpConnection($id, $closeExpectation);

        return [new WorkermanConnection($tcp, $manager), $tcp];
    }

    /** @return callable(): float */
    private function clock(float &$now): callable
    {
        return static function () use (&$now): float {
            return $now;
        };
    }
}

final class FakeTimer implements TimerInterface
{
    /** @var list<callable(): void> */
    public array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
    }

    public function fireLast(): void
    {
        $callback = $this->callbacks[count($this->callbacks) - 1];
        $callback();
    }
}

/** 测试替身：不调用 TcpConnection 父构造（无事件循环/套接字），仅覆盖被测方法 */
final class FakeTcpConnection extends TcpConnection
{
    public bool $closeCalled = false;

    /** @var 'once'|'never' */
    private string $closeExpectation;

    /** @param 'once'|'never' $closeExpectation */
    public function __construct(int $id, string $closeExpectation)
    {
        $this->id = $id;
        $this->closeExpectation = $closeExpectation;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($this->closeExpectation === 'once') {
            Assert::assertFalse($this->closeCalled, 'close() called more than once');
        } else {
            Assert::fail('close() should not be called');
        }

        $this->closeCalled = true;
    }

    public function getStatus(bool $rawOutput = true): int|string
    {
        return self::STATUS_ESTABLISHED;
    }
}
