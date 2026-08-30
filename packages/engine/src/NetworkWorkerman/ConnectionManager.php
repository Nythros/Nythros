<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman;

use Nythros\Contracts\TimerInterface;

/**
 * 连接管理器：集中维护连接元数据（最后活跃时间、认证状态），并驱动认证超时扫描。
 * Connection manager: centrally maintains connection metadata (last-active time, auth state) and drives the auth-timeout scan.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class ConnectionManager
{
    /** @var array<string, array{conn: WorkermanConnection, lastMessageTime: float, authenticated: bool}> 连接元数据表 connection metadata table. */
    private array $connections = [];

    /** @var callable(): float 时钟函数（可注入，便于测试） Clock function (injectable for testing). */
    private $now;

    /**
     * 构造连接管理器。
     * Constructs the connection manager.
     *
     * @param null|TimerInterface $timer 定时器实现；null 表示禁用认证超时扫描 Timer implementation; null disables the auth-timeout scan.
     * @param null|callable(): float $now 时钟注入（缺省 microtime(true)） Clock injection (defaults to microtime(true)).
     */
    public function __construct(
        private readonly ?TimerInterface $timer,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /**
     * 登记新连接：记录最后活跃时间为当前时刻，初始未认证。
     * Registers a new connection: last-active time is set to now and auth state starts as unauthenticated.
     *
     * @param WorkermanConnection $conn 要登记的连接 Connection to register.
     */
    public function attach(WorkermanConnection $conn): void
    {
        $this->connections[$conn->getId()] = [
            'conn' => $conn,
            'lastMessageTime' => ($this->now)(),
            'authenticated' => false,
        ];
    }

    /**
     * 注销连接并清除其元数据。
     * Deregisters a connection and removes its metadata.
     *
     * @param WorkermanConnection $conn 要注销的连接 Connection to deregister.
     */
    public function detach(WorkermanConnection $conn): void
    {
        unset($this->connections[$conn->getId()]);
    }

    /**
     * 刷新最后活跃时间（每次收到消息时调用）。
     * Refreshes the last-active time (called on every received message).
     *
     * @param WorkermanConnection $conn 目标连接 Target connection.
     */
    public function touch(WorkermanConnection $conn): void
    {
        $id = $conn->getId();
        // 容错：连接可能已注销，仅在存在时刷新 tolerate stale touches: refresh only if the connection is still registered
        if (isset($this->connections[$id])) {
            $this->connections[$id]['lastMessageTime'] = ($this->now)();
        }
    }

    /**
     * 标记连接为已认证。
     * Marks a connection as authenticated.
     *
     * @param WorkermanConnection $conn 目标连接 Target connection.
     */
    public function markAuthenticated(WorkermanConnection $conn): void
    {
        $id = $conn->getId();
        if (isset($this->connections[$id])) {
            $this->connections[$id]['authenticated'] = true;
        }
    }

    /**
     * 查询连接是否已认证。
     * Returns whether the connection is authenticated.
     *
     * @param WorkermanConnection $conn 目标连接 Target connection.
     * @return bool true 表示已认证；未登记连接视为未认证 true if authenticated; unregistered connections count as unauthenticated.
     */
    public function isAuthenticated(WorkermanConnection $conn): bool
    {
        return $this->connections[$conn->getId()]['authenticated'] ?? false;
    }

    /**
     * 查询最后活跃时间。
     * Returns the last-active time.
     *
     * @param WorkermanConnection $conn 目标连接 Target connection.
     * @return float Unix 时间戳（秒）；未登记连接返回 0.0 Unix timestamp in seconds; 0.0 for unregistered connections.
     */
    public function getLastMessageTime(WorkermanConnection $conn): float
    {
        return $this->connections[$conn->getId()]['lastMessageTime'] ?? 0.0;
    }

    /**
     * 周期扫描：未认证 && now - lastMessageTime > authTimeout → close；用注入 timer 注册 persistent 定时器
     * Periodic scan: unauthenticated && now - lastMessageTime > authTimeout → close; registers a persistent timer via the injected timer.
     *
     * @param int $authTimeoutSeconds 认证超时秒数 Auth timeout in seconds.
     * @param int $scanIntervalSeconds 扫描间隔秒数 Scan interval in seconds.
     */
    public function startAuthTimeoutScan(int $authTimeoutSeconds, int $scanIntervalSeconds): void
    {
        // 未注入定时器时禁用扫描（如纯测试/无事件循环环境） scan is disabled when no timer is injected (e.g. pure tests / no event loop)
        if ($this->timer === null) {
            return;
        }

        $this->timer->add((float) $scanIntervalSeconds, function () use ($authTimeoutSeconds): void {
            $now = ($this->now)();

            // 先收集超时连接再统一关闭：close() 会经 handleClose 触发 detach 修改 $this->connections，
            // 若在遍历中直接关闭会导致 foreach 迭代中修改数组。
            // Collect expired connections first, then close them together: close() triggers detach via
            // handleClose, which mutates $this->connections; closing inside the loop would modify the array during iteration.
            $expired = [];
            foreach ($this->connections as $entry) {
                if ($entry['authenticated']) {
                    continue;
                }
                if ($now - $entry['lastMessageTime'] > $authTimeoutSeconds) {
                    $expired[] = $entry['conn'];
                }
            }

            foreach ($expired as $conn) {
                $conn->close();
            }
        }, true);
    }

    /**
     * 返回全部存活连接。
     * Returns all live connections.
     *
     * @return array<string, WorkermanConnection> 供上层遍历（压测/统计） connection map for upper-layer iteration (benchmarking / statistics).
     */
    public function allConnections(): array
    {
        return array_map(
            static fn (array $entry): WorkermanConnection => $entry['conn'],
            $this->connections,
        );
    }
}
