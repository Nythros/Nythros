<?php

declare(strict_types=1);

namespace Nythros\Cluster;

/**
 * 服务注册表契约：服务实例注册/心跳/发现 + uid 寻址（bind/unbind/resolve）。
 * Service registry contract: instance register/heartbeat/discover + uid addressing (bind/unbind/resolve).
 */
interface ServiceRegistryInterface
{
    /**
     * 注册服务实例；重复注册 = 覆盖 meta + 续心跳（自愈路径）。
     * Register a service instance; re-registering the same id overwrites meta and renews the heartbeat (self-healing path).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param array<string, mixed> $meta 实例元数据（如 mapId/channelId/playerCount/wsAddress） Instance metadata (e.g. mapId/channelId/playerCount/wsAddress).
     */
    public function register(string $serviceType, string $serviceId, array $meta = []): void;

    /**
     * 心跳续期；meta 与既有值原子合并（playerCount 上报）。
     * Renew the heartbeat; meta is atomically merged with the existing values (playerCount reporting).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param array<string, mixed> $meta 心跳携带的元数据 Metadata carried by the heartbeat.
     */
    public function heartbeat(string $serviceType, string $serviceId, array $meta = []): void;

    /**
     * 发现存活实例：弱一致快照，至多 TTL 延迟；心跳键缺失即不可见。
     * Discover live instances: a weakly-consistent snapshot with at most TTL lag; missing heartbeat keys are invisible.
     *
     * @param string $serviceType 服务类型 Service type.
     * @return array<string, ServiceInstance> map<serviceId, ServiceInstance> 存活实例映射 Live instance map.
     */
    public function discover(string $serviceType): array;

    /**
     * 注销服务实例。
     * Unregister a service instance.
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $serviceId 实例标识 Instance identifier.
     */
    public function unregister(string $serviceType, string $serviceId): void;

    /**
     * uid 寻址：返回该 uid 绑定的存活实例 id；无映射/实例已死返回 null。
     * uid addressing: returns the live instance id the uid is bound to; null when unbound or the instance is dead.
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @return ?string 存活实例 id，无映射 null Live instance id, or null when unbound.
     */
    public function resolve(string $serviceType, string $uid): ?string;

    /**
     * 绑定 uid → 实例（覆盖写 = 同 uid 后登录者覆盖先登录者；会话期 TTL 与 token TTL 解耦，默认 21600s）。
     * Bind a uid to an instance (overwrite semantics: a later login overrides the earlier one; session TTL is decoupled from the token TTL, default 21600s).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @param string $serviceId 实例标识 Instance identifier.
     * @param int $ttlSeconds 绑定 TTL 秒数 Binding TTL in seconds.
     */
    public function bind(string $serviceType, string $uid, string $serviceId, int $ttlSeconds = 21600): void;

    /**
     * 解除 uid → 实例绑定（仅当当前映射值等于 serviceId 才删，跨实例误删不可能）。
     * Unbind a uid from an instance (removed only when the current mapping equals serviceId, so cross-instance removal is impossible).
     *
     * @param string $serviceType 服务类型 Service type.
     * @param string $uid 用户唯一标识 Unique user identifier.
     * @param string $serviceId 实例标识 Instance identifier.
     */
    public function unbind(string $serviceType, string $uid, string $serviceId): void;
}
