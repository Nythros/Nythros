<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 组队状态机 Redis Lua 实现（跨进程「一 uid 一队」不变量，ADR-015 §1.6 修复版）。
 * Redis Lua implementation of the team state machine (cross-process one-uid-one-team invariant; ADR-015 §1.6 fixed edition).
 *
 * 5 个 Lua 脚本（invite/accept/reject/leave/disband）在 Redis 内原子读改写，返回码 0~9
 * 与 TeamStoreInterface / SocialService::handleTeam 的 team:error 映射一一对应。三条跨进程
 * 不变量（ADR-015 §1.6）逐脚本落实：
 * The five Lua scripts (invite/accept/reject/leave/disband) atomically read-modify-write inside Redis; return
 * codes 0~9 map one-to-one onto the TeamStoreInterface / SocialService::handleTeam team:error mapping. The three
 * cross-process invariants (ADR-015 §1.6) hold per script:
 *
 * - TTL 同步续期（BLOCKER-1）：写队伍的脚本在 EXPIRE team 时遍历 members 给每个成员 SETEX uid-team 同 TTL。
 * - TTL synchronous renewal (BLOCKER-1): every team-writing script EXPIREs the team hash and, while doing so,
 *   iterates members to SETEX each member's uid-team key with the same TTL.
 * - cjson 编码约定（MAJOR-2）：hash 内 members/invites 是 JSON 字符串，脚本内 cjson.decode 再操作、写回 cjson.encode；
 *   leaderUid 为纯字符串直读。
 * - cjson encoding contract (MAJOR-2): members/invites are JSON strings inside the hash; scripts cjson.decode them,
 *   operate, then write back cjson.encode; leaderUid is read directly as a plain string.
 * - 建队判队原子（BLOCKER-2）：senderTeamId 读判 + INCR seq + 建队在同一脚本原子完成，键名 'team:'..teamId
 *   字符串拼接动态构造（demo 规模单机 Redis 成立，见 ADR-015 §1.6 BLOCKER-2 文档化约束）。
 * - Atomic create-or-join (BLOCKER-2): the sender-team read-verdict + INCR seq + team creation are atomic in one
 *   script; the key name is dynamically built as 'team:'..teamId (valid at demo-scale single Redis; see the ADR-015
 *   §1.6 BLOCKER-2 documented constraint).
 *
 * 键设计（nythros:gw: 前缀，可注入，ADR-015 §2）：
 * - nythros:gw:team:{teamId}   hash {leaderUid, members:JSON list, invites:JSON list}（TTL 每次写操作续期）
 * - nythros:gw:team:seq        INCR 整数序列（无 TTL）
 * - nythros:gw:uid-team:{uid}  SETEX teamId（TTL 随每次队伍写操作同步续期）
 * Key design (nythros:gw: prefix, injectable; ADR-015 §2):
 * - nythros:gw:team:{teamId}   hash {leaderUid, members:JSON list, invites:JSON list} (TTL renewed on every write)
 * - nythros:gw:team:seq        INCR integer sequence (no TTL)
 * - nythros:gw:uid-team:{uid}  SETEX teamId (TTL synchronously renewed on every team write)
 *
 * 连接管理：与 RedisTokenStore/RedisServiceRegistry 相同的工厂模式——构造可传已连接的 \Redis 实例（单进程/测试），
 * 或传连接工厂闭包（Workerman 多 Worker：fork 后各进程首次使用时各自建连）。
 * Connection management: the same factory pattern as RedisTokenStore/RedisServiceRegistry — pass a connected \Redis
 * instance (single process / tests) or a connection-factory closure (multi-Worker Workerman: each process connects
 * on first use after fork).
 */
final class RedisTeamStore implements TeamStoreInterface
{
    /** 队伍 hash 键子前缀（相对基前缀） Team hash key sub-prefix (relative to the base prefix). */
    private const TEAM_SUB_PREFIX = 'team:';

    /** uid → 队伍键子前缀（相对基前缀） uid → team key sub-prefix (relative to the base prefix). */
    private const UID_TEAM_SUB_PREFIX = 'uid-team:';

    /** 队伍序号键后缀（相对基前缀） Team sequence key suffix (relative to the base prefix). */
    private const SEQ_KEY_SUFFIX = 'team:seq';

    /** uid 格式白名单（uid 进入 uid-team 键构造，收敛键注入面，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** teamId 格式白名单（team-{seq}，ADR-015 §2） teamId format whitelist (team-{seq}, ADR-015 §2). */
    private const TEAM_ID_PATTERN = '/^team-[0-9]+$/';

    /**
     * invite Lua（ADR-015 §1.6 修复版，逐行对照）。
     * KEYS[1]=uid-team:{sender} KEYS[2]=uid-team:{target} KEYS[3]=team:seq
     * ARGV[1]=sender ARGV[2]=target ARGV[3]=now ARGV[4]=maxSize ARGV[5]=teamTtl ARGV[6]=prefix
     * 返回：{0, teamId, leaderUid} ok | {1} not_leader | {2} target_in_team | {3} team_full | {7} team_not_found | {9} target_is_sender
     */
    private const INVITE_SCRIPT = <<<'LUA'
-- 判定顺序（终审 Issue 1 + MAJOR-1）：自邀(9) → target_in_team(2) → 读 sender 队 → 建队 / {7}{1}{3}
if ARGV[1] == ARGV[2] then
    return {9}
end
if redis.call('EXISTS', KEYS[2]) == 1 then
    return {2}
end
local senderTeamId = redis.call('GET', KEYS[1])
if senderTeamId == false then
    -- 建队分支（BLOCKER-2）：INCR seq 与判队同脚本原子；invites 直接写发给 target 的条目
    local teamId = 'team-' .. redis.call('INCR', KEYS[3])
    redis.call('HSET', ARGV[6] .. 'team:' .. teamId,
        'leaderUid', ARGV[1],
        'members', cjson.encode({ARGV[1]}),
        'invites', cjson.encode({{targetUid = ARGV[2], expiresAt = tonumber(ARGV[3]) + 30}}))
    redis.call('EXPIRE', ARGV[6] .. 'team:' .. teamId, ARGV[5])
    redis.call('SETEX', KEYS[1], ARGV[5], teamId)
    return {0, teamId, ARGV[1]}
end
local flat = redis.call('HGETALL', ARGV[6] .. 'team:' .. senderTeamId)
if next(flat) == nil then
    return {7}
end
local team = {}
for i = 1, #flat, 2 do
    team[flat[i]] = flat[i + 1]
end
if team['leaderUid'] ~= ARGV[1] then
    return {1}
end
local members = cjson.decode(team['members'])
if #members >= tonumber(ARGV[4]) then
    return {3}
end
local invites = cjson.decode(team['invites'])
local now = tonumber(ARGV[3])
local found = false
for i = 1, #invites do
    if invites[i].targetUid == ARGV[2] then
        invites[i].expiresAt = now + 30
        found = true
        break
    end
end
if not found then
    invites[#invites + 1] = {targetUid = ARGV[2], expiresAt = now + 30}
end
redis.call('HSET', ARGV[6] .. 'team:' .. senderTeamId, 'invites', cjson.encode(invites))
redis.call('EXPIRE', ARGV[6] .. 'team:' .. senderTeamId, ARGV[5])
for _, m in ipairs(members) do
    redis.call('SETEX', ARGV[6] .. 'uid-team:' .. m, ARGV[5], senderTeamId)
end
return {0, senderTeamId, team['leaderUid']}
LUA;

    /**
     * accept Lua（ADR-015 §1.6 修复版，MAJOR-7 判定顺序）。
     * KEYS[1]=uid-team:{uid} KEYS[2]=team:{teamId}
     * ARGV[1]=uid ARGV[2]=teamId ARGV[3]=now ARGV[4]=maxSize ARGV[5]=teamTtl ARGV[6]=prefix
     * 返回：{0, members} ok | {3} team_full | {4} invite_not_found | {5} invite_not_for_you | {6} already_in_team | {7} team_not_found
     */
    private const ACCEPT_SCRIPT = <<<'LUA'
-- ③ already_in_team（先于④队伍不存在判定）
if redis.call('EXISTS', KEYS[1]) == 1 then
    return {6}
end
local flat = redis.call('HGETALL', KEYS[2])
if next(flat) == nil then
    return {7}
end
local team = {}
for i = 1, #flat, 2 do
    team[flat[i]] = flat[i + 1]
end
local members = cjson.decode(team['members'])
local invites = cjson.decode(team['invites'])
local now = tonumber(ARGV[3])
local inviteIdx = nil
local hasActive = false
for i = 1, #invites do
    if invites[i].targetUid == ARGV[1] then
        inviteIdx = i
    end
    if invites[i].expiresAt >= now then
        hasActive = true
    end
end
if inviteIdx == nil then
    if hasActive then
        return {5}
    end
    return {4}
end
if invites[inviteIdx].expiresAt < now then
    return {4}
end
if #members >= tonumber(ARGV[4]) then
    return {3}
end
members[#members + 1] = ARGV[1]
table.remove(invites, inviteIdx)
redis.call('HSET', KEYS[2], 'members', cjson.encode(members), 'invites', cjson.encode(invites))
redis.call('EXPIRE', KEYS[2], ARGV[5])
for _, m in ipairs(members) do
    redis.call('SETEX', ARGV[6] .. 'uid-team:' .. m, ARGV[5], ARGV[2])
end
redis.call('SETEX', KEYS[1], ARGV[5], ARGV[2])
return {0, members}
LUA;

    /**
     * reject Lua（ADR-015 §1.6 修复版）。
     * KEYS[1]=team:{teamId}
     * ARGV[1]=uid ARGV[2]=teamId ARGV[3]=now ARGV[4]=teamTtl ARGV[5]=prefix
     * 返回：{0, leaderUid} ok | {4} invite_not_found | {5} invite_not_for_you
     */
    private const REJECT_SCRIPT = <<<'LUA'
local flat = redis.call('HGETALL', KEYS[1])
if next(flat) == nil then
    return {4}
end
local team = {}
for i = 1, #flat, 2 do
    team[flat[i]] = flat[i + 1]
end
local invites = cjson.decode(team['invites'])
local now = tonumber(ARGV[3])
local inviteIdx = nil
local hasActive = false
for i = 1, #invites do
    if invites[i].targetUid == ARGV[1] then
        inviteIdx = i
    end
    if invites[i].expiresAt >= now then
        hasActive = true
    end
end
if inviteIdx == nil then
    if hasActive then
        return {5}
    end
    return {4}
end
if invites[inviteIdx].expiresAt < now then
    return {4}
end
table.remove(invites, inviteIdx)
local members = cjson.decode(team['members'])
redis.call('HSET', KEYS[1], 'invites', cjson.encode(invites))
redis.call('EXPIRE', KEYS[1], ARGV[4])
for _, m in ipairs(members) do
    redis.call('SETEX', ARGV[5] .. 'uid-team:' .. m, ARGV[4], ARGV[2])
end
return {0, team['leaderUid']}
LUA;

    /**
     * leave Lua（ADR-015 §1.6 修复版）。
     * KEYS[1]=uid-team:{uid} KEYS[2]=team:{teamId}
     * ARGV[1]=uid ARGV[2]=teamId ARGV[3]=teamTtl ARGV[4]=prefix
     * 返回：{0, action, members} ok（action=left|disbanded） | {8} not_member
     */
    private const LEAVE_SCRIPT = <<<'LUA'
local flat = redis.call('HGETALL', KEYS[2])
if next(flat) == nil then
    return {8}
end
local team = {}
for i = 1, #flat, 2 do
    team[flat[i]] = flat[i + 1]
end
local members = cjson.decode(team['members'])
local isMember = false
for _, m in ipairs(members) do
    if m == ARGV[1] then
        isMember = true
        break
    end
end
if not isMember then
    return {8}
end
if team['leaderUid'] == ARGV[1] then
    -- 队长离开 = 解散：DEL team + 遍历 members DEL uid-team
    redis.call('DEL', KEYS[2])
    for _, m in ipairs(members) do
        redis.call('DEL', ARGV[4] .. 'uid-team:' .. m)
    end
    return {0, 'disbanded', members}
end
local remaining = {}
for _, m in ipairs(members) do
    if m ~= ARGV[1] then
        remaining[#remaining + 1] = m
    end
end
local invites = cjson.decode(team['invites'])
local kept = {}
for _, inv in ipairs(invites) do
    if inv.targetUid ~= ARGV[1] then
        kept[#kept + 1] = inv
    end
end
redis.call('HSET', KEYS[2], 'members', cjson.encode(remaining), 'invites', cjson.encode(kept))
redis.call('EXPIRE', KEYS[2], ARGV[3])
redis.call('DEL', KEYS[1])
for _, m in ipairs(remaining) do
    redis.call('SETEX', ARGV[4] .. 'uid-team:' .. m, ARGV[3], ARGV[2])
end
return {0, 'left', remaining}
LUA;

    /**
     * disband Lua（ADR-015 §1.6 修复版）。
     * KEYS[1]=team:{teamId}
     * ARGV[1]=uid ARGV[2]=teamId ARGV[3]=teamTtl ARGV[4]=prefix
     * 返回：{0, members} ok | {1} not_leader | {7} team_not_found
     */
    private const DISBAND_SCRIPT = <<<'LUA'
local flat = redis.call('HGETALL', KEYS[1])
if next(flat) == nil then
    return {7}
end
local team = {}
for i = 1, #flat, 2 do
    team[flat[i]] = flat[i + 1]
end
if team['leaderUid'] ~= ARGV[1] then
    return {1}
end
local members = cjson.decode(team['members'])
redis.call('DEL', KEYS[1])
for _, m in ipairs(members) do
    redis.call('DEL', ARGV[4] .. 'uid-team:' .. m)
end
return {0, members}
LUA;

    /** 键基前缀（默认 nythros:gw:，测试可注入隔离前缀） Base key prefix (defaults to nythros:gw:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造 Redis 组队存储。
     * Create the Redis team store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:gw:） Base key prefix (defaults to nythros:gw:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:gw:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function findByUid(string $uid): ?string
    {
        $this->assertUid($uid);

        $teamId = $this->redis()->get($this->uidTeamKey($uid));

        return is_string($teamId) && $teamId !== '' ? $teamId : null;
    }

    public function get(string $teamId): ?array
    {
        $this->assertTeamId($teamId);
        $redis = $this->redis();

        $rawLeader = $redis->hGet($this->teamKey($teamId), 'leaderUid');
        if (!is_string($rawLeader) || $rawLeader === '') {
            return null;
        }

        $rawMembers = $redis->hGet($this->teamKey($teamId), 'members');
        if (!is_string($rawMembers)) {
            return null;
        }

        return [
            'leaderUid' => $rawLeader,
            'members' => $this->decodeMembers($rawMembers),
        ];
    }

    public function invite(string $senderUid, string $targetUid, int $maxSize, int $teamTtl, float $now): array
    {
        $this->assertUid($senderUid);
        $this->assertUid($targetUid);

        $outcome = $this->run(self::INVITE_SCRIPT, [
            $this->uidTeamKey($senderUid),
            $this->uidTeamKey($targetUid),
            $this->seqKey(),
            $senderUid,
            $targetUid,
            (string) $now,
            (string) $maxSize,
            (string) $teamTtl,
            $this->prefix,
        ], 3);

        if ($outcome['code'] !== self::CODE_OK) {
            return ['code' => $outcome['code']];
        }

        return [
            'code' => self::CODE_OK,
            'teamId' => (string) $outcome['raw'][1],
            'leaderUid' => (string) $outcome['raw'][2],
        ];
    }

    public function accept(string $uid, string $teamId, int $maxSize, int $teamTtl, float $now): array
    {
        $this->assertUid($uid);
        $this->assertTeamId($teamId);

        $outcome = $this->run(self::ACCEPT_SCRIPT, [
            $this->uidTeamKey($uid),
            $this->teamKey($teamId),
            $uid,
            $teamId,
            (string) $now,
            (string) $maxSize,
            (string) $teamTtl,
            $this->prefix,
        ], 2);

        if ($outcome['code'] !== self::CODE_OK) {
            return ['code' => $outcome['code']];
        }

        return ['code' => self::CODE_OK, 'members' => $this->memberList($outcome['raw'][1])];
    }

    public function reject(string $uid, string $teamId, int $teamTtl, float $now): array
    {
        $this->assertUid($uid);
        $this->assertTeamId($teamId);

        $outcome = $this->run(self::REJECT_SCRIPT, [
            $this->teamKey($teamId),
            $uid,
            $teamId,
            (string) $now,
            (string) $teamTtl,
            $this->prefix,
        ], 1);

        if ($outcome['code'] !== self::CODE_OK) {
            return ['code' => $outcome['code']];
        }

        return ['code' => self::CODE_OK, 'leaderUid' => (string) $outcome['raw'][1]];
    }

    public function leave(string $uid, string $teamId, int $teamTtl): array
    {
        $this->assertUid($uid);
        $this->assertTeamId($teamId);

        $outcome = $this->run(self::LEAVE_SCRIPT, [
            $this->uidTeamKey($uid),
            $this->teamKey($teamId),
            $uid,
            $teamId,
            (string) $teamTtl,
            $this->prefix,
        ], 2);

        if ($outcome['code'] !== self::CODE_OK) {
            return ['code' => $outcome['code']];
        }

        return [
            'code' => self::CODE_OK,
            'action' => (string) $outcome['raw'][1],
            'members' => $this->memberList($outcome['raw'][2]),
        ];
    }

    public function disband(string $uid, string $teamId, int $teamTtl): array
    {
        $this->assertUid($uid);
        $this->assertTeamId($teamId);

        $outcome = $this->run(self::DISBAND_SCRIPT, [
            $this->teamKey($teamId),
            $uid,
            $teamId,
            (string) $teamTtl,
            $this->prefix,
        ], 1);

        if ($outcome['code'] !== self::CODE_OK) {
            return ['code' => $outcome['code']];
        }

        return ['code' => self::CODE_OK, 'members' => $this->memberList($outcome['raw'][1])];
    }

    /**
     * 执行组队 Lua 脚本并解析首元素返回码。
     * Run a team Lua script and parse the leading return code.
     *
     * @param string $script Lua 脚本 Lua script
     * @param list<string> $args eval 参数（前 numKeys 项为 KEYS，其余为 ARGV） eval arguments (first numKeys are KEYS, the rest ARGV)
     * @param int $numKeys KEYS 数量 Number of KEYS
     * @return array{code: int, raw: array<mixed>} 返回码 + 原始结果表 Return code + raw result table
     * @throws \RuntimeException Redis 执行失败或返回格式非法 Redis execution failed or illegal return format
     */
    private function run(string $script, array $args, int $numKeys): array
    {
        $result = $this->redis()->eval($script, $args, $numKeys);

        if ($result === false) {
            throw new \RuntimeException(sprintf('RedisTeamStore Lua 执行失败: %s', (string) $this->redis()->getLastError()));
        }
        if (!is_array($result) || !isset($result[0])) {
            throw new \RuntimeException('RedisTeamStore: Lua 返回格式非法');
        }

        return ['code' => (int) $result[0], 'raw' => $result];
    }

    /**
     * 将 Lua 返回的 members 表规整为 list<string>（uid 在 Redis 侧始终为字符串；数值型条目防御性 string 化）。
     * Normalize the Lua-returned members table into a list<string> (uids are always strings on the Redis side; numeric entries are defensively stringified).
     *
     * @return list<string> 成员 uid 列表 Member uid list.
     */
    private function memberList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $members = [];
        foreach ($raw as $member) {
            if (is_string($member) || is_int($member)) {
                $members[] = (string) $member;
            }
        }

        return $members;
    }

    /**
     * 解码 members JSON（PHP 侧读取用，get 下发 auth_ok.team）。
     * Decode the members JSON (used by the PHP-side read path; get delivers auth_ok.team).
     *
     * @return list<string> 成员 uid 列表 Member uid list.
     */
    private function decodeMembers(string $raw): array
    {
        try {
            $members = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($members)) {
            return [];
        }

        return array_values(array_filter(
            $members,
            static fn (mixed $member): bool => is_string($member),
        ));
    }

    /**
     * 获取当前进程使用的 phpredis 连接（工厂模式：每个 fork 出的进程各自建连一次）。
     * Get the phpredis connection used by the current process (factory mode: each forked process connects once on its own).
     *
     * @return \Redis 当前进程的 phpredis 连接 The phpredis connection of the current process.
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
            throw new \InvalidArgumentException(sprintf('RedisTeamStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * teamId 格式白名单校验（team-{seq}，进入键构造的字段收敛注入面）。
     * Validate the teamId against its format whitelist (team-{seq}; narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertTeamId(string $teamId): void
    {
        if (preg_match(self::TEAM_ID_PATTERN, $teamId) !== 1) {
            throw new \InvalidArgumentException(sprintf('RedisTeamStore: 非法 teamId 格式: %s', $teamId));
        }
    }

    /**
     * 队伍 hash 键：基前缀 + team: + teamId。
     * Team hash key: base prefix + team: + teamId.
     */
    private function teamKey(string $teamId): string
    {
        return $this->prefix . self::TEAM_SUB_PREFIX . $teamId;
    }

    /**
     * uid → 队伍键：基前缀 + uid-team: + uid。
     * uid → team key: base prefix + uid-team: + uid.
     */
    private function uidTeamKey(string $uid): string
    {
        return $this->prefix . self::UID_TEAM_SUB_PREFIX . $uid;
    }

    /**
     * 队伍序号键：基前缀 + team:seq。
     * Team sequence key: base prefix + team:seq.
     */
    private function seqKey(): string
    {
        return $this->prefix . self::SEQ_KEY_SUFFIX;
    }
}
