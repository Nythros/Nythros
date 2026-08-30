<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * 认证器接口：校验凭证并产出身份。
 * Authenticator interface: validates credentials and produces an identity.
 */
interface AuthenticatorInterface
{
    /**
     * 校验凭证并返回身份；凭证无效时抛出异常。
     * Validate credentials and return an identity; throw when credentials are invalid.
     *
     * @param array<string|int, mixed> $credentials 凭证键值对 Credential key-value pairs
     * @return IdentityInterface 认证通过的身份 Authenticated identity.
     * @throws AuthenticationException 凭证无效 Credentials are invalid.
     */
    public function authenticate(array $credentials): IdentityInterface;
}
