<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 服务状态源契约（组装层实现接口、framework 消费）：status 命令的数据来源，
 * 返回键值对集合（值须为标量，便于协议回执与日志直出）。
 * The GM status-source contract (the assembly layer implements the interface, framework consumes it): the data source
 * of the status command, returning key-value pairs (values must be scalar so receipts and logs can carry them).
 */
interface GmStatusProviderInterface
{
    /**
     * 采集本服务当前状态快照。
     * Collects this service's current status snapshot.
     *
     * @return array<string, string|int|float|bool> 标量状态键值对 Scalar status key-value pairs.
     */
    public function status(): array;
}
