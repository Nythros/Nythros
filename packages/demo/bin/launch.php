<?php

declare(strict_types=1);

// 定位：packages/demo/bin/launch.php — 配置驱动部署启动器（组 9，ADR-013 决策 C）。
// 解析 deploy.yaml（拓扑唯一事实源）→ 逐 process 逐 service 展开 worker → spawn `php run-worker.php --service=...` 子进程
// → 打印各服务端口与 pid → 前台等待并转发退出信号（SIGINT/SIGTERM 全部停止）。
// Located at: packages/demo/bin/launch.php — the config-driven deployment launcher (group 9, ADR-013 decision C).
// Parses deploy.yaml (the single source of truth) → expands workers per process per service → spawns `php run-worker.php --service=...`
// children → prints each service's port and pid → waits in the foreground forwarding exit signals (SIGINT/SIGTERM stop everything).
//
// 阶段 4 不做进程监督（ADR 3.2）：worker 崩溃不自动拉起（崩溃自愈靠注册表 TTL 心跳过期，进程拉起靠重新执行本脚本）。
// Phase 4 has no process supervision (ADR 3.2): a crashed worker is never auto-restarted (crash self-healing relies on the registry's
// TTL heartbeat expiry; restarting processes means re-running this script).
//
// 阶段 5 起只启动战斗层 Map：社交层（登录/聊天/组队/帮派）由 bin/server 的 social 组承载（ADR-021 自研单栈），
// 本入口遍历 workers 时按 type === 'map' 过滤，社交声明被忽略。
// From phase 5 this launcher boots only the combat-tier Map: the social tier (login/chat/team/guild) is hosted by
// bin/server's social group (ADR-021's self-built single stack); this entry filters workers by type === 'map' and
// ignores social declarations.

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\Framework\Deploy\DeployConfig;

// stdout/stderr 无缓冲：spawn 摘要即时可见（重定向到文件时 PHP CLI 默认块缓冲，会推迟打印端口/pid）。
// Unbuffered stdout/stderr: the spawn summary stays immediately visible (PHP CLI block-buffers when redirected to a file, delaying the port/pid output).
stream_set_write_buffer(STDOUT, 0);
stream_set_write_buffer(STDERR, 0);

// 配置路径：argv[1] 可选，缺省 packages/demo/config/deploy.yaml（与脚本相对定位，不依赖 cwd）。
// Config path: argv[1] optional; defaults to packages/demo/config/deploy.yaml (script-relative, independent of the cwd).
$configPath = $argv[1] ?? dirname(__DIR__) . '/config/deploy.yaml';
if (!is_file($configPath)) {
    fwrite(STDERR, sprintf("[launch] fatal: deploy 配置不存在: %s\n", $configPath));
    exit(1);
}
$yaml = file_get_contents($configPath);
if ($yaml === false) {
    fwrite(STDERR, sprintf("[launch] fatal: 无法读取 deploy 配置: %s\n", $configPath));
    exit(1);
}

// 解析 + 展开：结构非法抛 InvalidArgumentException（带行号归因），直接终止（拓扑错误必须显性化，不能带病启动）。
// Parse + expand: illegal structures throw InvalidArgumentException (with line-number attribution) and terminate immediately —
// a topology error must be surfaced, never booted through.
$config = DeployConfig::parseYaml($yaml);
$workers = $config->workers();
$redis = $config->redis();
$mysql = $config->mysql();
$mapIds = $config->mapIds();
$workerScript = dirname(__DIR__) . '/bin/run-worker.php';
if (!is_file($workerScript)) {
    fwrite(STDERR, sprintf("[launch] fatal: worker 脚本不存在: %s\n", $workerScript));
    exit(1);
}

echo sprintf("[launch] deploy: %s\n", $configPath);
echo sprintf("[launch] redis: %s:%d\n", $redis['host'], $redis['port']);
echo sprintf("[launch] mysql: %s:%d/%s\n", $mysql['host'], $mysql['port'], $mysql['dbname']);
echo sprintf("[launch] topology: %d process(es), %d worker(s)\n", count($config->processes()), count($workers));
if ($mapIds !== []) {
    echo sprintf("[launch] mapIds whitelist: %s\n", implode(',', $mapIds));
}

// 逐 worker spawn：数组命令 + bypass_shell（不经 shell 拼接，argv 无注入面）；stdin 继承（Ctrl+C 信号可由进程组共享），
// stdout/stderr 落到独立日志文件——多个子进程与 launch 共享同一 stdout fd 会并发写交错丢行，独立文件保证
// launch 摘要完整可读、每服务日志各自可查（日志路径随 spawn 摘要打印）。
// Spawn per worker: array command + bypass_shell (no shell concatenation, no argv injection surface); stdin is inherited (Ctrl+C
// can be shared via the process group), while stdout/stderr go to a per-worker log file — sharing one stdout fd among the children
// and the launcher would interleave and lose lines under concurrent writes; separate files keep the launcher summary complete and
// readable with per-service logs (paths are printed with the spawn summary).
/** @var array<int, array{proc: resource, label: string, port: int, log: string}> $children pid => 子进程信息 pid => child info. */
$children = [];
$failures = 0;
foreach ($workers as $worker) {
    // maps-only 入口：社交三角色（gateway/chat/team）由 bin/server 的 social 组编排，此处跳过
    // Maps-only entry: the social trio (gateway/chat/team) is orchestrated by bin/server's social group; skipped here
    if ($worker->service->type !== 'map') {
        continue;
    }

    $command = DeployConfig::buildCommand($worker, $workerScript, $redis, $mysql);
    $logPath = sprintf('%s/nythros-worker-%s-%d.log', sys_get_temp_dir(), $worker->service->type, $worker->service->port);
    $proc = proc_open(
        $command,
        [0 => STDIN, 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, sprintf("[launch] spawn 失败: %s\n", implode(' ', $command)));
        $failures++;
        continue;
    }

    $status = proc_get_status($proc);
    $pid = $status['pid'];
    $serviceId = $worker->service->serviceId();
    $label = sprintf(
        '%s/%s%s',
        $worker->process,
        $worker->service->type,
        $serviceId !== null ? ' ' . $serviceId : '',
    );
    $children[$pid] = ['proc' => $proc, 'label' => $label, 'port' => $worker->service->port, 'log' => $logPath];
    printf("[launch] spawned %-16s port=%-5d pid=%d log=%s\n", $label, $worker->service->port, $pid, $logPath);
}

if ($failures > 0) {
    fwrite(STDERR, sprintf("[launch] fatal: %d 个 worker spawn 失败，终止\n", $failures));
    foreach ($children as $entry) {
        proc_terminate($entry['proc'], 15);
    }
    exit(1);
}
if ($children === []) {
    fwrite(STDERR, "[launch] fatal: 拓扑未展开出任何 worker\n");
    exit(1);
}

echo sprintf("[launch] %d worker(s) 已启动；Ctrl+C 全部停止（阶段 4 无进程监督，崩溃不自动拉起）\n", count($children));

// 前台等待 + 信号转发：SIGINT/SIGTERM → 向全部子进程转发 SIGTERM（Workerman 优雅 stopAll）后退出；
// SIGCHLD → 收割并打印退出的 worker（不重启——无监督，文档化于脚本头）。
// Foreground wait + signal forwarding: SIGINT/SIGTERM → forward SIGTERM to every child (Workerman's graceful stopAll) then exit;
// SIGCHLD → reap and report the exited worker (no restart — unsupervised, documented in the script header).
if (function_exists('pcntl_signal')) {
    $running = true;
    $stopAt = null; // 收到停止信号后的收尾截止时间（等待子进程优雅退出） The graceful-drain deadline after a stop signal arrives
    $handler = static function (int $signo) use (&$running, &$children, &$stopAt): void {
        if ($signo === SIGCHLD) {
            // 子进程退出：收割并打印（不重启——阶段 4 无监督） Reap and report exited children (no restart — phase 4 has no supervision)
            while (($exited = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                $entry = $children[$exited] ?? null;
                echo sprintf(
                    "[launch] worker exited pid=%d%s（阶段 4 无进程监督，不自动拉起）\n",
                    $exited,
                    $entry !== null ? sprintf(' port=%d [%s]', $entry['port'], $entry['label']) : '',
                );
                unset($children[$exited]);
            }

            return;
        }

        if ($stopAt !== null) {
            return; // 已在收尾中，重复信号忽略 Already draining; ignore repeated signals
        }

        echo sprintf("[launch] received signal %d, stopping all workers...\n", $signo);
        foreach ($children as $entry) {
            proc_terminate($entry['proc'], 15);
        }
        // 5 秒收尾窗口：等待 Workerman 优雅 stopAll 完成；超时或全部退出即结束 launch 自身
        // A 5s drain window: wait for Workerman's graceful stopAll; launch exits once every child is gone or the window elapses
        $stopAt = microtime(true) + 5.0;
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGCHLD, $handler);

    while ($running) {
        pcntl_signal_dispatch();
        usleep(200000); // 200ms 轮询（无事件循环的简单前台等待） 200ms polling (a simple foreground wait without an event loop)
        if ($stopAt !== null && ($children === [] || microtime(true) >= $stopAt)) {
            $running = false;
        }
    }
} else {
    // 非 POSIX（无 pcntl）：仅打印提示后前台等待（Windows 下 Workerman 单进程，spawn 的子进程独立存活）。
    // Non-POSIX (no pcntl): print a notice and wait (Windows runs single-process Workerman; spawned children live independently).
    fwrite(STDERR, "[launch] 无 pcntl：信号转发不可用（阶段 4 无进程监督），按 Ctrl+C 后子进程可能残留，请手动清理\n");
    while (true) {
        usleep(200000);
    }
}
