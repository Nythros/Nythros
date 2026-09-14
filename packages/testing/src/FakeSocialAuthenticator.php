<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Auth\Identity;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;

/**
 * FakeSocialAuthenticator - 可配置抛出异常或返回指定 uid（缺省取 credentials['username']）。
 * FakeSocialAuthenticator - configurable to throw or return a fixed uid (defaults to credentials['username']).
 */
final class FakeSocialAuthenticator implements AuthenticatorInterface
{
    /** @var ?\Throwable 抛出的异常（非 null 时抛） The throwable thrown when set. */
    public ?\Throwable $exception = null;

    /** @var ?string 固定返回的 uid（null = 取 credentials['username']） The fixed uid (null = credentials['username']). */
    public ?string $uid = null;

    public function authenticate(array $credentials): IdentityInterface
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $uid = $this->uid ?? (string) ($credentials['username'] ?? 'u1');

        return new Identity($uid, $uid);
    }
}
