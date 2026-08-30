<?php

declare(strict_types=1);

namespace Nythros\Demo;

use Nythros\Framework\Gm\GmPermissionInterface;

/**
 * 静态 GM 白名单授权器：内存 uid 白名单比对，demo 阶段替代真实权限系统
 * （裁决落点：GM 白名单留守 starter-kit——framework 只提供 GmPermissionInterface 能力接口）。
 * Static GM whitelist authorizer: an in-memory uid whitelist comparison standing in for a real permission
 * system in the demo phase (the ruling's landing spot: the GM whitelist stays in starter-kit — framework only
 * provides the GmPermissionInterface capability contract).
 *
 * 占位语义：白名单命中即放行全部命令；命令级差异（如分级权限）留待后续批次。
 * Placeholder semantics: a whitelist hit allows every command; per-command differentiation (e.g. tiered
 * permissions) is deferred to a later batch.
 */
final class StaticGmAuthorizer implements GmPermissionInterface
{
    /**
     * @param array<string, true> $whitelist uid 白名单集合（uid => true） The uid whitelist set (uid => true).
     */
    public function __construct(private readonly array $whitelist)
    {
    }

    public function allows(string $uid, string $command): bool
    {
        return isset($this->whitelist[$uid]);
    }
}
