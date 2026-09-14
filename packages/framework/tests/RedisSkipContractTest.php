<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Redis 集成测试 skip 契约（回归防护，纯文件扫描，不依赖 Redis 是否可用）。
 * The Redis integration-test skip contract (regression guard; a pure file scan, independent of Redis availability).
 *
 * 背景：Redis 集成测试的 setUp 先构造客户端对象、再尝试连接，失败时 markTestSkipped。但 PHPUnit
 * **跳过测试后仍会调用 tearDown**——若 setUp 只 skip 而未摘除那个未连上的客户端对象，tearDown 会对
 * 死连接执行 keys()/close() 并抛 `Redis server went away`，把一个本该 skip 的用例变成 Errored
 * （v0.2.0 实测：无 Redis 时 149 个错误，且 PHPUnit 对每个用例多计一次，1319 被报成 1468）。
 * 本测试钉住修复模式：每个 skip 调用前必须先 `$this->redis = null;`，让 tearDown 的空值守卫生效。
 * Background: a Redis integration test's setUp builds the client object and then connects, skipping on failure.
 * But PHPUnit **still calls tearDown after a skipped test** — if setUp skips without detaching that unconnected
 * client object, tearDown runs keys()/close() on a dead connection and throws `Redis server went away`, turning a
 * should-skip case into Errored (measured on v0.2.0: 149 errors with no Redis, and PHPUnit double-counts each case,
 * reporting 1319 as 1468). This test pins the fix pattern: every skip must be preceded by `$this->redis = null;`
 * so tearDown's null guard takes effect.
 */
final class RedisSkipContractTest extends TestCase
{
    public function testRedisIntegrationSetUpDetachesUnconnectedClientBeforeSkipping(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/packages', FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($path, '/tests/') || str_ends_with($path, 'RedisSkipContractTest.php')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());
            if (!str_contains($src, 'new \\Redis()') || !str_contains($src, 'markTestSkipped')) {
                continue;
            }
            if (preg_match('/protected function setUp\(\): void\s*\{(.*?)\n    \}/s', $src, $m) !== 1) {
                continue;
            }

            $body = $m[1];
            $offset = 0;
            while (($pos = strpos($body, 'markTestSkipped', $offset)) !== false) {
                $windowStart = max(0, $pos - 240);
                $before = substr($body, $windowStart, $pos - $windowStart);
                if (!str_contains($before, '$this->redis = null;')) {
                    $violations[] = $path;
                    break;
                }
                $offset = $pos + 1;
            }
        }

        self::assertSame(
            [],
            $violations,
            "Redis 集成测试在 markTestSkipped 之前必须摘除未连上的客户端（\$this->redis = null;），"
            . "否则 PHPUnit 在 skip 后仍调用 tearDown 会把它变成 error。违规文件：\n" . implode("\n", $violations),
        );
    }
}
