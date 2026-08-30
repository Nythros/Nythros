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
 * load 委托 $plugin->register 装配；enable/disable 仅转发；uninstall 先调插件自身卸载逻辑，
 * 再从注册表摘除。未加载的插件名在 enable/disable/uninstall 时显式抛异常（与 Container 未命中语义一致）。
 * load delegates to $plugin->register for assembly; enable/disable merely forward; uninstall invokes the
 * plugin's own cleanup then removes it from the registry. Unknown names passed to enable/disable/uninstall
 * throw explicitly (consistent with Container's unknown-id semantics).
 */
final class PluginRegistry
{
    /**
     * @var array<string, PluginInterface> name => 插件 name => plugin
     */
    private array $plugins = [];

    /**
     * 加载插件：调用 $plugin->register 装配后登记进注册表；同名插件重复加载抛异常。
     * Loads a plugin: invokes $plugin->register for assembly, then registers it; loading a plugin with a
     * duplicate name throws.
     *
     * @param PluginInterface $plugin 插件 The plugin.
     * @param ContainerInterface $container 服务容器 The service container.
     * @param EventDispatcherInterface $dispatcher 应用级事件派发器 The application-level event dispatcher.
     */
    public function load(PluginInterface $plugin, ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $name = $plugin->name();
        if (isset($this->plugins[$name])) {
            throw new InvalidArgumentException(sprintf('插件已加载: %s', $name));
        }
        $plugin->register($container, $dispatcher);
        $this->plugins[$name] = $plugin;
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
     * 按名查询插件；未加载返回 null。
     * Looks up a plugin by name; returns null when not loaded.
     *
     * @param string $name 插件名 Plugin name.
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
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
