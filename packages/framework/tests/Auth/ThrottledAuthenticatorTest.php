<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Auth;

use Nythros\Framework\Auth\Identity;
use Nythros\Framework\Auth\ThrottledAuthenticator;
use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;
use PHPUnit\Framework\TestCase;

/**
 * ThrottledAuthenticator 单元测试：防爆破装饰器的记账/锁定/清零/时钟注入语义。
 * ThrottledAuthenticator unit tests: the guard's failure accounting, lockout, reset and clock-injection semantics.
 */
final class ThrottledAuthenticatorTest extends TestCase
{
    /** 时间线时钟：测试推进虚拟时刻。 Timeline clock: tests advance a virtual time. */
    public float $now = 1000.0;

    /** 记录内层调用次数（锁定中不得触达内层）。 Counts inner calls (locked usernames must never reach the inner layer). */
    public int $innerCalls = 0;

    private function inner(): AuthenticatorInterface
    {
        return new class ($this) implements AuthenticatorInterface {
            public function __construct(private readonly ThrottledAuthenticatorTest $case)
            {
            }

            public function authenticate(array $credentials): IdentityInterface
            {
                ++$this->case->innerCalls;
                if (($credentials['password'] ?? null) !== 'right') {
                    throw new AuthenticationException('用户名或密码错误');
                }

                return new Identity((string) $credentials['username'], (string) $credentials['username']);
            }
        };
    }

    private function guard(int $maxAttempts = 3, int $lockoutSeconds = 60): ThrottledAuthenticator
    {
        return new ThrottledAuthenticator($this->inner(), $maxAttempts, $lockoutSeconds, fn (): float => $this->now);
    }

    public function test成功透传并清零失败计数(): void
    {
        $guard = $this->guard(maxAttempts: 2);
        try {
            $guard->authenticate(['username' => 'u1', 'password' => 'wrong']);
            self::fail('期望抛出认证异常');
        } catch (AuthenticationException $e) {
            self::assertSame('用户名或密码错误', $e->getMessage());
        }
        $identity = $guard->authenticate(['username' => 'u1', 'password' => 'right']);
        self::assertSame('u1', $identity->getUserId());
        // 成功清零：再失败一次不会立即锁定（阈值为 2）
        // The success resets the count: one more failure must not lock (threshold is 2).
        try {
            $guard->authenticate(['username' => 'u1', 'password' => 'wrong']);
            self::fail('期望抛出认证异常');
        } catch (AuthenticationException) {
        }
        self::assertSame(3, $this->innerCalls);
    }

    public function test连续失败达阈值即锁定且不触达内层(): void
    {
        $guard = $this->guard(maxAttempts: 3);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $guard->authenticate(['username' => 'u1', 'password' => 'wrong']);
                self::fail('期望抛出认证异常');
            } catch (AuthenticationException $e) {
                self::assertSame('用户名或密码错误', $e->getMessage());
            }
        }
        $this->innerCalls = 0;
        try {
            $guard->authenticate(['username' => 'u1', 'password' => 'right']);
            self::fail('锁定中应拒绝');
        } catch (AuthenticationException $e) {
            self::assertSame('尝试过于频繁，请稍后再试', $e->getMessage());
        }
        self::assertSame(0, $this->innerCalls, '锁定中不得触达内层认证器');
    }

    public function test锁定到期后恢复且重新计数(): void
    {
        $guard = $this->guard(maxAttempts: 2, lockoutSeconds: 60);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $guard->authenticate(['username' => 'u1', 'password' => 'wrong']);
            } catch (AuthenticationException) {
            }
        }
        $this->now += 61.0; // 越过锁定窗口 Past the lockout window.
        $identity = $guard->authenticate(['username' => 'u1', 'password' => 'right']);
        self::assertSame('u1', $identity->getUserId());
    }

    public function test不同账号计数互不影响(): void
    {
        $guard = $this->guard(maxAttempts: 2);
        try {
            $guard->authenticate(['username' => 'u1', 'password' => 'wrong']);
        } catch (AuthenticationException) {
        }
        $identity = $guard->authenticate(['username' => 'u2', 'password' => 'right']);
        self::assertSame('u2', $identity->getUserId(), 'u1 的失败不得影响 u2');
    }

    public function test非法参数快速失败(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThrottledAuthenticator($this->inner(), maxAttempts: 0);
    }
}
