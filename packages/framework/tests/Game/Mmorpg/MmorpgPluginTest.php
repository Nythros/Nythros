<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Mmorpg;

use Nythros\Framework\Container\Container;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Game\Mmorpg\MmorpgPlugin;
use Nythros\Framework\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * MmorpgPluginTest - mmorpg 插件注册测试（R4 试点，ADR-020 §4 插件形态）：load/enable 生命周期把
 * 配置注册进 Container（缺省 default()、构造期可注入自定义配置）、幂等 register、uninstall 清理注册项。
 * MmorpgPluginTest - the mmorpg plugin registration tests (the R4 pilot, ADR-020 §4's plugin form): the
 * load/enable lifecycle registers the config into the Container (default() by default, a custom config injectable
 * at construction), idempotent register and uninstall clearing the registration.
 */
final class MmorpgPluginTest extends TestCase
{
    public function testLoadRegistersDefaultConfigIntoContainer(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();

        $registry->load(new MmorpgPlugin(), $container, new EventDispatcher());
        $registry->enable('mmorpg');

        self::assertNotNull($registry->get('mmorpg'), '插件按名可查 the plugin is lookable by name');
        self::assertTrue($container->has(MmorpgPlugin::CONFIG_ID));
        $config = $container->get(MmorpgPlugin::CONFIG_ID);
        self::assertInstanceOf(MmorpgConfig::class, $config);
        self::assertEquals(MmorpgConfig::default(), $config, '未显式给定时注册缺省配置 the default config is registered when none is given');
    }

    public function testLoadRegistersInjectedCustomConfig(): void
    {
        $container = new Container();
        $custom = new MmorpgConfig(aggroRange: 20, respawnMs: 3000);

        $registry = new PluginRegistry();
        $registry->load(new MmorpgPlugin($custom), $container, new EventDispatcher());

        self::assertSame($custom, $container->get(MmorpgPlugin::CONFIG_ID), '构造期注入的自定义配置原样注册 the injected custom config registers as-is');
    }

    public function testRepeatedRegisterIsIdempotent(): void
    {
        $container = new Container();
        $plugin = new MmorpgPlugin();
        $dispatcher = new EventDispatcher();
        $plugin->register($container, $dispatcher);
        $plugin->register($container, $dispatcher);

        self::assertTrue($container->has(MmorpgPlugin::CONFIG_ID), '重复 register 不抛错不破坏注册 repeated register neither throws nor corrupts the registration');
    }

    public function testUninstallRemovesConfigRegistration(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();
        $dispatcher = new EventDispatcher();
        $registry->load(new MmorpgPlugin(), $container, $dispatcher);

        $registry->uninstall('mmorpg', $container, $dispatcher);

        self::assertNull($registry->get('mmorpg'), '卸载后插件从注册表摘除 the plugin leaves the registry after uninstall');
        self::assertFalse($container->has(MmorpgPlugin::CONFIG_ID), '卸载清理 Container 注册项 uninstall clears the Container registration');
    }

    public function testDuplicateLoadThrows(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();
        $dispatcher = new EventDispatcher();
        $registry->load(new MmorpgPlugin(), $container, $dispatcher);

        $this->expectException(\InvalidArgumentException::class);
        $registry->load(new MmorpgPlugin(), $container, $dispatcher);
    }
}
