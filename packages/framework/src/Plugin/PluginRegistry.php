<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin;

use InvalidArgumentException;
use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;

/**
 * 插件注册表：按唯一名管理插件生命周期（load/enable/disable/uninstall），并支持按名查询。
 * Plugin registry: manages the plugin lifecycle (load/enable/disable/uninstall) by unique name, with name-based lookup.
 *
 * 生命周期 load(register) → enable → (运行) → disable / uninstall。
 * 加载与启用分离，支持「先装配全部插件，再统一启用」；uninstall 具备完整运行时卸载语义
 * （清理 Container 注册项 + 退订 EventDispatcher 事件，见 §2.5 闭包引用约定）。
 * 条件装配（FeatureFlags）：实现 FeaturePluginInterface 的插件按能力开关决定 load 是否生效——
 * 关闭时跳过（返回 false、记入跳过名单、零 Container/dispatcher 足迹），未实现接口的插件不受约束。
 * Lifecycle: load(register) → enable → (runtime) → disable/uninstall. Loading and enabling are
 * separate so all plugins can be assembled first then enabled together; uninstall carries full
 * runtime removal semantics (clearing Container registrations + unsubscribing EventDispatcher events).
 * Conditional assembly (FeatureFlags): plugins implementing FeaturePluginInterface gate their load on the
 * feature flag — an off feature is skipped (false returned, recorded in the skip list, zero Container/
 * dispatcher footprint); plugins without the interface stay unconstrained.
 */
final class PluginRegistry
{
    /**
     * @var array<string, PluginInterface> name => 插件 name => plugin
     */
    private array $plugins = [];

    /**
     * @var array<string, true> 因能力开关关闭被跳过的插件名（观测面，供启动日志/诊断）
     *                            Plugin names skipped by a disabled feature flag (an observation seam)
     */
    private array $skipped = [];

    /** 能力开关表（null = 未注入，首次遇到自声明插件时从环境变量惰性构建） Flag table (null = lazily built from the environment on the first self-declaring plugin) */
    private ?FeatureFlags $featureFlags;

    public function __construct(?FeatureFlags $featureFlags = null)
    {
        $this->featureFlags = $featureFlags;
    }

    /**
     * 注入能力开关表（fork 后/装配期覆盖惰性默认，幂等）。
     * Inject the flag table (overrides the lazy environment default; assembly-time).
     */
    public function setFeatureFlags(FeatureFlags $flags): void
    {
        $this->featureFlags = $flags;
    }

    /**
     * 加载插件：调用 $plugin->register 装配后登记进注册表；同名插件重复加载抛异常。
     * 自声明能力（FeaturePluginInterface）被关闭时跳过装配并返回 false。
     * Loads a plugin: invokes $plugin->register for assembly, then registers it; loading a plugin with a
     * duplicate name throws. A plugin whose self-declared feature is disabled is skipped (returns false).
     *
     * @param PluginInterface $plugin 插件 The plugin.
     * @param ContainerInterface $container 服务容器 The service container.
     * @param EventDispatcherInterface $dispatcher 应用级事件派发器 The application-level event dispatcher.
     * @return bool true = 已装配；false = 能力开关关闭被跳过 true = assembled; false = skipped by a disabled flag
     */
    public function load(PluginInterface $plugin, ContainerInterface $container, EventDispatcherInterface $dispatcher): bool
    {
        $name = $plugin->name();
        if (isset($this->plugins[$name])) {
            throw new InvalidArgumentException(sprintf('插件已加载: %s', $name));
        }

        if ($plugin instanceof FeaturePluginInterface) {
            $flags = $this->featureFlags ??= FeatureFlags::fromEnvironment();
            $feature = $plugin->featureName();
            if (!$flags->isEnabled($feature)) {
                $this->skipped[$name] = true;
                error_log(sprintf('[PluginRegistry] 能力 "%s" 已关闭,插件 %s 跳过装配（feature off, plugin skipped）', $feature, $name));

                return false;
            }
        }

        $plugin->register($container, $dispatcher);
        $this->plugins[$name] = $plugin;
        // 成功装载即从跳过名单移除（名单语义=「当前处于跳过态」,翻转开关重装的场景不留陈旧记录）
        // A successful load clears any stale skip entry (the list means "currently skipped")
        unset($this->skipped[$name]);

        return true;
    }

    /**
     * 启用已加载插件。
     * Enables a loaded plugin.
     *
     * @param string $name 插件名 Plugin name.
     */
    public function enable(string $name): void
    {
        $this->requirePlugin($name)->enable();
    }

    /**
     * 停用已加载插件（保留注册）。
     * Disables a loaded plugin (registration is kept).
     *
     * @param string $name 插件名 Plugin name.
     */
    public function disable(string $name): void
    {
        $this->requirePlugin($name)->disable();
    }

    /**
     * 卸载已加载插件：调 $plugin->uninstall 清理注册与订阅后从注册表摘除。
     * Uninstalls a loaded plugin: invokes $plugin->uninstall to clear registrations and subscriptions,
     * then removes it from the registry.
     *
     * @param string $name 插件名 Plugin name.
     * @param ContainerInterface $container 服务容器 The service container.
     * @param EventDispatcherInterface $dispatcher 应用级事件派发器 The application-level event dispatcher.
     */
    public function uninstall(string $name, ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $plugin = $this->requirePlugin($name);
        $plugin->uninstall($container, $dispatcher);
        unset($this->plugins[$name]);
    }

    /**
     * 按名查询插件；未加载返回 null（被能力开关跳过的插件同样返回 null，用 skipped() 区分）。
     * Looks up a plugin by name; null when unregistered (flag-skipped plugins also yield null — see skipped()).
     *
     * @param string $name 插件名 Plugin name.
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * 因能力开关关闭被跳过的插件名名单（观测面：启动日志/诊断/make:game 能力报告消费）。
     * Plugin names skipped by disabled feature flags (the observation seam for boot logs, diagnostics and the
     * make:game capability report).
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return array_keys($this->skipped);
    }

    /**
     * 返回全部已加载插件（name => plugin）。
     * Returns all loaded plugins (name => plugin).
     *
     * @return array<string, PluginInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * 解析插件，未加载抛异常。
     * Resolves a plugin, throwing when not loaded.
     *
     * @param string $name 插件名 Plugin name.
     */
    private function requirePlugin(string $name): PluginInterface
    {
        if (!isset($this->plugins[$name])) {
            throw new InvalidArgumentException(sprintf('插件未加载: %s', $name));
        }

        return $this->plugins[$name];
    }
}
