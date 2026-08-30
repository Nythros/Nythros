<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Game\Horde;

use Nythros\Framework\Container\Container;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Horde\HordeConfig;
use Nythros\Framework\Game\Horde\HordePlugin;
use Nythros\Framework\Game\Horde\WaveDefinition;
use Nythros\Framework\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * HordePluginTest - horde 插件注册测试（R4 试点，ADR-020 §4 插件形态）：load/enable 生命周期把
 * 配置注册进 Container（缺省 default()、构造期可注入自定义配置）、幂等 register、uninstall 清理注册项。
 * HordePluginTest - the horde plugin registration tests (the R4 pilot, ADR-020 §4's plugin form): the
 * load/enable lifecycle registers the config into the Container (default() by default, a custom config injectable
 * at construction), idempotent register and uninstall clearing the registration.
 */
final class HordePluginTest extends TestCase
{
    public function testLoadRegistersDefaultConfigIntoContainer(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();

        $registry->load(new HordePlugin(), $container, new EventDispatcher());
        $registry->enable('horde');

        self::assertNotNull($registry->get('horde'), '插件按名可查 the plugin is lookable by name');
        self::assertTrue($container->has(HordePlugin::CONFIG_ID));
        $config = $container->get(HordePlugin::CONFIG_ID);
        self::assertInstanceOf(HordeConfig::class, $config);
        self::assertEquals(HordeConfig::default(), $config, '未显式给定时注册缺省配置 the default config is registered when none is given');
    }

    public function testLoadRegistersInjectedCustomConfig(): void
    {
        $container = new Container();
        // 空 waves 自 MINOR-3 起构造期非法：自定义配置改用合法单波（测试意图不变——注入原样注册）
        // Empty waves are illegal at construction since MINOR-3: the custom config uses a legitimate single wave instead (test intent unchanged — as-is injection)
        $custom = new HordeConfig(
            waves: [new WaveDefinition(count: 10, monsterMaxHp: 20, gridStartX: 0, gridStartY: 0, columns: 5, step: 2)],
            periodMs: 30,
        );

        $registry = new PluginRegistry();
        $registry->load(new HordePlugin($custom), $container, new EventDispatcher());

        self::assertSame($custom, $container->get(HordePlugin::CONFIG_ID), '构造期注入的自定义配置原样注册 the injected custom config registers as-is');
    }

    public function testRepeatedRegisterIsIdempotent(): void
    {
        $container = new Container();
        $plugin = new HordePlugin();
        $dispatcher = new EventDispatcher();
        $plugin->register($container, $dispatcher);
        $plugin->register($container, $dispatcher);

        self::assertTrue($container->has(HordePlugin::CONFIG_ID), '重复 register 不抛错不破坏注册 repeated register neither throws nor corrupts the registration');
    }

    public function testUninstallRemovesConfigRegistration(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();
        $dispatcher = new EventDispatcher();
        $registry->load(new HordePlugin(), $container, $dispatcher);

        $registry->uninstall('horde', $container, $dispatcher);

        self::assertNull($registry->get('horde'), '卸载后插件从注册表摘除 the plugin leaves the registry after uninstall');
        self::assertFalse($container->has(HordePlugin::CONFIG_ID), '卸载清理 Container 注册项 uninstall clears the Container registration');
    }

    public function testDuplicateLoadThrows(): void
    {
        $container = new Container();
        $registry = new PluginRegistry();
        $dispatcher = new EventDispatcher();
        $registry->load(new HordePlugin(), $container, $dispatcher);

        $this->expectException(\InvalidArgumentException::class);
        $registry->load(new HordePlugin(), $container, $dispatcher);
    }
}
