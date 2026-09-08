<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Framework\Auth\Identity;
use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;

/**
 * 静态账号认证器：内存账号表比对 username 与密码哈希（password_verify），demo 阶段替代真实账号系统。
 * 恒时设计（主循环 CPU 剥离）：无论账号是否存在都执行一次 password_verify，杜绝时序侧漏账号存在性——
 * 不存在账号也拿 dummy 哈希比对（恒定 bcrypt 成本）。bcrypt 由 password_hash 的 cost 控制，装配期在
 * run-worker 的 NYTHROS_BCRYPT_COST 设定（见 §6.3 登录限速 + best-practices）。
 * Static authenticator: verifies username/password against an in-memory account table of password hashes
 * (password_verify), standing in for a real account system in the demo phase.
 * Constant-time design (the hot-path CPU strip): password_verify runs exactly once per authentication
 * regardless of account existence, closing the timing side-channel that leaks account existence — a nonexistent
 * account is still compared against a dummy hash (a fixed bcrypt cost). bcrypt strength is governed by the
 * password_hash cost, set at assembly time via run-worker's NYTHROS_BCRYPT_COST (see §6.3 login rate limit + best-practices).
 */
final class StaticAuthenticator implements AuthenticatorInterface
{
    /**
     * dummy 哈希（账号不存在时恒定比对的假哈希），首次 authenticate 时惰性生成（构造零 bcrypt）。
     * The dummy hash (a fake hash always compared against when the account is absent), generated lazily on the
     * first authenticate() call (the constructor pays no bcrypt).
     */
    private ?string $dummyHash = null;

    /** bcrypt cost for the dummy hash: clamped to 4~31, matching the account-table strength. */
    private readonly int $dummyCost;

    /**
     * 构造静态认证器，注入账号表与 dummy 成本。
     * Constructs the static authenticator with the account table and the dummy cost.
     *
     * @param array<string, string> $accounts username => 密码哈希（password_hash 产物，非明文） Account table (username => password hash produced by password_hash, never plaintext)
     * @param ?int $bcryptCost dummy 哈希的 bcrypt cost（null = 读 env NYTHROS_BCRYPT_COST 缺省 10；4~31 钳位） dummy-hash bcrypt cost (null = read env NYTHROS_BCRYPT_COST, default 10; clamped 4~31)
     */
    public function __construct(
        private readonly array $accounts,
        ?int $bcryptCost = null,
    ) {
        $raw = $bcryptCost ?? (getenv('NYTHROS_BCRYPT_COST') !== false
            ? (int) getenv('NYTHROS_BCRYPT_COST')
            : 10);
        $this->dummyCost = max(4, min(31, $raw));
    }

    /**
     * 认证凭证：字段缺失或账号不存在/密码不符时抛 AuthenticationException，成功返回 Identity。
     * 不存在账号也执行一次 password_verify（恒时），不泄露账号存在性。
     * Authenticates credentials: throws AuthenticationException on missing fields, unknown account or password
     * mismatch; returns an Identity on success. An unknown account still pays one password_verify (constant time),
     * leaking nothing about account existence.
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

        // 恒时比对：账号存在取真哈希、不存在取 dummy 哈希，password_verify 必跑一次——
        // 「账号不存在」与「密码错误」同耗时同异常，时序探测无法区分（B-1 哈希表语义 + 主循环 CPU 剥离）
        // Constant-time comparison: real hash when the account exists, the dummy hash otherwise, password_verify
        // always runs once — "unknown account" and "wrong password" share cost and exception, so timing probes
        // cannot tell them apart (the B-1 hash-table semantics + the hot-path CPU strip).
        $stored = $this->accounts[$username] ?? ($this->dummyHash ??= password_hash(
            'nythros-dummy',
            PASSWORD_BCRYPT,
            ['cost' => $this->dummyCost],
        ));

        if (!password_verify($password, $stored)) {
            throw new AuthenticationException('用户名或密码错误');
        }

        // demo 阶段 userId 直接复用 username，真实系统会从账号表取独立 ID
        // In the demo phase userId reuses username directly; a real system would resolve a distinct ID from the account table
        return new Identity($username, $username);
    }
}
