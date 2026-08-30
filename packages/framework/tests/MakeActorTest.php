<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Make\MakeActor;
use PHPUnit\Framework\TestCase;

/**
 * MakeActorTest - 覆盖 kind → 基类映射、缺参/非法 kind 报错与渲染产物内容。
 * Tests covering the kind → base-class mapping, missing/invalid argument errors and rendered artifact contents.
 */
final class MakeActorTest extends TestCase
{
    private string $outDir;

    protected function setUp(): void
    {
        $this->outDir = sys_get_temp_dir() . '/nythros_make_actor_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->outDir);
    }

    public function testKindMapsToBaseClassWithExpectedHooks(): void
    {
        $expectations = [
            'player' => ['BasePlayer', ['onTick', 'onDamaged', 'onDeath']],
            'monster' => ['BaseMonster', ['onPatrol', 'onChase', 'onAttack', 'onDead', 'onDeath']],
            'npc' => ['BaseNPC', ['onIdle', 'onInteract']],
        ];

        foreach ($expectations as $kind => [$base, $hooks]) {
            $class = ucfirst($kind) . 'Actor';
            $target = (new MakeActor())->run([
                $class,
                '--kind=' . $kind,
                '--ns=Nythros\Demo\Game',
                '--out=' . $this->outDir,
            ]);

            self::assertSame($this->outDir . '/' . $class . '.php', $target, 'kind=' . $kind . ' 输出路径');
            $content = (string) file_get_contents($target);

            self::assertStringContainsString('final class ' . $class . ' extends ' . $base, $content, 'kind=' . $kind . ' 基类');
            foreach ($hooks as $hook) {
                self::assertStringContainsString($hook, $content, 'kind=' . $kind . ' 钩子 ' . $hook);
            }
            self::assertStringContainsString('declare(strict_types=1);', $content, 'kind=' . $kind . ' 严格类型声明');
            self::assertStringContainsString('parent::__construct', $content, 'kind=' . $kind . ' parent 构造调用');
        }
    }

    public function testMissingClassNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['--kind=monster', '--ns=Nythros\Demo\Game', '--out=' . $this->outDir]);
    }

    public function testMissingKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['Wolf', '--ns=Nythros\Demo\Game', '--out=' . $this->outDir]);
    }

    public function testIllegalKindThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['Wolf', '--kind=fairy', '--ns=Nythros\Demo\Game', '--out=' . $this->outDir]);
    }

    public function testMissingNamespaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['Wolf', '--kind=monster', '--out=' . $this->outDir]);
    }

    public function testMissingOutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['Wolf', '--kind=monster', '--ns=Nythros\Demo\Game']);
    }

    public function testIllegalClassNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MakeActor())->run(['Wolf-Bad', '--kind=monster', '--ns=Nythros\Demo\Game', '--out=' . $this->outDir]);
    }

    public function testRenderedArtifactContainsClassAndHooks(): void
    {
        $target = (new MakeActor())->run([
            'MonsterActor',
            '--kind=monster',
            '--ns=Nythros\Demo\Combat',
            '--out=' . $this->outDir,
        ]);
        $content = (string) file_get_contents($target);

        self::assertStringContainsString('namespace Nythros\Demo\Combat;', $content);
        self::assertStringContainsString('use Nythros\Framework\BaseMonster;', $content);
        self::assertStringContainsString('final class MonsterActor extends BaseMonster', $content);
        self::assertStringContainsString('parent::__construct($id, $maxHp);', $content);
        self::assertStringContainsString('protected function onPatrol(): void', $content);
        self::assertStringContainsString('TODO', $content, '钩子必须带 TODO 注释');
    }

    public function testGeneratedArtifactsPassPhpLint(): void
    {
        foreach (['player', 'monster', 'npc'] as $kind) {
            $class = ucfirst($kind) . 'Actor';
            $target = (new MakeActor())->run([
                $class,
                '--kind=' . $kind,
                '--ns=Nythros\Demo\Game',
                '--out=' . $this->outDir,
            ]);

            exec(PHP_BINARY . ' -l ' . escapeshellarg($target) . ' 2>&1', $output, $exitCode);
            self::assertSame(0, $exitCode, 'kind=' . $kind . ' php -l 失败: ' . implode("\n", $output));
        }
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
