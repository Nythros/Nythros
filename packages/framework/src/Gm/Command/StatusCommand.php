<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm\Command;

use Nythros\Framework\Gm\GmCommandInterface;
use Nythros\Framework\Gm\GmResult;
use Nythros\Framework\Gm\GmStatusProviderInterface;

/**
 * status 命令：回服务状态快照（数据来自注入的 GmStatusProviderInterface）。
 * The status command: replies with the service status snapshot (data from the injected GmStatusProviderInterface).
 */
final class StatusCommand implements GmCommandInterface
{
    public function __construct(private readonly GmStatusProviderInterface $provider)
    {
    }

    public function name(): string
    {
        return 'status';
    }

    public function execute(array $payload): GmResult
    {
        $snapshot = $this->provider->status();

        return GmResult::ok('status', $snapshot);
    }
}
