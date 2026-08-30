<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Social\RedisTeamStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisTeamStore 集成测试：依赖 127.0.0.1:6379 可用，不可用时整体跳过（CI/无 Redis 环境不红）。
 * Integration tests for RedisTeamStore: requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 键隔离：随机基前缀（bin2hex(random_bytes)），tearDown 清理，不与生产 nythros:gw: 键混用。
 * Key isolation: a random base prefix (bin2hex(random_bytes)) cleaned up in tearDown, never colliding with production nythros:gw: keys.
 *
 * 覆盖（reviewer 终审约束）：
 * (a) 建队后 team:{teamId} 与 uid-team:{sender} 的 TTL == teamTtl（不是 maxSize）
 * (b) maxSize=1 建队后第二人 accept 返回 3（team_full）
 * (c) 建队邀请 30s 内可 accept 消费、过期后返回 4
 * (d) 三不变量：BLOCKER-1 TTL 同步续期 / BLOCKER-2 建队判队原子（多进程并发不双建队）/ MAJOR-2 cjson 往返
 * (e) 并发矩阵：accept/disband 交错、双邀请 accept 后「一 uid 一队」防护、TTL 分叉
 * (f) 返回码 0~9 全映射
 * Covers (reviewer final-review constraints):
 * (a) post-create TTL of team:{teamId} and uid-team:{sender} == teamTtl (not maxSize)
 * (b) with maxSize=1, a second accept returns 3 (team_full)
 * (c) the create invite is accept-able within 30s and returns 4 after expiry
 * (d) three invariants: BLOCKER-1 TTL synchronous renewal / BLOCKER-2 atomic create-or-join (multi-process, no double team) / MAJOR-2 cjson round-trip
 * (e) concurrency matrix: accept/disband interleave, double-invite accept then one-uid-one-team guard, TTL divergence
 * (f) the full 0~9 return-code mapping
 */
final class RedisTeamStoreTest extends TestCase
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
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisTeamStore 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 RedisTeamStore 集成测试');
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

    private function store(): RedisTeamStore
    {
        return new RedisTeamStore($this->redis, $this->prefix);
    }

    private function teamKey(string $teamId): string
    {
        return $this->prefix . 'team:' . $teamId;
    }

    private function uidTeamKey(string $uid): string
    {
        return $this->prefix . 'uid-team:' . $uid;
    }

    /**
     * 断言键的 TTL 约等于 teamTtl（容差 5s），证明续期到 teamTtl 而非其他值（如 maxSize）。
     * Assert a key's TTL is approximately teamTtl (within 5s), proving it renews to teamTtl rather than any other value (e.g. maxSize).
     */
    private function assertTtlEqualsTeamTtl(string $key, int $teamTtl): void
    {
        $ttl = $this->redis->ttl($key);
        self::assertIsInt($ttl, sprintf('键 %s 应存在且有 TTL', $key));
        self::assertGreaterThan($teamTtl - 5, $ttl, sprintf('键 %s 的 TTL 应约等于 teamTtl(%d)', $key, $teamTtl));
        self::assertLessThanOrEqual($teamTtl, $ttl);
    }

    // (a) 建队后 team 与 uid-team 的 TTL == teamTtl（不是 maxSize）

    public function testCreateTeamSetsTtlToTeamTtlNotMaxSize(): void
    {
        $result = $this->store()->invite('u1', 'u2', 2, 60, 100.0);

        self::assertSame(RedisTeamStore::CODE_OK, $result['code']);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        $teamTtl = $this->redis->ttl($this->teamKey($teamId));
        $uidTtl = $this->redis->ttl($this->uidTeamKey('u1'));

        // 关键：TTL == teamTtl(60)，而非 maxSize(2)
        self::assertIsInt($teamTtl);
        self::assertIsInt($uidTtl);
        self::assertGreaterThan(2, $teamTtl, 'team 键 TTL 不应等于 maxSize');
        self::assertGreaterThan(2, $uidTtl, 'uid-team 键 TTL 不应等于 maxSize');
        self::assertLessThanOrEqual(60, $teamTtl);
        self::assertLessThanOrEqual(60, $uidTtl);
    }

    // (b) maxSize=1 建队后第二人 accept 返回 3

    public function testMaxSizeOneSecondAcceptReturnsTeamFull(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 1, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        $accept = $store->accept('u2', $teamId, 1, 60, 100.0);
        self::assertSame(RedisTeamStore::CODE_TEAM_FULL, $accept['code']);
    }

    // (c) 建队邀请 30s 内可 accept 消费、过期后返回 4

    public function testInviteAcceptibleWithin30sWindow(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // 100 + 29.9 仍在 30s 窗口内（expiresAt = 130）
        $accept = $store->accept('u2', $teamId, 5, 60, 129.9);
        self::assertSame(RedisTeamStore::CODE_OK, $accept['code']);
        self::assertSame(['u1', 'u2'], $accept['members']);
    }

    public function testAcceptExpiredInviteReturnsInviteNotFound(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // 131 > 130（expiresAt），邀请已过期 → 4
        $accept = $store->accept('u2', $teamId, 5, 60, 131.0);
        self::assertSame(RedisTeamStore::CODE_INVITE_NOT_FOUND, $accept['code']);
    }

    public function testRejectExpiredInviteReturnsInviteNotFound(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        $reject = $store->reject('u2', $teamId, 60, 131.0);
        self::assertSame(RedisTeamStore::CODE_INVITE_NOT_FOUND, $reject['code']);
    }

    // (d) 三不变量：BLOCKER-1 TTL 同步续期

    public function testTtlRenewalKeepsMemberKeysAlive(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);

        // 人为压低成员键 TTL 模拟「成员键先于队伍过期」的 TTL 分叉
        $this->redis->expire($this->uidTeamKey('u1'), 2);
        $this->redis->expire($this->uidTeamKey('u2'), 2);
        self::assertLessThanOrEqual(2, $this->redis->ttl($this->uidTeamKey('u1')));

        // 触发一次写队伍的操作（队长邀请 u3）→ 遍历 members 给每个成员 SETEX 同 TTL
        self::assertSame(RedisTeamStore::CODE_OK, $store->invite('u1', 'u3', 5, 60, 100.0)['code']);

        // 成员键 TTL 被续期回 ~teamTtl（BLOCKER-1）
        $this->assertTtlEqualsTeamTtl($this->uidTeamKey('u1'), 60);
        $this->assertTtlEqualsTeamTtl($this->uidTeamKey('u2'), 60);
    }

    // (d) 三不变量：BLOCKER-2 建队判队原子（同 sender 并发 invite 不双建队）

    public function testConcurrentInviteSameSenderCreatesSingleTeam(): void
    {
        $targets = ['u2', 'u3', 'u4', 'u5', 'u6'];
        $workerCount = count($targets);

        $workerScript = tempnam(sys_get_temp_dir(), 'rts_worker_');
        self::assertNotFalse($workerScript);
        file_put_contents($workerScript, <<<'PHP'
<?php
require $argv[1];
$redis = new \Redis();
try {
    $connected = @$redis->connect('127.0.0.1', 6379, 1.0);
} catch (\Throwable) {
    $connected = false;
}
if ($connected !== true) {
    fwrite(STDOUT, 'connect-fail');
    exit(1);
}
$redis->set($argv[3] . ':ready:' . $argv[5], '1');
$deadline = microtime(true) + 10.0;
while ($redis->get($argv[3]) === false) {
    if (microtime(true) > $deadline) {
        fwrite(STDOUT, 'barrier-timeout');
        exit(1);
    }
    usleep(200);
}
try {
    $store = new \Nythros\Framework\Social\RedisTeamStore($redis, $argv[2]);
    $result = $store->invite('u1', $argv[4], 5, 60, microtime(true));
    fwrite(STDOUT, $result['code'] . ':' . ($result['teamId'] ?? ''));
} catch (\Throwable $e) {
    fwrite(STDOUT, 'error:' . $e->getMessage());
    exit(1);
}
PHP);

        $barrier = $this->prefix . 'barrier';
        $processes = [];
        $pipes = [];
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        for ($i = 0; $i < $workerCount; $i++) {
            $pipes[$i] = [];
            $processes[$i] = proc_open(
                [PHP_BINARY, $workerScript, $autoload, $this->prefix, $barrier, $targets[$i], (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
            self::assertIsResource($processes[$i]);
        }

        $readyDeadline = microtime(true) + 10.0;
        do {
            $readyCount = 0;
            for ($i = 0; $i < $workerCount; $i++) {
                if ($this->redis->get($barrier . ':ready:' . $i) !== false) {
                    $readyCount++;
                }
            }
            if ($readyCount === $workerCount) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $readyDeadline);
        self::assertSame($workerCount, $readyCount, '子进程未全部就绪');

        $this->redis->set($barrier, '1');

        $teamIds = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $out = stream_get_contents($pipes[$i][1]);
            stream_get_contents($pipes[$i][2]);
            $code = proc_close($processes[$i]);
            self::assertSame(0, $code, 'invite 子进程异常退出');
            self::assertNotFalse($out);
            $out = trim((string) $out);
            self::assertStringStartsWith('0:', $out, '并发 invite 应全部 ok');
            $teamIds[] = substr($out, 2);
        }

        unlink($workerScript);

        // 所有并发 invite 返回同一 teamId（未双建队）
        self::assertCount(1, array_unique($teamIds), '所有并发 invite 应返回同一 teamId');

        // 恰好一个 team hash 存在（team:seq 仅 INCR 一次）
        $teamKeys = $this->redis->keys($this->prefix . 'team:team-*');
        self::assertIsArray($teamKeys);
        self::assertCount(1, $teamKeys);

        // 该队 invites 含全部 5 个目标的条目
        $teamId = $teamIds[0];
        $rawInvites = $this->redis->hGet($this->teamKey($teamId), 'invites');
        self::assertIsString($rawInvites);
        $invites = json_decode($rawInvites, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount($workerCount, $invites);
    }

    // (d) 三不变量：MAJOR-2 cjson 往返

    public function testMembersRoundTripThroughCjson(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);

        $team = $store->get($teamId);
        self::assertNotNull($team);
        self::assertSame('u1', $team['leaderUid']);
        self::assertSame(['u1', 'u2'], $team['members']);
    }

    public function testNumericUidRoundTripsAsString(): void
    {
        $store = $this->store();
        $result = $store->invite('1001', '1002', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        $team = $store->get($teamId);
        self::assertNotNull($team);
        // 纯数字 uid 必须保持为字符串（cjson 不数值化）
        self::assertSame(['1001'], $team['members']);

        $accept = $store->accept('1002', $teamId, 5, 60, 100.0);
        self::assertSame(RedisTeamStore::CODE_OK, $accept['code']);
        self::assertSame(['1001', '1002'], $accept['members']);
        self::assertSame($teamId, $store->findByUid('1002'));
    }

    // (e) 并发矩阵：「一 uid 一队」双队防护

    public function testDoubleInviteAcceptSecondReturnsAlreadyInTeam(): void
    {
        $store = $this->store();

        // 队伍 A：u1 邀请 u2；队伍 B：u3 邀请 u2（u2 尚未入队，两队并存）
        $teamA = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamB = $store->invite('u3', 'u2', 5, 60, 100.0);
        $teamAId = $teamA['teamId'];
        $teamBId = $teamB['teamId'];
        self::assertIsString($teamAId);
        self::assertIsString($teamBId);
        self::assertNotSame($teamAId, $teamBId);

        // u2 接受队伍 A → 0
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamAId, 5, 60, 100.0)['code']);

        // u2 再接受队伍 B → 6（already_in_team，一 uid 一队）
        self::assertSame(RedisTeamStore::CODE_ALREADY_IN_TEAM, $store->accept('u2', $teamBId, 5, 60, 100.0)['code']);

        // u2 反查索引指向队伍 A
        self::assertSame($teamAId, $store->findByUid('u2'));
    }

    // (e) 并发矩阵：accept/disband 交错

    public function testAcceptAfterDisbandReturnsTeamNotFound(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // 队长解散（disband）先于 accept
        self::assertSame(RedisTeamStore::CODE_OK, $store->disband('u1', $teamId, 60)['code']);

        // u2 此时 accept → 7（队伍已不存在；u2 从未入队，uid-team 键不存在 → 不命中 already_in_team）
        self::assertSame(RedisTeamStore::CODE_TEAM_NOT_FOUND, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);
    }

    public function testDisbandClearsAllMemberIndexes(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);

        self::assertSame(['u1', 'u2'], $store->disband('u1', $teamId, 60)['members']);

        self::assertNull($store->findByUid('u1'));
        self::assertNull($store->findByUid('u2'));
        self::assertNull($store->get($teamId));
    }

    // (f) 返回码 0~9 全映射

    public function testInviteSelfReturnsTargetIsSender(): void
    {
        self::assertSame(RedisTeamStore::CODE_TARGET_IS_SENDER, $this->store()->invite('u1', 'u1', 5, 60, 100.0)['code']);
    }

    public function testInviteTargetInTeamReturnsTargetInTeam(): void
    {
        $store = $this->store();

        // u1 建队 A（u1 队长）；u3 建队 B（u3 队长，u4 成员）
        $teamA = $store->invite('u1', 'u2', 5, 60, 100.0);
        self::assertIsString($teamA['teamId']);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamA['teamId'], 5, 60, 100.0)['code']);

        $teamB = $store->invite('u3', 'u4', 5, 60, 100.0);
        self::assertIsString($teamB['teamId']);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u4', $teamB['teamId'], 5, 60, 100.0)['code']);

        // u1 邀请已入队的 u4 → 2
        self::assertSame(RedisTeamStore::CODE_TARGET_IN_TEAM, $store->invite('u1', 'u4', 5, 60, 100.0)['code']);
    }

    public function testInviteByNonLeaderReturnsNotLeader(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);

        // u2 已是成员（非队长）→ invite u3 返回 1
        self::assertSame(RedisTeamStore::CODE_NOT_LEADER, $store->invite('u2', 'u3', 5, 60, 100.0)['code']);
    }

    public function testAcceptUnknownTeamReturnsTeamNotFound(): void
    {
        self::assertSame(RedisTeamStore::CODE_TEAM_NOT_FOUND, $this->store()->accept('u9', 'team-999', 5, 60, 100.0)['code']);
    }

    public function testAcceptInviteForOtherReturnsInviteNotForYou(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // u3 未被邀请，但队内有发给 u2 的有效邀请 → 5
        self::assertSame(RedisTeamStore::CODE_INVITE_NOT_FOR_YOU, $store->accept('u3', $teamId, 5, 60, 100.0)['code']);
    }

    public function testAcceptNoInviteReturnsInviteNotFound(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // u2 拒绝（删除唯一邀请）后，u2 再 accept → 4（无有效邀请）
        self::assertSame(RedisTeamStore::CODE_OK, $store->reject('u2', $teamId, 60, 100.0)['code']);
        self::assertSame(RedisTeamStore::CODE_INVITE_NOT_FOUND, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);
    }

    public function testLeaveNonMemberReturnsNotMember(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);

        // u3 非成员 → leave 返回 8
        self::assertSame(RedisTeamStore::CODE_NOT_MEMBER, $store->leave('u3', $teamId, 60)['code']);
    }

    public function testDisbandByNonLeaderReturnsNotLeader(): void
    {
        $store = $this->store();
        $result = $store->invite('u1', 'u2', 5, 60, 100.0);
        $teamId = $result['teamId'];
        self::assertIsString($teamId);
        self::assertSame(RedisTeamStore::CODE_OK, $store->accept('u2', $teamId, 5, 60, 100.0)['code']);

        // u2 非队长 → disband 返回 1
        self::assertSame(RedisTeamStore::CODE_NOT_LEADER, $store->disband('u2', $teamId, 60)['code']);
    }
}
