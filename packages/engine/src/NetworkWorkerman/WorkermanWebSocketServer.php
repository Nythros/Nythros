<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman;

use Nythros\Contracts\TimerInterface;
use Nythros\KernelWorkerman\WorkermanTimer;
use Nythros\Network\RateLimiterInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\BatchSerializerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

/**
 * 基于 Workerman 的 WebSocket 服务器：内置连接管理、限流、认证超时扫描与慢客户端检测。
 * Workerman-based WebSocket server: built-in connection management, rate limiting, auth-timeout scan, and slow-client detection.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class WorkermanWebSocketServer implements ServerInterface
{
    /** @var Worker Workerman Worker 实例（事件循环宿主） Workerman Worker instance (event-loop host). */
    private readonly Worker $worker;

    /** @var ConnectionManager 连接管理器（元数据与超时扫描） Connection manager (metadata and timeout scan). */
    private readonly ConnectionManager $manager;

    /** @var null|RateLimiterInterface 限流器；null 表示不启用 Rate limiter; null disables it. */
    private readonly ?RateLimiterInterface $rateLimiter;

    /** @var null|int 认证超时秒数；null 表示不扫描 Auth timeout in seconds; null disables the scan. */
    private readonly ?int $authTimeoutSeconds;

    /** @var int 认证超时扫描间隔（秒） Auth-timeout scan interval in seconds. */
    private readonly int $scanIntervalSeconds;

    /** @var null|int 发送缓冲上限；null 表示不启用慢客户端检测 Send buffer ceiling; null disables slow-client detection. */
    private readonly ?int $maxSendBufferSize;

    /** @var int 单帧最大字节数 Max package size in bytes. */
    private readonly int $maxPackageSize;

    /** @var BatchSerializerInterface 错误兜底帧编码器：与正常出站同走批量包编码路径（缺省 JSON 批量） Error-frame fallback encoder: rides the same batch-packet encoding path as normal outbound frames (JSON batch by default). */
    private readonly BatchSerializerInterface $errorSerializer;

    /** @var callable(string): void 错误日志回调（缺省 error_log） Error logger callback (defaults to error_log). */
    private $errorLogger;

    /** @var array<int, WorkermanConnection> 存活连接表（TcpConnection id => 适配器） live connection map (TcpConnection id => adapter). */
    private array $connections = [];

    /** @var array<int, bool> 慢客户端标记表（发送缓冲已写满） slow-client flags (send buffer currently full). */
    private array $slowConnections = [];

    /** @var list<callable(): void> Worker 启动回调队列 worker-start handler queue. */
    private array $onWorkerStartHandlers = [];

    /** @var list<callable(Worker): void> Worker 退出回调队列 worker-stop handler queue. */
    private array $onWorkerStopHandlers = [];

    /** @var list<callable(WorkermanConnection): void> 连接建立回调队列 connect handler queue. */
    private array $onConnectHandlers = [];

    /** @var list<callable(WorkermanConnection, string): void> 消息回调队列 message handler queue. */
    private array $onMessageHandlers = [];

    /** @var list<callable(WorkermanConnection): void> 连接关闭回调队列 close handler queue. */
    private array $onCloseHandlers = [];

    /** @var list<callable(WorkermanConnection): void> 慢客户端回调队列 slow-client handler queue. */
    private array $onSlowClientHandlers = [];

    /**
     * 构造 Workerman WebSocket 服务器。
     * Constructs the Workerman WebSocket server.
     *
     * @param string $listenAddress 监听地址（默认 websocket://0.0.0.0:8080） Listen address (default websocket://0.0.0.0:8080).
     * @param int $workerCount Worker 进程数（Linux 下多进程 fork 生效；Windows 下 Workerman 仅单进程）
     *                          Worker process count (multi-process fork on Linux; Windows runs a single process under Workerman).
     * @param int $maxPackageSize 单帧最大字节数 Max package size in bytes.
     * @param null|int $maxSendBufferSize 发送缓冲上限，超过即视为慢客户端 Send buffer ceiling; exceeding it marks a slow client.
     * @param null|int $authTimeoutSeconds 未认证连接超时断开秒数 Auth timeout in seconds for unauthenticated connections.
     * @param null|int $scanIntervalSeconds 超时扫描间隔（秒） Timeout scan interval in seconds.
     * @param null|RateLimiterInterface $rateLimiter 限流器 Rate limiter.
     * @param null|TimerInterface $timer 定时器实现 Timer implementation.
     * @param null|callable(): float $clock 时钟函数 Clock function.
     * @param null|BatchSerializerInterface $errorSerializer 错误兜底帧编码器；缺省 JsonBatchSerializer（与该频道正常出站
     *                                                       同构的批量包编码，二进制频道应注入其批量序列化器） Error-frame
     *                                                       fallback encoder; defaults to JsonBatchSerializer (batch-packet
     *                                                       encoding isomorphic to normal outbound frames — binary channels
     *                                                       should inject their own batch serializer).
     * @param null|callable(string): void $errorLogger 错误日志回调（缺省 error_log） Error logger callback (defaults to error_log).
     */
    public function __construct(
        string $listenAddress = 'websocket://0.0.0.0:8080',
        int $workerCount = 1,                    // Worker 进程数（Linux 多进程；Windows 单进程） Worker process count (multi-process on Linux; single process on Windows).
        int $maxPackageSize = 10_485_760,        // 10MB 10MB.
        ?int $maxSendBufferSize = 10_485_760,    // 慢客户端检测阈值 Slow-client detection threshold.
        ?int $authTimeoutSeconds = 10,           // 未认证连接超时断开；null = 关闭 Disconnect unauthenticated connections after timeout; null disables it.
        ?int $scanIntervalSeconds = 5,           // 超时扫描间隔（秒） Timeout scan interval in seconds.
        ?RateLimiterInterface $rateLimiter = null, // 默认不启用 Disabled by default.
        ?TimerInterface $timer = null,           // 缺省 new WorkermanTimer() Defaults to new WorkermanTimer().
        ?callable $clock = null,                 // 缺省 microtime(true) Defaults to microtime(true).
        ?BatchSerializerInterface $errorSerializer = null, // 缺省 JsonBatchSerializer Defaults to JsonBatchSerializer().
        ?callable $errorLogger = null,           // 缺省 error_log Defaults to error_log.
    ) {
        // Linux 下 count = fork 的 Worker 进程数（阶段 4 多频道每频道独立进程的前提；onWorkerStart 每进程执行一次，
        // 构造器状态 COW、共享资源工厂 lazy 建连——ADR 10.6）；Windows 下 Workerman 仅单进程，由 Workerman 自身行为保证。
        // On Linux count is the number of forked worker processes (the premise of phase 4's one-process-per-channel topology; onWorkerStart
        // runs once per process with COW constructor state and lazily connected shared-resource factories — ADR 10.6); on Windows Workerman
        // runs a single process, guaranteed by Workerman itself.
        $timer ??= new WorkermanTimer();
        $this->rateLimiter = $rateLimiter;
        $this->authTimeoutSeconds = $authTimeoutSeconds;
        $this->scanIntervalSeconds = $scanIntervalSeconds ?? 5;
        $this->maxSendBufferSize = $maxSendBufferSize;
        $this->maxPackageSize = $maxPackageSize;
        $this->errorSerializer = $errorSerializer ?? new JsonBatchSerializer();
        $this->errorLogger = $errorLogger ?? static function (string $message): void {
            error_log($message);
        };
        $this->manager = new ConnectionManager($timer, $clock);

        $this->worker = new Worker($listenAddress);
        // 多进程配置直通：count 即 Worker 进程数，不再钳制（默认 count=1 保持单进程现状；
        // 需要多频道/多进程时由调用方传入 workerCount，仅配置变化、代码零改动）。
        // Multi-process passes through: count is the worker process count with no clamp (the default count=1 keeps the single-process status quo;
        // multi-channel/multi-process topologies pass workerCount in — a configuration change only, zero code change).
        $this->worker->count = $workerCount;
        $this->worker->onWorkerStart = $this->handleWorkerStart(...);
        $this->worker->onWorkerStop = $this->handleWorkerStop(...);
        $this->worker->onConnect = $this->handleConnect(...);
        $this->worker->onMessage = $this->handleMessage(...);
        $this->worker->onClose = $this->handleClose(...);
    }

    /**
     * 注册 Worker 启动回调（追加式：多个处理器按注册顺序依次执行）。
     * Registers a worker-start handler (appending: multiple handlers run in registration order).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onWorkerStart(callable $handler): void
    {
        $this->onWorkerStartHandlers[] = $handler;
    }

    /**
     * 注册 Worker 退出回调（追加式：多个处理器按注册顺序依次执行）。
     * Registers a worker-stop handler (appending: multiple handlers run in registration order).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onWorkerStop(callable $handler): void
    {
        $this->onWorkerStopHandlers[] = $handler;
    }

    /**
     * 注册连接建立回调（追加式：每个新连接都会依次通知所有处理器）。
     * Registers a connect handler (appending: every new connection notifies all handlers in order).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onConnect(callable $handler): void
    {
        $this->onConnectHandlers[] = $handler;
    }

    /**
     * 注册消息回调（追加式：同一消息会依次派发给所有处理器，实现广播式扩展）。
     * Registers a message handler (appending: the same message is dispatched to all handlers in order, enabling broadcast-style extension).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onMessage(callable $handler): void
    {
        $this->onMessageHandlers[] = $handler;
    }

    /**
     * 注册连接关闭回调（追加式：断开时依次通知所有处理器）。
     * Registers a close handler (appending: all handlers are notified in order on disconnect).
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onClose(callable $handler): void
    {
        $this->onCloseHandlers[] = $handler;
    }

    /**
     * 慢客户端检测注册：阶段 2 只检测不断开
     * Slow-client detection registration: phase 2 only detects, never disconnects.
     *
     * @param callable $handler 回调处理器 Handler callback.
     */
    public function onSlowClient(callable $handler): void
    {
        $this->onSlowClientHandlers[] = $handler;
    }

    /**
     * 启动服务器：进入 Workerman 事件循环（阻塞，直到进程被终止）。
     * Starts the server: enters the Workerman event loop (blocks until the process is terminated).
     */
    public function start(): void
    {
        Worker::runAll();
    }

    /**
     * 停止服务器：通知所有 Worker 优雅退出。
     * Stops the server: tells all workers to exit gracefully.
     */
    public function stop(): void
    {
        Worker::stopAll();
    }

    /**
     * Worker 启动回调：先启动认证超时扫描，再派发用户注册的启动处理器。
     * Worker start callback: starts the auth-timeout scan first, then dispatches user-registered start handlers.
     *
     * @param Worker $worker 启动中的 Worker 实例 The Worker instance being started.
     */
    private function handleWorkerStart(Worker $worker): void
    {
        // 仅在配置了认证超时时启动扫描；每个 Worker 进程都会注册自己的扫描定时器
        // Start the scan only when auth timeout is configured; each worker process registers its own scan timer
        if ($this->authTimeoutSeconds !== null) {
            $this->manager->startAuthTimeoutScan($this->authTimeoutSeconds, $this->scanIntervalSeconds);
        }

        foreach ($this->onWorkerStartHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Worker 退出回调：按注册顺序派发用户注册的退出处理器（清理钩子，如服务注销）。
     * Worker stop callback: dispatches user-registered stop handlers in registration order (cleanup hooks, e.g. service unregister).
     *
     * @param Worker $worker 正在退出的 Worker 实例 The Worker instance being stopped.
     */
    private function handleWorkerStop(Worker $worker): void
    {
        foreach ($this->onWorkerStopHandlers as $handler) {
            $handler($worker);
        }
    }

    /**
     * 连接建立回调：包装适配器、登记元数据、配置帧/缓冲上限并派发连接处理器。
     * Connect callback: wraps the adapter, registers metadata, configures package/buffer limits, and dispatches connect handlers.
     *
     * @param TcpConnection $tcp Workerman 底层 TCP 连接 Underlying Workerman TCP connection.
     */
    private function handleConnect(TcpConnection $tcp): void
    {
        $conn = new WorkermanConnection($tcp, $this->manager);
        $this->connections[$tcp->id] = $conn;
        $this->manager->attach($conn);

        // 出站帧一律走二进制 WebSocket 帧（BINARY opcode）：帧末批量下发的是二进制批量包，
        // 文本 JSON 协议仍由社交/网关层独占，两个频道的帧类型在传输层即可区分。
        // Outbound frames always go out as binary WebSocket frames (BINARY opcode): frame-end batches are binary
        // packets, the text JSON protocol stays exclusive to the social/gateway channel — the two channels are
        // distinguishable at the transport level.
        $tcp->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;

        // 限制单帧最大字节，超过则 Workerman 直接断开，防止超大包打爆内存
        // Cap the max package size; oversized frames are dropped by Workerman to prevent memory blowups
        $tcp->maxPackageSize = $this->maxPackageSize;
        if ($this->maxSendBufferSize !== null) {
            $tcp->maxSendBufferSize = $this->maxSendBufferSize;
            // 发送缓冲写满 → 标记慢客户端并通知；排空 → 清除标记，形成「检测不主动断开」的软告警闭环
            // Buffer full → mark slow client and notify; buffer drained → clear the flag, forming a detect-but-not-disconnect soft-alert loop
            $tcp->onBufferFull = function (TcpConnection $tcpConnection) use ($conn): void {
                $this->slowConnections[$tcpConnection->id] = true;
                foreach ($this->onSlowClientHandlers as $handler) {
                    $handler($conn);
                }
            };
            $tcp->onBufferDrain = function (TcpConnection $tcpConnection): void {
                unset($this->slowConnections[$tcpConnection->id]);
            };
        }

        foreach ($this->onConnectHandlers as $handler) {
            $handler($conn);
        }
    }

    /**
     * 消息回调：刷新活跃时间 → 限流 → 类型校验 → 依序派发处理器，处理器异常转换为错误帧回写。
     * Message callback: refresh activity → rate limit → type check → dispatch handlers in order, converting handler exceptions into an error frame.
     *
     * @param TcpConnection $tcp Workerman 底层 TCP 连接 Underlying Workerman TCP connection.
     * @param mixed $data Workerman 解帧后的原始负载 Raw payload after Workerman unframing.
     */
    private function handleMessage(TcpConnection $tcp, mixed $data): void
    {
        $conn = $this->connections[$tcp->id] ?? null;
        // 未知连接（理论上不该发生）：静默丢弃，避免对失效连接做任何操作
        // Unknown connection (should not happen): drop silently to avoid touching a stale connection
        if ($conn === null) {
            return;
        }

        $this->manager->touch($conn);

        // 限流检查：内部连接（服务间 RPC transport，rpc:hello 握手登记后 markInternal）豁免限流——
        // RPC 帧不受 10 tokens/s 限制（MINOR-3）；其余连接超限时静默丢弃消息，不触发任何 handler（防刷）
        // Rate-limit check: internal connections (inter-service RPC transports, marked after the rpc:hello handshake) are
        // exempt — RPC frames are never capped by the 10 tokens/s limit (MINOR-3); other connections over the limit are
        // silently dropped without triggering any handler (anti-flood)
        if ($this->rateLimiter !== null && !$conn->isInternal() && !$this->rateLimiter->consume($conn->getId())) {
            return; // 超限静默丢弃 dropped when over limit
        }

        // 非字符串负载（如纯二进制帧）由上层自行处理，此处不做派发
        // Non-string payloads (e.g. raw binary frames) are handled by upper layers; skip dispatch here
        if (!is_string($data)) {
            return;
        }

        try {
            foreach ($this->onMessageHandlers as $handler) {
                $handler($conn, $data);
            }
        } catch (Throwable $e) {
            // 处理器异常：记录日志并回写统一错误帧，保证一个 handler 崩溃不拖垮整个消息循环。
            // 错误帧与正常出站同走批量包编码路径（出站帧强制二进制 WebSocket，文本 JSON 兜底会让二进制
            // 协议客户端解析失败）；日志走可注入回调（缺省 error_log）。
            // Handler exception: log it and write back a unified error frame so one crashing handler cannot take down
            // the message loop. The error frame rides the same batch-packet encoding path as normal outbound frames
            // (outbound frames are forced binary WebSocket — a text-JSON fallback would break binary-protocol clients);
            // logging goes through the injectable callback (defaults to error_log).
            ($this->errorLogger)(sprintf('[Nythros] onMessage handler failed: %s', $e->getMessage()));
            $conn->send($this->errorSerializer->encodeBatch([
                Message::create('error', [
                    'code' => 500,
                    'message' => $e->getMessage(),
                ]),
            ]));
        }
    }

    /**
     * 关闭回调：注销元数据、清理慢客户端标记并派发关闭处理器。
     * Close callback: deregisters metadata, clears the slow-client flag, and dispatches close handlers.
     *
     * @param TcpConnection $tcp Workerman 底层 TCP 连接 Underlying Workerman TCP connection.
     */
    private function handleClose(TcpConnection $tcp): void
    {
        $id = $tcp->id;
        $conn = $this->connections[$id] ?? null;
        // 未登记过的连接无需清理（可能从未成功 attach）
        // Nothing to clean up for connections that were never registered (attach may never have succeeded)
        if ($conn === null) {
            return;
        }

        $this->manager->detach($conn);
        unset($this->connections[$id]);
        unset($this->slowConnections[$id]);

        foreach ($this->onCloseHandlers as $handler) {
            $handler($conn);
        }

        // 断连释放限流桶：防止 $buckets 随断连重连无限增长 release the rate-limit bucket on disconnect to prevent $buckets from growing unbounded across reconnects
        $this->rateLimiter?->forget((string) $id);
    }
}
