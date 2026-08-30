<?php

declare(strict_types=1);

// 单频道 Map 服务器入口（入门套件核心可执行）：--mapId + --channelId 定位 config/servers.php 里的
// 服务条目（端口/World 类型/NPC 由配置决定，单一事实源），组装 World → WebSocket 服务器 → GameServer，
// 50ms 世界 tick（时钟推进 + world 更新 + 帧末事件总线 flush）。
// Located at: skeleton/bin/map-worker.php — the per-channel Map worker entry (the kit's runnable core).
// --mapId + --channelId locate the service entry inside config/servers.php (port / World type / NPCs come from
// the config — a single source of truth); assembles World → WebSocket server → GameServer and runs a 50ms
// world tick (clock advance + world update + frame-end event-bus flush).

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\WorldType;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Config\Config;
use Nythros\KernelWorkerman\WorkermanClock;
use Nythros\KernelWorkerman\WorkermanTimer;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Skeleton\GameServer;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use Workerman\Worker;

$fail = static function (string $message): never {
    fwrite(STDERR, sprintf("[map-worker] fatal: %s
", $message));
    exit(1);
};

$options = getopt('', ['mapId:', 'channelId:']);
if (!is_string($options['mapId'] ?? null) || $options['mapId'] === '') {
    $fail('--mapId 必须是非空字符串');
}
if (!is_string($options['channelId'] ?? null) || $options['channelId'] === '') {
    $fail('--channelId 必须是非空字符串');
}
$mapId = $options['mapId'];
$channelId = $options['channelId'];

// 从配置读取本服务条目（port/worldType/npc）——拓扑唯一事实源
// Read this service entry from the config (port/worldType/npc) — the single source of truth for the topology
$servers = Config::fromPhpFile(dirname(__DIR__) . '/config/servers.php')->get('servers', []);
$entry = null;
foreach ($servers as $candidate) {
    if (($candidate['mapId'] ?? null) === $mapId && ($candidate['channelId'] ?? null) === $channelId) {
        $entry = $candidate;
        break;
    }
}
if ($entry === null) {
    $fail(sprintf('config/servers.php 中不存在 %s#%s 服务条目', $mapId, $channelId));
}
$port = $entry['port'] ?? 0;
$worldTypeRaw = $entry['worldType'] ?? 'aoi';
$npcSeeds = is_array($entry['npc'] ?? null) ? $entry['npc'] : [];
if (!is_int($port) || $port < 1 || $port > 65535) {
    $fail('port 必须是 1~65535 的整数');
}

// 世界类型（AOI / 全量广播）：两种都注入 AOI —— GridAOI（九宫格视野）或 UniversalAOI（全量 = 全世界即视野）
// World type (AOI / full broadcast): both inject an AOI — GridAOI (3x3 view) or UniversalAOI (full = the whole world is the view)
$useFull = $worldTypeRaw === 'full';
$entityManager = new SimpleEntityManager();
$aoi = $useFull ? new UniversalAOI($entityManager) : new GridAOI(10);
$world = new World($entityManager, new SimpleActorSystem(), $aoi, new SimpleEventBus(50000), new RegionScheduler(), $useFull ? WorldType::FULL_BROADCAST : WorldType::AOI);

$server = new WorkermanWebSocketServer(
    sprintf('websocket://0.0.0.0:%d', $port),
    authTimeoutSeconds: 10,
    scanIntervalSeconds: 2,
    errorSerializer: new JsonBatchSerializer(),
);

$timer = new WorkermanTimer();
$clock = new WorkermanClock($timer, 0.05);

// 世界 tick 与帧末批量发送由 GameServer::onStart 编排（继承自 RealtimeServer 的 flushOutbox）
// The world tick and frame-end batch flush are orchestrated by GameServer::onStart (flushOutbox inherited from RealtimeServer)
$game = new GameServer($server, new JsonBatchSerializer(), $world, $npcSeeds, $clock, $timer);
$game->register();

// 单实例锁键（G-5，同 demo run-worker）：每频道显式 pidFile——同一 worker 脚本的多个频道实例锁互不冲突
// （缺省按 start_file 生成会导致「第二个频道 always already running」）；启动前清理陈旧 pidfile
// （记录的 pid 已死 = 锁已释放，崩溃重启恢复不被陈旧单实例锁（pidFile + .lock）阻碍）。
// Singleton lock key (G-5, same as the demo run-worker): an explicit per-channel pidFile — instances of the
// same worker script across channels never clash (the start_file-derived default would make every second
// channel report "already running"); stale pidfiles are cleaned at boot (the recorded pid is dead = the lock
// is free, so crash-restart recovery is never blocked by a stale singleton lock).
$pidFile = sprintf('%s/nythros-skeleton-%s#%s.pid', sys_get_temp_dir(), $mapId, $channelId);
$stalePid = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;
if ($stalePid > 0) {
    $alive = function_exists('posix_kill') && @posix_kill($stalePid, 0);
    if (!$alive) {
        @unlink($pidFile);
        @unlink($pidFile . '.lock');
    }
}
Worker::$pidFile = $pidFile;

// Workerman 的 parseCommand 会扫描 $argv 找 start/stop 命令：本脚本的自定义参数（--mapId=...）不在命令集内，
// 会打印 usage 并退出——消费完自定义参数后注入显式 start 命令（demo run-worker 同款做法）。
// Workerman's parseCommand scans $argv for start/stop commands: this script's custom flags are outside the
// command set (usage would be printed and exit) — consume the custom flags, then inject an explicit start.
$GLOBALS['argv'] = [$argv[0], 'start'];

echo sprintf("[map-worker] %s#%s starting on :%d (worldType=%s, npc=%d)
", $mapId, $channelId, $port, $worldTypeRaw, count($npcSeeds));
$server->start();
