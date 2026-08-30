<?php

declare(strict_types=1);

namespace Nythros\Framework\Deploy;

/**
 * 展开后的单个 worker：一个启动进程的完整描述（所属部署单元 + 服务声明 + count 内实例序号）。
 * An expanded worker: the full description of one launch process (owning deployment unit + service declaration + instance ordinal within count).
 */
final class DeployWorker
{
    /**
     * 构造 worker 描述。
     * Constructs the worker description.
     *
     * @param string $process 所属 process 块名（部署单元） The owning process-block name (deployment unit).
     * @param DeployService $service 服务声明 The service declaration.
     * @param int $instance count 内实例序号（1 起，count=1 恒为 1） Instance ordinal within count (1-based; always 1 when count=1).
     */
    public function __construct(
        public readonly string $process,
        public readonly DeployService $service,
        public readonly int $instance = 1,
    ) {
    }
}
