<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Config\ConfigSourceLines;
use PHPUnit\Framework\TestCase;

/**
 * ConfigSourceLinesTest - 覆盖 PHP 数组配置文件的路径→行号映射：嵌套键、列表自动索引、
 * 逐段向上回退定位、注释跳过与文件不可读兜底。
 * ConfigSourceLinesTest - covers the PHP-array config file's path→line map: nested keys, list auto-indexing,
 * segment-by-segment upward-fallback resolution, comment skipping and the unreadable-file fallback.
 */
final class ConfigSourceLinesTest extends TestCase
{
    private const SOURCE = <<<'PHP'
        <?php
        // 顶部注释应被跳过
        // A top comment is skipped
        return [
            'spawnPoint' => ['x' => 1, 'y' => 2],
            'monsters' => [
                ['id' => 'm1', 'anchor' => ['x' => 5, 'y' => 6]],
                ['id' => 'm2'],
            ],
        ];
        PHP;

    public function testMapsNestedKeysAndListIndicesToLines(): void
    {
        $map = ConfigSourceLines::build(self::SOURCE);

        self::assertSame(5, $map->lineFor('spawnPoint'));
        self::assertSame(5, $map->lineFor('spawnPoint.x'));
        self::assertSame(6, $map->lineFor('monsters'));
        self::assertSame(7, $map->lineFor('monsters.0.id'));
        self::assertSame(7, $map->lineFor('monsters.0.anchor.y'));
        self::assertSame(8, $map->lineFor('monsters.1.id'));
    }

    public function testLineForFallsBackSegmentBySegment(): void
    {
        $map = ConfigSourceLines::build(self::SOURCE);

        // monsters.1.anchor 未在源码出现，monsters.1 无自有行号（留给首个内层标量）——
        // 逐段回退直达 monsters（第 6 行）
        // monsters.1.anchor never appears in the source, and monsters.1 holds no own line (left to its first inner
        // scalar) — the fallback walks straight up to monsters (line 6).
        self::assertSame(6, $map->lineFor('monsters.1.anchor.x'));
        self::assertNull($map->lineFor('nowhere.at.all'));
    }

    public function testForFileReturnsNullWhenUnreadable(): void
    {
        self::assertNull(ConfigSourceLines::forFile(sys_get_temp_dir() . '/nythros_missing_' . uniqid() . '.php'));
    }

    public function testForFileResolvesLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nythros_lines_');
        file_put_contents($file, self::SOURCE);

        try {
            $map = ConfigSourceLines::forFile($file);
            self::assertNotNull($map);
            self::assertSame(7, $map->lineFor('monsters.0.id'));
        } finally {
            @unlink($file);
        }
    }
}
