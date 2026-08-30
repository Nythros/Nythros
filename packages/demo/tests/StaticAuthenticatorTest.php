<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

use Nythros\Demo\StaticAuthenticator;
use Nythros\Framework\Auth\Identity;
use Nythros\Security\AuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * StaticAuthenticatorTest - 静态账号认证器单测（B-5 密码改造）：哈希表语义下正确密码认证成功、
 * 错误密码/未知用户/缺字段抛 AuthenticationException、表值不存明文。
 * StaticAuthenticatorTest - unit tests for the static authenticator (B-5 password rework): a correct password
 * authenticates under hash-table semantics, wrong password / unknown user / missing fields throw
 * AuthenticationException, and the table never stores plaintext.
 */
final class StaticAuthenticatorTest extends TestCase
{
    public function testCorrectPasswordAuthenticatesWithIdentity(): void
    {
        $authenticator = new StaticAuthenticator([
            '1001' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $identity = $authenticator->authenticate(['username' => '1001', 'password' => 'secret']);

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('1001', $identity->getUserId());
        self::assertSame('1001', $identity->getUsername());
    }

    public function testWrongPasswordThrowsAuthenticationException(): void
    {
        $authenticator = new StaticAuthenticator([
            '1001' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(['username' => '1001', 'password' => 'wrong']);
    }

    public function testUnknownUserThrowsAuthenticationException(): void
    {
        $authenticator = new StaticAuthenticator([
            '1001' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(['username' => 'ghost', 'password' => 'secret']);
    }

    public function testMissingFieldsThrowAuthenticationException(): void
    {
        $authenticator = new StaticAuthenticator([
            '1001' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(['username' => '1001']);
    }

    public function testTableStoresHashesNotPlaintext(): void
    {
        // 哈希表语义：明文密码直接等于表值必须认证失败（表值只能是 password_hash 产物）
        // Hash-table semantics: a plaintext password equal to the table value must fail verification (the table value can only be a password_hash output)
        $authenticator = new StaticAuthenticator([
            '1001' => 'secret',
        ]);

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(['username' => '1001', 'password' => 'secret']);
    }
}
