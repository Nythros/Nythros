<?php

declare(strict_types=1);

namespace Nythros\Cluster;

/**
 * 服务实例描述：discover 返回的存活实例单元（id + 元数据）。
 * Service instance descriptor: the live instance unit returned by discover (id + metadata).
 */
final readonly class ServiceInstance
{
    /**
     * 构造服务实例描述。
     * Construct a service instance descriptor.
     *
     * @param string $id 实例标识 Instance identifier.
     * @param array<string, mixed> $meta 实例元数据 Instance metadata.
     */
    public function __construct(
        public string $id,
        public array $meta,
    ) {
    }
}
