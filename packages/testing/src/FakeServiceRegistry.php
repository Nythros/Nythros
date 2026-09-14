<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Cluster\ServiceInstance;
use Nythros\Cluster\ServiceRegistryInterface;

/**
 * FakeServiceRegistry - 记录 register/heartbeat/unregister/bind/unbind 调用，discover 按配置返回。
 * FakeServiceRegistry - records register/heartbeat/unregister/bind/unbind calls; discover returns configured instances.
 */
final class FakeServiceRegistry implements ServiceRegistryInterface
{
    /** @var list<array{type: string, id: string, meta: array<string, mixed>}> register 调用记录 Register call records. */
    public array $registers = [];

    /** @var list<array{type: string, id: string, meta: array<string, mixed>}> heartbeat 调用记录 Heartbeat call records. */
    public array $heartbeats = [];

    /** @var list<array{type: string, id: string}> unregister 调用记录 Unregister call records. */
    public array $unregisters = [];

    /** @var list<array{type: string, uid: string, serviceId: string, ttlSeconds: int}> bind 调用记录 Bind call records. */
    public array $binds = [];

    /** @var list<array{type: string, uid: string, serviceId: string}> unbind 调用记录 Unbind call records. */
    public array $unbinds = [];

    /** @var array<string, array<string, ServiceInstance>> type => (serviceId => instance) discover 返回表 Discover result tables. */
    public array $discoveries = [];

    /** @var array<string, array<string, ?string>> type => (uid => serviceId|null) resolve 配置表 Resolve configuration tables. */
    public array $resolveResults = [];

    /** @var list<array{type: string, uid: string}> resolve 调用记录 Resolve call records. */
    public array $resolveCalls = [];

    public function register(string $serviceType, string $serviceId, array $meta = []): void
    {
        $this->registers[] = ['type' => $serviceType, 'id' => $serviceId, 'meta' => $meta];
    }

    public function heartbeat(string $serviceType, string $serviceId, array $meta = []): void
    {
        $this->heartbeats[] = ['type' => $serviceType, 'id' => $serviceId, 'meta' => $meta];
    }

    public function discover(string $serviceType): array
    {
        return $this->discoveries[$serviceType] ?? [];
    }

    public function unregister(string $serviceType, string $serviceId): void
    {
        $this->unregisters[] = ['type' => $serviceType, 'id' => $serviceId];
    }

    public function resolve(string $serviceType, string $uid): ?string
    {
        $this->resolveCalls[] = ['type' => $serviceType, 'uid' => $uid];

        return $this->resolveResults[$serviceType][$uid] ?? null;
    }

    public function bind(string $serviceType, string $uid, string $serviceId, int $ttlSeconds = 21600): void
    {
        $this->binds[] = ['type' => $serviceType, 'uid' => $uid, 'serviceId' => $serviceId, 'ttlSeconds' => $ttlSeconds];
    }

    public function unbind(string $serviceType, string $uid, string $serviceId): void
    {
        $this->unbinds[] = ['type' => $serviceType, 'uid' => $uid, 'serviceId' => $serviceId];
    }
}
