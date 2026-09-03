<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Mmorpg;

use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;

/**
 * Mmorpg 插件（R4 类型模块试点，ADR-020 §4「命名空间 + PluginRegistry 插件形态」）：
 * 向 Container 注册 mmorpg 配置（'mmorpg.config'，缺省 MmorpgConfig::default()），供
 * 组装层解析后注入 MapServer——玩法参数归 framework，装配归组装层。
 * The mmorpg plugin (the R4 type-module pilot, ADR-020 §4's "namespace + PluginRegistry plugin form"):
 * registers the mmorpg config ('mmorpg.config', defaulting to MmorpgConfig::default()) into the Container for the
 * the assembly layer to resolve into MapServer — gameplay parameters live in the framework,
 * assembly in the assembly layer.
 */
final class MmorpgPlugin implements PluginInterface
{
    public const CONFIG_ID = 'mmorpg.config';

    private ?MmorpgConfig $config = null;

    public function __construct(
        ?MmorpgConfig $config = null,
    ) {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'mmorpg';
    }

    /**
     * 加载：向 Container 注册 mmorpg 配置（幂等；构造期未显式给定时注册缺省配置）。
     * Load: registers the mmorpg config into the Container (idempotent; the default config is registered when none was given at construction).
     */
    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->config ??= MmorpgConfig::default();
        $container->set(self::CONFIG_ID, $this->config);
    }

    public function enable(): void
    {
        // 激活运行时行为（配置型插件无独立运行态，占位）。
        // Activates runtime behavior (a config-only plugin has no standalone runtime state; placeholder).
    }

    public function disable(): void
    {
        // 暂停运行时行为（配置型插件无独立运行态，占位）。
        // Pauses runtime behavior (a config-only plugin has no standalone runtime state; placeholder).
    }

    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $container->remove(self::CONFIG_ID);
        $this->config = null;
    }
}
