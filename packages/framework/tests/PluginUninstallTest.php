<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Container\Container;
use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;
use Nythros\Framework\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * PluginUninstallTest - 验证「卸载退订闭包引用约定」：register 与 uninstall 必须持有同一闭包句柄。
 * Tests verifying the "uninstall unsubscribe closure reference convention": register and uninstall
 * must hold the same closure handle.
 *
 * 通过可观测的 SubscriberPlugin 夹具证明：
 * register 后 dispatch 触发监听器 → uninstall 用同一引用退订 → 再 dispatch 不再触发；
 * 而新写的闭包字面量是不同实例，removeListener 按引用精确匹配无法命中。
 * The observable SubscriberPlugin fixture proves: dispatch fires after register; uninstall
 * unsubscribes via the same reference so dispatch no longer fires; a fresh closure literal is a
 * different instance and cannot be matched by removeListener's exact-reference matching.
 */
final class PluginUninstallTest extends TestCase
{
    public function testUninstallUnsubscribesBySameClosureReference(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $registry = new PluginRegistry();
        $plugin = new SubscriberPlugin('skill.cast', 'demo.service');

        $registry->load($plugin, $container, $dispatcher);
        $dispatcher->dispatch('skill.cast');
        self::assertSame(1, $plugin->listenerCalls(), 'register 后监听器生效。The listener is active after register.');

        $registry->uninstall('subscriber', $container, $dispatcher);
        $dispatcher->dispatch('skill.cast');

        self::assertSame(1, $plugin->listenerCalls(), 'uninstall 必须以同一闭包引用退订，dispatch 不再触发。uninstall must unsubscribe via the same closure reference so dispatch no longer fires.');
    }

    public function testRemoveListenerRequiresSameClosureReference(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $registry = new PluginRegistry();
        $plugin = new SubscriberPlugin('buff.applied', 'demo.service');

        $registry->load($plugin, $container, $dispatcher);
        $dispatcher->dispatch('buff.applied');
        self::assertSame(1, $plugin->listenerCalls(), 'register 后监听器生效。The listener is active after register.');

        // 新闭包字面量是新实例，removeListener 按引用精确匹配无法命中既有监听器。
        // A fresh closure literal is a new instance, so removeListener's exact-reference matching cannot hit the registered listener.
        $dispatcher->removeListener('buff.applied', static function (array $payload): void {
        });

        $dispatcher->dispatch('buff.applied');
        self::assertSame(2, $plugin->listenerCalls(), '新闭包字面量不是同一引用，无法退订既有监听器。A fresh closure literal is not the same reference and cannot unsubscribe the registered listener.');

        // uninstall 使用 register 保存的同一句柄退订，之后不再触发。
        // uninstall uses the handle saved by register, so the listener no longer fires afterwards.
        $registry->uninstall('subscriber', $container, $dispatcher);
        $dispatcher->dispatch('buff.applied');

        self::assertSame(2, $plugin->listenerCalls(), 'uninstall 必须以同一闭包引用退订，dispatch 不再触发。uninstall must unsubscribe via the same closure reference so dispatch no longer fires.');
    }

    public function testRepeatedRegisterSubscribesOnlyOnce(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $plugin = new SubscriberPlugin('skill.cast', 'demo.service');

        $plugin->register($container, $dispatcher);
        $plugin->register($container, $dispatcher);

        $dispatcher->dispatch('skill.cast');

        self::assertSame(1, $plugin->listenerCalls(), '重复 register 不得重复订阅同一监听器。Repeated register must not double-subscribe the listener.');
    }
}

/**
 * 可观测夹具插件：register 订阅事件并计数，uninstall 以同一句柄退订。
 * Observable fixture plugin: subscribes on register with a counter and unsubscribes via the same handle on uninstall.
 */
final class SubscriberPlugin implements PluginInterface
{
    private int $calls = 0;
    private bool $subscribed = false;

    /**
     * @var (callable(array<string, mixed>): void)|null
     */
    private $listener = null;

    public function __construct(
        private readonly string $event,
        private readonly string $serviceId,
    ) {
    }

    public function name(): string
    {
        return 'subscriber';
    }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $container->set($this->serviceId, $this);
        if ($this->subscribed) {
            return;
        }
        $this->listener = function (array $payload): void {
            $this->calls++;
        };
        $dispatcher->listen($this->event, $this->listener);
        $this->subscribed = true;
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        if ($this->subscribed && $this->listener !== null) {
            $dispatcher->removeListener($this->event, $this->listener);
        }
        $this->subscribed = false;
        $this->listener = null;
        $container->remove($this->serviceId);
    }

    public function listenerCalls(): int
    {
        return $this->calls;
    }
}
