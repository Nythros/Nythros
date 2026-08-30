<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * 身份接口：认证成功后获得的用户身份。
 * Identity interface: the user identity obtained after successful authentication.
 */
interface IdentityInterface
{
    /**
     * 返回用户唯一标识。
     * Return the unique user identifier.
     *
     * @return string 用户唯一标识 Unique user identifier.
     */
    public function getUserId(): string;

    /**
     * 返回用户名。
     * Return the username.
     *
     * @return string 用户名 Username.
     */
    public function getUsername(): string;
}
