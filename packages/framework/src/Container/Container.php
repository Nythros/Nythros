<?php

declare(strict_types=1);

namespace Nythros\Framework\Container;

use InvalidArgumentException;

/**
 * 轻量服务容器：实例表 + 延迟工厂表；工厂首次 get 时装配并缓存，未命中抛异常。
 * Lightweight service container: an instance table plus a lazy factory table; factories are
 * assembled and cached on the first get, and unknown ids throw.
 */
final class Container implements ContainerInterface
{
    /**
     * @var array<string, mixed> id => 实例 id => instance
     */
    private array $instances = [];

    /**
     * @var array<string, callable> id => 延迟工厂 id => lazy factory
     */
    private array $factories = [];

    public function get(string $id): mixed
    {
        if (isset($this->factories[$id])) {
            $this->instances[$id] = ($this->factories[$id])();
            unset($this->factories[$id]);
            return $this->instances[$id];
        }
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        throw new InvalidArgumentException(sprintf('容器中不存在服务: %s', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    public function set(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        unset($this->factories[$id]);
    }

    public function factory(string $id, callable $fn): void
    {
        $this->factories[$id] = $fn;
        unset($this->instances[$id]);
    }

    public function remove(string $id): void
    {
        unset($this->instances[$id], $this->factories[$id]);
    }
}
