<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Config\ConfigRepository;
use Nythros\Framework\Config\ConfigSchema;
use Nythros\Framework\Event\EventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * ConfigRepositorySchemaTest - 覆盖仓库 × schema 接线（P11）：注册期坏表 fail-fast（错误带行号）、
 * 归一化默认值回填进配置产出、热载坏表回滚（旧配置保留、不应用不发事件）与修表后自动恢复。
 * ConfigRepositorySchemaTest - covers the repository × schema wiring (the P11): register-time fail-fast on a
 * rejected table (errors carry line numbers), normalized default back-fill in the config output, hot-reload
 * rollback on a rejected table (old config kept, no application, no events) and automatic recovery once fixed.
 */
final class ConfigRepositorySchemaTest extends TestCase
{
    /** @var list<string> 测试结束待清理的临时文件路径 Temporary file paths to clean up after the test. */
    private array $tempPaths = [];

    /** @var int 假 mtime 读取器的递增时钟（同秒多次写文件在真 mtime 下无法区分，注入计数器驱动轮询） The fake mtime reader's incrementing clock (multiple same-second writes are indistinguishable under real mtimes; an injected counter drives the polling). */
    private int $fakeClock = 1000;

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
    }

    /**
     * 假 mtime 读取器注入的仓库：每次读取时钟 +1，写文件模拟用 tick() 显式推进。
     * A repository with the fake mtime reader injected: every read advances the clock; write simulations advance it explicitly via tick().
     */
    private function repo(): ConfigRepository
    {
        return new ConfigRepository(new EventDispatcher(), function (): int {
            return $this->fakeClock;
        });
    }

    private function tick(): void
    {
        ++$this->fakeClock;
    }

    public function testRegisterFileRejectsBadTableWithLineNumber(): void
    {
        $file = $this->write('entries.php', "<?php return ['entries' => [\n  ['itemId' => 'bone', 'weight' => 'heavy'],\n]];\n");
        $repo = $this->repo();

        try {
            $repo->registerFile('drops', $file, self::schema());
            self::fail('坏表必须在注册期 fail-fast。A rejected table must fail fast at registration.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('drops', $e->getMessage());
            self::assertStringContainsString('第 2 行', $e->getMessage());
            self::assertStringContainsString('entries.0.weight：应为 int，实际 string("heavy")', $e->getMessage());
        }
    }

    public function testRegisterFileBackFillsOptionalDefaults(): void
    {
        $file = $this->write('drops.php', "<?php return ['entries' => [['itemId' => 'bone', 'weight' => 3]]];\n");
        $repo = $this->repo();
        $repo->registerFile('drops', $file, self::schema());

        self::assertSame(['itemId' => 'bone', 'weight' => 3, 'minCount' => 1, 'maxCount' => 1, 'feature' => null], $repo->get('drops.entries.0'));
        self::assertSame(0, $repo->get('drops.noDropWeight'));
    }

    public function testHotReloadRejectsBadTableAndKeepsOldConfig(): void
    {
        $file = $this->write('drops.php', "<?php return ['entries' => [['itemId' => 'bone', 'weight' => 3]]];\n");
        $repo = $this->repo();
        $repo->registerFile('drops', $file, self::schema());
        $this->tick();

        // 热载改成坏表：回滚（旧配置保留），且不应用不发事件
        // Hot-reloading into a rejected table: rollback (old config kept), no application, no events
        file_put_contents($file, "<?php return ['entries' => [['itemId' => 'bone', 'weight' => 'oops']]];\n");
        $this->tick();

        self::assertFalse($repo->check());
        self::assertSame(3, $repo->get('drops.entries.0.weight'));

        // 修表后下次轮询自动恢复热载
        // Once fixed, the next poll resumes hot reload automatically
        file_put_contents($file, "<?php return ['entries' => [['itemId' => 'bone', 'weight' => 9]]];\n");
        $this->tick();

        self::assertTrue($repo->check());
        self::assertSame(9, $repo->get('drops.entries.0.weight'));
    }

    public function testRegisterDirectoryAppliesSchemasByKey(): void
    {
        $dir = sys_get_temp_dir() . '/nythros_schema_dir_' . uniqid();
        mkdir($dir);
        $this->tempPaths[] = $dir;
        file_put_contents($dir . '/drops.php', "<?php return ['entries' => [['itemId' => 'bone', 'weight' => 3]]];\n");
        file_put_contents($dir . '/generic.php', "<?php return ['anything' => ['goes' => 1]];\n");

        try {
            $repo = new ConfigRepository(new EventDispatcher());
            $repo->registerDirectory($dir, ['drops' => self::schema()]);

            self::assertSame(1, $repo->get('drops.entries.0.minCount'));
            self::assertSame(1, $repo->get('generic.anything.goes'));
        } finally {
            @unlink($dir . '/drops.php');
            @unlink($dir . '/generic.php');
            @rmdir($dir);
        }
    }

    /**
     * drops 表测试 schema：结构合法 + itemId 非空。
     * A drops-table test schema: structural validity + non-empty itemId.
     */
    private static function schema(): ConfigSchema
    {
        return ConfigSchema::shape([
            'noDropWeight' => ConfigSchema::int(min: 0)->optional(0),
            'entries' => ConfigSchema::listOf(ConfigSchema::shape([
                'itemId' => ConfigSchema::string(minLength: 1),
                'weight' => ConfigSchema::int(min: 1),
                'minCount' => ConfigSchema::int(min: 1)->optional(1),
                'maxCount' => ConfigSchema::int(min: 1)->optional(1),
                'feature' => ConfigSchema::string(minLength: 1)->nullable()->optional(null),
            ])),
        ]);
    }

    /**
     * 写临时配置文件并登记清理。
     * Writes a temp config file and registers it for cleanup.
     */
    private function write(string $filename, string $content): string
    {
        $path = sys_get_temp_dir() . '/nythros_schema_' . uniqid() . '_' . $filename;
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return $path;
    }
}
