<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin;

use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;

/**
 * 插件契约：定义加载/启用/停用/卸载四态生命周期，由 PluginRegistry 驱动。
 * Plugin contract: defines the four-state lifecycle (load/enable/disable/uninstall), driven by PluginRegistry.
 *
 * 生命周期 load(register) → enable → (运行) → disable / uninstall。
 * 加载与启用分离，支持「先装配全部插件，再统一启用」；uninstall 具备完整运行时卸载语义
 * （清理 Container 注册项 + 退订 EventDispatcher 事件，见 §2.5 闭包引用约定）。
 * Lifecycle: load(register) → enable → (runtime) → disable / uninstall. Loading and enabling are
 * separate so all plugins can be assembled first then enabled together; uninstall carries full
 * runtime removal semantics (clearing Container registrations + unsubscribing EventDispatcher events).
 */
interface PluginInterface
{
    /**
     * 插件唯一名，如 'skill' / 'item' / 'buff'。
     * Unique plugin name, e.g. 'skill' / 'item' / 'buff'.
     */
    public function name(): string;

    /**
     * 加载：向 Container 注册本插件能力（仓库/服务）并订阅事件；幂等，可重复调用。
     * Load: registers the plugin's capabilities (repositories/services) into the Container and
     * subscribes events; idempotent, safe to call repeatedly.
     *
     * @param ContainerInterface $container 服务容器 The service container.
     * @param EventDispatcherInterface $dispatcher 应用级事件派发器 The application-level event dispatcher.
     */
    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void;

    /**
     * 启用：激活运行时行为。
     * Enable: activates runtime behavior.
     */
    public function enable(): void;

    /**
     * 停用：暂停运行时行为（保留注册）。
     * Disable: pauses runtime behavior (registration is kept).
     */
    public function disable(): void;

    /**
     * 卸载：清理注册与订阅、回收资源，具备完整运行时卸载语义。
     * Uninstall: clears registrations and subscriptions and reclaims resources, with full runtime removal semantics.
     *
     * @param ContainerInterface $container 服务容器 The service container.
     * @param EventDispatcherInterface $dispatcher 应用级事件派发器 The application-level event dispatcher.
     */
    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void;
}
