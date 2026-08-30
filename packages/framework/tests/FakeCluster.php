<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Cluster\ServiceInstance;
use Nythros\Cluster\ServiceRegistryInterface;
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;

/**
 * 共享测试 fakes：ServiceRegistry / TokenManager 的调用记录实现，供 MapServerTest 与 SocialServiceTest 复用。
 * Shared test fakes: call-recording implementations of ServiceRegistry / TokenManager, reused by MapServerTest and SocialServiceTest.
 */

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

/**
 * FakeTokenManager - 记录 peek/consume/issue 调用；peek 按配置 records 返回，consume 按配置 results 返回（缺省 Valid），issue 可配置抛异常。
 * FakeTokenManager - records peek/consume/issue calls; peek returns configured records, consume returns configured results (Valid by default), issue can be configured to throw.
 */
final class FakeTokenManager implements TokenManagerInterface
{
    /** @var array<string, TokenRecord> token => 记录 peek 返回表 Peek result table. */
    public array $records = [];

    /** @var array<string, TokenStatus> token => consume 判定（缺省 Valid） Consume verdict per token (Valid by default). */
    public array $consumeResults = [];

    /** @var list<array{token: string, scope: string}> consume 调用记录 Consume call records. */
    public array $consumeCalls = [];

    /** @var list<array{uid: string, mapId: string, scopes: array<string>, ttlSeconds: int}> issue 调用记录 Issue call records. */
    public array $issueCalls = [];

    /** @var ?\Throwable issue 抛出的异常（非 null 时 issue 抛出） The throwable issue throws when set. */
    public ?\Throwable $issueException = null;

    public function peek(string $token): ?TokenRecord
    {
        return $this->records[$token] ?? null;
    }

    public function consume(string $token, string $scope): TokenStatus
    {
        $this->consumeCalls[] = ['token' => $token, 'scope' => $scope];

        return $this->consumeResults[$token] ?? TokenStatus::Valid;
    }

    public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string
    {
        $this->issueCalls[] = ['uid' => $uid, 'mapId' => $mapId, 'scopes' => $scopes, 'ttlSeconds' => $ttlSeconds];
        if ($this->issueException !== null) {
            throw $this->issueException;
        }

        return 'issued-token-' . count($this->issueCalls);
    }
}
