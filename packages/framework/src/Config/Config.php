<?php

declare(strict_types=1);

namespace Nythros\Framework\Config;

use InvalidArgumentException;

/**
 * 应用级配置：PHP 数组文件加载（零 yaml 依赖）与点号路径读取。
 * Application-level config: PHP array file loading (zero yaml dependency) with dot-path reads.
 */
final class Config
{
    /**
     * @param array<string, mixed> $items 配置项集合 The configuration items.
     */
    public function __construct(private array $items)
    {
    }

    /**
     * 从 PHP 文件加载配置：文件须返回 array。
     * Loads configuration from a PHP file: the file must return an array.
     *
     * @param string $path 配置文件路径 The config file path.
     */
    public static function fromPhpFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('配置文件不存在: %s', $path));
        }
        $items = require $path;
        if (!is_array($items)) {
            throw new InvalidArgumentException(sprintf('配置文件必须返回 array: %s', $path));
        }
        return new self($items);
    }

    /**
     * 读取配置：支持点号路径（a.b.c），未命中返回默认值。
     * Reads a configuration value via dot path (a.b.c); returns the default when missing.
     *
     * @param string $key 点号路径键 Dot-path key.
     * @param mixed $default 未命中时的默认值 The default value when the key is missing.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * 键是否存在（含点为 null 的值）。
     * Whether the key exists (including values that are null).
     *
     * @param string $key 点号路径键 Dot-path key.
     */
    public function has(string $key): bool
    {
        // 以容器实例自身作哨兵：命中时 get 返回 null 也视为存在
        // The container instance itself acts as a sentinel: a hit that resolves to null still counts as existing.
        return $this->get($key, $this) !== $this;
    }

    /**
     * 全部配置项。
     * All configuration items.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
