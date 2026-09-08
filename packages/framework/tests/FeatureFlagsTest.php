<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Container\Container;
use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Game\Mmorpg\MmorpgConfig;
use Nythros\Framework\Game\Mmorpg\MmorpgPlugin;
use Nythros\Framework\Plugin\FeatureFlags;
use Nythros\Framework\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * FeatureFlags + PluginRegistry 条件装配测试（能力清单地基，路线图②）：
 * 开关判定三级优先（覆盖 > 白名单 > 默认开）、自声明插件被关闭时 load 跳过（零 Container 足迹、
 * 记入跳过名单、可重复装载不冲突）、未实现接口的既有插件不受开关体系约束。
 * Tests for FeatureFlags + the registry's conditional assembly (the capability-manifest foundation):
 * the three-tier precedence (override > whitelist > default-on), a self-declaring plugin skipped when off
 * (zero Container footprint, recorded in the skip list, loadable again by name), and legacy plugins without
 * the interface staying unconstrained.
 */
final class FeatureFlagsTest extends TestCase
{
    public function testPrecedenceTiers(): void
    {
        // 缺省全开（无白名单无覆盖）
        // Default: everything on (no whitelist, no override)
        $open = new FeatureFlags(null);
        self::assertTrue($open->isEnabled('mmorpg'));

        // 白名单：列出即开、未列即关（显式空白名单 = 全关）
        // Whitelist: listed on, unlisted off (an explicit empty whitelist means all off)
        $wl = new FeatureFlags(['quest', 'mail']);
        self::assertTrue($wl->isEnabled('quest'));
        self::assertFalse($wl->isEnabled('mmorpg'));
        self::assertFalse((new FeatureFlags([]))->isEnabled('anything'));

        // 覆盖压过白名单与缺省（双向）
        // Overrides beat both the whitelist and the default (both directions)
        $overridden = new FeatureFlags(['quest'], ['mail' => true, 'quest' => false]);
        self::assertTrue($overridden->isEnabled('mail'), '覆盖可反向开启未列入白名单的能力');
        self::assertFalse($overridden->isEnabled('quest'), '覆盖可在白名单内反向关闭');
    }

    public function testFromEnvironmentParsesWhitelistAndOverrideKeys(): void
    {
        $before = getenv(FeatureFlags::ENV_WHITELIST);
        putenv(FeatureFlags::ENV_WHITELIST . '=quest,mmorpg');
        $_ENV[FeatureFlags::ENV_OVERRIDE_PREFIX . 'MAIL'] = '1';
        try {
            $flags = FeatureFlags::fromEnvironment();
            self::assertTrue($flags->isEnabled('quest'));
            self::assertTrue($flags->isEnabled('mmorpg'));
            self::assertFalse($flags->isEnabled('skill'), '白名单未列即关');
            self::assertTrue($flags->isEnabled('mail'), '覆盖键压过白名单');
        } finally {
            if ($before === false) {
                putenv(FeatureFlags::ENV_WHITELIST);
            } else {
                putenv(FeatureFlags::ENV_WHITELIST . '=' . $before);
            }
            unset($_ENV[FeatureFlags::ENV_OVERRIDE_PREFIX . 'MAIL']);
        }
    }

    public function testRegistrySkipsDisabledFeaturePluginWithoutFootprint(): void
    {
        $container = new Container();
        $registry = new PluginRegistry(new FeatureFlags([], ['mmorpg' => true]));
        // 空白名单 = 全关,但显式覆盖 mmorpg=开 → 真实路径先验证「开」
        // Empty whitelist = all off, but mmorpg is explicitly overridden on → the "on" path first
        self::assertTrue($registry->load(new MmorpgPlugin(config: MmorpgConfig::default()), $container, new EventDispatcher()));
        self::assertNotNull($container->get(MmorpgPlugin::CONFIG_ID));

        // 关闭态:load 返回 false、插件不入册、Container 零足迹、跳过名单可见、enable 前必须判返回值
        // The off path: false returned, nothing registered, zero Container footprint, skip list visible
        $offRegistry = new PluginRegistry(new FeatureFlags(null, ['mmorpg' => false]));
        $offContainer = new Container();

        self::assertFalse($offRegistry->load(new MmorpgPlugin(), $offContainer, new EventDispatcher()));
        self::assertNull($offRegistry->get('mmorpg'));
        self::assertSame(['mmorpg'], $offRegistry->skipped(), '跳过名单可观测(能力报告消费)');
        self::assertFalse($offContainer->has(MmorpgPlugin::CONFIG_ID), '被关闭的插件绝不进 Container');

        // 被跳过的名字不占注册表:开关翻转后可正常装载,跳过名单同步清空(名单=当前跳过态)
        // A skipped name doesn't squat the registry: it loads fine once the flag flips, and the skip list clears
        $offRegistry->setFeatureFlags(new FeatureFlags(['mmorpg']));
        self::assertTrue($offRegistry->load(new MmorpgPlugin(), $offContainer, new EventDispatcher()));
        self::assertSame([], $offRegistry->skipped(), '成功装载清除跳过记录');
    }

    public function testLegacyPluginWithoutInterfaceUnaffectedByWhitelist(): void
    {
        // 未实现 FeaturePluginInterface 的既有/第三方插件:白名单再窄也照常装载（零破坏承诺）
        // Plugins without the interface load regardless of the whitelist (the zero-breakage promise)
        $registry = new PluginRegistry(new FeatureFlags([]));
        $plugin = new \Nythros\Framework\Plugin\Skill\SkillPlugin();

        self::assertTrue($registry->load($plugin, new Container(), new EventDispatcher()));
        self::assertSame($plugin, $registry->get('skill'));
    }
}
