<?php

declare(strict_types=1);

namespace Nythros\Framework\Social;

/**
 * 帮派存储（Redis 持久，无 TTL）：最小 join/leave 面 + R3 正式化面（建会/解散/踢人/职位/公告/审批/人数上限）。
 * Guild store (Redis-backed, no TTL): the minimal join/leave surface plus the R3 formalized surface
 * (create/disband/kick/roles/notice/approval/size cap).
 *
 * 键设计（nythros:gw: 前缀，ADR-015 §2）：
 * - nythros:gw:guild:{guildId}   hash {name, notice, maxMembers, leaderUid,
 *                                  members:JSON list, roles:JSON map{uid=>role}, applicants:JSON list}
 * - nythros:gw:uid-guild:{uid}   string = guildId（一 uid 一帮）
 * Key design (nythros:gw: prefix, ADR-015 §2):
 * - nythros:gw:guild:{guildId}   hash {name, notice, maxMembers, leaderUid,
 *                                  members: JSON list, roles: JSON map {uid => role}, applicants: JSON list}
 * - nythros:gw:uid-guild:{uid}   string = guildId (one uid → one guild)
 *
 * 权限矩阵表驱动（PERMISSION_MATRIX 为唯一事实源）；踢人另受阶位约束（只能踢低于自己阶位的目标，
 * 会长为最高阶不可被踢）。demo 规模单机 Redis 下采用非原子读写（与 ADR-015 §1.6 BLOCKER 说明同口径——
 * 跨进程不变量仅约束 Team；帮派操作由 SocialService 单入口串行化到可接受程度）。
 * The table-driven permission matrix (PERMISSION_MATRIX is the single source of truth); kicking is additionally
 * rank-constrained (only targets ranked below the operator; the leader ranks highest and can never be kicked).
 * Non-atomic read-modify-write at demo scale on a single Redis (same as the ADR-015 §1.6 BLOCKER notes —
 * cross-process invariants constrain Team only; guild operations funnel through SocialService's single entry).
 */
final class GuildStore implements GuildStoreInterface
{
    /** 帮派 hash 键子前缀（相对基前缀） Guild hash key sub-prefix (relative to the base prefix). */
    private const GUILD_SUB_PREFIX = 'guild:';

    /** uid → 帮派键子前缀（相对基前缀） uid → guild key sub-prefix (relative to the base prefix). */
    private const UID_GUILD_SUB_PREFIX = 'uid-guild:';

    /** uid 格式白名单（uid 进入 uid-guild 键构造，ADR-015 §2） uid format whitelist (uid enters key construction). */
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** guildId 格式白名单（SERVICE_ID 风格，进入 guild 键构造，ADR-015 §2） guildId format whitelist (SERVICE_ID style, enters key construction). */
    private const GUILD_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._#:-]{0,63}$/';

    /** 人数上限缺省值（legacy join 隐式建会时使用） Default member cap (used by legacy join's implicit creation). */
    private const DEFAULT_MAX_MEMBERS = 100;

    /**
     * 权限矩阵：操作 → 允许职位（表驱动校验的唯一事实源）。
     * The permission matrix: operation → allowed roles (the single source of truth for table-driven checks).
     *
     * @var array<string, list<string>>
     */
    private const PERMISSION_MATRIX = [
        'disband' => [self::ROLE_LEADER],
        'kick' => [self::ROLE_LEADER, self::ROLE_OFFICER],
        'promote' => [self::ROLE_LEADER],
        'notice' => [self::ROLE_LEADER, self::ROLE_OFFICER],
        'approve' => [self::ROLE_LEADER, self::ROLE_OFFICER],
    ];

    /**
     * 职位阶位（踢人层级判定：仅能踢低于自己阶位的目标）。
     * Role ranks (kick hierarchy: only targets ranked below the operator are kickable).
     *
     * @var array<string, int>
     */
    private const ROLE_RANKS = [
        self::ROLE_LEADER => 3,
        self::ROLE_OFFICER => 2,
        self::ROLE_MEMBER => 1,
    ];

    /** 键基前缀（默认 nythros:gw:，测试可注入隔离前缀） Base key prefix (defaults to nythros:gw:, tests inject an isolated prefix). */
    private readonly string $prefix;

    /** @var \Redis|\Closure(): \Redis 已连接的 phpredis 客户端，或返回已连接客户端的工厂 Connected phpredis client, or a factory returning a connected client */
    private \Redis|\Closure $redis;

    /**
     * 构造帮派存储。
     * Create the guild store.
     *
     * @param \Redis|\Closure(): \Redis $redis 已连接的 phpredis 客户端，或连接工厂 Connected phpredis client, or a connection factory
     * @param string $prefix 键基前缀（默认 nythros:gw:） Base key prefix (defaults to nythros:gw:)
     */
    public function __construct(\Redis|\Closure $redis, string $prefix = 'nythros:gw:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function create(string $uid, string $guildId, ?string $name, int $maxMembers): array
    {
        $this->assertUid($uid);
        $this->assertGuildId($guildId);
        if ($maxMembers < 1) {
            throw new \InvalidArgumentException(sprintf('GuildStore: maxMembers 必须为正整数: %d', $maxMembers));
        }

        // 换帮拦截：creator 已有帮派（含本帮）→ ALREADY_IN_GUILD
        // Guild-switch guard: the creator already has a guild (this one included) → ALREADY_IN_GUILD
        if ($this->findByUid($uid) !== null) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }

        $redis = $this->redis();
        if ($redis->hLen($this->guildKey($guildId)) > 0) {
            return ['code' => self::CODE_GUILD_EXISTS];
        }

        $redis->hMSet($this->guildKey($guildId), [
            'name' => $name ?? '',
            'notice' => '',
            'maxMembers' => (string) $maxMembers,
            'leaderUid' => $uid,
            'members' => json_encode([$uid], JSON_THROW_ON_ERROR),
            'roles' => json_encode([$uid => self::ROLE_LEADER], JSON_THROW_ON_ERROR),
            'applicants' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        $redis->set($this->uidGuildKey($uid), $guildId);

        return ['code' => self::CODE_OK];
    }

    public function disband(string $operatorUid, string $guildId): array
    {
        $this->assertUid($operatorUid);
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'disband');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        $members = $guild['members'];
        $redis = $this->redis();
        $redis->del($this->guildKey($guildId));
        foreach ($members as $member) {
            $redis->del($this->uidGuildKey($member));
        }

        return ['code' => self::CODE_OK, 'members' => $members];
    }

    public function kick(string $operatorUid, string $targetUid, string $guildId): array
    {
        $this->assertUid($operatorUid);
        $this->assertUid($targetUid);
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'kick');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        // 目标校验：非成员 / 自指 / 阶位不低于操作者（含会长不可被踢）→ TARGET_INVALID
        // Target validation: not a member / self / rank not below the operator (the leader included) → TARGET_INVALID
        $targetRole = $guild['roles'][$targetUid] ?? null;
        if ($targetRole === null || $targetUid === $operatorUid
            || self::ROLE_RANKS[$targetRole] >= self::ROLE_RANKS[$guild['roles'][$operatorUid]]
        ) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $this->removeMember($guildId, $targetUid);

        return ['code' => self::CODE_OK];
    }

    public function promote(string $operatorUid, string $targetUid, string $guildId, string $role): array
    {
        $this->assertUid($operatorUid);
        $this->assertUid($targetUid);
        $this->assertGuildId($guildId);

        // 任命目标职位只允许 officer/member（会长职位不可授予——不提供会长转移）
        // The promoted role only allows officer/member (the leader role is never granted — no leadership transfer)
        if ($role !== self::ROLE_OFFICER && $role !== self::ROLE_MEMBER) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'promote');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        // 目标校验：非成员 / 自指 / 目标是会长 → TARGET_INVALID
        // Target validation: not a member / self / the target is the leader → TARGET_INVALID
        $targetRole = $guild['roles'][$targetUid] ?? null;
        if ($targetRole === null || $targetUid === $operatorUid || $targetRole === self::ROLE_LEADER) {
            return ['code' => self::CODE_TARGET_INVALID];
        }

        $roles = $guild['roles'];
        $roles[$targetUid] = $role;
        $this->redis()->hSet($this->guildKey($guildId), 'roles', json_encode($roles, JSON_THROW_ON_ERROR));

        return ['code' => self::CODE_OK];
    }

    public function setNotice(string $operatorUid, string $guildId, string $notice): array
    {
        $this->assertUid($operatorUid);
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($operatorUid, $guild, 'notice');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }

        $this->redis()->hSet($this->guildKey($guildId), 'notice', $notice);

        return ['code' => self::CODE_OK];
    }

    public function apply(string $uid, string $guildId): array
    {
        $this->assertUid($uid);
        $this->assertGuildId($guildId);

        // 已有帮派（含本帮成员重复申请）→ ALREADY_IN_GUILD
        // Already in a guild (members re-applying included) → ALREADY_IN_GUILD
        if ($this->findByUid($uid) !== null) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        if (in_array($uid, $guild['applicants'], true)) {
            return ['code' => self::CODE_ALREADY_APPLIED];
        }
        if (count($guild['members']) >= $guild['maxMembers']) {
            return ['code' => self::CODE_GUILD_FULL];
        }

        $guild['applicants'][] = $uid;
        $this->redis()->hSet($this->guildKey($guildId), 'applicants', json_encode($guild['applicants'], JSON_THROW_ON_ERROR));

        return ['code' => self::CODE_OK];
    }

    public function approve(string $approverUid, string $applicantUid, string $guildId, bool $accept): array
    {
        $this->assertUid($approverUid);
        $this->assertUid($applicantUid);
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return ['code' => self::CODE_GUILD_NOT_FOUND];
        }
        $verdict = $this->checkPermission($approverUid, $guild, 'approve');
        if ($verdict !== null) {
            return ['code' => $verdict];
        }
        if (!in_array($applicantUid, $guild['applicants'], true)) {
            return ['code' => self::CODE_APPLICATION_NOT_FOUND];
        }

        // 先摘申请再分支：拒绝路径到此结束；接受路径受换帮/满员约束
        // Remove the application first, then branch: rejection ends here; acceptance is guarded by guild-switch/full checks
        $guild['applicants'] = array_values(array_filter(
            $guild['applicants'],
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));

        if (!$accept) {
            $this->redis()->hSet($this->guildKey($guildId), 'applicants', json_encode($guild['applicants'], JSON_THROW_ON_ERROR));

            return ['code' => self::CODE_OK];
        }

        if ($this->findByUid($applicantUid) !== null) {
            return ['code' => self::CODE_ALREADY_IN_GUILD];
        }
        if (count($guild['members']) >= $guild['maxMembers']) {
            return ['code' => self::CODE_GUILD_FULL];
        }

        $this->appendMember($guildId, $applicantUid, self::ROLE_MEMBER);
        $this->redis()->hSet($this->guildKey($guildId), 'applicants', json_encode($guild['applicants'], JSON_THROW_ON_ERROR));

        return ['code' => self::CODE_OK];
    }

    public function roleOf(string $uid, string $guildId): ?string
    {
        $this->assertUid($uid);
        $this->assertGuildId($guildId);

        return $this->readGuild($guildId)['roles'][$uid] ?? null;
    }

    public function members(string $guildId): array
    {
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            return [];
        }

        $members = [];
        foreach ($guild['members'] as $member) {
            $members[] = ['uid' => $member, 'role' => $guild['roles'][$member] ?? self::ROLE_MEMBER];
        }

        return $members;
    }

    public function join(string $uid, string $guildId): bool
    {
        $this->assertUid($uid);
        $this->assertGuildId($guildId);

        // 换帮拦截：uid 已在其他帮派 → false（SocialService 映射 403 already_in_guild）
        // Guild-switch guard: uid already in a different guild → false (SocialService maps it to 403 already_in_guild)
        $existing = $this->findByUid($uid);
        if ($existing !== null && $existing !== $guildId) {
            return false;
        }
        if ($existing === $guildId) {
            return true;
        }

        $guild = $this->readGuild($guildId);
        if ($guild === null) {
            // legacy 兼容：join 隐式建会（无会长的演示形态，verify-phase5 依赖此行为）——先落基础 hash 再追加成员
            // Legacy compatibility: join implicitly creates the guild (a leaderless demo shape verify-phase5 relies
            // on) — write the base hash first, then append the member
            // 边界提示：此路径产出的孤儿会（无会长、无职位体系）仅供 demo；生产形态必须走 guild:create
            // Boundary note: the orphan guild this path yields (no leader, no role hierarchy) is demo-only; production must go through guild:create
            $redis = $this->redis();
            $redis->hMSet($this->guildKey($guildId), [
                'name' => '',
                'notice' => '',
                'maxMembers' => (string) self::DEFAULT_MAX_MEMBERS,
                'leaderUid' => '',
                'members' => json_encode([], JSON_THROW_ON_ERROR),
                'roles' => json_encode([], JSON_THROW_ON_ERROR),
                'applicants' => json_encode([], JSON_THROW_ON_ERROR),
            ]);
            $this->appendMember($guildId, $uid, self::ROLE_MEMBER);

            return true;
        }
        if (count($guild['members']) >= $guild['maxMembers']) {
            return false;
        }

        $this->appendMember($guildId, $uid, self::ROLE_MEMBER);

        return true;
    }

    public function leave(string $uid, string $guildId): bool
    {
        $this->assertUid($uid);
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);
        if ($guild === null || !in_array($uid, $guild['members'], true)) {
            return false;
        }

        $remaining = array_values(array_filter(
            $guild['members'],
            static fn (string $member): bool => $member !== $uid,
        ));
        $redis = $this->redis();

        if ($remaining === []) {
            $redis->del($this->guildKey($guildId));
        } else {
            $roles = $guild['roles'];
            unset($roles[$uid]);
            $redis->hSet($this->guildKey($guildId), 'members', json_encode($remaining, JSON_THROW_ON_ERROR));
            $redis->hSet($this->guildKey($guildId), 'roles', json_encode($roles, JSON_THROW_ON_ERROR));
        }
        $redis->del($this->uidGuildKey($uid));

        return true;
    }

    public function findByUid(string $uid): ?string
    {
        $this->assertUid($uid);

        $guildId = $this->redis()->get($this->uidGuildKey($uid));

        return is_string($guildId) && $guildId !== '' ? $guildId : null;
    }

    public function get(string $guildId): ?array
    {
        $this->assertGuildId($guildId);

        $guild = $this->readGuild($guildId);

        return $guild === null ? null : ['name' => $guild['name'], 'notice' => $guild['notice'], 'members' => $guild['members']];
    }

    /**
     * 追加成员：members 列表 + roles 映射 + uid-guild 索引三处同步写。
     * Append a member: members list + roles map + the uid-guild index written in sync.
     */
    private function appendMember(string $guildId, string $uid, string $role): void
    {
        $guild = $this->readGuild($guildId) ?? throw new \LogicException('guild must exist when appending a member');

        if (!in_array($uid, $guild['members'], true)) {
            $guild['members'][] = $uid;
        }
        $guild['roles'][$uid] = $role;

        $redis = $this->redis();
        $redis->hMSet($this->guildKey($guildId), [
            'members' => json_encode($guild['members'], JSON_THROW_ON_ERROR),
            'roles' => json_encode($guild['roles'], JSON_THROW_ON_ERROR),
        ]);
        $redis->set($this->uidGuildKey($uid), $guildId);
    }

    /**
     * 移除成员：members/roles 同步更新；最后一人离帮删 hash；清 uid-guild 索引。
     * Remove a member: members/roles updated in sync; the last member leaving deletes the hash; the uid-guild index cleared.
     */
    private function removeMember(string $guildId, string $uid): void
    {
        $guild = $this->readGuild($guildId) ?? throw new \LogicException('guild must exist when removing a member');

        $remaining = array_values(array_filter(
            $guild['members'],
            static fn (string $member): bool => $member !== $uid,
        ));
        unset($guild['roles'][$uid]);

        $redis = $this->redis();
        if ($remaining === []) {
            $redis->del($this->guildKey($guildId));
        } else {
            $redis->hMSet($this->guildKey($guildId), [
                'members' => json_encode($remaining, JSON_THROW_ON_ERROR),
                'roles' => json_encode($guild['roles'], JSON_THROW_ON_ERROR),
            ]);
        }
        $redis->del($this->uidGuildKey($uid));
    }

    /**
     * 权限矩阵表驱动校验：非成员 NOT_MEMBER；职位不在允许表 PERMISSION_DENIED；通过返回 null。
     * The table-driven permission-matrix check: not a member reads NOT_MEMBER; a role outside the allowed table reads
     * PERMISSION_DENIED; passing returns null.
     *
     * @param array{name: ?string, notice: string, maxMembers: int, leaderUid: ?string, members: list<string>, roles: array<string, string>, applicants: list<string>} $guild
     */
    private function checkPermission(string $operatorUid, array $guild, string $operation): ?int
    {
        $role = $guild['roles'][$operatorUid] ?? null;
        if ($role === null) {
            return self::CODE_NOT_MEMBER;
        }
        if (!in_array($role, self::PERMISSION_MATRIX[$operation], true)) {
            return self::CODE_PERMISSION_DENIED;
        }

        return null;
    }

    /**
     * 读取帮派全量数据并解码 JSON 字段；不存在返回 null。
     * Read and decode the full guild data; null when absent.
     *
     * @return ?array{name: ?string, notice: string, maxMembers: int, leaderUid: ?string, members: list<string>, roles: array<string, string>, applicants: list<string>}
     */
    private function readGuild(string $guildId): ?array
    {
        $raw = $this->redis()->hGetAll($this->guildKey($guildId));
        if ($raw === false || $raw === []) {
            return null;
        }

        $members = $this->decodeStringList($raw['members'] ?? '[]');
        $rolesRaw = $this->decodeMap($raw['roles'] ?? '{}');
        $roles = [];
        foreach ($rolesRaw as $member => $role) {
            if (is_string($role) && in_array($role, [self::ROLE_LEADER, self::ROLE_OFFICER, self::ROLE_MEMBER], true)) {
                $roles[(string) $member] = $role;
            }
        }

        $rawName = $raw['name'] ?? '';
        $rawLeader = $raw['leaderUid'];

        return [
            'name' => is_string($rawName) && $rawName !== '' ? $rawName : null,
            'notice' => is_string($raw['notice'] ?? null) ? $raw['notice'] : '',
            'maxMembers' => is_numeric($raw['maxMembers'] ?? null) ? (int) $raw['maxMembers'] : self::DEFAULT_MAX_MEMBERS,
            'leaderUid' => is_string($rawLeader) && $rawLeader !== '' ? $rawLeader : null,
            'members' => $members,
            'roles' => $roles,
            'applicants' => $this->decodeStringList($raw['applicants'] ?? '[]'),
        ];
    }

    /**
     * 解码字符串列表 JSON；畸形/非字符串条目防御性丢弃。
     * Decode a JSON string list; malformed / non-string entries are defensively dropped.
     *
     * @return list<string>
     */
    private function decodeStringList(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $entry): bool => is_string($entry),
        ));
    }

    /**
     * 解码字符串映射 JSON；畸形条目防御性丢弃。
     * Decode a JSON string map; malformed entries are defensively dropped.
     *
     * @return array<string, mixed>
     */
    private function decodeMap(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
            throw new \InvalidArgumentException(sprintf('GuildStore: 非法 uid 格式: %s', $uid));
        }
    }

    /**
     * guildId 格式白名单校验（进入键构造的字段收敛注入面）。
     * Validate the guildId against its format whitelist (narrowing the injection surface of key-constructing fields).
     *
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private function assertGuildId(string $guildId): void
    {
        if (preg_match(self::GUILD_ID_PATTERN, $guildId) !== 1) {
            throw new \InvalidArgumentException(sprintf('GuildStore: 非法 guildId 格式: %s', $guildId));
        }
    }

    /**
     * 帮派 hash 键：基前缀 + guild: + guildId。
     * Guild hash key: base prefix + guild: + guildId.
     */
    private function guildKey(string $guildId): string
    {
        return $this->prefix . self::GUILD_SUB_PREFIX . $guildId;
    }

    /**
     * uid → 帮派键：基前缀 + uid-guild: + uid。
     * uid → guild key: base prefix + uid-guild: + uid.
     */
    private function uidGuildKey(string $uid): string
    {
        return $this->prefix . self::UID_GUILD_SUB_PREFIX . $uid;
    }
}
