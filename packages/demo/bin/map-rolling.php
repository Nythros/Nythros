<?php

declare(strict_types=1);

// 定位：packages/demo/bin/map-rolling.php — Map 频道滚动更新编排脚本（ADR-015 §3）。
// 两个子命令（一次性 CLI，直接连 Redis，不需要 Workerman）：
//   mark-stopping <serviceId>        标记旧实例 stopping（heartbeat merge 只覆盖 status，mapId/channelId/wsAddress 保留）
//   watch <serviceId> [--timeout=600] 轮询 discover('map') 观察该实例 meta.playerCount，归零提示可安全 stop，超时提示强制 stop
// Located at: packages/demo/bin/map-rolling.php — the Map channel rolling-update orchestration script (ADR-015 §3).
// Two subcommands (a one-shot CLI that connects to Redis directly; no Workerman):
//   mark-stopping <serviceId>        Marks the old instance as stopping (heartbeat merge overwrites only status; mapId/channelId/wsAddress survive)
//   watch <serviceId> [--timeout=600] Polls discover('map') watching meta.playerCount; zero prompts a safe stop, timeout prompts a forced stop

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\Cluster\RedisServiceRegistry;

/**
 * 解析 CLI 参数（参数非法 = 用法错误：stderr 归因 + exit(1)）。
 * 手动扫描支持任意位置的 --redisHost/--redisPort/--timeout（getopt 在首个位置参数处停止，
 * 无法解析 spec 中位于 serviceId 之后的 --timeout=600），两种取值形式（--opt=value / --opt value）均支持。
 * Parses CLI arguments (illegal arguments = usage error: stderr attribution + exit(1)).
 * Manual scan supports --redisHost/--redisPort/--timeout in any position (getopt stops at the first positional and
 * cannot parse the spec's --timeout=600 placed after serviceId); both value forms (--opt=value / --opt value) work.
 *
 * @param list<string> $argv 原始 argv Raw argv.
 * @return array{command: string, serviceId: string, redisHost: string, redisPort: int, timeout: int} 校验后的选项 Validated options.
 */
function parseMapRollingArgs(array $argv): array
{
    $fail = static function (string $message): never {
        fwrite(STDERR, sprintf("[map-rolling] fatal: %s\n", $message));
        exit(1);
    };

    $tokens = array_slice($argv, 1); // 首元素为脚本路径 Skip the script path
    $opts = [
        'redisHost' => '127.0.0.1',
        'redisPort' => '6379',
        'timeout' => '600',
    ];
    $positionals = [];

    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        $matched = false;
        foreach (['redisHost', 'redisPort', 'timeout'] as $option) {
            $prefix = '--' . $option . '=';
            if (str_starts_with($token, $prefix)) {
                $opts[$option] = substr($token, strlen($prefix));
                $matched = true;
                break;
            }
            if ($token === '--' . $option) {
                $index++;
                if ($index >= $count) {
                    $fail(sprintf('%s 缺少取值', $token));
                }
                $opts[$option] = $tokens[$index];
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $positionals[] = $token;
        }
    }

    if ($opts['redisHost'] === '') {
        $fail('--redisHost 必须是非空字符串');
    }

    $redisPort = (int) $opts['redisPort'];
    if (preg_match('/^\d+$/', $opts['redisPort']) !== 1 || $redisPort < 1 || $redisPort > 65535) {
        $fail('--redisPort 必须是 1~65535 的整数');
    }

    $timeout = (int) $opts['timeout'];
    if (preg_match('/^\d+$/', $opts['timeout']) !== 1 || $timeout < 1) {
        $fail('--timeout 必须是正整数');
    }

    $command = $positionals[0] ?? null;
    $serviceId = $positionals[1] ?? null;
    if (!in_array($command, ['mark-stopping', 'watch'], true) || $serviceId === null || $serviceId === '') {
        $fail('用法: php map-rolling.php mark-stopping <serviceId> | watch <serviceId> [--timeout=600]');
    }

    return [
        'command' => $command,
        'serviceId' => $serviceId,
        'redisHost' => $opts['redisHost'],
        'redisPort' => $redisPort,
        'timeout' => $timeout,
    ];
}

/** @var array{command: string, serviceId: string, redisHost: string, redisPort: int, timeout: int} $args 校验后的选项 Validated options. */
$args = parseMapRollingArgs($argv);

// stdout/stderr 无缓冲：watch 的轮询输出即时可见（重定向到文件时 PHP CLI 默认块缓冲，会推迟打印）。
// Unbuffered stdout/stderr: watch's polling output stays immediately visible (PHP CLI block-buffers when redirected, delaying output).
stream_set_write_buffer(STDOUT, 0);
stream_set_write_buffer(STDERR, 0);

// Redis 连接工厂：一次性 CLI 单进程，直接建连即可；沿用 run-worker.php 的工厂模式与 connect 超时（1s），
// 连接失败 throw 而非 exit——由下方 catch 兜底转为 stderr fatal + exit(1)，避免未捕获异常打印裸堆栈。
// Redis connection factory: a one-shot single-process CLI just connects directly; it reuses run-worker.php's factory pattern
// and 1s connect timeout — failures throw instead of exit(), converted to a stderr fatal + exit(1) by the catch below to avoid raw stack traces.
$redisFactory = static function () use ($args): \Redis {
    $redis = new \Redis();
    try {
        $connected = @$redis->connect($args['redisHost'], $args['redisPort'], 1.0);
    } catch (\Throwable) {
        $connected = false;
    }
    if ($connected !== true) {
        throw new \RuntimeException(sprintf('无法连接 Redis %s:%d', $args['redisHost'], $args['redisPort']));
    }

    return $redis;
};

$registry = new RedisServiceRegistry($redisFactory);

try {
    switch ($args['command']) {
        case 'mark-stopping':
            // heartbeat merge 只覆盖 status 字段，mapId/channelId/wsAddress 保留（register 整体覆盖会丢字段）。
            // heartbeat merge overwrites only status; mapId/channelId/wsAddress survive (register's wholesale overwrite would lose them).
            $registry->heartbeat('map', $args['serviceId'], ['status' => 'stopping']);

            // 回读确认：打印合并后的 meta，直观验证 status=stopping 且注册字段未丢（对应验收冒烟）。
            // Read-back confirmation: print the merged meta to visibly verify status=stopping with registered fields intact (matches the acceptance smoke test).
            $instance = $registry->discover('map')[$args['serviceId']] ?? null;
            if ($instance === null) {
                printf("[map-rolling] 已标记 stopping: %s（但 discover 未找到实例，可能从未注册）\n", $args['serviceId']);
            } else {
                printf(
                    "[map-rolling] 已标记 stopping: %s（status=%s, mapId=%s, channelId=%s, wsAddress=%s）\n",
                    $args['serviceId'],
                    (string) ($instance->meta['status'] ?? '?'),
                    (string) ($instance->meta['mapId'] ?? '?'),
                    (string) ($instance->meta['channelId'] ?? '?'),
                    (string) ($instance->meta['wsAddress'] ?? '?'),
                );
            }
            break;

        case 'watch':
            $deadline = microtime(true) + $args['timeout'];
            $lastCount = null;
            printf(
                "[map-rolling] 观察 %s（轮询间隔 5s，超时 %ds）...\n",
                $args['serviceId'],
                $args['timeout'],
            );

            while (true) {
                $instances = $registry->discover('map');
                $instance = $instances[$args['serviceId']] ?? null;

                // 实例不在 discover 结果（心跳键过期或已 unregister）→ 视为已停止，直接归零结论。
                // Instance absent from discover (heartbeat expired or unregistered) → treat as stopped and conclude immediately.
                if ($instance === null) {
                    printf("[map-rolling] %s 不在 discover 结果（视为已停止）\n", $args['serviceId']);
                    printf("[map-rolling] %s 可安全 stop\n", $args['serviceId']);
                    exit(0);
                }

                $playerCount = (int) ($instance->meta['playerCount'] ?? 0);
                if ($playerCount !== $lastCount) {
                    printf("[map-rolling] %s playerCount=%d\n", $args['serviceId'], $playerCount);
                    $lastCount = $playerCount;
                }

                if ($playerCount <= 0) {
                    printf("[map-rolling] %s 可安全 stop\n", $args['serviceId']);
                    exit(0);
                }

                if (microtime(true) >= $deadline) {
                    printf("[map-rolling] %s 强制 stop（剩余玩家将断线重连自迁移）\n", $args['serviceId']);
                    exit(0);
                }

                sleep(5);
            }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[map-rolling] fatal: %s\n", $e->getMessage()));
    exit(1);
}
