#!/usr/bin/env php
<?php

declare(strict_types=1);

// 定位：bin/start-maps.php — Workerman 原生多频道地图/副本入口（单入口、单 master 管全部频道进程）。
// 读 deploy.yaml（framework Deploy/DeployConfig）→ 每个 map/副本服务创建一个独立 Worker 实例
// （默认 count=1：一端口一进程一 World；副本池如需同端口多进程对等实例可声明 count>1，由 Workerman master
// 先监听再 fork 共享 socket，进程间内存隔离）→ 频道组装复用餐用 MapChannelFactory（与 run-worker 逐频道入口一致）→ 统一 runAll。
// 优点：master 自带监督（worker 崩溃自动重启）、start/stop/restart/reload/status 命令、单点日志与信号管理。
// 注意：单入口 = 单机聚合（全部端口须同时空闲）；跨机/单频道独立部署请用 run-worker + 外部编排（bin/server --parts）。
// Located at: bin/start-maps.php — the native multi-channel map/dungeon entry (a single entry; one master manages
// every channel process). Reads deploy.yaml (framework Deploy/DeployConfig) and creates one Worker instance per
// map/dungeon service (default count=1: one port, one process, one World; instance pools may declare count>1 — the
// Workerman master listens first then forks with the shared socket, keeping process memory isolated) → the channel
// assembly reuses MapChannelFactory (identical to the run-worker per-channel entry) → one runAll.
// Gains: built-in supervision (crashed workers auto-restart), start/stop/restart/reload/status commands, single-point
// logging and signal handling. Caveat: one entry = one-machine aggregation (all ports must be free at boot);
// cross-machine / single-channel deployments still use run-worker + external orchestration (bin/server --parts).

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Demo\MapChannelFactory;
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Framework\Deploy\DeployConfig;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;
use Nythros\Security\RedisTokenStore;
use Nythros\Security\TokenManager;
use Workerman\Worker;

// 配置路径：argv[1] 可选，缺省 demo 的 deploy.yaml（与脚本相对定位，不依赖 cwd）
// Config path: argv[1] optional; defaults to the demo deploy.yaml (script-relative, independent of the cwd)
$configPath = $argv[1] ?? dirname(__DIR__) . '/packages/demo/config/deploy.yaml';
if (!is_file($configPath)) {
    fwrite(STDERR, sprintf("[start-maps] fatal: 配置不存在: %s\n", $configPath));
    exit(1);
}
$config = DeployConfig::parseYaml((string) file_get_contents($configPath));

// Redis 连接工厂：lazy 建连（Workerman fork 后各 worker 首次使用时各自建立独立连接，避免共享 fd 破坏 Redis 协议）
// Redis connection factory: lazily connected (each forked worker opens its own connection on first use)
$redisInfo = $config->redis();
$redisFactory = static function () use ($redisInfo): \Redis {
    $redis = new \Redis();
    try {
        $connected = @$redis->connect($redisInfo['host'], $redisInfo['port'], 1.0);
    } catch (\Throwable) {
        $connected = false;
    }
    if ($connected !== true) {
        throw new \RuntimeException(sprintf('[start-maps] fatal: 无法连接 Redis %s:%d', $redisInfo['host'], $redisInfo['port']));
    }

    return $redis;
};

// MySQL 连接工厂（归档落库）：lazy 建连（与 Redis 同口径）
// MySQL connection factory (archive persistence): lazily connected
$mysqlInfo = $config->mysql();
$pdoFactory = static function () use ($mysqlInfo): \PDO {
    return new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $mysqlInfo['host'], $mysqlInfo['port'], $mysqlInfo['dbname']),
        $mysqlInfo['user'],
        $mysqlInfo['password'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );
};

// 跨进程共享组装：token（多 scope）与服务注册表（discover/bind/resolve）
// Shared cross-process assembly: tokens (multi-scope) and the service registry (discover/bind/resolve)
$tokenManager = new TokenManager(new RedisTokenStore($redisFactory));
$serviceRegistry = new RedisServiceRegistry($redisFactory);
$serializer = MapCodec::create();

// 每频道一个独立 Worker 实例（count=服务声明 count）—— 组装复用餐用 MapChannelFactory；
// maps-only 入口：社交三角色（gateway/chat/team）由 bin/server 的 social 组编排，此处过滤（ADR-021）
// One independent Worker instance per channel (count = the service's declared count) — assembly reuses MapChannelFactory;
// maps-only entry: the social trio (gateway/chat/team) is orchestrated by bin/server's social group, filtered out here (ADR-021)
$created = 0;
foreach ($config->processes() as $processName => $services) {
    foreach ($services as $service) {
        if ($service->type !== 'map') {
            continue;
        }
        $mapId = (string) $service->mapId;
        $channelId = (string) $service->channelId;
        $server = new WorkermanWebSocketServer(
            sprintf('websocket://0.0.0.0:%d', $service->port),
            workerCount: $service->count,
            authTimeoutSeconds: 10,
            scanIntervalSeconds: 2,
            errorSerializer: $serializer,
        );
        MapChannelFactory::attachChannel(
            $server,
            $mapId,
            $channelId,
            $service->worldType ?? 'aoi',
            $service->port,
            $serializer,
            $tokenManager,
            $serviceRegistry,
            $redisFactory,
            $pdoFactory,
        );
        printf("[start-maps] channel %-22s port=%-5d worldType=%-4s count=%d (process %s)\n", sprintf('%s#%s', $mapId, $channelId), $service->port, $service->worldType ?? 'aoi', $service->count, $processName);
        $created++;
    }
}
if ($created === 0) {
    fwrite(STDERR, '[start-maps] fatal: deploy.yaml 未声明任何地图/副本服务（processes 段为空）\n');
    exit(1);
}

// Workerman 的 parseCommand 扫描 $argv：注入显式 start 命令（消费掉自定义配置路径参数）
// Workerman's parseCommand scans argv: inject an explicit start (the custom config-path arg is consumed)
$GLOBALS['argv'] = [$argv[0], 'start'];

// 独立 pidFile/logFile（G-5 同口径）：不占用 Workerman 缺省的 /tmp/workerman.pid——那是全局单例锁，
// 压测/演练脚本（stress-map/soak）会被 "already running" 拒掉（故障演练实抓）。
// A distinct pidFile/logFile (the G-5 convention): stop squatting on Workerman's default /tmp/workerman.pid —
// a global singleton lock that gets the stress/soak scripts rejected as "already running" (caught live by drills).
Worker::$pidFile = '/tmp/nythros-start-maps.pid';
Worker::$logFile = '/tmp/nythros-server/start-maps-workerman.log';

echo sprintf('[start-maps] %d channel(s) assembled, starting unified runAll...\n', $created);
Worker::runAll();
