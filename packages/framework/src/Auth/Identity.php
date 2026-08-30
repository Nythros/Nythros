<?php

declare(strict_types=1);

namespace Nythros\Framework\Auth;

use Nythros\Security\IdentityInterface;

/**
 * 身份对象：不可变的 userId + username 组合，demo 阶段两者取同值。
 * Identity value object: an immutable userId + username pair; in the demo phase both hold the same value.
 */
final readonly class Identity implements IdentityInterface
{
    /**
     * 构造身份对象。
     * Constructs the identity.
     *
     * @param string $userId 用户唯一标识 Unique user identifier
     * @param string $username 用户名 Username
     */
    public function __construct(
        public string $userId,
        public string $username,
    ) {
    }

    /**
     * 返回用户唯一标识。
     * Returns the unique user identifier.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * 返回用户名。
     * Returns the username.
     */
    public function getUsername(): string
    {
        return $this->username;
    }
}
