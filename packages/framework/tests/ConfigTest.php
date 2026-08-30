<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * ConfigTest - 覆盖点号路径读取、默认值、存在性判断与 PHP 文件加载。
 * Tests covering dot-path reads, defaults, key existence and PHP file loading.
 */
final class ConfigTest extends TestCase
{
    public function testGetReadsDotPath(): void
    {
        $config = new Config(['a' => ['b' => ['c' => 42]]]);

        self::assertSame(42, $config->get('a.b.c'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $config = new Config(['a' => ['b' => 1]]);

        self::assertSame('fallback', $config->get('a.x', 'fallback'));
        self::assertNull($config->get('a.x'));
        self::assertSame('fallback', $config->get('x.y.z', 'fallback'));
    }

    public function testHasReflectsKeyExistenceIncludingNullValues(): void
    {
        $config = new Config(['a' => ['b' => null, 'c' => 1]]);

        self::assertTrue($config->has('a.b'), '值为 null 的键也必须视为存在。A key holding null must still count as existing.');
        self::assertTrue($config->has('a.c'));
        self::assertFalse($config->has('a.missing'));
        self::assertFalse($config->has('x.y'));
    }

    public function testAllReturnsEveryItem(): void
    {
        $items = ['server' => ['port' => 8080], 'log' => ['level' => 'info']];
        $config = new Config($items);

        self::assertSame($items, $config->all());
    }

    public function testFromPhpFileLoadsTheReturnedArray(): void
    {
        $path = $this->writeTempConfigFile('<?php return ["a" => ["b" => 7]];');
        try {
            $config = Config::fromPhpFile($path);

            self::assertSame(7, $config->get('a.b'));
            self::assertTrue($config->has('a'));
        } finally {
            @unlink($path);
        }
    }

    public function testFromPhpFileThrowsForMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::fromPhpFile('/nonexistent/path/to/config.php');
    }

    public function testFromPhpFileThrowsForNonArrayReturn(): void
    {
        $path = $this->writeTempConfigFile('<?php return 42;');
        try {
            $this->expectException(InvalidArgumentException::class);

            Config::fromPhpFile($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 写一个临时 PHP 配置文件。
     * Writes a temporary PHP config file.
     */
    private function writeTempConfigFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/nythros_config_test_' . uniqid() . '.php';
        file_put_contents($path, $contents);

        return $path;
    }
}
