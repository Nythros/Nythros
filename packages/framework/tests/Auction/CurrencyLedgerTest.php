<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Auction;

use Nythros\Framework\Auction\CurrencyLedger;
use PHPUnit\Framework\TestCase;

/**
 * CurrencyLedger 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过。
 * CurrencyLedger integration tests: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 */
final class CurrencyLedgerTest extends TestCase
{
    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 CurrencyLedger 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 CurrencyLedger 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':ec:';
    }

    protected function tearDown(): void
    {
        if ($this->redis === null) {
            return;
        }

        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
        $this->redis = null;
    }

    private function ledger(): CurrencyLedger
    {
        return new CurrencyLedger($this->redis, $this->prefix);
    }

    public function testBalanceDefaultsToZero(): void
    {
        self::assertSame(0, $this->ledger()->balance('u1'));
    }

    public function testDepositAccumulates(): void
    {
        $ledger = $this->ledger();

        $ledger->deposit('u1', 100);
        $ledger->deposit('u1', 50);

        self::assertSame(150, $ledger->balance('u1'));
    }

    public function testWithdrawSufficiencySemantics(): void
    {
        $ledger = $this->ledger();
        $ledger->deposit('u1', 100);

        // 余额不足：拒绝且零变更
        // Insufficient balance: rejected with zero mutation
        self::assertFalse($ledger->withdraw('u1', 150));
        self::assertSame(100, $ledger->balance('u1'));

        self::assertTrue($ledger->withdraw('u1', 60));
        self::assertSame(40, $ledger->balance('u1'));

        self::assertFalse($ledger->withdraw('u1', 41));
        self::assertTrue($ledger->withdraw('u1', 40));
        self::assertSame(0, $ledger->balance('u1'));
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        $ledger = $this->ledger();

        try {
            $ledger->deposit('u1', 0);
            self::fail('零金额入账必须被拒绝');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $ledger->withdraw('u1', -5);
            self::fail('负金额出账必须被拒绝');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testIllegalUidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger()->balance("bad;uid\x80");
    }
}
