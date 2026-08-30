<?php

declare(strict_types=1);

// 定位：packages/demo/bin/run-worker.php — 配置驱动部署的单服务 Worker 启动脚本（组 9，ADR-013 决策 C）。
// 按 --service 组装一个服务实例的全部依赖（Redis token/注册表工厂、WebSocket 服务）并阻塞运行；
// 由 bin/server（或 launch.php）解析 deploy.yaml 后逐 service spawn（进程归属由拓扑声明，本脚本不感知「合并/拆开」）。
// 服务类型：map（战斗层频道，MapChannelFactory 组装）/ gateway|chat|team（社交层三角色，ADR-021 自研单栈，
// 共用 SocialServer 类、各角色连接表独立对称直连；chat/team 对外地址经 NYTHROS_CHAT_ADDRESS/NYTHROS_TEAM_ADDRESS 注入）。
// Located at: packages/demo/bin/run-worker.php — the per-service worker bootstrap of the config-driven deployment (group 9, ADR-013 decision C).
// Assembles every dependency of one service instance per --service (Redis token/registry factories, the WebSocket server) and runs blocking;
// bin/server (or launch.php) parses deploy.yaml and spawns one instance of this script per service (process affiliation comes from the topology
// declaration — this script is unaware of merge/split). Service types: map (a combat-tier channel via MapChannelFactory) and
// gateway|chat|team (the three social-tier roles of ADR-021's self-built single stack, sharing the SocialServer class with independent
// per-role connection tables over symmetric direct connections; the chat/team public addresses are injected via NYTHROS_CHAT_ADDRESS/NYTHROS_TEAM_ADDRESS).
//
// 插件装配（修复 debt MINOR-5）：Skill/Item/Buff 插件经 PluginRegistry::load 走 ADR-017 §6 生命周期
// （register 把 SkillRepository/ItemRepository/BuffRepository 注册进 Container），组装时经
// $container->get('skill.repository') 等取用注入 CombatService；数据定义（技能/物品）仍在脚本侧注册进仓库。
// Plugin assembly (fixes debt MINOR-5): the Skill/Item/Buff plugins go through the PluginRegistry::load ADR-017 §6
// lifecycle (register puts SkillRepository/ItemRepository/BuffRepository into the Container); the assembly resolves them
// via $container->get('skill.repository') etc. and injects them into CombatService; data definitions (skills/items)
// are still registered into the repositories on the script side.

require __DIR__ . '/../../../vendor/autoload.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\UniversalAOI;
use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Contracts\WorldType;
use Nythros\Demo\MapChannelFactory;
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Demo\SocialServer;
use Nythros\Demo\StaticAuthenticator;
use Nythros\Demo\WorkermanHubTransport;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Auth\ThrottledAuthenticator;
use Nythros\Framework\Leaderboard\RedisLeaderboardStore;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Social\GuildStore;
use Nythros\Framework\Social\HubTransportInterface;
use Nythros\Framework\Social\InMemoryConnectionHub;
use Nythros\Framework\Social\LocationStore;
use Nythros\Framework\Social\RedisFriendStore;
use Nythros\Framework\Social\RedisTeamStore;
use Nythros\Framework\Social\SocialService;
use Nythros\Network\SimpleTokenBucket;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\MsgpackSerializer;
use Nythros\Protocol\ProtobufSerializer;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\RedisTokenStore;
use Nythros\Security\TokenManager;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use Workerman\Worker;

/**
 * 解析并校验 CLI 参数（参数非法 = 配置错误：stderr 归因 + exit(1)，不进入服务组装）。
 * Parses and validates CLI arguments (illegal arguments = configuration error: stderr attribution + exit(1), never reaching service assembly).
 *
 * @param list<string> $argv 原始 argv Raw argv.
 * @return array{service: string, port: int, mapId: ?string, channelId: ?string, worldType: \Nythros\Contracts\WorldType, redisHost: string, redisPort: int, pidFile: string, mysqlHost: string, mysqlPort: int, mysqlUser: string, mysqlPass: string, mysqlDb: string} 校验后的选项 Validated options.
 */
function parseWorkerOptions(array $argv): array
{
    $options = getopt('', ['service:', 'port:', 'mapId:', 'channelId:', 'worldType:', 'redisHost:', 'redisPort:', 'pidFile:', 'mysqlHost:', 'mysqlPort:', 'mysqlUser:', 'mysqlPass:', 'mysqlDb:']);

    $fail = static function (string $message): never {
        fwrite(STDERR, sprintf("[run-worker] fatal: %s\n", $message));
        exit(1);
    };

    $service = $options['service'] ?? null;
    if (!is_string($service) || !in_array($service, ['map', 'gateway', 'chat', 'team'], true)) {
        $fail('--service 必须是 map|gateway|chat|team 之一');
    }

    $port = isset($options['port']) && is_string($options['port']) ? (int) $options['port'] : null;
    if ($port === null || $port < 1 || $port > 65535) {
        $fail('--port 必须是 1~65535 的整数');
    }

    // pidFile（G-5）：Workerman 5 按 pidFile 做单实例锁（同 start_file 同 pidFile 的第二个实例 "already running" 即退），
    // 缺省按 type+port 生成独立 pidFile——每个服务实例唯一，崩溃重启恢复不再被陈旧单实例锁挡住
    //（deploy.yaml 可显式声明 pidFile 覆盖本缺省）。
    // pidFile (G-5): Workerman 5 enforces the singleton lock by pidFile (a second instance sharing the same pidFile exits "already running");
    // the default generates a distinct pidFile per type+port — unique per service instance, so crash-restart recovery is never blocked
    // by a stale singleton lock (deploy.yaml may declare pidFile explicitly to override this default).
    if (isset($options['pidFile'])) {
        if (!is_string($options['pidFile']) || $options['pidFile'] === '') {
            $fail('--pidFile 必须是非空路径字符串');
        }
        $pidFile = $options['pidFile'];
    } else {
        $pidFile = sprintf('%s/nythros-%s-%d.pid', sys_get_temp_dir(), $service, $port);
    }

    $mapId = isset($options['mapId']) && is_string($options['mapId']) && $options['mapId'] !== '' ? $options['mapId'] : null;
    $channelId = isset($options['channelId']) && is_string($options['channelId']) && $options['channelId'] !== '' ? $options['channelId'] : null;

    // mapId/channelId 仅 map 服务必填（serviceId = {mapId}#{channelId}）；社交三角色无需
    // mapId/channelId are required for map services only (serviceId = {mapId}#{channelId}); the social roles need none
    if ($service === 'map' && ($mapId === null || $channelId === null)) {
        $fail('map 服务必须提供 --mapId 与 --channelId（serviceId = {mapId}#{channelId}）');
    }

    // 世界类型（阶段 2a）：map 频道可用 AOI（主城/野外）或 FULL_BROADCAST（副本/竞技场）；
    // 全量型 World 无空间索引，注入 UniversalAOI（全量 = 全世界即视野），广播/移动/攻击距离/清理自动走全量语义。
    // World type (2a): a map channel runs either AOI (town/wild) or FULL_BROADCAST (dungeon/arena); a full-broadcast
    // World has no spatial index — it injects a UniversalAOI (full = the whole world is the view), so
    // broadcast/move/range/cleanup naturally follow full semantics.
    $worldTypeRaw = isset($options['worldType']) && is_string($options['worldType']) ? $options['worldType'] : 'aoi';
    $worldType = match ($worldTypeRaw) {
        'aoi' => \Nythros\Contracts\WorldType::AOI,
        'full' => \Nythros\Contracts\WorldType::FULL_BROADCAST,
        default => $fail('--worldType 必须是 aoi 或 full'),
    };

    $redisHost = isset($options['redisHost']) && is_string($options['redisHost']) && $options['redisHost'] !== '' ? $options['redisHost'] : '127.0.0.1';
    $redisPort = isset($options['redisPort']) && is_string($options['redisPort']) ? (int) $options['redisPort'] : 6379;
    if ($redisPort < 1 || $redisPort > 65535) {
        $fail('--redisPort 必须是 1~65535 的整数');
    }

    // MySQL 归档连接参数（A-1 落库断链修复）：缺省 127.0.0.1:3306 / root / 空密码 / nythros 库，
    // 与 deploy.yaml 的 mysql 段缺省一致（launch.php 透传覆盖）。
    // MySQL archive connection parameters (A-1 persistence-chain fix): defaults 127.0.0.1:3306 / root / empty password / the nythros
    // database, matching the deploy.yaml mysql-section defaults (overridden by launch.php passthrough).
    $mysqlHost = isset($options['mysqlHost']) && is_string($options['mysqlHost']) && $options['mysqlHost'] !== '' ? $options['mysqlHost'] : '127.0.0.1';
    $mysqlPort = isset($options['mysqlPort']) && is_string($options['mysqlPort']) ? (int) $options['mysqlPort'] : 3306;
    if ($mysqlPort < 1 || $mysqlPort > 65535) {
        $fail('--mysqlPort 必须是 1~65535 的整数');
    }
    $mysqlUser = isset($options['mysqlUser']) && is_string($options['mysqlUser']) ? $options['mysqlUser'] : 'root';
    $mysqlPass = isset($options['mysqlPass']) && is_string($options['mysqlPass']) ? $options['mysqlPass'] : '';
    $mysqlDb = isset($options['mysqlDb']) && is_string($options['mysqlDb']) && $options['mysqlDb'] !== '' ? $options['mysqlDb'] : 'nythros';

    return [
        'service' => $service,
        'port' => $port,
        'mapId' => $mapId,
        'channelId' => $channelId,
        'worldType' => $worldType,
        'redisHost' => $redisHost,
        'redisPort' => $redisPort,
        'pidFile' => $pidFile,
        'mysqlHost' => $mysqlHost,
        'mysqlPort' => $mysqlPort,
        'mysqlUser' => $mysqlUser,
        'mysqlPass' => $mysqlPass,
        'mysqlDb' => $mysqlDb,
    ];
}

/** @var array{service: string, port: int, mapId: ?string, channelId: ?string, worldType: \Nythros\Contracts\WorldType, redisHost: string, redisPort: int, pidFile: string, mysqlHost: string, mysqlPort: int, mysqlUser: string, mysqlPass: string, mysqlDb: string} $options 校验后的选项 Validated options. */
$options = parseWorkerOptions($argv);

// Workerman 的 parseCommand 会扫描 $argv 寻找 start/stop 等命令：本脚本的自定义参数（--service=...）不在命令集内，
// 找不到命令时 Workerman 打印 usage 并退出。自定义参数已消费完毕，将其从 $argv 移除并注入显式 start 命令。
// Workerman's parseCommand scans $argv for start/stop commands: this script's custom flags (--service=...) are outside the
// command set, and with no command found Workerman prints usage and exits. The custom flags are consumed — remove them from $argv and inject an explicit start command.
$GLOBALS['argv'] = [$argv[0], 'start'];

// pidFile 注入（G-5）：必须在 runAll 前设置（parseCommand 据此做单实例锁判定与 pid 落盘），
// 缺省按 type+port 生成使每个服务实例的锁互不冲突。
// pidFile injection (G-5): must be set before runAll (parseCommand uses it for the singleton-lock verdict and the pid write);
// the per-type+port default keeps every service instance's lock independent.
Worker::$pidFile = $options['pidFile'];

// stdout 无缓冲：启动日志即时可见（重定向到文件时 PHP CLI 默认块缓冲，会推迟打印）。
// Unbuffered stdout: startup logs stay immediately visible (PHP CLI block-buffers when redirected to a file, delaying output).
stream_set_write_buffer(STDOUT, 0);

// Redis 连接工厂：lazy 建连（Workerman fork 后各 worker 首次使用时各自建立独立连接；
// 复制已建立的 socket fd 会破坏 Redis 协议——server.php 已验证模式）。
// Redis connection factory: lazily connected (each forked worker opens its own connection on first use; sharing a duplicated
// socket fd across workers would corrupt the Redis protocol — the pattern verified in server.php).
// 连接失败用 throw 而非 exit(1)：异常向上传播被 WorkermanWebSocketServer 的 catch Throwable 兜底
// （日志 + 500 响应），worker 存活；Redis 恢复后无需重启即恢复服务；exit(1) 会引发 master 重启风暴。
// Connect failures use throw instead of exit(1): the exception propagates up into WorkermanWebSocketServer's catch Throwable
// fallback (log + 500 response), so the worker survives and recovers once Redis returns — no restart needed; exit(1) would make
// the master restart the worker forever (restart storm).
$redisFactory = static function () use ($options): \Redis {
    $redis = new \Redis();
    try {
        $connected = @$redis->connect($options['redisHost'], $options['redisPort'], 1.0);
    } catch (\Throwable) {
        $connected = false;
    }
    if ($connected !== true) {
        throw new \RuntimeException(sprintf(
            '[run-worker] fatal: 无法连接 Redis %s:%d，跨进程共享状态不可用，请求返回 500',
            $options['redisHost'],
            $options['redisPort'],
        ));
    }

    // 生产 Redis 认证与库选择（ADR-027）：NYTHROS_REDIS_PASSWORD / NYTHROS_REDIS_DB 注入。
    // 认证失败由上层 500 兜底（与建连失败同口径），worker 存活、Redis 恢复（含凭证修正）后自愈。
    // Production Redis auth & db selection (ADR-027): injected via NYTHROS_REDIS_PASSWORD / NYTHROS_REDIS_DB.
    // Auth failures fall into the same 500 fallback as connect failures — the worker survives and self-heals
    // once Redis returns (including after credential fixes).
    $redisPassword = getenv('NYTHROS_REDIS_PASSWORD');
    if (is_string($redisPassword) && $redisPassword !== '') {
        @$redis->auth($redisPassword);
    }
    $redisDb = getenv('NYTHROS_REDIS_DB');
    if (is_string($redisDb) && $redisDb !== '' && preg_match('/^\d+$/', $redisDb) === 1) {
        @$redis->select((int) $redisDb);
    }

    return $redis;
};

// MySQL 连接工厂：lazy 建连（与 Redis 工厂同口径——Workerman fork 后各 worker 首次使用时各自建立独立连接，
// 复制已建立的 socket fd 会破坏 MySQL 协议）。建连失败抛异常：被 ArchivePipeline 的存储契约捕获
// （save 返回 false / saveBatch 返回失败 id），worker 存活，MySQL 恢复后自愈。
// MySQL connection factory: lazily connected (same convention as the Redis factory — each forked worker opens its
// own connection on first use; a duplicated socket fd would corrupt the MySQL protocol). Connect failures throw:
// the exception is caught by ArchivePipeline's storage contract (save returns false / saveBatch returns the
// failed-id list), so the worker survives and self-heals once MySQL returns.
$pdoFactory = static function () use ($options): \PDO {
    return new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $options['mysqlHost'], $options['mysqlPort'], $options['mysqlDb']),
        $options['mysqlUser'],
        $options['mysqlPass'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );
};

// 共享组装：token（多 scope 跨进程）与服务注册表（discover/bind/resolve）。
// Shared assembly: tokens (multi-scope, cross-process) and the service registry (discover/bind/resolve).
$tokenManager = new TokenManager(new RedisTokenStore($redisFactory));
$serviceRegistry = new RedisServiceRegistry($redisFactory);

// 本实例 serviceId：map = {mapId}#{channelId}；社交三角色无注册表身份（连接表进程内自治）
// This instance's serviceId: map = {mapId}#{channelId}; the social roles carry no registry identity (their connection tables are process-local)
$serviceId = sprintf('%s#%s', (string) $options['mapId'], (string) $options['channelId']);
// 序列化器分流（ADR-022 双轨制装配点，热路径扩展点 architecture.md §5）：map 频道恒走 MapCodec 二进制批量；
// 社交层单条序列化器按环境变量 NYTHROS_SERIALIZER 选择——json（缺省）= JsonBatchSerializer 既有行为；
// msgpack = MsgpackSerializer 单条 + 同格式 msgpack 批量容器；protobuf = ProtobufSerializer 单条 + 批量容器
// （容器契约见下方适配闭包）；未知取值启动即失败。
// Serializer split (the ADR-022 dual-track assembly point, hot-path extension point of architecture.md §5): map channels
// always use MapCodec's binary batches; the social tier picks its single serializer via the NYTHROS_SERIALIZER env var —
// json (default) keeps the existing JsonBatchSerializer behavior; msgpack wires a MsgpackSerializer single with a
// same-format msgpack batch container; protobuf wires a ProtobufSerializer single with a batch container (see the
// adapter closure below for the container contract); unknown values fail fast.
if ($options['service'] === 'map') {
    $serializer = MapCodec::create();
} else {
    $serializerChoice = getenv('NYTHROS_SERIALIZER') ?: 'json';
    if ($serializerChoice === 'json') {
        $serializer = new JsonBatchSerializer();
    } elseif ($serializerChoice === 'msgpack') {
        $msgpack = new MsgpackSerializer();
        $serializer = new JsonBatchSerializer($msgpack, $msgpack->pack(...), $msgpack->unpack(...));
    } elseif ($serializerChoice === 'protobuf') {
        // protobuf 轨的批量容器适配：JsonBatchSerializer 的容器契约要求 pack(帧形关联数组) 的产物可被单条序列化器
        // 解码——msgpack 的顶层 map 恰好就是信封形状，而 protobuf 的信封（NythrosMessage）与通用值树（Value）
        // 是两个消息。故在装配层分流：帧形数组（含 type 键的关联数组，即 encodeBatch 的元素）显式编码为信封字节；
        // 其余值（批量容器 list 本身）走 Value 树。解码侧 unpack 对两种形状天然对称（Value 树还原容器）。
        // The protobuf track's batch-container adapter: JsonBatchSerializer's container contract requires
        // pack(frame-shaped assoc array) to yield bytes the single serializer can decode — a msgpack top-level map
        // happens to be the envelope shape, while protobuf keeps the envelope (NythrosMessage) and the generic value
        // tree (Value) as two distinct messages. The assembly layer therefore splits: frame-shaped arrays (assoc
        // arrays carrying a type key, i.e. encodeBatch's elements) encode explicitly as envelope bytes; everything
        // else (the batch container list itself) rides the Value tree. Decoding stays symmetric via unpack (a Value
        // tree restores the container).
        $protobuf = new ProtobufSerializer();
        $packContainerValue = static function (mixed $value) use ($protobuf): string {
            if (is_array($value) && !array_is_list($value) && array_key_exists('type', $value)) {
                $type = $value['type'];
                $requestId = $value['requestId'] ?? null;
                $timestamp = $value['timestamp'] ?? null;
                $payload = $value['payload'] ?? [];

                return $protobuf->encode(new Message(
                    is_string($type) ? $type : '',
                    is_string($requestId) ? $requestId : null,
                    is_int($timestamp) || is_float($timestamp) ? (float) $timestamp : 0.0,
                    is_array($payload) ? $payload : [],
                ))->bytes();
            }

            return $protobuf->pack($value);
        };
        // 判定规则：decode 内部对字段 1 (type) 做存在性+非空校验，等效"显式检查 type 字段存在性"——
        // 有 type（decode 成功）→ 信封（关联数组，供 JsonBatchSerializer 单帧分支）；
        // 缺 type（DecodeException）→ Value 树（批量容器的正常形态）。
        // Decoding rule: decode validates field-1 (type) presence and non-empty, equivalent to "explicit type-field check" —
        // type present (decode succeeds) → envelope (assoc array, fed to JsonBatchSerializer single-frame branch);
        // type absent (DecodeException) → Value tree (normal batch-container shape).
        $unpackContainerValue = static function (string $bytes) use ($protobuf): mixed {
            try {
                $message = $protobuf->decode(new Frame($bytes));

                return [
                    'type' => $message->type,
                    'requestId' => $message->requestId,
                    'timestamp' => $message->timestamp,
                    'payload' => $message->payload,
                ];
            } catch (\Nythros\Protocol\DecodeException) {
                return $protobuf->unpack($bytes);
            }
        };
        $serializer = new JsonBatchSerializer($protobuf, $packContainerValue, $unpackContainerValue);
    } else {
        fwrite(STDERR, sprintf("[run-worker] fatal: 非法 NYTHROS_SERIALIZER \"%s\"（期望 json|msgpack|protobuf）\n", $serializerChoice));
        exit(1);
    }
}

// WebSocket 服务公共构造：认证超时 10 秒、每 2 秒扫描、默认启用限流（10 tokens/秒、容量 20）——与 server.php 一致。
// Common WebSocket server construction: 10s auth timeout, 2s scan interval, rate limiting enabled by default (10 tokens/s, capacity 20) — same as server.php.
$server = new WorkermanWebSocketServer(
    sprintf('websocket://0.0.0.0:%d', $options['port']),
    authTimeoutSeconds: 10,
    scanIntervalSeconds: 2,
    rateLimiter: new SimpleTokenBucket(refillPerSecond: 10.0, capacity: 20),
    errorSerializer: $serializer,
);

// 连接/断开控制台回显 + 慢客户端告警：便于观察客户端生命周期（检测不主动断开）。
// Connect/close console echoes + slow-client alerts: client lifecycles stay observable (detect but never disconnect).
$server->onConnect(static function ($conn) use ($options): void {
    echo sprintf('[%s] connect: %s from %s', $options['service'], $conn->getId(), $conn->getRemoteAddress()) . PHP_EOL;
});
$server->onClose(static function ($conn) use ($options): void {
    echo sprintf('[%s] close: %s', $options['service'], $conn->getId()) . PHP_EOL;
});
$server->onSlowClient(static function ($conn) use ($options): void {
    static $slowCount = 0;
    $slowCount++;
    error_log(sprintf('[%s] slow client detected: conn [%s] (slow-count=%d)', $options['service'], $conn->getId(), $slowCount));
});

// 按服务类型组装并启动（start() = register 处理器 + Workerman runAll 阻塞事件循环）。
// Assemble per service type and start (start() = register handlers + the blocking Workerman runAll event loop).
switch ($options['service']) {
    case 'map':
        // Map：一频道一进程一 World —— 频道组装（World/插件/MapServer/schema/spawn/性能采样）抽至
        // MapChannelFactory，供外部逐频道入口与原生多频道入口（bin/start-maps.php）共用同一组装；
        // master 期组装 → fork 后 worker 进程继承状态（runAll 前 register 追加处理器，语义与既往一致）。
        // Map: one process + one World per channel — the channel assembly (World/plugins/MapServer/schema/spawn/perf)
        // is extracted into MapChannelFactory, shared with the native multi-channel entry (bin/start-maps.php);
        // assembled in the master process then inherited by the worker after fork (handlers appended before runAll).
        MapChannelFactory::attachChannel(
            $server,
            (string) $options['mapId'],
            (string) $options['channelId'],
            $options['worldType'] === \Nythros\Contracts\WorldType::FULL_BROADCAST ? 'full' : 'aoi',
            $options['port'],
            $serializer,
            $tokenManager,
            $serviceRegistry,
            $redisFactory,
            $pdoFactory,
        );

        echo sprintf('[run-worker] map starting on %d (serviceId=%s)...', $options['port'], $serviceId) . PHP_EOL;
        $server->start();
        break;
    case 'gateway':
    case 'chat':
    case 'team':
        // 社交三角色（ADR-021 自研单栈）：共用 SocialServer 类、各角色连接表独立（对称直连）。
        // 装配：空 World（骨架构造依赖，社交层无实体/AOI 消费）+ ConnectionRegistry + Workerman 传输绑定
        // （WorkermanHubTransport 落地 HubTransportInterface）+ InMemoryConnectionHub + SocialService 全家桶
        // （StaticAuthenticator/RedisTeamStore/GuildStore/LocationStore/TokenManager）。
        // chat/team 对外地址经 NYTHROS_CHAT_ADDRESS/NYTHROS_TEAM_ADDRESS 注入（bin/server 按 deploy.yaml 服务声明生成），
        // auth_ok 据此下发 map/chat/team 三地址；map 地址仍走注册表 discover 动态分配。
        // The three social roles (ADR-021 self-built single stack): they share the SocialServer class with independent
        // per-role connection tables (symmetric direct connections). Assembly: an empty World (a skeleton constructor
        // dependency — the social tier consumes no entities/AOI) + ConnectionRegistry + the Workerman transport binding
        // (WorkermanHubTransport implements HubTransportInterface) + InMemoryConnectionHub + the full SocialService stack
        // (StaticAuthenticator/RedisTeamStore/GuildStore/LocationStore/TokenManager). The chat/team public addresses are
        // injected via NYTHROS_CHAT_ADDRESS/NYTHROS_TEAM_ADDRESS (generated by bin/server from deploy.yaml's service
        // declarations), so auth_ok hands out all three map/chat/team addresses; the map address still comes from the
        // registry's dynamic discover.
        $entityManager = new SimpleEntityManager();
        $world = new World($entityManager, new SimpleActorSystem(), new UniversalAOI($entityManager), new SimpleEventBus(), new RegionScheduler(totalBudgetMs: 6.0), WorldType::AOI);

        $transport = new WorkermanHubTransport($server);
        $hub = new InMemoryConnectionHub($transport);

        // 账号表双形态（生产口径见 docs/security.md §5）：
        //   NYTHROS_ACCOUNTS_FILE：PHP 文件返回 [uid => password_hash(...)]（生产形态——明文不进 env/进程列表）；
        //   NYTHROS_ACCOUNTS：`uid=password` 明文对，装载即哈希（开发形态；缺省 1001/1002/1003 密码 secret）。
        // Account table in two forms (production conventions in docs/security.md §5):
        //   NYTHROS_ACCOUNTS_FILE: a PHP file returning [uid => password_hash(...)] (production — plaintext never enters env/process lists);
        //   NYTHROS_ACCOUNTS: `uid=password` plaintext pairs, hashed on load (development; defaults 1001/1002/1003 with password secret).
        $accountsFile = getenv('NYTHROS_ACCOUNTS_FILE');
        $accounts = [];
        if (is_string($accountsFile) && $accountsFile !== '') {
            $accounts = require $accountsFile;
            if (!is_array($accounts) || $accounts === []) {
                fwrite(STDERR, "[run-worker] fatal: NYTHROS_ACCOUNTS_FILE 必须返回非空的 [uid => 密码哈希] 数组\n");
                exit(1);
            }
        } else {
            foreach (explode(',', getenv('NYTHROS_ACCOUNTS') ?: '1001=secret,1002=secret,1003=secret') as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $parts = explode('=', $pair, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    fwrite(STDERR, sprintf("[run-worker] fatal: 非法账号对 \"%s\"（期望 uid=password）\n", $pair));
                    exit(1);
                }
                $accounts[$parts[0]] = password_hash($parts[1], PASSWORD_DEFAULT);
            }
        }

        // 防爆破包装：连续失败 NYTHROS_AUTH_MAX_ATTEMPTS 次（缺省 5）锁定 NYTHROS_AUTH_LOCKOUT_SECONDS 秒
        // （缺省 60）。gateway 单进程内计数即完整语义；多网关实例时全局上限按实例数放大，生产可调低阈值。
        // Brute-force wrapper: consecutive failures beyond NYTHROS_AUTH_MAX_ATTEMPTS (default 5) lock the username
        // for NYTHROS_AUTH_LOCKOUT_SECONDS (default 60). The gateway is single-process so in-process counting is
        // complete; with multiple gateway instances the global cap scales by instance count — lower the threshold for production.
        $authenticator = new ThrottledAuthenticator(
            new StaticAuthenticator($accounts),
            maxAttempts: (int) (getenv('NYTHROS_AUTH_MAX_ATTEMPTS') ?: 5),
            lockoutSeconds: (int) (getenv('NYTHROS_AUTH_LOCKOUT_SECONDS') ?: 60),
        );

        // 地图白名单：NYTHROS_MAP_IDS 环境变量（逗号分隔，bin/server 按 deploy.yaml mapId 生成注入）。
        // 以 getenv 存在性判断区分「未设置」与「空串」：未设置才回退缺省 map-1,map-2（便于脱离 bin/server
        // 手动起社交角色）；空串 = 显式空白名单（deploy.yaml 未声明任何 map），不回退、拒绝全部 mapId。
        // Map whitelist: the NYTHROS_MAP_IDS env var (comma-separated, generated by bin/server from deploy.yaml's mapIds).
        // Existence is checked via getenv to tell "unset" from "empty": only unset falls back to the default map-1,map-2
        // (handy when starting a social role manually without bin/server); an empty string is an explicit empty whitelist
        // (no map declared in deploy.yaml) — no fallback, every mapId is rejected.
        $mapIdsEnv = getenv('NYTHROS_MAP_IDS');
        $mapIds = array_values(array_filter(array_map('trim', explode(',', $mapIdsEnv === false ? 'map-1,map-2' : $mapIdsEnv))));

        $social = new SocialService(
            $hub,
            $tokenManager,
            $serviceRegistry,
            $authenticator,
            new LocationStore($redisFactory),
            new GuildStore($redisFactory),
            new RedisTeamStore($redisFactory),
            new \Nythros\Protocol\JsonSerializer(),
            $mapIds,
            [
                'chat' => getenv('NYTHROS_CHAT_ADDRESS') ?: '',
                'team' => getenv('NYTHROS_TEAM_ADDRESS') ?: '',
            ],
            // 好友关系存储（R3 社交批）：Redis 持久，nythros:gw:friend:* 键族
            // The friend-relationship store (the R3 social batch): Redis-backed, the nythros:gw:friend:* key family
            new RedisFriendStore($redisFactory),
            // 协议版本守卫（版本协商，ADR-027）：最低客户端版本，未设置 = 不启用（存量客户端零影响）
            // The protocol-version guard (version negotiation, ADR-027): the minimum client version; unset = off
            is_string(getenv('NYTHROS_MIN_CLIENT_VERSION')) && preg_match('/^\d+$/', trim((string) getenv('NYTHROS_MIN_CLIENT_VERSION'))) === 1
                ? (int) trim((string) getenv('NYTHROS_MIN_CLIENT_VERSION'))
                : null,
        );

        $socialServer = new SocialServer(
            $server,
            $serializer,
            $world,
            new ConnectionRegistry(),
            $social,
            $hub,
            // token 消费 scope（ADR-021 §3.2 多 scope 兑现）：chat/team 角色消费各自 scope 的 token 登录；
            // gateway 角色传 null = 不启用 token 路径，auth 帧一律完整握手签发新 token
            // Token-consume scope (fulfilling ADR-021 §3.2's multi-scope promise): the chat/team roles consume their own
            // scope for token login; the gateway role passes null = the token path is disabled, every auth frame takes
            // the full handshake issuing a fresh token
            match ($options['service']) {
                'chat' => 'chat',
                'team' => 'team',
                default => null,
            },
            // 排行榜存储（R3 社交批）：Redis ZSet，leaderboard:top/rank 查询帧就地应答
            // The leaderboard store (the R3 social batch): a Redis ZSet answering leaderboard:top/rank query frames in place
            new RedisLeaderboardStore($redisFactory),
        );

        echo sprintf('[run-worker] %s starting on %d...', $options['service'], $options['port']) . PHP_EOL;
        $socialServer->start();
        break;
    default:
        // 不可达：parseWorkerOptions 已白名单校验
        // Unreachable: parseWorkerOptions already whitelisted
        throw new \LogicException(sprintf('未知服务类型: %s', $options['service']));
}
