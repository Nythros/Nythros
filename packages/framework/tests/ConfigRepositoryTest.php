<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use LogicException;
use Nythros\Contracts\TimerInterface;
use Nythros\Framework\Config\ConfigRepository;
use Nythros\Framework\Event\EventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * ConfigRepositoryTest - 覆盖配置热载仓库：多文件/目录注册、mtime 轮询检测、原子替换与失败回滚、
 * 变更事件（无变更零事件）、Timer 轮询装配。
 * ConfigRepositoryTest - covers the config hot-reload repository: multi-file/directory registration, mtime
 * polling detection, atomic replacement with failure rollback, change events (zero events without changes)
 * and the Timer polling assembly.
 */
final class ConfigRepositoryTest extends TestCase
{
    /** @var list<string> 测试结束待清理的临时文件路径 Temporary file paths to clean up after the test. */
    private array $tempPaths = [];

    /** 本用例专属临时目录（文件名 = 配置键，供假 mtime 读取器按 basename 取键）。 This case's own temp dir (filename = config key, so the fake mtime reader resolves keys by basename). */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nythros_cfg_repo_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testRegisterFileLoadsAndReadsDotPath(): void
    {
        $repo = new ConfigRepository(new EventDispatcher());
        $repo->registerFile('app', $this->writeConfig('app', '<?php return ["a" => ["b" => 7, "nil" => null]];'));

        self::assertSame(7, $repo->get('app.a.b'));
        self::assertTrue($repo->has('app.a.nil'), '值为 null 的键也必须视为存在。A null-valued key must still count as existing.');
        self::assertNull($repo->get('app.a.nil'));
        self::assertSame('x', $repo->get('app.missing', 'x'));
        self::assertFalse($repo->has('other.key'));
    }

    public function testGetBeforeRegistrationReturnsDefault(): void
    {
        $repo = new ConfigRepository(new EventDispatcher());

        self::assertNull($repo->config());
        self::assertSame('d', $repo->get('any.key', 'd'));
        self::assertFalse($repo->has('any.key'));
    }

    public function testRegisterDirectoryScansPhpFilesSortedByKey(): void
    {
        $dir = sys_get_temp_dir() . '/nythros_cfg_repo_' . uniqid();
        mkdir($dir);
        $this->tempPaths[] = $dir . '/beta.php';
        $this->tempPaths[] = $dir . '/alpha.php';
        $this->tempPaths[] = $dir . '/notes.txt';
        file_put_contents($dir . '/beta.php', '<?php return ["v" => 2];');
        file_put_contents($dir . '/alpha.php', '<?php return ["v" => 1];');
        file_put_contents($dir . '/notes.txt', 'not php');

        $repo = new ConfigRepository(new EventDispatcher());
        $repo->registerDirectory($dir);

        self::assertSame(1, $repo->get('alpha.v'));
        self::assertSame(2, $repo->get('beta.v'));
        self::assertFalse($repo->has('notes'));
    }

    public function testRegisterDuplicateKeyThrows(): void
    {
        $repo = new ConfigRepository(new EventDispatcher());
        $repo->registerFile('app', $this->writeConfig('app', '<?php return [];'));

        $this->expectException(LogicException::class);

        $repo->registerFile('app', $this->writeConfig('dup', '<?php return [];'));
    }

    /**
     * 假 mtime 注入驱动替换：mtime 变化 → 重载 + 逐文件 config.changed 事件；无变化零事件。
     * Fake-mtime injection drives replacement: an mtime change reloads and dispatches per-file config.changed
     * events; no change means zero events.
     */
    public function testCheckReloadsChangedFilesAndDispatchesEvents(): void
    {
        $mtimes = ['app' => 100, 'feat' => 200];
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ConfigRepository::EVENT_CHANGED, static function (array $payload) use (&$events): void {
            $events[] = $payload;
        });
        $repo = new ConfigRepository($dispatcher, static function (string $path) use (&$mtimes): int|false {
            return $mtimes[basename($path, '.php')];
        });
        $repo->registerFile('app', $this->writeConfig('app', '<?php return ["mode" => "safe"];'));
        $repo->registerFile('feat', $this->writeConfig('feat', '<?php return ["on" => false];'));

        // 无变化：check 返回 false 且零事件
        // No change: check returns false with zero events
        self::assertFalse($repo->check());
        self::assertSame([], $events);

        // 双文件同时变更：原子替换两键 + 恰好两条事件（逐文件）
        // Both files change together: both keys swap atomically plus exactly two events (one per file)
        $mtimes['app'] = 101;
        $mtimes['feat'] = 201;
        $this->rewrite('app', '<?php return ["mode" => "hot"];');
        $this->rewrite('feat', '<?php return ["on" => true];');

        self::assertTrue($repo->check());
        self::assertSame('hot', $repo->get('app.mode'));
        self::assertTrue($repo->get('feat.on'));
        self::assertSame([
            ['key' => 'app', 'path' => $this->paths['app']],
            ['key' => 'feat', 'path' => $this->paths['feat']],
        ], $events);
    }

    /**
     * 原子替换失败回滚：双文件变更其一非法 → 整体放弃，旧配置原样保留、零事件；修复后下轮轮询成功。
     * Atomic-replacement rollback: of two changed files one is invalid → the whole batch aborts, the old config
     * stays intact with zero events; after a fix the next poll succeeds.
     */
    public function testCheckRollsBackWholeBatchWhenOneFileFails(): void
    {
        $mtimes = ['good' => 1, 'bad' => 1];
        $events = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ConfigRepository::EVENT_CHANGED, static function () use (&$events): void {
            $events++;
        });
        $repo = new ConfigRepository($dispatcher, static function (string $path) use (&$mtimes): int|false {
            return $mtimes[basename($path, '.php')];
        });
        $repo->registerFile('good', $this->writeConfig('good', '<?php return ["v" => 1];'));
        $repo->registerFile('bad', $this->writeConfig('bad', '<?php return ["v" => 1];'));

        $mtimes['good'] = 2;
        $mtimes['bad'] = 2;
        $this->rewrite('good', '<?php return ["v" => 2];');
        $this->rewrite('bad', '<?php return 42;'); // 非 array：加载必败 non-array: load must fail

        self::assertFalse($repo->check(), '任一文件加载失败必须整体放弃。Any single load failure must abort the whole batch.');
        self::assertSame(1, $repo->get('good.v'), '回滚语义：未变更应用的旧值必须保留。Rollback: the old unapplied value must survive.');
        self::assertSame(0, $events, '回滚时不得发任何变更事件。No change events may fire during rollback.');

        // 修复后下一轮成功重载
        // After the fix the next poll reloads successfully
        $mtimes['bad'] = 3;
        $this->rewrite('bad', '<?php return ["v" => 9];');

        self::assertTrue($repo->check());
        self::assertSame(2, $repo->get('good.v'));
        self::assertSame(9, $repo->get('bad.v'));
        self::assertSame(2, $events);
    }

    /**
     * 文件被删除（mtime 不可读为 false）：视为待变更但加载失败 → 回滚，运行中配置不被清空。
     * A deleted file (unreadable mtime reads false): counts as pending but its load fails → rollback, the running
     * config is never wiped.
     */
    public function testDeletedFileRollsBackInsteadOfWipingConfig(): void
    {
        $mtimes = ['gone' => 10];
        $repo = new ConfigRepository(new EventDispatcher(), static function (string $path) use (&$mtimes): int|false {
            return $mtimes[basename($path, '.php')] ?? false;
        });
        $repo->registerFile('gone', $this->writeConfig('gone', '<?php return ["keep" => true];'));

        // 模拟文件删除：mtime 不可读（false）+ 文件真实移除（加载阶段必然失败）
        // Simulate deletion: unreadable mtime (false) plus the file actually removed (the load phase must fail)
        $mtimes['gone'] = false;
        @unlink($this->paths['gone']);

        self::assertFalse($repo->check());
        self::assertTrue($repo->get('gone.keep'), '删除文件不得清空既有配置。A deleted file must not wipe the existing config.');
    }

    public function testStartPollingRegistersPersistentTimerDrivingCheck(): void
    {
        $timer = new FakeTimer();
        $mtimes = ['app' => 1];
        $repo = new ConfigRepository(new EventDispatcher(), static function (string $path) use (&$mtimes): int|false {
            return $mtimes[basename($path, '.php')];
        });
        $repo->registerFile('app', $this->writeConfig('app', '<?php return ["v" => 1];'));

        $repo->startPolling($timer, 5.0);

        self::assertSame([5.0], $timer->intervals, '轮询必须以给定间隔注册持久定时器。Polling must register a persistent timer at the given interval.');

        // 定时器到期（模拟）：mtime 未变 → 无动作；变更后触发 → 重载生效
        // Timer fires (simulated): unchanged mtime → nothing; after a change → reload applies
        $timer->fire();
        self::assertSame(1, $repo->get('app.v'));

        $mtimes['app'] = 2;
        $this->rewrite('app', '<?php return ["v" => 2];');
        $timer->fire();
        self::assertSame(2, $repo->get('app.v'), '定时器触发的 check 必须应用热载变更。The timer-driven check must apply hot-reload changes.');
    }

    /** @var array<string, string> 本用例的 键 => 文件路径表 This case's key => path table. */
    private array $paths = [];

    /**
     * 写一个临时 PHP 配置文件并登记进本用例路径表（文件名 = 键，假 mtime 读取器按此取键）。
     * Writes a temporary PHP config file and records it (filename = key; the fake mtime reader resolves by it).
     */
    private function writeConfig(string $key, string $contents): string
    {
        $path = $this->dir . '/' . $key . '.php';
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;
        $this->paths[$key] = $path;

        return $path;
    }

    /** 原位重写已登记的配置文件内容。 Rewrites a registered config file's contents in place. */
    private function rewrite(string $key, string $contents): void
    {
        file_put_contents($this->paths[$key], $contents);
    }
}

/**
 * 测试假定时器：捕获 add 的间隔与回调，手动 fire 模拟到期。
 * Test fake timer: captures add()'s interval and callback; fire() simulates expiry by hand.
 */
final class FakeTimer implements TimerInterface
{
    /** @var list<float> 已注册间隔 Registered intervals. */
    public array $intervals = [];

    /** @var list<callable> 已注册回调 Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->intervals[] = $intervalSeconds;
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
    }

    /** 手动触发全部回调一次。 Fires every registered callback once. */
    public function fire(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }
}
