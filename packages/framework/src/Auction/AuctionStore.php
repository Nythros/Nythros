<?php

declare(strict_types=1);

namespace Nythros\Framework\Auction;

/**
 * 交易行挂单存储（Redis 持久，无 TTL；购买/撤单走 Redis Lua 原子语义）。
 * The auction listing store (Redis-backed, no TTL; purchase/cancel run as atomic Redis Lua).
 *
 * 易失风险声明：挂单即托管资产，存储为 Redis 键——Redis 未持久化时重启即失（照 MailStoreInterface 声明口径）。
 * Volatility notice: listings are escrowed assets stored as Redis keys — lost on restart without persistence.
 *
 * 键设计（nythros:ec: 前缀）：
 * - nythros:ec:auction:{auctionId}  hash {seller, itemId, count, price, createdAt}（无 TTL；
 *   字段全为标量——Lua 可直接读字段做原子判定，无需嵌套解码，协议约束 V7 的嵌套 msgpack 路径不涉及存储层）
 * - nythros:ec:balance:{uid}        与 CurrencyLedger 共用的余额键（BALANCE_SUB_PREFIX 两侧一致，见其键契约）
 * Key design (nythros:ec: prefix):
 * - nythros:ec:auction:{auctionId}  hash {seller, itemId, count, price, createdAt} (no TTL; every field is a
 *   scalar so Lua can read fields for atomic verdicts without nested decoding — the V7 nested-msgpack wire path
 *   does not reach the storage layer)
 * - nythros:ec:balance:{uid}        the balance key shared with CurrencyLedger (identical BALANCE_SUB_PREFIX on
 *   both sides, see its key contract)
 */
final class AuctionStore
{
    /** 挂单 hash 键子前缀（相对基前缀） Listing hash key sub-prefix (relative to the base prefix). */
    private const AUCTION_SUB_PREFIX = 'auction:';

    /** 余额键子前缀（与 CurrencyLedger::BALANCE_SUB_PREFIX 保持一致，见其键契约） Balance key sub-prefix (kept identical to CurrencyLedger's, see its key contract). */
    private const BALANCE_SUB_PREFIX = CurrencyLedger::BALANCE_SUB_PREFIX;

    /** uid 格式白名单 uid format whitelist. */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** auctionId 格式白名单 auctionId format whitelist. */
    private const AUCTION_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/';

    /**
     * 购买结算 Lua（并发互斥核心：Redis 单线程执行脚本，两买家同时购买同一挂单恰有一个成功）。
     * 判定顺序：挂单存在 → 自购拦截 → 价格比对 → 买家余额充足；全过后原子完成「删单+双向转账」。
     * 成功时随返回值带回删单前快照（seller/itemId/count）——PHP 侧据此构造发货邮件，删单后无从补读。
     * 返回：{1, seller, itemId, count} 成功 | {-1} 挂单不存在 | {-2} 自购 | {-3} 价格不符 | {-4} 余额不足。
     * The purchase-settlement Lua (the mutual-exclusion core: Redis runs scripts single-threaded, so of two buyers
     * racing on one listing exactly one succeeds). Verdict order: listing exists → self-purchase guard → price
     * match → buyer balance suffices; then "delete listing + two-way transfer" commits atomically.
     * On success the pre-deletion snapshot (seller/itemId/count) rides the return — the PHP side builds the delivery
     * mail from it, since a post-deletion read is impossible.
     * Returns: {1, seller, itemId, count} ok | {-1} no listing | {-2} self-purchase | {-3} price mismatch | {-4} insufficient balance.
     * KEYS[1]=auction:{auctionId} KEYS[2]=balance:{buyerUid} KEYS[3]=balance:{sellerUid}
     * ARGV[1]=buyerUid ARGV[2]=price
     */
    private const PURCHASE_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    return {-1}
end
local seller = redis.call('HGET', KEYS[1], 'seller')
if seller == ARGV[1] then
    return {-2}
end
local storedPrice = tonumber(redis.call('HGET', KEYS[1], 'price'))
if storedPrice ~= tonumber(ARGV[2]) then
    return {-3}
end
local balance = tonumber(redis.call('GET', KEYS[2]) or '0')
if balance < storedPrice then
    return {-4}
end
local itemId = redis.call('HGET', KEYS[1], 'itemId')
local count = redis.call('HGET', KEYS[1], 'count')
redis.call('DECRBY', KEYS[2], storedPrice)
redis.call('INCRBY', KEYS[3], storedPrice)
redis.call('DEL', KEYS[1])
return {1, seller, itemId, count}
LUA;

    /**
     * 撤单 Lua（归属校验+完整性闸门+删单原子）：{1} 完整挂单已撤 | {2} 残缺挂单已直接删除 | {-1} 挂单不存在 | {-2} 非卖家本人。
     * 残缺判定：itemId 缺失/空串或 count 缺失/非正——create 的 HSETNX+hMSet 两步间进程崩溃会留下仅含
     * seller 的残缺 hash；此类记录货物信息不可信（退回邮件无从构造），删除是唯一安全动作，托管损失告警后人工对账。
     * The cancel Lua (ownership check + integrity gate + deletion atomically): {1} a complete listing cancelled |
     * {2} an incomplete listing deleted outright | {-1} no listing | {-2} not the seller.
     * Incompleteness reads as a missing/empty itemId or a missing/non-positive count — a crash between create's
     * HSETNX and hMSet leaves a seller-only hash; such records carry untrustworthy goods info (no return mail can be
     * built), deletion is the only safe move, and the escrow loss goes to manual reconciliation after an alert.
     * KEYS[1]=auction:{auctionId} ARGV[1]=sellerUid
     */
    private const CANCEL_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    return {-1}
end
if redis.call('HGET', KEYS[1], 'seller') ~= ARGV[1] then
    return {-2}
end
local itemId = redis.call('HGET', KEYS[1], 'itemId')
local count = tonumber(redis.call('HGET', KEYS[1], 'count') or '')
if itemId == nil or itemId == '' or count == nil or count <= 0 then
    redis.call('DEL', KEYS[1])
    return {2}
end
redis.call('DEL', KEYS[1])
return {1}
LUA;

    /** 键基前缀（默认 nythros:ec:，测试可注入隔离前缀） Base key prefix (defaults to nythros:ec:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造交易行存储。
     * Create the auction store.
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
     * 登记挂单（托管落库）；auctionId 已存在时返回 false（幂等防护）。
     * Registers a listing (escrow persisted); returns false when the auctionId already exists (an idempotency guard).
     *
     * @param string $auctionId 挂单唯一 id Unique listing id.
     * @param string $sellerUid 卖家 uid The seller uid.
     * @param string $itemId 物品 id Item id.
     * @param int $count 数量（正整数） Count (positive).
     * @param int $price 单价总额（正整数） Total price (positive).
     * @return bool true = 已登记；false = id 冲突 true when registered; false on an id clash.
     * @throws \InvalidArgumentException 参数格式非法 / 数量价格非正 Illegal formats or non-positive count/price.
     * @throws \RuntimeException Redis 写入失败 Redis write failed.
     */
    public function create(string $auctionId, string $sellerUid, string $itemId, int $count, int $price): bool
    {
        $this->assertAuctionId($auctionId);
        $this->assertUid($sellerUid);
        if ($count <= 0 || $price <= 0) {
            throw new \InvalidArgumentException(sprintf('AuctionStore: 数量与价格必须为正整数: %d/%d', $count, $price));
        }

        // HSET 单字段多对：phpredis 5 支持可变参数形态；NX 语义用 EXISTS 预检 + HSETNX 首字段实现
        // Multi-field HSET: phpredis 5 supports variadic; NX semantics via an EXISTS pre-check plus HSETNX on the first field
        $redis = $this->redis();
        $key = $this->auctionKey($auctionId);
        if (!$redis->hSetNx($key, 'seller', $sellerUid)) {
            return false;
        }

        $redis->hMSet($key, [
            'itemId' => $itemId,
            'count' => $count,
            'price' => $price,
            'createdAt' => microtime(true),
        ]);

        return true;
    }

    /**
     * 读取挂单。
     * Reads a listing.
     *
     * @return ?array{auctionId: string, seller: string, itemId: string, count: int, price: int, createdAt: float}
     *         挂单记录；不存在 null The listing record; null when absent.
     * @throws \InvalidArgumentException auctionId 格式非法 Illegal auctionId format.
     */
    public function get(string $auctionId): ?array
    {
        $this->assertAuctionId($auctionId);

        $flat = $this->redis()->hGetAll($this->auctionKey($auctionId));
        if ($flat === false || $flat === [] || !isset($flat['seller'])) {
            return null;
        }

        return [
            'auctionId' => $auctionId,
            'seller' => (string) $flat['seller'],
            'itemId' => (string) ($flat['itemId'] ?? ''),
            'count' => (int) ($flat['count'] ?? 0),
            'price' => (int) ($flat['price'] ?? 0),
            'createdAt' => (float) ($flat['createdAt'] ?? 0),
        ];
    }

    /**
     * 购买结算（Lua 原子）：成功返回 ok=true + 删单前快照（seller/itemId/count，供发货邮件构造）；
     * 失败返回 ok=false + code（no_listing/self_purchase/price_mismatch/insufficient_balance）。
     * 资金面（买家扣款+卖家入账）与删单在同一脚本内完成，任何一步失败整体不变更。
     * Purchase settlement (atomic Lua): success yields ok=true plus the pre-deletion snapshot (seller/itemId/count,
     * for building the delivery mail); failure yields ok=false plus a code (no_listing/self_purchase/
     * price_mismatch/insufficient_balance). The money side (buyer debit + seller credit) and the listing deletion
     * commit inside one script — any failed check mutates nothing.
     *
     * @return array{ok: bool, seller: ?string, itemId: string, count: int, code: string} 结算结果 The settlement verdict.
     * @throws \InvalidArgumentException 参数格式非法 Illegal argument format.
     * @throws \RuntimeException Lua 执行失败 Lua execution failed.
     */
    public function purchase(string $auctionId, string $buyerUid, int $price): array
    {
        $this->assertAuctionId($auctionId);
        $this->assertUid($buyerUid);

        // KEYS[3] 需要 seller uid 构造余额键，但 seller 本身是脚本的产出——先读一次快照构造键，
        // 脚本内部以实际 seller 为准返回（快照过期由 EXISTS/自购/价格三重校验兜底；挂单不存在时
        // 快照为空串，脚本首检 EXISTS 即拒绝，空串键永不被触碰）
        // KEYS[3] needs the seller uid to build the balance key, but the seller is itself the script's output — read a
        // snapshot first to build the key; the script returns the actual seller (a stale snapshot is backstopped by the
        // EXISTS/self-purchase/price triple checks; with no listing the snapshot is an empty string and the script's
        // leading EXISTS rejects, so the empty-string key is never touched)
        $snapshotSeller = $this->requireSellerForKeys($auctionId);
        $result = $this->evalScript(self::PURCHASE_SCRIPT, [
            $this->auctionKey($auctionId),
            $this->balanceKey($buyerUid),
            $this->balanceKey($snapshotSeller),
            $buyerUid,
            (string) $price,
        ], 3);

        if ($result[0] === 1) {
            return [
                'ok' => true,
                'seller' => $result[1] !== '' ? (string) $result[1] : null,
                'itemId' => (string) ($result[2] ?? ''),
                'count' => (int) ($result[3] ?? 0),
                'code' => 'ok',
            ];
        }

        $code = match ($result[0]) {
            -1 => 'no_listing',
            -2 => 'self_purchase',
            -3 => 'price_mismatch',
            default => 'insufficient_balance',
        };

        return ['ok' => false, 'seller' => null, 'itemId' => '', 'count' => 0, 'code' => $code];
    }

    /**
     * 撤单（Lua 原子归属校验+删单）：true = 已撤（含残缺挂单的直接删除路径——货物信息不可信，
     * 不构造退回邮件，托管损失已告警转人工对账）；false = 不存在或非卖家本人。
     * Cancel (atomic Lua ownership check + deletion): true = cancelled (the incomplete-listing path included —
     * goods info untrustworthy, no return mail is built, the escrow loss alerted for manual reconciliation);
     * false = absent or not the seller.
     *
     * @throws \InvalidArgumentException 参数格式非法 Illegal argument format.
     * @throws \RuntimeException Lua 执行失败 Lua execution failed.
     */
    public function cancel(string $auctionId, string $sellerUid): bool
    {
        $this->assertAuctionId($auctionId);
        $this->assertUid($sellerUid);

        $result = $this->evalScript(self::CANCEL_SCRIPT, [$this->auctionKey($auctionId), $sellerUid], 1);
        if ($result[0] === 2) {
            // 残缺挂单：已直接删除，告警转人工对账（托管货物无法定位退回）
            // An incomplete listing: deleted outright with an alert for manual reconciliation (the escrowed goods cannot be located for a return)
            error_log(sprintf('AuctionStore: 残缺挂单已直接删除（人工对账） auctionId=%s seller=%s', $auctionId, $sellerUid));

            return true;
        }

        return $result[0] === 1;
    }

    /**
     * 补偿路径读快照构造卖家余额键（purchase 内部使用；挂单不存在时回退空串键——脚本 EXISTS 首检即拒绝，
     * 空串键不会被触碰）。
     * Reads a snapshot to build the seller's balance key for compensation paths (used inside purchase; when the
     * listing is absent the fallback empty-string key is never touched — the script's leading EXISTS rejects first).
     */
    private function requireSellerForKeys(string $auctionId): string
    {
        $listing = $this->get($auctionId);

        return $listing === null ? '' : $listing['seller'];
    }

    /**
     * 执行 Lua 脚本并解析数组返回（照 RedisTeamStore 先例；异常归一为 RuntimeException 带归因）。
     * Runs a Lua script and parses the array return (following the RedisTeamStore precedent; failures normalize
     * into a RuntimeException with attribution).
     *
     * @param list<string> $args eval 参数（前 numKeys 项为 KEYS，其余为 ARGV） eval arguments (first numKeys are KEYS, the rest ARGV)
     * @return list<int|string> Lua 返回表 The Lua return table.
     */
    private function evalScript(string $script, array $args, int $numKeys): array
    {
        $result = $this->redis()->eval($script, $args, $numKeys);
        if (!is_array($result)) {
            throw new \RuntimeException(sprintf('AuctionStore Lua 执行失败: %s', (string) $this->redis()->getLastError()));
        }

        return array_map(
            static fn (mixed $item): int|string => is_int($item) || is_string($item) ? $item : '',
            array_values($result),
        );
    }

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
     * uid 格式白名单校验。
     * Validate the uid against its format whitelist.
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('AuctionStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * auctionId 格式白名单校验。
     * Validate the auctionId against its format whitelist.
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertAuctionId(string $auctionId): void
    {
        if (preg_match(self::AUCTION_ID_PATTERN, $auctionId) !== 1) {
            throw new \InvalidArgumentException(sprintf('AuctionStore: 非法 auctionId 格式: %s', $auctionId));
        }
    }

    /**
     * 挂单 hash 键：基前缀 + auction: + auctionId。
     * Listing hash key: base prefix + auction: + auctionId.
     */
    private function auctionKey(string $auctionId): string
    {
        return $this->prefix . self::AUCTION_SUB_PREFIX . $auctionId;
    }

    /**
     * 余额键：基前缀 + balance: + uid（与 CurrencyLedger 同规则）。
     * Balance key: base prefix + balance: + uid (same rule as CurrencyLedger).
     */
    private function balanceKey(string $uid): string
    {
        return $this->prefix . self::BALANCE_SUB_PREFIX . $uid;
    }
}
