<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Social\GuildStore;
use Nythros\Framework\Social\GuildStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * GuildStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for GuildStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:gw: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:gw: keys.
 */
final class GuildStoreTest extends TestCase
{
    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 GuildStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 GuildStore 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':gw:';
    }

    protected function tearDown(): void
    {
        if ($this->redis === null) {
            return;
        }

        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
        $this->redis = null;
    }

    private function store(): GuildStore
    {
        return new GuildStore($this->redis, $this->prefix);
    }

    public function testJoinThenFindByUidAndGet(): void
    {
        $store = $this->store();

        $store->join('u1', 'guild-1');

        self::assertSame('guild-1', $store->findByUid('u1'));

        $guild = $store->get('guild-1');
        self::assertNotNull($guild);
        self::assertSame(['u1'], $guild['members']);
        self::assertNull($guild['name']);
    }

    public function testJoinIsIdempotent(): void
    {
        $store = $this->store();

        $store->join('u1', 'guild-1');
        $store->join('u1', 'guild-1');

        $guild = $store->get('guild-1');
        self::assertNotNull($guild);
        self::assertSame(['u1'], $guild['members']);
    }

    public function testLeaveNonMemberReturnsFalse(): void
    {
        $store = $this->store();

        $store->join('u1', 'guild-1');

        self::assertFalse($store->leave('u2', 'guild-1'));
        // 非成员离开不影响现有成员
        self::assertSame('guild-1', $store->findByUid('u1'));
    }

    public function testLeaveMemberReturnsTrueAndClearsIndex(): void
    {
        $store = $this->store();

        $store->join('u1', 'guild-1');
        $store->join('u2', 'guild-1');

        self::assertTrue($store->leave('u1', 'guild-1'));
        self::assertNull($store->findByUid('u1'));

        $guild = $store->get('guild-1');
        self::assertNotNull($guild);
        self::assertSame(['u2'], $guild['members']);
    }

    public function testLastMemberLeaveDeletesHash(): void
    {
        $store = $this->store();

        $store->join('u1', 'guild-1');
        self::assertTrue($store->leave('u1', 'guild-1'));

        self::assertNull($store->findByUid('u1'));
        self::assertNull($store->get('guild-1'));
    }

    // ── R3 公会正式化（建会/解散/踢人/职位/公告/审批/人数上限） ──
    // ── R3 guild formalization (create/disband/kick/roles/notice/approval/size cap) ──

    public function testCreateMakesCreatorLeaderAndDisbandClearsEverything(): void
    {
        $store = $this->store();

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->create('u1', 'guild-r3', '名门', 30));

        // 会长职位 + 成员索引
        // The leader role plus the member index
        self::assertSame(GuildStoreInterface::ROLE_LEADER, $store->roleOf('u1', 'guild-r3'));
        self::assertSame('guild-r3', $store->findByUid('u1'));
        self::assertSame([['uid' => 'u1', 'role' => GuildStoreInterface::ROLE_LEADER]], $store->members('guild-r3'));

        // 解散清场：hash 与全部成员索引删除，返回原成员列表
        // Disband cleanup: hash and every member index deleted, former members returned
        $result = $store->disband('u1', 'guild-r3');
        self::assertSame(['code' => GuildStoreInterface::CODE_OK, 'members' => ['u1']], $result);
        self::assertNull($store->get('guild-r3'));
        self::assertNull($store->findByUid('u1'));
        self::assertSame([], $store->members('guild-r3'));
    }

    public function testCreateRejectsExistingGuildIdAndGuildedCreator(): void
    {
        $store = $this->store();

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->create('u1', 'guild-r3', null, 10));
        self::assertSame(['code' => GuildStoreInterface::CODE_GUILD_EXISTS], $store->create('u2', 'guild-r3', null, 10));
        self::assertSame(['code' => GuildStoreInterface::CODE_ALREADY_IN_GUILD], $store->create('u1', 'guild-other', null, 10));
    }

    /**
     * 权限矩阵表驱动测试：会长/官员/成员 × 操作（解散/踢人/任命/公告/审批）。
     * 预期表即权限矩阵的行为投影——每行断言实际返回码。
     * Table-driven permission-matrix tests: leader/officer/member × operations (disband/kick/promote/notice/approve).
     * The expectation table is the behavioral projection of the matrix — every row asserts the actual return code.
     */
    public function testPermissionMatrixTableDriven(): void
    {
        // [操作者职位, 操作, 目标, 预期返回码] —— PERMISSION_MATRIX 的行为投影
        // [operator role, operation, target, expected code] — the behavioral projection of PERMISSION_MATRIX
        $matrix = [
            // 解散：仅会长 disband: leader only
            ['leader', 'disband', null, GuildStoreInterface::CODE_OK],
            ['officer', 'disband', null, GuildStoreInterface::CODE_PERMISSION_DENIED],
            ['member', 'disband', null, GuildStoreInterface::CODE_PERMISSION_DENIED],
            // 踢人：会长/官员可踢，但只能踢低阶位 kick: leader/officer, lower ranks only
            ['leader', 'kick', 'member', GuildStoreInterface::CODE_OK],
            ['leader', 'kick', 'officer', GuildStoreInterface::CODE_OK],
            ['leader', 'kick', 'leader', GuildStoreInterface::CODE_TARGET_INVALID],
            ['officer', 'kick', 'member', GuildStoreInterface::CODE_OK],
            ['officer', 'kick', 'officer', GuildStoreInterface::CODE_TARGET_INVALID],
            ['officer', 'kick', 'leader', GuildStoreInterface::CODE_TARGET_INVALID],
            ['member', 'kick', 'member', GuildStoreInterface::CODE_PERMISSION_DENIED],
            // 任命：仅会长 promote: leader only
            ['leader', 'promote_officer', 'member', GuildStoreInterface::CODE_OK],
            ['leader', 'promote_member', 'officer', GuildStoreInterface::CODE_OK],
            ['leader', 'promote_self', 'leader', GuildStoreInterface::CODE_TARGET_INVALID],
            ['officer', 'promote_officer', 'member', GuildStoreInterface::CODE_PERMISSION_DENIED],
            ['member', 'promote_officer', 'member', GuildStoreInterface::CODE_PERMISSION_DENIED],
            // 公告：会长/官员 notice: leader/officer
            ['leader', 'notice', null, GuildStoreInterface::CODE_OK],
            ['officer', 'notice', null, GuildStoreInterface::CODE_OK],
            ['member', 'notice', null, GuildStoreInterface::CODE_PERMISSION_DENIED],
            // 审批：会长/官员 approve: leader/officer
            ['leader', 'approve', 'applicant', GuildStoreInterface::CODE_OK],
            ['officer', 'approve', 'applicant', GuildStoreInterface::CODE_OK],
            ['member', 'approve', 'applicant', GuildStoreInterface::CODE_PERMISSION_DENIED],
        ];

        foreach ($matrix as [$role, $operation, $target, $expected]) {
            // 每行前重建固定局面：清键后重建 leader/officer/member 三席 + applicant 申请在册
            // Rebuild the fixed fixture before every row: clear keys, then rebuild the leader/officer/member trio plus a pending applicant
            $keys = [$this->prefix . 'guild:guild-mx'];
            foreach (['leader', 'officer', 'member', 'applicant'] as $uid) {
                $keys[] = $this->prefix . 'uid-guild:' . $uid;
            }
            $this->redis->del($keys);

            $store = new GuildStore($this->redis, $this->prefix);
            $store->create('leader', 'guild-mx', null, 50);
            $store->join('officer', 'guild-mx');
            $store->promote('leader', 'officer', 'guild-mx', GuildStoreInterface::ROLE_OFFICER);
            $store->join('member', 'guild-mx');
            if ($target === 'applicant' || str_starts_with($operation, 'approve')) {
                $store->apply('applicant', 'guild-mx');
            }

            $code = match ($operation) {
                'disband' => $store->disband($role, 'guild-mx')['code'],
                'kick' => $store->kick($role, (string) $target, 'guild-mx')['code'],
                'promote_officer' => $store->promote($role, (string) $target, 'guild-mx', GuildStoreInterface::ROLE_OFFICER)['code'],
                'promote_member' => $store->promote($role, (string) $target, 'guild-mx', GuildStoreInterface::ROLE_MEMBER)['code'],
                'promote_self' => $store->promote($role, $role, 'guild-mx', GuildStoreInterface::ROLE_MEMBER)['code'],
                'notice' => $store->setNotice($role, 'guild-mx', '公告')['code'],
                'approve' => $store->approve($role, (string) $target, 'guild-mx', true)['code'],
            };

            self::assertSame(
                $expected,
                $code,
                sprintf('权限矩阵失配 %s × %s%s: 期望 %d 实际 %d', $role, $operation, $target !== null ? ' → ' . $target : '', $expected, $code),
            );
        }
    }

    public function testKickRemovesTargetFromMembersAndIndex(): void
    {
        $store = $this->store();
        $store->create('leader', 'guild-r3', null, 50);
        $store->join('u2', 'guild-r3');

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->kick('leader', 'u2', 'guild-r3'));
        self::assertNull($store->findByUid('u2'));
        self::assertNull($store->roleOf('u2', 'guild-r3'));
        self::assertSame([['uid' => 'leader', 'role' => GuildStoreInterface::ROLE_LEADER]], $store->members('guild-r3'));
    }

    public function testPromoteChangesRole(): void
    {
        $store = $this->store();
        $store->create('leader', 'guild-r3', null, 50);
        $store->join('u2', 'guild-r3');

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->promote('leader', 'u2', 'guild-r3', GuildStoreInterface::ROLE_OFFICER));
        self::assertSame(GuildStoreInterface::ROLE_OFFICER, $store->roleOf('u2', 'guild-r3'));

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->promote('leader', 'u2', 'guild-r3', GuildStoreInterface::ROLE_MEMBER));
        self::assertSame(GuildStoreInterface::ROLE_MEMBER, $store->roleOf('u2', 'guild-r3'));
    }

    public function testSetNoticeWritesNoticeField(): void
    {
        $store = $this->store();
        $store->create('leader', 'guild-r3', null, 50);
        $store->join('u2', 'guild-r3');

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->setNotice('leader', 'guild-r3', '本周六攻城战'));
        self::assertSame('本周六攻城战', $store->get('guild-r3')['notice']);

        // 成员无公告权限
        // Members hold no notice permission
        self::assertSame(['code' => GuildStoreInterface::CODE_PERMISSION_DENIED], $store->setNotice('u2', 'guild-r3', 'x'));
    }

    public function testApplyApproveFlowWithCapEnforcement(): void
    {
        $store = $this->store();
        $store->create('leader', 'guild-r3', null, 2);

        // 满员预检：上限 2、已占 1 席，申请仍可入队
        // Full-capacity pre-check: cap 2 with 1 seat taken, applying still queues
        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->apply('u2', 'guild-r3'));
        self::assertSame(['code' => GuildStoreInterface::CODE_ALREADY_APPLIED], $store->apply('u2', 'guild-r3'));

        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->approve('leader', 'u2', 'guild-r3', true));
        self::assertSame(GuildStoreInterface::ROLE_MEMBER, $store->roleOf('u2', 'guild-r3'));

        // 上限 2 已满：新申请直接 GUILD_FULL；批准路径同样拦截
        // Cap 2 reached: a fresh application reads GUILD_FULL; the approval path is guarded too
        self::assertSame(['code' => GuildStoreInterface::CODE_GUILD_FULL], $store->apply('u3', 'guild-r3'));

        $store->leave('u2', 'guild-r3');
        $store->apply('u3', 'guild-r3');
        self::assertSame(['code' => GuildStoreInterface::CODE_OK], $store->approve('leader', 'u3', 'guild-r3', false));
        self::assertNull($store->findByUid('u3'));
        self::assertSame(['code' => GuildStoreInterface::CODE_APPLICATION_NOT_FOUND], $store->approve('leader', 'u3', 'guild-r3', true));
    }

    public function testApproveRejectsApplicantWhoJoinedAnotherGuild(): void
    {
        $store = $this->store();
        $store->create('leader', 'guild-r3', null, 50);
        $store->apply('u2', 'guild-r3');
        $store->create('u2', 'guild-other', null, 50);

        self::assertSame(['code' => GuildStoreInterface::CODE_ALREADY_IN_GUILD], $store->approve('leader', 'u2', 'guild-r3', true));
    }

    public function testLegacyJoinStillWorksAlongsideFormalizedSurface(): void
    {
        $store = $this->store();

        // legacy join 隐式建会（verify-phase5 兼容），新成员职位 member
        // Legacy join implicitly creates the guild (verify-phase5 compatibility), new members get the member role
        self::assertTrue($store->join('u1', 'guild-a'));
        self::assertSame(GuildStoreInterface::ROLE_MEMBER, $store->roleOf('u1', 'guild-a'));
        self::assertSame(['name' => null, 'notice' => '', 'members' => ['u1']], $store->get('guild-a'));
    }
}
