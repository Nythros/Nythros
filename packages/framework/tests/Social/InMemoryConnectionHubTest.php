<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Social\HubTransportInterface;
use Nythros\Framework\Social\InMemoryConnectionHub;
use PHPUnit\Framework\TestCase;

/**
 * InMemoryConnectionHubTest - 连接注册表全语义单测（ADR-021）：bind/group/session/detach，不依赖传输实现。
 * 组装策略：传输端口用记录调用的 Fake 收集 sendToConnection/close 调用序列。
 * InMemoryConnectionHubTest - full-semantics unit tests for the connection hub (ADR-021): bind/group/session/detach,
 * with no transport implementation involved. Assembly: the transport port is a call-recording fake collecting
 * sendToConnection/close invocations.
 */
final class InMemoryConnectionHubTest extends TestCase
{
    public function testBindUidSupportsManyConnectionsPerUidAndReverseLookup(): void
    {
        $hub = $this->buildHub();

        $hub->bindUid('conn-1', '1001');
        $hub->bindUid('conn-2', '1001');
        $hub->bindUid('conn-3', '1002');

        self::assertSame(['conn-1', 'conn-2'], $hub->getClientIdByUid('1001'));
        self::assertSame(['conn-3'], $hub->getClientIdByUid('1002'));
        self::assertSame([], $hub->getClientIdByUid('nobody'));
        self::assertTrue($hub->isUidOnline('1001'));
        self::assertFalse($hub->isUidOnline('nobody'));
    }

    public function testRebindToAnotherUidRemovesOldMapping(): void
    {
        $hub = $this->buildHub();

        $hub->bindUid('conn-1', '1001');
        $hub->bindUid('conn-1', '1002');

        self::assertSame([], $hub->getClientIdByUid('1001'), '旧 uid 反查不得残留脏项。');
        self::assertSame(['conn-1'], $hub->getClientIdByUid('1002'));
        self::assertFalse($hub->isUidOnline('1001'));
    }

    public function testRebindSameUidIsIdempotent(): void
    {
        $hub = $this->buildHub();

        $hub->bindUid('conn-1', '1001');
        $hub->bindUid('conn-1', '1001');

        self::assertSame(['conn-1'], $hub->getClientIdByUid('1001'));
    }

    public function testGroupBroadcastSkipsExcludedConnection(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        foreach (['conn-1', 'conn-2', 'conn-3'] as $clientId) {
            $hub->attachConnection($clientId);
            $hub->bindUid($clientId, $clientId);
        }
        $hub->joinGroup('conn-1', 'map:map-1:ch-1');
        $hub->joinGroup('conn-2', 'map:map-1:ch-1');
        $hub->joinGroup('conn-3', 'map:map-1:ch-2');

        $hub->sendToGroup('map:map-1:ch-1', 'frame-a', 'conn-1');

        self::assertSame(['conn-2'], $this->targetsOf($transport, 'frame-a'));

        // 重复加入幂等：广播仍只收到一份
        // Joining twice is idempotent: still exactly one copy per broadcast
        $hub->joinGroup('conn-2', 'map:map-1:ch-1');
        $hub->sendToGroup('map:map-1:ch-1', 'frame-b', null);
        self::assertSame(['conn-1', 'conn-2'], $this->targetsOf($transport, 'frame-b'));
    }

    public function testLeaveGroupRemovesFromBroadcastOnly(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        $hub->attachConnection('conn-1');
        $hub->attachConnection('conn-2');
        $hub->bindUid('conn-1', '1001');
        $hub->joinGroup('conn-1', 'team:team-1');
        $hub->joinGroup('conn-2', 'team:team-1');
        $hub->leaveGroup('conn-1', 'team:team-1');

        $hub->sendToGroup('team:team-1', 'frame-a', null);

        self::assertSame(['conn-2'], $this->targetsOf($transport, 'frame-a'));
        // 离组不影响 uid 绑定与会话
        // Leaving a group never touches the uid binding or the session
        self::assertSame(['conn-1'], $hub->getClientIdByUid('1001'));
        self::assertTrue($hub->isUidOnline('1001'));
    }

    public function testSendToAllCoversEveryLiveConnectionIncludingUnbound(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        // conn-guest 只 attach 未认证（无 session/uid）：world 广播仍应触达（对齐 gateway-worker sendToAll 全连接语义）
        // conn-guest is attached but unauthenticated (no session/uid): world broadcast still reaches it (gateway-worker's all-connections semantics)
        $hub->attachConnection('conn-guest');
        $hub->attachConnection('conn-1');
        $hub->bindUid('conn-1', '1001');
        $hub->setSession('conn-1', ['uid' => '1001']);

        $hub->sendToAll('frame-w', null);
        self::assertSame(['conn-guest', 'conn-1'], $this->targetsOf($transport, 'frame-w'));

        $hub->sendToAll('frame-x', 'conn-1');
        self::assertSame(['conn-guest'], $this->targetsOf($transport, 'frame-x'));
    }

    public function testSendToUidDeliversToEveryBoundConnection(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        $hub->bindUid('conn-1', '1001');
        $hub->bindUid('conn-2', '1001');

        $hub->sendToUid('1001', 'frame-p');

        self::assertSame(['conn-1', 'conn-2'], $this->targetsOf($transport, 'frame-p'));
        self::assertSame([], $this->targetsOf($transport, 'frame-p', after: 2), '离线 uid 定向发送自动丢弃。');
        $hub->sendToUid('offline-uid', 'frame-q');
        self::assertSame([], $this->targetsOf($transport, 'frame-q'));
    }

    public function testSessionSetUpdateReplaceSemantics(): void
    {
        $hub = $this->buildHub();

        self::assertNull($hub->getSession('conn-1'));

        $hub->setSession('conn-1', ['uid' => '1001', 'loc' => ['mapId' => 'map-1']]);
        self::assertSame(['uid' => '1001', 'loc' => ['mapId' => 'map-1']], $hub->getSession('conn-1'));

        // update 合并保留未提及字段
        // update merges and keeps untouched fields
        $hub->updateSession('conn-1', ['teamId' => 'team-1']);
        self::assertSame(['uid' => '1001', 'loc' => ['mapId' => 'map-1'], 'teamId' => 'team-1'], $hub->getSession('conn-1'));

        // set 整量覆盖丢弃旧字段
        // set replaces wholesale, dropping old fields
        $hub->setSession('conn-1', ['uid' => '1001']);
        self::assertSame(['uid' => '1001'], $hub->getSession('conn-1'));
    }

    public function testDetachConnectionClearsEveryIndexAtOnce(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        $hub->attachConnection('conn-1');
        $hub->bindUid('conn-1', '1001');
        $hub->joinGroup('conn-1', 'map:map-1:ch-1');
        $hub->joinGroup('conn-1', 'guild:g-1');
        $hub->setSession('conn-1', ['uid' => '1001']);
        $hub->attachConnection('conn-2');
        $hub->bindUid('conn-2', '1001');
        $hub->joinGroup('conn-2', 'map:map-1:ch-1');

        $hub->detachConnection('conn-1');

        // uid 多对多：另一连接仍在，uid 保持在线；本连接的全部索引清空
        // Many-to-many uid: the other connection survives, the uid stays online; every index of this connection is cleared
        self::assertSame(['conn-2'], $hub->getClientIdByUid('1001'));
        self::assertTrue($hub->isUidOnline('1001'));
        self::assertNull($hub->getSession('conn-1'));

        $hub->sendToGroup('map:map-1:ch-1', 'frame-a', null);
        self::assertSame(['conn-2'], $this->targetsOf($transport, 'frame-a'));

        $hub->sendToGroup('guild:g-1', 'frame-b', null);
        self::assertSame([], $this->targetsOf($transport, 'frame-b'), '分组随 detach 清空后广播为空。');

        $hub->sendToAll('frame-c', null);
        self::assertSame(['conn-2'], $this->targetsOf($transport, 'frame-c'), 'detach 后不再进入广播全集。');

        // detach 幂等：重复调用不抛错、无副作用
        // detach is idempotent: repeated calls neither throw nor side-effect
        $hub->detachConnection('conn-1');
        self::assertSame(['conn-2'], $hub->getClientIdByUid('1001'));
    }

    public function testCloseClientDelegatesToTransport(): void
    {
        $transport = new RecordingTransport();
        $hub = new InMemoryConnectionHub($transport);

        $hub->closeClient('conn-9');

        self::assertSame(['conn-9'], $transport->closes);
    }

    /**
     * 组装带记录传输的 hub。
     * Builds a hub over a recording transport.
     */
    private function buildHub(): InMemoryConnectionHub
    {
        return new InMemoryConnectionHub(new RecordingTransport());
    }

    /**
     * 过滤传输记录中指定帧的目标连接（可选跳过前 N 条）。
     * Filters the transport log for a frame's target connections (optionally skipping the first N entries).
     *
     * @return list<string> 目标连接列表 Target connections.
     */
    private static function targetsOf(RecordingTransport $transport, string $message, int $after = 0): array
    {
        $hits = [];
        foreach ($transport->sends as $send) {
            if ($send['message'] === $message) {
                $hits[] = $send['clientId'];
            }
        }

        return array_slice($hits, $after);
    }
}

/**
 * 记录调用的传输替身：收集 sendToConnection/close 序列供断言。
 * Call-recording transport double: collects the sendToConnection/close sequence for assertions.
 */
final class RecordingTransport implements HubTransportInterface
{
    /** @var list<array{clientId: string, message: string}> 发送记录 Send records. */
    public array $sends = [];

    /** @var list<string> 关闭记录 Close records. */
    public array $closes = [];

    public function sendToConnection(string $clientId, string $message): void
    {
        $this->sends[] = ['clientId' => $clientId, 'message' => $message];
    }

    public function close(string $clientId): void
    {
        $this->closes[] = $clientId;
    }
}
