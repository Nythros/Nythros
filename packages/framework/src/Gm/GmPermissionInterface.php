<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 权限检查契约：uid 是否有权执行某命令（framework 只定义接口，身份实现留守 starter-kit——
 * 典型形态为 StaticAuthenticator 侧的白名单扩展占位）。
 * GM permission-check contract: whether a uid may run a command (framework defines the interface only; the
 * identity implementation stays in starter-kit — typically a whitelist extension placeholder beside
 * StaticAuthenticator).
 */
interface GmPermissionInterface
{
    /**
     * 权限判定：允许执行返回 true。
     * The permission verdict: true allows execution.
     *
     * @param string $uid 发起命令的账号 uid The uid issuing the command.
     * @param string $command 命令名 The command name.
     */
    public function allows(string $uid, string $command): bool;
}
