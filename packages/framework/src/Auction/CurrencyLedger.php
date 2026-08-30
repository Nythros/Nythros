<?php

declare(strict_types=1);

namespace Nythros\Framework\Auction;

/**
 * 货币账本（D2 缺口最小语义：余额/托管/结算的余额面）。
 * The currency ledger (the minimal D2-gap semantics: the balance side of escrow/settlement).
 *
 * 落点裁决（独立 CurrencyLedger 而非 Inventory 扩展）：
 * 1. Inventory 是进程内易失对象、以 entityId（uid@conn）为键、同账号多开互不干扰——与「一 uid 一余额、
 *    跨会话跨进程一致」的钱包模型冲突；
 * 2. 交易行结算要求玩家离线也能收款（异步交付），只有 Redis Store 能承载该语义；
 * 3. 并发购买互斥依赖 Redis Lua 原子性，余额扣减必须与挂单删除同脚本同存储（见 AuctionStore）；
 * 4. GuildStore/RedisTeamStore 先例确立 Redis Store 为本仓库跨进程状态的既定解法。
 * Inventory 保持「地面拾取移动背包」语义；currency 型物品入账由装配层显式兑换（demo 侧 economy:deposit 路由）。
 * Landing ruling (a standalone CurrencyLedger, not an Inventory extension):
 * 1. Inventory is a volatile in-process object keyed by entityId (uid@conn) with per-login isolation — it clashes
 *    with the wallet model of "one uid, one balance, consistent across sessions and processes";
 * 2. Auction settlement must credit offline sellers (async delivery), which only a Redis store can carry;
 * 3. Concurrent-purchase mutual exclusion relies on Redis Lua atomicity — the balance debit must share one script
 *    and one storage with the listing deletion (see AuctionStore);
 * 4. The GuildStore/RedisTeamStore precedents establish Redis stores as this repo's settled solution for
 *    cross-process state. Inventory keeps its "picked-up mobile bag" semantics; crediting currency items is an
 *    explicit assembly-layer conversion (the demo's economy:deposit route).
 *
 * 易失风险声明：余额即托管资产，存储为 Redis 键——Redis 未持久化时重启即失（照 MailStoreInterface 声明口径）。
 * Volatility notice: balances are escrowed assets stored as Redis keys — lost on restart without persistence.
 *
 * 键设计（nythros:ec: 前缀）：nythros:ec:balance:{uid} = string int（无 TTL，持久）。
 * Key design (nythros:ec: prefix): nythros:ec:balance:{uid} = string int (no TTL, persistent).
 *
 * 与 AuctionStore 的键契约：AuctionStore 的 Lua 直接操作本类同一规则构造的 balance:{uid} 键
 * （BALANCE_SUB_PREFIX 常量两侧一致），购买结算才能单脚本原子完成「删单+双向转账」。
 * Key contract with AuctionStore: AuctionStore's Lua manipulates balance:{uid} keys built by the same rule
 * (identical BALANCE_SUB_PREFIX constants on both sides) so a purchase settles "delete listing + two-way transfer"
 * atomically in one script.
 */
final class CurrencyLedger
{
    /** 余额键子前缀（相对基前缀；与 AuctionStore::BALANCE_SUB_PREFIX 保持一致，见类注释键契约） Balance key sub-prefix (kept identical to AuctionStore's, see the key contract in the class docblock). */
    public const BALANCE_SUB_PREFIX = 'balance:';

    /** uid 格式白名单（uid 进入键构造，收敛键注入面） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 键基前缀（默认 nythros:ec:，测试可注入隔离前缀） Base key prefix (defaults to nythros:ec:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造货币账本。
     * Create the currency ledger.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:ec:） Base key prefix (defaults to nythros:ec:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:ec:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * 查询余额；无记录（从未入账）返回 0。
     * Reads the balance; an unset key (never credited) reads 0.
     *
     * @throws \InvalidArgumentException uid 格式非法 Illegal uid format.
     */
    public function balance(string $uid): int
    {
        $this->assertUid($uid);

        $raw = $this->redis()->get($this->balanceKey($uid));

        return is_string($raw) && $raw !== '' ? (int) $raw : 0;
    }

    /**
     * 入账（正整数）；INCRBY 天然建键。
     * Credits a positive amount (INCRBY creates the key naturally).
     *
     * @throws \InvalidArgumentException uid 格式非法 / 金额非正 Illegal uid format or non-positive amount.
     */
    public function deposit(string $uid, int $amount): void
    {
        $this->assertUid($uid);
        if ($amount <= 0) {
            throw new \InvalidArgumentException(sprintf('CurrencyLedger: 入账金额必须为正整数: %d', $amount));
        }

        $this->redis()->incrBy($this->balanceKey($uid), $amount);
    }

    /**
     * 出账：余额充足时扣减返回 true；不足时不产生任何变更返回 false。
     * Debits: deducts and returns true when the balance suffices; returns false with no mutation otherwise.
     *
     * @throws \InvalidArgumentException uid 格式非法 / 金额非正 Illegal uid format or non-positive amount.
     */
    public function withdraw(string $uid, int $amount): bool
    {
        $this->assertUid($uid);
        if ($amount <= 0) {
            throw new \InvalidArgumentException(sprintf('CurrencyLedger: 出账金额必须为正整数: %d', $amount));
        }

        return $this->redis()->eval(self::WITHDRAW_SCRIPT, [$this->balanceKey($uid), (string) $amount], 1) === 1;
    }

    /**
     * 出账 Lua（原子校验+扣减）：GET 后不足即拒绝，避免 read-then-write 竞态窗口。
     * The withdraw Lua (atomic check+debit): rejects inside the script when short, closing the read-then-write race window.
     * KEYS[1]=balance:{uid} ARGV[1]=amount
     */
    private const WITHDRAW_SCRIPT = <<<'LUA'
local balance = tonumber(redis.call('GET', KEYS[1]) or '0')
if balance < tonumber(ARGV[1]) then
    return 0
end
redis.call('DECRBY', KEYS[1], ARGV[1])
return 1
LUA;

    /**
     * 获取当前进程使用的 phpredis 连接（工厂模式：每个 fork 出的进程各自建连一次）。
     * Get the phpredis connection used by the current process (factory mode: each forked process connects once on its own).
     */
    private function redis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $factory = $this->redis;
        $client = $factory();

        // 缓存工厂产物：本进程后续调用复用同一连接 Cache the factory result: subsequent calls in this process reuse the same connection
        $this->redis = $client;

        return $client;
    }

    /**
     * uid 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the uid against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('CurrencyLedger: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * 余额键：基前缀 + balance: + uid。
     * Balance key: base prefix + balance: + uid.
     */
    private function balanceKey(string $uid): string
    {
        return $this->prefix . self::BALANCE_SUB_PREFIX . $uid;
    }
}
