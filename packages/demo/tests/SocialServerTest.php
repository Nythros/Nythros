<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';
require_once __DIR__ . '/../../framework/tests/FakeSocial.php';

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\UniversalAOI;
use Nythros\Cluster\ServiceInstance;
use Nythros\Contracts\WorldType;
use Nythros\Demo\SocialServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Leaderboard\LeaderboardStoreInterface;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Social\HubTransportInterface;
use Nythros\Framework\Social\InMemoryConnectionHub;
use Nythros\Framework\Social\SocialService;
use Nythros\Framework\Tests\FakeFriendStore;
use Nythros\Framework\Tests\FakeGuildStore;
use Nythros\Framework\Tests\FakeLocationStore;
use Nythros\Framework\Tests\FakeServiceRegistry;
use Nythros\Framework\Tests\FakeSocialAuthenticator;
use Nythros\Framework\Tests\FakeTeamStore;
use Nythros\Framework\Tests\FakeTokenManager;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenRecord;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * SocialServerTest - 社交运行时入口认证路由测试：auth 帧 token 路径与完整握手的分流。
 * 组装策略：Server/Connection 用 stub（回调捕获），hub 取真实 InMemoryConnectionHub + 记录型传输替身，
 * SocialService 依赖复用 framework 测试 fakes（FakeTokenManager/FakeServiceRegistry/FakeSocial 全家桶），
 * World 用真实 UniversalAOI 组装（社交层无实体/AOI 消费，仅满足骨架构造依赖）。
 * SocialServer runtime-entry auth-routing tests: the split between the auth frame's token path and the full handshake.
 * Assembly strategy: Server/Connection are stubbed (callback capture); the hub is a real InMemoryConnectionHub with a
 * recording transport substitute; SocialService dependencies reuse the framework test fakes (FakeTokenManager /
 * FakeServiceRegistry / the FakeSocial family); the World is assembled from a real UniversalAOI (the social tier consumes
 * no entities/AOI — it only satisfies the skeleton constructor dependency).
 */
final class SocialServerTest extends TestCase
{
    public function testTokenFrameRoutesToTokenConsumeLoginOnChatRole(): void
    {
        $h = $this->buildHarness('chat');
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map', 'chat', 'team'], 0.0, 999.0);

        $h->connect($h->conn);
        $h->sendFrame(['token' => 'token-a']);

        // token 路径：auth_ok 仅 uid；consume 落在本角色 scope；registry 认证态挂载（entityId = uid）
        // Token path: auth_ok carries the uid only; consume lands on this role's scope; the registry auth state mounts (entityId = uid)
        $ok = self::messagesOfType($h->receivedMessages(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('1001', $ok[0]->payload['uid']);
        self::assertArrayNotHasKey('token', $ok[0]->payload);
        self::assertSame([['token' => 'token-a', 'scope' => 'chat']], $h->tokens->consumeCalls);
        self::assertSame('1001', $h->registry->getEntityId('conn-1'));

        // 已认证路由可达：chat:send 不再落入 guest 兜底（404）
        // Authenticated routing is reachable: chat:send no longer falls into the guest fallback (404)
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('chat:send', ['scope' => 'world', 'content' => 'hi'], 'c-1'));
        self::assertCount(0, self::messagesOfType($h->receivedMessages(), 'error'));
    }

    public function testHandshakeWithoutTokenStillRoutesToFullAuth(): void
    {
        $h = $this->buildHarness('team');
        $h->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);

        // 无 token 字段 → 完整握手：auth_ok 带 token（签发）+ map/team/guild 五字段
        // No token field → full handshake: auth_ok carries the issued token plus the map/team/guild fields
        $ok = self::messagesOfType($h->receivedMessages(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('issued-token-1', $ok[0]->payload['token']);
        self::assertCount(0, $h->tokens->consumeCalls);
    }

    public function testGatewayRoleNeverTakesTokenPathEvenWithTokenField(): void
    {
        $h = $this->buildHarness(null);

        $h->connect($h->conn);
        // gateway 角色（scope = null）：即使 payload 带 token 也走完整握手——payload 缺 mapId 被完整握手的
        // 400 拒绝（失败帧无 reason 字段），绝不触发任何 scope 消费
        // The gateway role (scope = null): even a token-carrying payload takes the full handshake — the missing mapId
        // is rejected by the handshake's 400 (the failure frame carries no reason field) and no scope consumption ever fires
        $h->sendFrame(['token' => 'token-a']);

        $failed = self::messagesOfType($h->receivedMessages(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(400, $failed[0]->payload['code']);
        self::assertArrayNotHasKey('reason', $failed[0]->payload);
        self::assertCount(0, $h->tokens->consumeCalls);
    }

    /**
     * 协议版本守卫（ADR-027）：gateway 启用最低版本后，version 缺失/过低在 authenticate 之前被拒绝；
     * 守卫缺省关闭时行为与接入前一致。
     * The protocol-version guard (ADR-027): with a minimum version configured on the gateway, a missing or
     * too-old version is rejected before authenticate; with the guard off, behavior matches pre-integration.
     */
    public function testGatewayVersionGuardRejectsOldClientsBeforeAuthenticate(): void
    {
        // 缺 version → 拒绝（不触达认证器：FakeSocialAuthenticator 无失败记录可断言，用 400 文案锚定）
        $h = $this->buildHarness(null, minClientVersion: 2);
        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);
        $missing = self::messagesOfType($h->receivedMessages(), 'auth_failed');
        self::assertCount(1, $missing);
        self::assertSame(400, $missing[0]->payload['code']);
        self::assertSame('client_version_too_old', $missing[0]->payload['message']);

        // 版本达标 → 完整握手放行（独立线束：被拒连接已 close，不复用）
        $h2 = $this->buildHarness(null, minClientVersion: 2);
        $h2->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);
        $h2->connect($h2->conn);
        $h2->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1', 'version' => 2]);
        $ok = self::messagesOfType($h2->receivedMessages(), 'auth_ok');
        self::assertCount(1, $ok);
    }

    public function testFriendFrameRoutesToSocialServiceAfterAuth(): void
    {
        // 完整握手登录（gateway 形态）后发 friend:list：路由可达且经 SocialService 应答 friend:ok。
        // After a full-handshake login (the gateway shape), friend:list routes through SocialService and answers friend:ok.
        $h = $this->buildHarness(null);
        $h->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('friend:list', [], 'f-1'));

        $oks = self::messagesOfType($h->receivedMessages(), 'friend:ok');
        self::assertCount(1, $oks);
        self::assertSame('list', $oks[0]->payload['action']);
        self::assertSame([], $oks[0]->payload['uids']);
    }

    public function testLeaderboardTopAndRankFramesAnswerFromWiredStore(): void
    {
        $store = new FakeLeaderboardStore();
        $store->scores = ['level' => ['1002' => 300.0, '1001' => 200.0]];
        $h = $this->buildHarness(null, $store);
        $h->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('leaderboard:top', ['boardId' => 'level', 'n' => 10], 'lb-1'));
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('leaderboard:rank', ['boardId' => 'level'], 'lb-2'));

        $rows = self::messagesOfType($h->receivedMessages(), 'leaderboard:rows');
        self::assertCount(1, $rows);
        self::assertSame('level', $rows[0]->payload['boardId']);
        self::assertSame([1, 2], $rows[0]->payload['ranks']);
        self::assertSame(['1002', '1001'], $rows[0]->payload['uids']);
        // JSON 往返会把整值浮点折叠为 int：以语义相等断言分数
        // The JSON round-trip folds whole-value floats into ints: assert scores semantically
        self::assertEquals([300.0, 200.0], $rows[0]->payload['scores']);

        $ranked = self::messagesOfType($h->receivedMessages(), 'leaderboard:ranked');
        self::assertCount(1, $ranked);
        self::assertSame('1001', $ranked[0]->payload['uid']);
        self::assertSame(2, $ranked[0]->payload['rank']);
        self::assertEquals(200.0, $ranked[0]->payload['score']);
    }

    public function testLeaderboardRankAnswersNullWhenUnranked(): void
    {
        $store = new FakeLeaderboardStore();
        $store->scores = ['level' => ['1002' => 300.0]];
        $h = $this->buildHarness(null, $store);
        $h->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('leaderboard:rank', ['boardId' => 'level'], 'lb-1'));

        $ranked = self::messagesOfType($h->receivedMessages(), 'leaderboard:ranked');
        self::assertCount(1, $ranked);
        self::assertNull($ranked[0]->payload['rank']);
        self::assertEquals(0.0, $ranked[0]->payload['score']);
    }

    public function testLeaderboardWithoutWiredStoreReplies501(): void
    {
        $h = $this->buildHarness(null);
        $h->serviceRegistry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $h->connect($h->conn);
        $h->sendFrame(['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1']);
        ($h->onMessageHandler)($h->conn, SocialServerHarness::frame('leaderboard:top', ['boardId' => 'level', 'n' => 5], 'lb-1'));

        $errors = self::messagesOfType($h->receivedMessages(), 'error');
        self::assertCount(1, $errors);
        self::assertSame(501, $errors[0]->payload['code']);
    }

    /**
     * 组装 SocialServer 测试线束（指定角色 token 消费 scope；null = gateway 形态）。
     * Builds the SocialServer test harness (with the role's consumed token scope; null = the gateway shape).
     */
    private function buildHarness(?string $tokenAuthScope, ?LeaderboardStoreInterface $leaderboard = null, ?int $minClientVersion = null): SocialServerHarness
    {
        $h = new SocialServerHarness();
        $h->tokens = new FakeTokenManager();
        $h->serviceRegistry = new FakeServiceRegistry();

        $transport = new RecordingHubTransport();
        $hub = new InMemoryConnectionHub($transport);
        $h->hubTransport = $transport;

        $social = new SocialService(
            $hub,
            $h->tokens,
            $h->serviceRegistry,
            new FakeSocialAuthenticator(),
            new FakeLocationStore(),
            new FakeGuildStore(),
            new FakeTeamStore(),
            new JsonSerializer(),
            ['map-1'],
            [],
            new FakeFriendStore(),
            $minClientVersion,
        );

        $server = $this->createStub(ServerInterface::class);
        $server->method('onWorkerStart')->willReturnCallback(static function (): void {
            // 骨架 worker start 钩子：测试无需驱动 Worker-start hook: nothing to drive in these tests
        });
        $server->method('onConnect')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onConnectHandlers[] = $handler;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onMessageHandler = $handler;
        });
        $server->method('onClose')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onCloseHandlers[] = $handler;
        });

        $h->conn = $this->createStub(ConnectionInterface::class);
        $h->conn->method('getId')->willReturn('conn-1');
        $h->conn->method('getSendBufferQueueSize')->willReturn(0);
        // 骨架 send() 直写连接（不经 hub）：记录直发帧供断言
        // The skeleton's send() writes straight to the connection (not via the hub): record direct frames for assertions
        $h->conn->method('send')->willReturnCallback(static function (string $data) use ($h): void {
            $h->directFrames[] = $data;
        });

        $h->registry = new ConnectionRegistry();
        $entityManager = new SimpleEntityManager();
        $world = new World($entityManager, new SimpleActorSystem(), new UniversalAOI($entityManager), new SimpleEventBus(), new RegionScheduler(totalBudgetMs: 6.0), WorldType::AOI);

        $socialServer = new SocialServer($server, new JsonBatchSerializer(), $world, $h->registry, $social, $hub, $tokenAuthScope, $leaderboard);
        $socialServer->register();

        return $h;
    }

    /**
     * 按消息类型过滤并返回全部匹配消息。
     * Filters messages by type and returns all matches.
     *
     * @param list<Message> $messages 已解码消息列表 Decoded messages.
     * @param string $type 目标消息类型 Target message type.
     * @return list<Message> 匹配消息列表 Matching messages.
     */
    private static function messagesOfType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->type === $type,
        ));
    }
}

/**
 * SocialServerTest 测试线束：集中存放可变状态（连接 stub、回调句柄、fakes）。
 * Test harness for SocialServerTest: holds mutable state (connection stubs, callback handles, fakes).
 */
final class SocialServerHarness
{
    public ConnectionInterface $conn;
    public ConnectionRegistry $registry;
    public FakeTokenManager $tokens;
    public FakeServiceRegistry $serviceRegistry;
    public RecordingHubTransport $hubTransport;

    /** @var list<callable> 连接建立回调（SocialServer 构造注册 hub 登记 + 骨架注册连接表） Connect callbacks (hub registration from the SocialServer constructor plus the skeleton's connection table). */
    public array $onConnectHandlers = [];

    /** @var ?callable 消息回调（骨架 dispatch） Message callback (the skeleton's dispatch). */
    public $onMessageHandler = null;

    /** @var list<callable> 连接关闭回调 Close callbacks. */
    public array $onCloseHandlers = [];

    /** @var list<string> 骨架 send() 直写连接的帧字节（认证回执/错误帧等） Frame bytes written straight to the connection by the skeleton's send() (auth receipts / error frames). */
    public array $directFrames = [];

    /**
     * 模拟连接建立（触发全部 onConnect 处理器）。
     * Simulates a connection being established (fires every onConnect handler).
     */
    public function connect(ConnectionInterface $conn): void
    {
        foreach ($this->onConnectHandlers as $handler) {
            $handler($conn);
        }
    }

    /**
     * 以本连接发送一条 auth 帧（JSON 单帧批量包格式）。
     * Sends an auth frame on the harness connection (a one-frame JSON batch packet).
     *
     * @param array<string|int, mixed> $payload 消息负载 Message payload.
     */
    public function sendFrame(array $payload): void
    {
        ($this->onMessageHandler)($this->conn, self::frame('auth', $payload, 'auth-1'));
    }

    /**
     * 收到的下行帧解码为消息列表（hub 投递帧 + 骨架直发帧两个来源合并）。
     * Decodes the downstream frames into messages (merging hub-delivered and skeleton-direct frames).
     *
     * @return list<Message> 已解码消息 Decoded messages.
     */
    public function receivedMessages(): array
    {
        $serializer = new JsonBatchSerializer();
        $out = [];
        $frames = array_merge($this->directFrames, $this->hubTransport->sent['conn-1'] ?? []);
        foreach ($frames as $frame) {
            foreach ($serializer->decodeBatch($frame) as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }

    /**
     * 构造一条合法协议帧字节（JSON，含 type/requestId/timestamp/payload 四字段）。
     * Builds a valid protocol frame payload (JSON with the type/requestId/timestamp/payload fields).
     *
     * @param array<string|int, mixed> $payload 消息负载 Message payload.
     */
    public static function frame(string $type, array $payload, ?string $requestId = null): string
    {
        return json_encode([
            'type' => $type,
            'requestId' => $requestId,
            'timestamp' => 123.0,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }
}

/**
 * RecordingHubTransport - 记录型传输替身：捕获 hub 下行投递与踢线调用。
 * RecordingHubTransport - a recording transport substitute capturing the hub's downstream deliveries and kicks.
 */
final class RecordingHubTransport implements HubTransportInterface
{
    /** @var array<string, list<string>> clientId => 已投递帧字节 clientId => delivered frame bytes. */
    public array $sent = [];

    /** @var list<string> 被踢线/关闭的连接 id Connection ids closed via the transport. */
    public array $closes = [];

    public function sendToConnection(string $clientId, string $message): void
    {
        $this->sent[$clientId][] = $message;
    }

    public function close(string $clientId): void
    {
        $this->closes[] = $clientId;
    }
}

/**
 * FakeLeaderboardStore - 内存排行榜存储（ZSet 同语义：分数降序 + 同分字典序），供 SocialServer 路由测试。
 * FakeLeaderboardStore - an in-memory leaderboard store (ZSet semantics: descending scores with lexicographic
 * ties), serving the SocialServer routing tests.
 */
final class FakeLeaderboardStore implements LeaderboardStoreInterface
{
    /** @var array<string, array<string, float>> boardId => (uid => score) 配置表 boardId => (uid => score) configuration table. */
    public array $scores = [];

    public function report(string $board, string $uid, float $score): void
    {
        $this->scores[$board][$uid] = $score;
    }

    public function aggregate(string $board, array $scores): void
    {
        foreach ($scores as $uid => $score) {
            $this->scores[$board][(string) $uid] = (float) $score;
        }
    }

    public function remove(string $board, string $uid): bool
    {
        if (!isset($this->scores[$board][$uid])) {
            return false;
        }
        unset($this->scores[$board][$uid]);

        return true;
    }

    public function top(string $board, int $n, int $offset = 0): array
    {
        $entries = $this->scores[$board] ?? [];
        arsort($entries);

        $rows = [];
        $index = 0;
        foreach ($entries as $uid => $score) {
            if ($index < $offset) {
                $index++;
                continue;
            }
            if (count($rows) >= $n) {
                break;
            }
            $rows[] = ['rank' => $index + 1, 'uid' => (string) $uid, 'score' => (float) $score];
            $index++;
        }

        return $rows;
    }

    public function rankOf(string $board, string $uid): ?array
    {
        $entries = $this->scores[$board] ?? [];
        arsort($entries);
        $rank = 0;
        foreach ($entries as $entryUid => $score) {
            $rank++;
            if ((string) $entryUid === $uid) {
                return ['rank' => $rank, 'score' => (float) $score];
            }
        }

        return null;
    }

    public function size(string $board): int
    {
        return count($this->scores[$board] ?? []);
    }
}
