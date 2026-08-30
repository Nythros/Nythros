<?php

declare(strict_types=1);

// 定位：benchmarks/demo-bench.php — demo 装配层基准（一次性离线执行；不启动 Workerman 事件循环）。
// 覆盖：FrameMerger 批量入队+排空吞吐（含状态去重）、MapServer 指令处理（auth/move 消息直调、
// 用 stub Server/TokenManager 装配，不触发真实网络）、二进制批量包在 MapServer 全链路的帧间耗时。
// Located at: benchmarks/demo-bench.php — demo assembly-layer benchmark (one-shot offline; no Workerman loop).
// Covers: FrameMerger batch enqueue+drain throughput (with STATE dedup), MapServer command handling (auth/move
// messages invoked directly with a stubbed Server/TokenManager, no real network), and the full binary batch path.

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Demo\MapServer;
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\FrameMerger;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenManagerInterface;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

$hrtime = static function (callable $fn, int $iters): float {
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }
    return (hrtime(true) - $t0) / 1e6;
};

echo "== demo 装配层基准 Demo Benchmarks ==" . PHP_EOL;

// ── 1. FrameMerger 批量入队 + 排空 ──
// 1. FrameMerger batch enqueue + drain
echo PHP_EOL . "[1] FrameMerger" . PHP_EOL;
$serializer = MapCodec::create();
$merger = new FrameMerger($serializer);
/** @var list<ConnectionInterface> $conns */
$conns = [];
for ($i = 0; $i < 8; $i++) {
    $c = new class ($i) implements ConnectionInterface {
        public function __construct(private readonly int $n)
        {
        }
        public function getId(): string
        {
            return 'conn-' . $this->n;
        }
        public function getRemoteAddress(): string
        {
            return '127.0.0.1:1';
        }
        public function send(string $payload): void
        {
        }
        public function sendBatch(array $payloads): void
        {
        }
        public function getSendBufferQueueSize(): int
        {
            return 0;
        }
        public function close(): void
        {
        }
        public function isClosed(): bool
        {
            return false;
        }
        public function getLastMessageTime(): float
        {
            return microtime(true);
        }
        public function markAuthenticated(): void
        {
        }
        public function isAuthenticated(): bool
        {
            return true;
        }
        public function markInternal(): void
        {
        }
        public function isInternal(): bool
        {
            return false;
        }
        public function onBufferFull(callable $handler): void
        {
        }
        public function onBufferDrain(callable $handler): void
        {
        }
    };
    $conns[] = $c;
}

// 入队吞吐：8 连接 × 20 事件帧/次 Enqueue throughput: 8 conns × 20 event frames per pass
$ms = $hrtime(static function () use ($merger, $conns): void {
    foreach ($conns as $i => $c) {
        for ($j = 0; $j < 20; $j++) {
            $merger->enqueue($c, 'entity_moved', ['id' => 'e' . $j, 'position' => ['x' => $j, 'y' => 0]]);
        }
    }
}, 2000);
printf("  enqueue 160帧/次: %.0f 帧/s\n", 2000 * 160 / $ms * 1000);

// 排空吞吐 Drain throughput
$ms = $hrtime(static function () use ($merger, $conns): void {
    foreach ($conns as $i => $c) {
        for ($j = 0; $j < 20; $j++) {
            $merger->enqueue($c, 'entity_moved', ['id' => 'e' . $j, 'position' => ['x' => $j, 'y' => 0]]);
        }
    }
    $drained = $merger->drain(1024 * 1024);
    if ($drained === []) {
        throw new \RuntimeException('unexpected');
    }
}, 1000);
$drainedCount = 1000 * 8 * 20;
printf("  入队+排空 160帧/次: %.0f 帧/s (%d 个批量包/次)\n", $drainedCount / $ms * 1000, 8);

// ── 2. MapServer 认证 + 移动消息处理（stub 装配，直调 dispatchSafe 路径）──
// 2. MapServer auth + move handling (stubbed assembly, direct dispatchSafe path)
echo PHP_EOL . "[2] MapServer 消息处理" . PHP_EOL;
$tokens = new class () implements TokenManagerInterface {
    public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string
    {
        return str_repeat('a', 64);
    }
    public function consume(string $token, string $scope): \Nythros\Security\TokenStatus
    {
        return \Nythros\Security\TokenStatus::Valid;
    }
    public function peek(string $token): ?\Nythros\Security\TokenRecord
    {
        return null;
    }
};
$world = new World(new SimpleEntityManager(), new SimpleActorSystem(), new GridAOI(10), new SimpleEventBus(50000), new RegionScheduler(100.0));
$server = new class () implements ServerInterface {
    /** @var null|callable 最近注册的 onMessage 回调 Latest registered onMessage handler. */
    public $onMessageHandler = null;
    public function onConnect(callable $handler): void
    {
    }
    public function onMessage(callable $handler): void
    {
        $this->onMessageHandler = $handler;
    }
    public function onClose(callable $handler): void
    {
    }
    public function onWorkerStart(callable $handler): void
    {
    }
    public function onWorkerStop(callable $handler): void
    {
    }
    public function onSlowClient(callable $handler): void
    {
    }
    public function start(): void
    {
    }
    public function stop(): void
    {
    }
};
$map = new MapServer(
    $server,
    MapCodec::create(),
    $tokens,
    $world,
    new ConnectionRegistry(),
    serviceId: 'map-1#ch-1',
    mapId: 'map-1',
    typeIndex: new \Nythros\Framework\Combat\EntityTypeIndex(),
    skills: new \Nythros\Framework\Plugin\Skill\SkillRepository(),
    random: new \Nythros\Framework\Combat\SystemRandomSource(),
);

$conn = $conns[0];
$authFrame = $serializer->encodeBatch([\Nythros\Protocol\Message::create('auth', ['token' => 't'], 'r1')]);
$moveFrame = $serializer->encodeBatch([\Nythros\Protocol\Message::create('move', ['dx' => 10, 'dy' => 0], 'r2')]);

// 认证处理（TokenManager stub 恒 Ok → auth_ok/Mounted；经 server 注册的 onMessage 回调直调，测真实分发路径）
/** @var callable $dispatch 服务器注册的 onMessage 回调（内部走 dispatchSafe） The server-registered onMessage handler (routes into dispatchSafe). */
// register() 注册 onMessage/onConnect 等处理器（等价 MapServer::start() 前半段）
$map->register();
$dispatch = $server->onMessageHandler;
$ms = $hrtime(static fn () => $dispatch($conn, $authFrame), 3000);
printf("  auth 处理: %.0f msgs/s\n", 3000 / $ms * 1000);
$ms = $hrtime(static fn () => $dispatch($conn, $moveFrame), 3000);
printf("  move 处理: %.0f msgs/s\n", 3000 / $ms * 1000);

echo PHP_EOL . "== demo 基准完成 ==" . PHP_EOL;
