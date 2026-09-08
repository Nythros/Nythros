<?php

declare(strict_types=1);

namespace Nythros\Framework\Game\Horde;

use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\FeaturePluginInterface;
use Nythros\Framework\Plugin\PluginInterface;

/**
 * Horde 插件（R4 类型模块试点，ADR-020 §4「命名空间 + PluginRegistry 插件形态」）：
 * 向 Container 注册 horde 配置（'horde.config'，缺省 HordeConfig::default()），供
 * 组装层解析后注入 RoomHub/MapServer——玩法参数归 framework，装配归组装层。
 * 能力自声明（FeaturePluginInterface，name 'horde'）：可被 NYTHROS_FEATURES/NYTHROS_FEATURE_HORDE 关闭。
 * The horde plugin (the R4 type-module pilot, ADR-020 §4's "namespace + PluginRegistry plugin form"):
 * registers the horde config ('horde.config', defaulting to HordeConfig::default()) into the Container for the
 * assembly layer to resolve into RoomHub/MapServer — gameplay parameters live in the framework, assembly in the
 * assembly layer. Self-declares feature 'horde' (disable-able via NYTHROS_FEATURES/NYTHROS_FEATURE_HORDE).
 */
final class HordePlugin implements PluginInterface, FeaturePluginInterface
{
    public const CONFIG_ID = 'horde.config';

    private ?HordeConfig $config = null;

    public function __construct(
        ?HordeConfig $config = null,
    ) {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'horde';
    }

    public function featureName(): string
    {
        return 'horde';
    }

    /**
     * 加载：向 Container 注册 horde 配置（幂等；构造期未显式给定时注册缺省配置）。
     * Load: registers the horde config into the Container (idempotent; the default config is registered when none was given at construction).
     */
    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->config ??= HordeConfig::default();
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
