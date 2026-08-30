<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Make\MakeEvent;
use Nythros\Framework\Make\MakeMap;
use Nythros\Framework\Make\MakeSkill;
use PHPUnit\Framework\TestCase;

/**
 * MakeCommandsTest - 覆盖 make:skill / make:event / make:map 的参数校验与生成产物。
 * Tests covering argument validation and generated artifacts of make:skill / make:event / make:map.
 */
final class MakeCommandsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nythros_make_cmds_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testSkillRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeSkill())->run(['--out=' . $this->tmpDir . '/skills.php']);
    }

    public function testSkillRejectsMissingOut(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeSkill())->run(['Fireball']);
    }

    public function testSkillRejectsNonNumericOption(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeSkill())->run(['Fireball', '--out=' . $this->tmpDir . '/skills.php', '--range=far']);
    }

    public function testSkillWritesConfigFile(): void
    {
        $path = $this->tmpDir . '/skills.php';
        $target = (new MakeSkill())->run([
            'Fireball',
            '--out=' . $path,
            '--damage-multiplier=1.5',
            '--cooldown-seconds=2.0',
            '--range=6',
        ]);

        self::assertSame($path, $target);
        self::assertFileExists($path);

        $config = require $path;
        self::assertArrayHasKey('fireball', $config);
        self::assertSame('fireball', $config['fireball']['id']);
        self::assertSame('Fireball', $config['fireball']['name']);
        self::assertSame(1.5, $config['fireball']['damageMultiplier']);
        self::assertSame(2.0, $config['fireball']['cooldownSeconds']);
        self::assertSame(6, $config['fireball']['range']);
    }

    public function testSkillAppendsToExistingConfig(): void
    {
        $path = $this->tmpDir . '/skills.php';
        (new MakeSkill())->run(['Fireball', '--out=' . $path]);
        (new MakeSkill())->run(['Frostbolt', '--out=' . $path]);

        $config = require $path;
        self::assertArrayHasKey('fireball', $config);
        self::assertArrayHasKey('frostbolt', $config);
    }

    public function testSkillRejectsDuplicate(): void
    {
        $path = $this->tmpDir . '/skills.php';
        (new MakeSkill())->run(['Fireball', '--out=' . $path]);

        $this->expectException(InvalidArgumentException::class);

        (new MakeSkill())->run(['Fireball', '--out=' . $path]);
    }

    public function testEventRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeEvent())->run(['--out=' . $this->tmpDir . '/events']);
    }

    public function testEventRejectsMissingOut(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeEvent())->run(['PlayerKilled']);
    }

    public function testEventWritesClassFile(): void
    {
        $out = $this->tmpDir . '/events';
        $target = (new MakeEvent())->run(['PlayerKilled', '--out=' . $out, '--ns=Nythros\Tests\Generated']);
        $content = (string) file_get_contents($target);

        self::assertFileExists($target);
        self::assertStringContainsString('namespace Nythros\Tests\Generated;', $content);
        self::assertStringContainsString('final class PlayerKilled', $content);
        self::assertStringContainsString("public const NAME = 'player.killed';", $content);
        self::assertStringContainsString('declare(strict_types=1);', $content);

        require $target;
        self::assertSame('player.killed', \Nythros\Tests\Generated\PlayerKilled::NAME);
    }

    public function testEventDefaultsNamespace(): void
    {
        $out = $this->tmpDir . '/events';
        $target = (new MakeEvent())->run(['ItemDropped', '--out=' . $out]);
        $content = (string) file_get_contents($target);

        self::assertStringContainsString('namespace Nythros\Demo\Events;', $content);
        self::assertStringContainsString("public const NAME = 'item.dropped';", $content);
    }

    public function testMapRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeMap())->run(['--out=' . $this->tmpDir . '/maps.php']);
    }

    public function testMapRejectsMissingOut(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeMap())->run(['Forest']);
    }

    public function testMapWritesConfigFile(): void
    {
        $path = $this->tmpDir . '/maps.php';
        $target = (new MakeMap())->run(['Forest', '--out=' . $path, '--width=120', '--height=80']);

        self::assertSame($path, $target);
        self::assertFileExists($path);

        $config = require $path;
        self::assertArrayHasKey('forest', $config);
        self::assertSame('forest', $config['forest']['id']);
        self::assertSame('Forest', $config['forest']['name']);
        self::assertSame(120, $config['forest']['width']);
        self::assertSame(80, $config['forest']['height']);
    }

    public function testMapAppendsToExistingConfig(): void
    {
        $path = $this->tmpDir . '/maps.php';
        (new MakeMap())->run(['Forest', '--out=' . $path]);
        (new MakeMap())->run(['Desert', '--out=' . $path]);

        $config = require $path;
        self::assertArrayHasKey('forest', $config);
        self::assertArrayHasKey('desert', $config);
    }

    public function testMapRejectsDuplicate(): void
    {
        $path = $this->tmpDir . '/maps.php';
        (new MakeMap())->run(['Forest', '--out=' . $path]);

        $this->expectException(InvalidArgumentException::class);

        (new MakeMap())->run(['Forest', '--out=' . $path]);
    }

    /**
     * 递归删除临时目录。
     * Recursively removes the temporary directory.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
