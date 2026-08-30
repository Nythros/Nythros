<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * 认证异常：凭证无效或认证失败时抛出。
 * Authentication exception: thrown when credentials are invalid or authentication fails.
 */
class AuthenticationException extends \RuntimeException
{
}
