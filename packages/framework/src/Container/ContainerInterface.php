<?php

declare(strict_types=1);

namespace Nythros\Framework\Container;

/**
 * 轻量服务容器契约：实例/工厂注册与按 id 解析。
 * Lightweight service container contract: instance/factory registration and id-based resolution.
 */
interface ContainerInterface
{
    /**
     * 解析服务；未命中抛异常。
     * Resolves a service; throws when the id is unknown.
     *
     * @param string $id 服务标识 Service id.
     */
    public function get(string $id): mixed;

    /**
     * 服务是否已注册（实例或工厂皆算）。
     * Whether the service is registered (either as an instance or a factory).
     *
     * @param string $id 服务标识 Service id.
     */
    public function has(string $id): bool;

    /**
     * 注册实例。
     * Registers an instance.
     *
     * @param string $id 服务标识 Service id.
     * @param mixed $value 实例值 The instance value.
     */
    public function set(string $id, mixed $value): void;

    /**
     * 注册延迟工厂：首次 get 时装配。
     * Registers a lazy factory: assembled on the first get.
     *
     * @param string $id 服务标识 Service id.
     * @param callable $fn 工厂，返回实例 The factory returning the instance.
     */
    public function factory(string $id, callable $fn): void;

    /**
     * 卸载注册项：同时清理实例与工厂表项，未命中静默忽略。
     * Unregisters a service: clears both the instance and factory entries; missing ids are silently ignored.
     *
     * @param string $id 服务标识 Service id.
     */
    public function remove(string $id): void;
}
