<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Container\Container;
use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\Buff\BuffPlugin;
use Nythros\Framework\Plugin\Item\ItemPlugin;
use Nythros\Framework\Plugin\PluginInterface;
use Nythros\Framework\Plugin\PluginRegistry;
use Nythros\Framework\Plugin\Skill\SkillPlugin;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use PHPUnit\Framework\TestCase;

/**
 * PluginRegistryTest - 覆盖 load/get/all、仓库注册进 Container、enable/disable/uninstall 转发与未加载报错。
 * Tests covering load/get/all, repository registration into the Container, enable/disable/uninstall
 * forwarding and unknown-name errors.
 */
final class PluginRegistryTest extends TestCase
{
    public function testLoadRegistersPluginAndGetReturnsIt(): void
    {
        $registry = new PluginRegistry();
        $plugin = new SkillPlugin();

        $registry->load($plugin, new Container(), new EventDispatcher());

        self::assertSame($plugin, $registry->get('skill'));
    }

    public function testLoadRegistersPluginRepositoryIntoContainer(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();

        $registry->load(new SkillPlugin(), $container, new EventDispatcher());

        self::assertTrue($container->has('skill.repository'), 'load 必须把 SkillRepository 注册进 Container。');
        self::assertInstanceOf(SkillRepository::class, $container->get('skill.repository'));
    }

    public function testGetUnknownReturnsNull(): void
    {
        $registry = new PluginRegistry();

        self::assertNull($registry->get('unknown'));
    }

    public function testAllReturnsLoadedPlugins(): void
    {
        $registry = new PluginRegistry();
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $skill = new SkillPlugin();
        $item = new ItemPlugin();
        $buff = new BuffPlugin();

        $registry->load($skill, $container, $dispatcher);
        $registry->load($item, $container, $dispatcher);
        $registry->load($buff, $container, $dispatcher);

        self::assertSame(['skill' => $skill, 'item' => $item, 'buff' => $buff], $registry->all());
    }

    public function testLoadDuplicateNameThrows(): void
    {
        $registry = new PluginRegistry();
        $container = new Container();

        $registry->load(new SkillPlugin(), $container, new EventDispatcher());

        $this->expectException(InvalidArgumentException::class);

        $registry->load(new SkillPlugin(), $container, new EventDispatcher());
    }

    public function testEnableDisableUninstallForwardToPlugin(): void
    {
        $registry = new PluginRegistry();
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $plugin = new class () implements PluginInterface {
            public int $enableCalls = 0;
            public int $disableCalls = 0;
            public int $uninstallCalls = 0;

            public function name(): string
            {
                return 'stateful';
            }

            public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
            {
            }

            public function enable(): void
            {
                $this->enableCalls++;
            }

            public function disable(): void
            {
                $this->disableCalls++;
            }

            public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
            {
                $this->uninstallCalls++;
            }
        };

        $registry->load($plugin, $container, $dispatcher);
        $registry->enable('stateful');
        $registry->disable('stateful');

        self::assertSame(1, $plugin->enableCalls, 'enable 必须转发到插件。enable must be forwarded to the plugin.');
        self::assertSame(1, $plugin->disableCalls, 'disable 必须转发到插件。disable must be forwarded to the plugin.');
        self::assertSame($plugin, $registry->get('stateful'), 'disable 后插件保留注册。The plugin stays registered after disable.');

        $registry->uninstall('stateful', $container, $dispatcher);

        self::assertSame(1, $plugin->uninstallCalls, 'uninstall 必须转发到插件。uninstall must be forwarded to the plugin.');
        self::assertNull($registry->get('stateful'), 'uninstall 后插件从注册表摘除。The plugin is removed from the registry after uninstall.');
    }

    public function testUninstallRemovesRepositoryAndPlugin(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $registry = new PluginRegistry();

        $registry->load(new ItemPlugin(), $container, $dispatcher);
        self::assertTrue($container->has('item.repository'));

        $registry->uninstall('item', $container, $dispatcher);

        self::assertFalse($container->has('item.repository'), 'uninstall 必须清理 Container 注册项。uninstall must clear Container registrations.');
        self::assertNull($registry->get('item'), 'uninstall 后插件从注册表摘除。The plugin is removed from the registry after uninstall.');
    }

    public function testEnableUnknownThrows(): void
    {
        $registry = new PluginRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->enable('unknown');
    }

    public function testDisableUnknownThrows(): void
    {
        $registry = new PluginRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->disable('unknown');
    }

    public function testUninstallUnknownThrows(): void
    {
        $registry = new PluginRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->uninstall('unknown', new Container(), new EventDispatcher());
    }
}
