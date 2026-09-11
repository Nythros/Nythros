<?php

declare(strict_types=1);

namespace Nythros\Framework\Cluster;

/**
 * Redis 连接器（ADR-031，哨兵 HA 实现的客户端侧唯一改造点）：连接工厂 + 哨兵主库解析 + 主从切换自愈。
 * Redis connector (ADR-031, the single client-side change point of the Sentinel HA implementation): the
 * connection factory + sentinel master resolution + master-failover self-healing.
 *
 * 契约：各 store 仍以 `\Redis|\Closure(): \Redis` 消费连接——把 `factory()` 的产物（或本对象的
 * `client()` 闭包）传进去即可，store 代码零改动。store 会永久缓存工厂产物，本对象因此**追踪**自己
 * 创建的每一条连接，在切换时对它们**原地重指向**（对同一 \Redis 对象再次 connect() 会关闭旧 socket
 * 并连到新地址——2026-09 在真实哨兵环境实测验证）。
 * Contract: stores keep consuming `\Redis|\Closure(): \Redis` — hand them `factory()`'s product (or this
 * object's client closure) and no store code changes. Stores cache the factory product permanently, so this
 * object TRACKS every connection it created and re-points them in place on a switch (calling connect() again
 * on the same \Redis object closes the old socket and dials the new address — verified against a real
 * Sentinel setup in 2026-09).
 *
 * 两条实测约束决定了 refresh() 的形态（缺一不可）：
 * Two measured constraints shape refresh() (both are load-bearing):
 * 1. phpredis 对非 persistent 连接**不会**自动重连到原地址——主进程崩溃重启后旧对象永久报
 *    "Redis server ... went away"，直到显式 connect()。故 refresh() 每轮对失活连接（isConnected() === false，
 *    本地判定零网络开销）原地重连；这同时修复了「已建立连接在 Redis 重启后 worker 永不恢复」的存量缺陷。
 * 1. phpredis does NOT auto-reconnect a non-persistent connection — after a Redis restart the stale object
 *    throws "Redis server ... went away" forever until an explicit connect(). refresh() therefore reconnects
 *    dead clients each round (isConnected() is a local check, zero network cost), fixing the pre-existing
 *    defect where an established connection never healed across a Redis restart.
 * 2. `RedisSentinel::getMasterAddrByName()` 返回**数字索引数组** ["ip","port"]（phpredis 6.3 实测），
 *    且构造参数只认 host/port/persistent/auth/database——没有超时开关，哨兵假死会阻塞到 PHP 的
 *    default_socket_timeout。故解析期间临时收敛 default_socket_timeout（实测 1s 干净抛 RedisException）。
 * 2. `RedisSentinel::getMasterAddrByName()` returns a NUMERIC-index array ["ip","port"] (phpredis 6.3,
 *    measured), and its constructor accepts only host/port/persistent/auth/database — no timeout knob, so a
 *    hung sentinel blocks until PHP's default_socket_timeout. Resolution therefore temporarily tightens
 *    default_socket_timeout (measured: a clean RedisException after 1s).
 *
 * 失效语义（与 ADR-028 分层一致）：哨兵全部不可达 = 保留现有连接（数据面不因哨兵故障中断）；主变后
 * 新主暂不可达 = 保留现有连接并下轮重试；切换窗口内的请求仍走 500 兜底（worker 不退出）。
 * Failure semantics (matching ADR-028's layering): all sentinels unreachable = keep current connections
 * (the data plane survives a sentinel outage); the new master not yet reachable = keep connections and retry
 * next round; requests inside the failover window still hit the request-level 500 fallback (the worker lives).
 */
final class RedisConnector
{
    /** 缺省主从刷新间隔（秒）：切换完成到 worker 自愈的延迟上界。 Default refresh interval (s): the upper bound from failover completion to worker self-healing. */
    public const DEFAULT_REFRESH_INTERVAL_SECONDS = 5.0;

    /** 哨兵查询超时（秒，经 default_socket_timeout 生效；phpredis 无专用开关）。 Sentinel query timeout (s, applied via default_socket_timeout; phpredis exposes no dedicated knob). */
    public const DEFAULT_SENTINEL_TIMEOUT_SECONDS = 1.0;

    /** 数据连接建立超时（秒，与既有内联工厂同口径）。 Data-connection connect timeout (s, same convention as the legacy inline factories). */
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 1.0;

    /** 数据连接读超时（秒；0 = 不限。防网络分区下读操作无限挂起——本项目无阻塞型命令，取 3s 是安全余量）。 Data-connection read timeout (s; 0 = unlimited — guards against indefinite hangs on partition; the codebase has no blocking commands, 3s is headroom). */
    public const DEFAULT_READ_TIMEOUT_SECONDS = 3.0;

    /** 缺省哨兵监控组名（NYTHROS_REDIS_MASTER 未设置时）。 Default sentinel monitor name (when NYTHROS_REDIS_MASTER is unset). */
    public const DEFAULT_MASTER_NAME = 'nythros';

    /** 哨兵端点环境变量（逗号分隔 host:port；未设置/空 = 直连模式，与接入前逐字节等价）。 Sentinel endpoints env var (comma-separated host:port; unset/empty = direct mode, byte-identical to the pre-integration behavior). */
    public const ENV_SENTINELS = 'NYTHROS_REDIS_SENTINELS';

    /** 哨兵监控组名环境变量。 Sentinel monitor-name env var. */
    public const ENV_MASTER = 'NYTHROS_REDIS_MASTER';

    /** 哨兵自身认证密码环境变量（哨兵开 requirepass 时）。 Sentinel auth password env var (when sentinels run requirepass). */
    public const ENV_SENTINEL_PASSWORD = 'NYTHROS_REDIS_SENTINEL_PASSWORD';

    /** 数据节点认证密码环境变量（ADR-027 口径）。 Data-node auth password env var (the ADR-027 convention). */
    public const ENV_PASSWORD = 'NYTHROS_REDIS_PASSWORD';

    /** 数据节点库选择环境变量（ADR-027 口径）。 Data-node db selection env var (the ADR-027 convention). */
    public const ENV_DB = 'NYTHROS_REDIS_DB';

    /** @var list<\Redis> 本对象创建过的连接（本进程内；主变时原地重指向，失活时原地重连） Every connection this object created (process-local; re-pointed on a master switch and reconnected when dead). */
    private array $clients = [];

    /** @var array{0: string, 1: int}|null 当前使用中的地址（哨兵模式 = 最近一次解析的主库；直连模式 = 配置地址） The address currently in use (Sentinel mode: the last resolved master; direct mode: the configured address). */
    private ?array $address = null;

    /** 上次刷新时刻（微秒时间戳；refresh 的节流基准）。 Last refresh instant (µs timestamp; the refresh throttle baseline). */
    private float $lastRefreshAt = 0.0;

    /**
     * @param string $host 直连模式主机（直连模式的唯一地址；哨兵模式仅作日志/回退展示） Direct-mode host (the only address in direct mode; display/fallback only under Sentinel).
     * @param int $port 直连模式端口 Direct-mode port.
     * @param list<string> $sentinels 哨兵端点（"host:port"；空 = 直连模式） Sentinel endpoints ("host:port"; empty = direct mode).
     * @param ?string $masterName 哨兵监控组名（哨兵模式必填） Sentinel monitor name (required in Sentinel mode).
     * @param ?string $sentinelPassword 哨兵认证密码（哨兵未开认证传 null） Sentinel auth password (null when sentinels are unauthenticated).
     * @param ?string $password 数据节点认证密码（未开认证传 null） Data-node auth password (null when unauthenticated).
     * @param ?int $db 数据节点库编号（null = 不 select） Data-node db index (null = no select).
     * @param float $refreshIntervalSeconds 主从刷新间隔（秒） Master/replica refresh interval (s).
     * @param float $connectTimeoutSeconds 建连超时（秒） Connect timeout (s).
     * @param float $readTimeoutSeconds 读超时（秒；0 = 不限） Read timeout (s; 0 = unlimited).
     * @param (\Closure(): mixed)|null $masterResolver 主库解析器测试缝（返回 [host, port]；null = 走真实哨兵） Test seam for master resolution (returns [host, port]; null = the real Sentinel path).
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly array $sentinels = [],
        private readonly ?string $masterName = null,
        private readonly ?string $sentinelPassword = null,
        private readonly ?string $password = null,
        private readonly ?int $db = null,
        private readonly float $refreshIntervalSeconds = self::DEFAULT_REFRESH_INTERVAL_SECONDS,
        private readonly float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private readonly float $readTimeoutSeconds = self::DEFAULT_READ_TIMEOUT_SECONDS,
        private readonly ?\Closure $masterResolver = null,
    ) {
    }

    /**
     * 从环境变量构造（各入口统一口径）：哨兵三变量 + ADR-027 的认证/库选择。
     * Builds from environment variables (the shared convention across entry points): the three Sentinel vars
     * plus ADR-027's auth/db selection.
     *
     * 哨兵变量未设置 = 直连模式（开发缺省，行为与接入前一致）。
     * Unset Sentinel vars = direct mode (the development default, behavior identical to the pre-integration code).
     *
     * @param string $host 直连模式主机（deploy.yaml/CLI 注入） Direct-mode host (injected from deploy.yaml/CLI).
     * @param int $port 直连模式端口 Direct-mode port.
     * @param float $connectTimeoutSeconds 数据连接建连超时（秒；入口按既有口径覆写，如 storage-exporter 的 2.0） Data-connection connect timeout (s; entry points override per their legacy convention, e.g. the storage exporter's 2.0).
     */
    public static function fromEnv(string $host, int $port, float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS): self
    {
        $sentinels = [];
        $rawSentinels = getenv(self::ENV_SENTINELS);
        if (is_string($rawSentinels) && trim($rawSentinels) !== '') {
            foreach (explode(',', $rawSentinels) as $endpoint) {
                $endpoint = trim($endpoint);
                if ($endpoint !== '') {
                    $sentinels[] = $endpoint;
                }
            }
        }

        $rawMaster = getenv(self::ENV_MASTER);
        $masterName = is_string($rawMaster) && trim($rawMaster) !== ''
            ? trim($rawMaster)
            : ($sentinels === [] ? null : self::DEFAULT_MASTER_NAME);

        $rawSentinelPassword = getenv(self::ENV_SENTINEL_PASSWORD);
        $sentinelPassword = is_string($rawSentinelPassword) && $rawSentinelPassword !== '' ? $rawSentinelPassword : null;

        return new self(
            $host,
            $port,
            $sentinels,
            $masterName,
            $sentinelPassword,
            self::envPassword(),
            self::envDb(),
            connectTimeoutSeconds: $connectTimeoutSeconds,
        );
    }

    /**
     * 是否哨兵模式（未配置哨兵且无测试解析器 = 直连）。
     * Whether Sentinel mode is active (no sentinels and no test resolver = direct).
     */
    public function isSentinelMode(): bool
    {
        return $this->masterResolver !== null || $this->sentinels !== [];
    }

    /** 刷新间隔（秒）：入口据此挂定时器。 Refresh interval (s): entry points arm their timers with it. */
    public function refreshIntervalSeconds(): float
    {
        return $this->refreshIntervalSeconds;
    }

    /**
     * 连接工厂闭包（store 构造参数直接可用的形态）。
     * The connection-factory closure (directly consumable as a store constructor argument).
     *
     * @return \Closure(): \Redis
     */
    public function factory(): \Closure
    {
        return fn (): \Redis => $this->client();
    }

    /**
     * 新建一条连接（哨兵模式先解析主库地址），并纳入追踪。
     * Creates one connection (resolving the master first in Sentinel mode) and tracks it.
     *
     * 建连失败抛 RuntimeException（与既有内联工厂同口径：异常被上层 catch Throwable 兜底为日志 + 500
     * 响应，worker 存活；本对象不缓存失败连接，下次调用重试）。
     * Connect failures throw RuntimeException (the legacy inline-factory convention: the exception is caught
     * upstream as log + 500, the worker lives; a failed connection is never tracked, so the next call retries).
     *
     * @throws \RuntimeException 哨兵不可达或建连失败 Sentinel unreachable or connect failed.
     */
    public function client(): \Redis
    {
        $address = $this->isSentinelMode() ? $this->resolveMaster() : [$this->host, $this->port];
        if ($address === null) {
            throw new \RuntimeException(sprintf(
                '[RedisConnector] fatal: 哨兵全部不可达（%s=%s），无法解析主库地址',
                self::ENV_SENTINELS,
                implode(',', $this->sentinels),
            ));
        }

        $redis = new \Redis();
        if (!$this->applyConnection($redis, $address)) {
            throw new \RuntimeException(sprintf(
                '[RedisConnector] fatal: 无法连接 Redis %s:%d，跨进程共享状态不可用，请求返回 500',
                $address[0],
                $address[1],
            ));
        }

        $this->address = $address;
        $this->clients[] = $redis;

        return $redis;
    }

    /**
     * 主从刷新（worker 定时器周期调用；测试可 force 立即执行）。
     * Master/replica refresh (called periodically by worker timers; tests may force an immediate round).
     *
     * 三种结果：地址切换（探测新主 → 全部追踪连接原地重指向）；地址未变（失活连接原地重连——覆盖
     * Redis 重启/网络闪断）；哨兵不可达（保留现有连接，返回 false 等下一轮）。
     * Three outcomes: address switched (probe the new master → re-point every tracked connection in place);
     * address unchanged (reconnect dead clients — covers a Redis restart / network blip); sentinels
     * unreachable (keep connections, return false and retry next round).
     *
     * @param bool $force 跳过节流立即执行（测试/演练用） Skips the throttle for an immediate round (tests/drills).
     * @return bool 本轮是否成功完成（直连模式恒 true；节流跳过与哨兵不可达返回 false） Whether the round completed successfully (always true in direct mode; throttled skips and unreachable sentinels return false).
     */
    public function refresh(bool $force = false): bool
    {
        if (!$this->isSentinelMode()) {
            return true;
        }

        $now = self::monotonicNow();
        if (!$force && ($now - $this->lastRefreshAt) < $this->refreshIntervalSeconds) {
            return false;
        }
        $this->lastRefreshAt = $now;

        $resolved = $this->resolveMaster();
        if ($resolved !== null && $resolved !== $this->address) {
            if (!$this->probe($resolved)) {
                error_log(sprintf(
                    '[RedisConnector] 主库 %s:%d 暂不可达，保留现有连接（下轮重试）',
                    $resolved[0],
                    $resolved[1],
                ));

                return false;
            }

            $previous = $this->address;
            $this->address = $resolved;
            $retargeted = 0;
            foreach ($this->clients as $client) {
                if ($this->applyConnection($client, $resolved)) {
                    ++$retargeted;
                }
            }
            error_log(sprintf(
                '[RedisConnector] 主库切换 %s → %s:%d，%d/%d 条连接已重指向',
                $previous === null ? '(未连接)' : sprintf('%s:%d', $previous[0], $previous[1]),
                $resolved[0],
                $resolved[1],
                $retargeted,
                count($this->clients),
            ));

            return true;
        }

        // 地址未变：原地重连失活连接（phpredis 不会自动重连，实测见类注释）。
        // Address unchanged: reconnect dead clients in place (phpredis never auto-reconnects — see the class docblock).
        $reconnected = 0;
        if ($this->address !== null) {
            foreach ($this->clients as $client) {
                if ($client->isConnected() === false && $this->applyConnection($client, $this->address)) {
                    ++$reconnected;
                }
            }
        }
        if ($reconnected > 0) {
            error_log(sprintf('[RedisConnector] %d 条失活连接已原地重连（主库地址未变）', $reconnected));
        }

        return $resolved !== null;
    }

    /**
     * 当前使用中的地址（哨兵模式 = 最近一次解析/连接成功的主库；直连模式 = 配置地址）。
     * The address currently in use (Sentinel: the last resolved/connected master; direct: the configured address).
     *
     * @return array{0: string, 1: int}
     */
    public function masterAddress(): array
    {
        return $this->address ?? [$this->host, $this->port];
    }

    /** 追踪中的连接数（演练/观测用）。 Number of tracked connections (drills/observability). */
    public function trackedClients(): int
    {
        return count($this->clients);
    }

    /**
     * 解除追踪（调用方主动 close 连接时配对调用；否则刷新会把已关闭的连接重新接上）。
     * Untracks a connection (call it when the owner deliberately closes one; otherwise refresh would reconnect
     * a deliberately closed client).
     */
    public function release(\Redis $client): void
    {
        $remaining = [];
        foreach ($this->clients as $tracked) {
            if ($tracked !== $client) {
                $remaining[] = $tracked;
            }
        }
        $this->clients = $remaining;
    }

    /**
     * 解析主库地址：测试缝优先；否则依次询问各哨兵（单点失败即尝试下一个），全部失败返回 null。
     * Resolves the master address: the test seam wins; otherwise each sentinel is asked in turn (one failure
     * moves to the next), and null means all of them failed.
     *
     * @return array{0: string, 1: int}|null
     */
    private function resolveMaster(): ?array
    {
        if ($this->masterResolver !== null) {
            return $this->normalizeAddress(($this->masterResolver)());
        }

        if ($this->sentinels === [] || $this->masterName === null) {
            return null;
        }

        if (!class_exists(\RedisSentinel::class)) {
            error_log(sprintf('[RedisConnector] %s 已配置但 phpredis 无 RedisSentinel 类（需 ext-redis >= 5.3），降级为直连', self::ENV_SENTINELS));

            return null;
        }

        // 哨兵查询超时只能经 default_socket_timeout 生效（RedisSentinel 构造无超时参数，实测）；
        // 期间临时收敛，结束后恢复原值，避免影响进程内其他 socket 调用。
        // The sentinel query timeout only takes effect via default_socket_timeout (the RedisSentinel constructor has
        // no timeout parameter, measured); tighten it for the round and restore afterwards so other socket calls in
        // the process keep the configured value.
        $previousTimeout = (string) ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) max(1, (int) ceil(self::DEFAULT_SENTINEL_TIMEOUT_SECONDS)));
        try {
            foreach ($this->sentinels as $endpoint) {
                $parts = explode(':', $endpoint, 2);
                $sentinelHost = trim($parts[0]);
                $sentinelPort = isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : 26379;
                if ($sentinelHost === '') {
                    continue;
                }

                try {
                    $options = ['host' => $sentinelHost, 'port' => $sentinelPort];
                    if ($this->sentinelPassword !== null && $this->sentinelPassword !== '') {
                        $options['auth'] = $this->sentinelPassword;
                    }
                    // PHPStan 的 phpredis stub 仍是旧版位置参数签名（`__construct(string $host, ...)`），
                    // 而 phpredis 6.3 实测**只认 options 数组**（传位置参数报 "expects at most 1 argument"，
                    // 且 auth 只能经数组传入）——本行按实测写，ignore 的是 stub 与运行时不符。
                    // PHPStan's phpredis stub still carries the legacy positional signature while phpredis 6.3
                    // accepts ONLY the options array (positional args error "expects at most 1 argument", and auth
                    // is only passable via the array) — measured; the ignore covers the stub/runtime mismatch.
                    // @phpstan-ignore-next-line
                    $sentinel = new \RedisSentinel($options);
                    $resolved = $this->normalizeAddress($sentinel->getMasterAddrByName($this->masterName));
                    if ($resolved !== null) {
                        return $resolved;
                    }
                } catch (\Throwable) {
                    // 单点哨兵不可达/超时：尝试下一个（全部失败 = 保留现有连接，见 refresh）
                    // A single sentinel unreachable/timed out: try the next one (all failed = keep connections, see refresh).
                    continue;
                }
            }
        } finally {
            // 恢复原值：default_socket_timeout 是核心 ini（恒有值，运行时为数字字符串），原样写回。
            // Restore: default_socket_timeout is a core ini (always set, a numeric string at runtime) — write it back as-is.
            ini_set('default_socket_timeout', $previousTimeout);
        }

        return null;
    }

    /**
     * 规范化地址：兼容 getMasterAddrByName 的数字索引数组与测试解析器的返回值。
     * Normalizes an address: accepts getMasterAddrByName's numeric-index array and the test resolver's value.
     *
     * @return array{0: string, 1: int}|null
     */
    private function normalizeAddress(mixed $address): ?array
    {
        if (!is_array($address) || !isset($address[0], $address[1])) {
            return null;
        }
        $host = $address[0];
        $port = $address[1];
        if (!is_string($host) || $host === '') {
            return null;
        }
        if (is_int($port)) {
            $portNumber = $port;
        } elseif (is_string($port) && ctype_digit($port)) {
            $portNumber = (int) $port;
        } else {
            return null;
        }
        if ($portNumber < 1 || $portNumber > 65535) {
            return null;
        }

        return [$host, $portNumber];
    }

    /**
     * 对指定连接应用地址（connect + 认证 + 库选择 + 读超时）；已建立的连接再次调用 = 原地重指向。
     * Applies an address to a client (connect + auth + select + read timeout); calling it again on an
     * established client re-points that object in place.
     *
     * @param array{0: string, 1: int} $address 目标地址 Target address.
     * @return bool 是否可用（false = 建连或认证失败，调用方决定重试/放弃） Whether the client is usable (false = connect/auth failed; the caller decides to retry or give up).
     */
    private function applyConnection(\Redis $redis, array $address): bool
    {
        try {
            $connected = @$redis->connect($address[0], $address[1], $this->connectTimeoutSeconds);
        } catch (\Throwable) {
            return false;
        }
        if ($connected !== true) {
            return false;
        }

        try {
            if ($this->password !== null && $this->password !== '') {
                $authenticated = @$redis->auth($this->password);
                if ($authenticated !== true) {
                    return false;
                }
            }
            if ($this->db !== null) {
                @$redis->select($this->db);
            }
            if ($this->readTimeoutSeconds > 0) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeoutSeconds);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * 新主探活（先连后切，避免把全部连接指向一个不可达地址）：探测连接用后即弃。
     * New-master probe (connect before switching, so a whole fleet of clients is never pointed at an
     * unreachable address): the probe connection is discarded after use.
     *
     * @param array{0: string, 1: int} $address 待验证地址 Address to verify.
     */
    private function probe(array $address): bool
    {
        return $this->applyConnection(new \Redis(), $address);
    }

    /**
     * 单调时钟（秒）：刷新节流是「间隔测量」，必须用单调钟——WSL2 的墙钟会周期性向前跳变
     * （实测每 ~34s 跳 ~1.85s，并因此让 Redis Sentinel 持续进入 tilt 模式），用 microtime 计时会
     * 让节流失效或漂移。
     * Monotonic clock (s): the refresh throttle is an interval measurement and must use a monotonic clock —
     * the WSL2 wall clock periodically jumps forward (measured ~1.85s every ~34s, which also keeps Redis
     * Sentinel in tilt mode), so microtime-based throttling drifts or misfires.
     */
    private static function monotonicNow(): float
    {
        return hrtime(true) / 1e9;
    }

    private static function envPassword(): ?string
    {
        $password = getenv(self::ENV_PASSWORD);

        return is_string($password) && $password !== '' ? $password : null;
    }

    private static function envDb(): ?int
    {
        $db = getenv(self::ENV_DB);

        return is_string($db) && preg_match('/^\d+$/', $db) === 1 ? (int) $db : null;
    }
}
