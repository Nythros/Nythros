<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Framework\Auth\Identity;
use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;

/**
 * 静态账号认证器：内存账号表比对 username 与密码哈希（password_verify），demo 阶段替代真实账号系统。
 * Static authenticator: verifies username/password against an in-memory account table of password hashes (password_verify), standing in for a real account system in the demo phase.
 */
final class StaticAuthenticator implements AuthenticatorInterface
{
    /**
     * 构造静态认证器，注入账号表。
     * Constructs the static authenticator with the account table.
     *
     * @param array<string, string> $accounts username => 密码哈希（password_hash 产物，非明文） Account table (username => password hash produced by password_hash, never plaintext)
     */
    public function __construct(private readonly array $accounts)
    {
    }

    /**
     * 认证凭证：字段缺失或账号不存在/密码不符时抛 AuthenticationException，成功返回 Identity。
     * Authenticates credentials: throws AuthenticationException on missing fields, unknown account or password mismatch; returns an Identity on success.
     *
     * @param array<string|int, mixed> $credentials 凭证（含 username/password 键） Credentials (with username/password keys)
     * @return IdentityInterface 认证成功的身份 The authenticated identity
     * @throws AuthenticationException 凭证非法或账号校验失败 Invalid credentials or failed account verification
     */
    public function authenticate(array $credentials): IdentityInterface
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!is_string($username) || !is_string($password)) {
            throw new AuthenticationException('缺少 username 或 password 字段');
        }

        // array_key_exists + 哈希比对：区分「账号不存在」与「密码错误」都由同一异常表达，避免泄露账号是否存在；
        // 表值必须是 password_hash 产物——明文密码直接等于表值即认证失败（哈希表语义，B-1 密码改造）
        // array_key_exists plus hash verification: both "unknown account" and "wrong password" share one exception,
        // avoiding account-existence leakage; the table values must be password_hash outputs — a plaintext password
        // equal to the table value fails verification (hash-table semantics, B-1 password rework)
        if (!array_key_exists($username, $this->accounts) || !password_verify($password, $this->accounts[$username])) {
            throw new AuthenticationException('用户名或密码错误');
        }

        // demo 阶段 userId 直接复用 username，真实系统会从账号表取独立 ID
        // In the demo phase userId reuses username directly; a real system would resolve a distinct ID from the account table
        return new Identity($username, $username);
    }
}
