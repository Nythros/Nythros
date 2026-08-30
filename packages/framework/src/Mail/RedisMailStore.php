<?php

declare(strict_types=1);

namespace Nythros\Framework\Mail;

/**
 * 邮件存储 Redis 实现（照 GuildStore 先例：\Redis|\Closure 构造 + 键前缀 + 格式白名单，无 TTL 持久）。
 * Mail store, Redis-backed (following the GuildStore precedent: a \Redis|\Closure constructor, key prefixes and
 * format whitelists; persistent with no TTL).
 *
 * 易失风险声明：见 MailStoreInterface——邮件承载托管资产，Redis 未持久化时重启即失。
 * Volatility notice: see MailStoreInterface — mail carries escrowed assets and is lost on restart without persistence.
 *
 * 键设计（nythros:ml: 前缀）：
 * - nythros:ml:mailbox:{uid}  hash {mailId => JSON 记录}（无 TTL；记录含嵌套附件列表，JSON 编码存储，
 *   与 GuildStore members 同口径；线上帧的附件嵌套负载另走 MsgpackSerializer 路径，协议约束 V7）
 * - nythros:ml:claimed:{uid}  set of mailId（领取幂等闸门，Lua 原子判定）
 * Key design (nythros:ml: prefix):
 * - nythros:ml:mailbox:{uid}  hash {mailId => JSON record} (no TTL; the record holds the nested attachment list,
 *   JSON-encoded like GuildStore's members; on the wire the nested attachment payload rides the MsgpackSerializer
 *   path per protocol constraint V7)
 * - nythros:ml:claimed:{uid}  set of mailId (the claim idempotency gate, judged atomically in Lua)
 */
final class RedisMailStore implements MailStoreInterface
{
    /** 收件箱 hash 键子前缀（相对基前缀） Mailbox hash key sub-prefix (relative to the base prefix). */
    private const MAILBOX_SUB_PREFIX = 'mailbox:';

    /** 领取闸门 set 键子前缀（相对基前缀） Claim-gate set key sub-prefix (relative to the base prefix). */
    private const CLAIMED_SUB_PREFIX = 'claimed:';

    /** uid 格式白名单（uid 进入键构造，收敛键注入面） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** mailId 格式白名单（进入 hash field 构造） mailId format whitelist (enters hash-field construction). */
    private const MAIL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/';

    /**
     * 领取闸门 Lua（原子 SISMEMBER+SADD）：已领取返回 0，首次领取写入并返回 1。
     * The claim-gate Lua (atomic SISMEMBER+SADD): returns 0 when already claimed, writes and returns 1 on first claim.
     * KEYS[1]=claimed:{uid} ARGV[1]=mailId
     */
    private const CLAIM_GATE_SCRIPT = <<<'LUA'
if redis.call('SISMEMBER', KEYS[1], ARGV[1]) == 1 then
    return 0
end
redis.call('SADD', KEYS[1], ARGV[1])
return 1
LUA;

    /** 键基前缀（默认 nythros:ml:，测试可注入隔离前缀） Base key prefix (defaults to nythros:ml:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造邮件存储。
     * Create the mail store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:ml:） Base key prefix (defaults to nythros:ml:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:ml:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function insert(string $toUid, string $mailId, string $fromUid, string $title, string $body, array $attachments): void
    {
        $this->assertUid($toUid);
        $this->assertMailId($mailId);

        $record = [
            'from' => $fromUid,
            'title' => $title,
            'body' => $body,
            'attachments' => $attachments,
            'sentAt' => microtime(true),
        ];

        $result = $this->redis()->hSet(
            $this->mailboxKey($toUid),
            $mailId,
            json_encode($record, JSON_THROW_ON_ERROR),
        );
        if ($result === false) {
            throw new \RuntimeException(sprintf('RedisMailStore insert 失败: %s', (string) $this->redis()->getLastError()));
        }
    }

    public function get(string $uid, string $mailId): ?array
    {
        $this->assertUid($uid);
        $this->assertMailId($mailId);

        $raw = $this->redis()->hGet($this->mailboxKey($uid), $mailId);

        return $raw === false ? null : $this->decodeRecord($raw, $mailId);
    }

    public function listByUid(string $uid): array
    {
        $this->assertUid($uid);

        $all = $this->redis()->hGetAll($this->mailboxKey($uid));
        if ($all === false || $all === []) {
            return [];
        }

        $records = [];
        foreach ($all as $mailId => $raw) {
            $record = $this->decodeRecord((string) $raw, (string) $mailId);
            if ($record !== null) {
                $records[] = $record;
            }
        }
        usort($records, static fn (array $a, array $b): int => $a['sentAt'] <=> $b['sentAt']);

        return $records;
    }

    public function claimGate(string $uid, string $mailId): bool
    {
        $this->assertUid($uid);
        $this->assertMailId($mailId);

        $result = $this->redis()->eval(self::CLAIM_GATE_SCRIPT, [$this->claimedKey($uid), $mailId], 1);

        return $result === 1;
    }

    public function releaseClaimGate(string $uid, string $mailId): void
    {
        $this->assertUid($uid);
        $this->assertMailId($mailId);

        $this->redis()->sRem($this->claimedKey($uid), $mailId);
    }

    public function delete(string $uid, string $mailId): bool
    {
        $this->assertUid($uid);
        $this->assertMailId($mailId);

        $deleted = $this->redis()->hDel($this->mailboxKey($uid), $mailId);
        $this->redis()->sRem($this->claimedKey($uid), $mailId);

        return $deleted > 0;
    }

    /**
     * 解码邮件 JSON 记录并补齐 mailId 字段；畸形记录防御性返回 null。
     * Decodes the mail JSON record, filling in the mailId field; malformed records defensively return null.
     *
     * @return ?array{mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}
     */
    private function decodeRecord(string $raw, string $mailId): ?array
    {
        try {
            $record = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($record)) {
            return null;
        }

        $attachments = [];
        foreach ($record['attachments'] ?? [] as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['itemId'] ?? null) || !is_int($attachment['count'] ?? null)) {
                continue;
            }
            $attachments[] = ['itemId' => $attachment['itemId'], 'count' => $attachment['count']];
        }

        return [
            'mailId' => $mailId,
            'from' => is_string($record['from'] ?? null) ? $record['from'] : '',
            'title' => is_string($record['title'] ?? null) ? $record['title'] : '',
            'body' => is_string($record['body'] ?? null) ? $record['body'] : '',
            'attachments' => $attachments,
            'sentAt' => is_float($record['sentAt'] ?? null) || is_int($record['sentAt'] ?? null) ? (float) $record['sentAt'] : 0.0,
        ];
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
     * uid 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the uid against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertUid(string $uid): void
    {
        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisMailStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * mailId 格式白名单校验（进入 hash field 构造的字段收敛注入面）。
     * Validate the mailId against its format whitelist (narrowing the injection surface of hash-field construction).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertMailId(string $mailId): void
    {
        if (preg_match(self::MAIL_ID_PATTERN, $mailId) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisMailStore: 非法 mailId 格式: %s', $mailId));
        }
    }

    /**
     * 收件箱 hash 键：基前缀 + mailbox: + uid。
     * Mailbox hash key: base prefix + mailbox: + uid.
     */
    private function mailboxKey(string $uid): string
    {
        return $this->prefix . self::MAILBOX_SUB_PREFIX . $uid;
    }

    /**
     * 领取闸门 set 键：基前缀 + claimed: + uid。
     * Claim-gate set key: base prefix + claimed: + uid.
     */
    private function claimedKey(string $uid): string
    {
        return $this->prefix . self::CLAIMED_SUB_PREFIX . $uid;
    }
}
