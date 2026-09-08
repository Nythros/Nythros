<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Capability;

use Nythros\Framework\Capability\CapabilityCatalog;
use Nythros\Framework\Game\Horde\HordePlugin;
use Nythros\Framework\Game\Mmorpg\MmorpgPlugin;
use Nythros\Framework\Make\MakeCapabilities;
use Nythros\Framework\Plugin\FeaturePluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * 能力目录一致性测试（单一事实源的 CI 锁）：
 * ①目录 entry 类全部可加载(拼写/重构漂移即红);②实现 FeaturePluginInterface 的插件其
 * featureName 必须在目录登记(开关体系与目录不漂移);③报告输出稳定(文本含全能力、JSON 可解析)。
 * Catalog consistency tests (the CI lock on the single source of truth): every entry class resolves, every
 * FeaturePluginInterface feature name is registered in the catalog, and the report renders stably (text lists
 * all capabilities; JSON parses).
 */
final class CapabilityCatalogTest extends TestCase
{
    public function testEveryEntryClassResolves(): void
    {
        foreach (CapabilityCatalog::all() as $capability => $spec) {
            if ($spec['entry'] === null) {
                continue;
            }
            self::assertTrue(
                class_exists($spec['entry']),
                sprintf('能力 %s 的 entry 类无法加载: %s', $capability, $spec['entry']),
            );
        }
    }

    public function testSelfDeclaringPluginsAreRegisteredInCatalog(): void
    {
        // 框架内所有实现 FeaturePluginInterface 的插件,其能力名必须出现在目录——否则开关判定与报告漂移
        // Every framework plugin self-declaring via FeaturePluginInterface must appear in the catalog, or the
        // flag decisions and the report drift apart
        $catalog = array_keys(CapabilityCatalog::all());
        foreach ([new MmorpgPlugin(), new HordePlugin()] as $plugin) {
            self::assertInstanceOf(FeaturePluginInterface::class, $plugin);
            self::assertContains($plugin->featureName(), $catalog, sprintf('插件能力 %s 未登记进 CapabilityCatalog', $plugin->featureName()));
        }
    }

    public function testReportRendersAllCapabilitiesInBothFormats(): void
    {
        ob_start();
        $code = (new MakeCapabilities())->run([]);
        $text = (string) ob_get_clean();

        self::assertSame(0, $code);
        foreach (array_keys(CapabilityCatalog::all()) as $capability) {
            self::assertStringContainsString($capability, $text, '文本报告必须含每一项能力');
        }

        ob_start();
        $code = (new MakeCapabilities())->run(['--format=json']);
        $json = (string) ob_get_clean();
        self::assertSame(0, $code);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(count(CapabilityCatalog::all()), $decoded);
        self::assertArrayHasKey('flag', $decoded[0]);
    }

    public function testReportHonoursWhitelist(): void
    {
        $before = getenv('NYTHROS_FEATURES');
        putenv('NYTHROS_FEATURES=combat,quest');
        try {
            ob_start();
            (new MakeCapabilities())->run(['--format=json']);
            $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            $byCapability = array_column($decoded, null, 'capability');
            self::assertSame('on', $byCapability['quest']['flag']);
            self::assertSame('off', $byCapability['mmorpg']['flag'], '白名单外能力在报告中显示为 off');
        } finally {
            if ($before === false) {
                putenv('NYTHROS_FEATURES');
            } else {
                putenv('NYTHROS_FEATURES=' . $before);
            }
        }
    }
}
