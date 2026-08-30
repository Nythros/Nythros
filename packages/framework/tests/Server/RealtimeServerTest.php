<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Server;

use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\EventEnvelope;
use Nythros\Contracts\WorldType;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Server\MovementValidator;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Scheduler\SimpleScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestRealtimeServer.php';

/**
 * RealtimeServerTest - 覆盖实时服务器运行时的模板行为：消息分发（单帧校验/认证态路由/兜底）、
 * 玩家挂载与 move 广播模板、视野统一路径（AOI 与全量零分支）、慢客户端硬阈值断开、连接清理模板、
 * 世界视野事件转发（含负载装饰钩子）。
 * RealtimeServerTest - covers the realtime server runtime's template behavior: message dispatch (single-frame
 * validation / authenticated routing / fallbacks), the player-mount and move-broadcast templates, the unified view
 * path (AOI and full broadcast share one code path), the slow-client hard-threshold close, the connection-cleanup
 * template, and world view-event forwarding (with the payload-decoration hook).
 */
final class RealtimeServerTest extends TestCase
{
    private function makeConn(string $id, array &$batched, array &$closed): ConnectionInterface
    {
        $conn = $this->createStub(ConnectionInterface::class);
        $conn->method('getId')->willReturn($id);
        $conn->method('getSendBufferQueueSize')->willReturn(0);
        $conn->method('isAuthenticated')->willReturn(true);
        $conn->method('send')->willReturnCallback(static function (string $payload) use (&$batched): void {
            $batched[] = $payload;
        });
        $conn->method('sendBatch')->willReturnCallback(static function (array $payloads) use (&$batched): void {
            $batched = array_merge($batched, $payloads);
        });
        $conn->method('close')->willReturnCallback(static function () use (&$closed, $id): void {
            $closed[] = $id;
        });
        $conn->method('markAuthenticated')->willReturnCallback(static function (): void {
        });
        $conn->method('getRemoteAddress')->willReturn('127.0.0.1:1');
        $conn->method('getLastMessageTime')->willReturn(microtime(true));
        $conn->method('isClosed')->willReturn(false);
        $conn->method('markInternal')->willReturnCallback(static function (): void {
        });
        $conn->method('isInternal')->willReturn(false);
        $conn->method('onBufferFull')->willReturnCallback(static function (callable $h): void {
        });
        $conn->method('onBufferDrain')->willReturnCallback(static function (callable $h): void {
        });

        return $conn;
    }

    private function makeServerStub(array &$handlers): ServerInterface
    {
        $server = $this->createStub(ServerInterface::class);
        $server->method('onConnect')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onConnect'] = $h;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onMessage'] = $h;
        });
        $server->method('onClose')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onClose'] = $h;
        });
        $server->method('onWorkerStart')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onWorkerStart'][] = $h;
        });
        $server->method('onWorkerStop')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onWorkerStop'][] = $h;
        });

        return $server;
    }

    /** 组装带真实 World 的服务器套件（[game, handlers, world]）。 Builds a suite with a real World. */
    private function suite(bool $fullBroadcast = false): array
    {
        $entityManager = new SimpleEntityManager();
        $aoi = $fullBroadcast ? new UniversalAOI($entityManager) : new GridAOI(10);
        $world = new World($entityManager, new SimpleActorSystem(), $aoi, new SimpleEventBus(50000), new SimpleScheduler(), $fullBroadcast ? WorldType::FULL_BROADCAST : WorldType::AOI);
        $handlers = [];
        $game = new TestRealtimeServer($this->makeServerStub($handlers), $world);
        $game->register();

        return [$game, $handlers, $world];
    }

    /** 让两个玩家完成 auth（返回两个连接）。 Auths two players (returns both connections). */
    private function authPair(array &$handlers, array &$batchedA, array &$closedA, array &$batchedB, array &$closedB): array
    {
        $serializer = new JsonBatchSerializer();
        $connA = $this->makeConn('ca', $batchedA, $closedA);
        $connB = $this->makeConn('cb', $batchedB, $closedB);
        $handlers['onConnect']($connA);
        $handlers['onConnect']($connB);
        $handlers['onMessage']($connA, $serializer->encodeBatch([Message::create('auth', ['uid' => 'a'])]));
        $handlers['onMessage']($connB, $serializer->encodeBatch([Message::create('auth', ['uid' => 'b'])]));

        return [$connA, $connB];
    }

    private function decodeBatch(string $blob): array
    {
        return (new JsonBatchSerializer())->decodeBatch($blob);
    }

    public function testGuestAuthMountsPlayerAndRepliesAuthOk(): void
    {
        [$game, $handlers, $world] = $this->suite();
        $batched = [];
        $closed = [];
        $conn = $this->makeConn('c1', $batched, $closed);
        $handlers['onConnect']($conn);

        $serializer = new JsonBatchSerializer();
        $handlers['onMessage']($conn, $serializer->encodeBatch([Message::create('auth', ['uid' => 'alice'])]));

        $frames = $this->decodeBatch($batched[0]);
        self::assertSame('auth_ok', $frames[0]->type);
        self::assertSame('alice@c1', $frames[0]->payload['id']);
        self::assertSame(1, $game->authCalls);

        // 实体已挂载且 registry 已 attach（实体 id 可反查连接）
        // The entity is mounted and the registry is attached (the entity id resolves back to the connection)
        self::assertSame('alice@c1', $world->getEntityManager()->get('alice@c1')?->getId());
    }

    public function testGuestMoveRejected401AndClosed(): void
    {
        [$game, $handlers] = $this->suite();
        $batched = [];
        $closed = [];
        $conn = $this->makeConn('c1', $batched, $closed);
        $handlers['onConnect']($conn);

        $serializer = new JsonBatchSerializer();
        $handlers['onMessage']($conn, $serializer->encodeBatch([Message::create('move', ['dx' => 1, 'dy' => 0])]));

        $frames = $this->decodeBatch($batched[0]);
        self::assertSame('error', $frames[0]->type);
        self::assertSame(401, $frames[0]->payload['code']);
        self::assertSame(['c1'], $closed, '未认证 move 必须 401 并断开。');
    }

    public function testUnknownGuestGets404WithoutClose(): void
    {
        [$game, $handlers] = $this->suite();
        $batched = [];
        $closed = [];
        $conn = $this->makeConn('c1', $batched, $closed);
        $handlers['onConnect']($conn);

        $serializer = new JsonBatchSerializer();
        $handlers['onMessage']($conn, $serializer->encodeBatch([Message::create('nonsense', [])]));

        $frames = $this->decodeBatch($batched[0]);
        self::assertSame(404, $frames[0]->payload['code']);
        self::assertSame([], $closed, '未知未认证消息必须宽容 404 不关闭。');
    }

    public function testDispatchRejectsMultiFramePackets(): void
    {
        [$game, $handlers] = $this->suite();
        $batched = [];
        $closed = [];
        $conn = $this->makeConn('c1', $batched, $closed);
        $handlers['onConnect']($conn);

        $serializer = new JsonBatchSerializer();
        $handlers['onMessage']($conn, $serializer->encodeBatch([Message::create('ping', []), Message::create('ping', [])]));

        $frames = $this->decodeBatch($batched[0]);
        self::assertSame(400, $frames[0]->payload['code']);
    }

    public function testMoveBroadcastsToViewAndSkipsSelf(): void
    {
        [$game, $handlers] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedA = [];
        $batchedB = [];

        // A 移动两格（仍在同 cell）：B 收到 entity_moved，A 自己收不到（跳过自身）
        // A moves two cells (same GridAOI cell): B receives entity_moved; A itself receives nothing (self skipped)
        $handlers['onMessage']($connA, (new JsonBatchSerializer())->encodeBatch([Message::create('move', ['dx' => 2, 'dy' => 0])]));
        $game->exposeFlush();

        $idsOfAinB = [];
        foreach ($batchedB as $blob) {
            foreach ($this->decodeBatch($blob) as $m) {
                $idsOfAinB[] = [$m->type, $m->payload['id'] ?? null];
            }
        }
        self::assertContains(['entity_moved', 'a@ca'], $idsOfAinB, 'B 应收到 A 的 entity_moved。');

        $idsOfAinA = [];
        foreach ($batchedA as $blob) {
            foreach ($this->decodeBatch($blob) as $m) {
                $idsOfAinA[] = [$m->type, $m->payload['id'] ?? null];
            }
        }
        self::assertNotContains(['entity_moved', 'a@ca'], $idsOfAinA, 'A 不应收到自己的 entity_moved（跳过自身）。');
        self::assertSame(2, $game->getWorld()->getEntityManager()->get('a@ca')->getPosition()['x'], 'A 的坐标必须更新。');
    }

    public function testFullBroadcastWorldReachesDistantEntity(): void
    {
        // 全量广播型（UniversalAOI）：世界内所有实体互相可见，距离不影响广播
        // Full-broadcast (UniversalAOI): every entity sees every other; distance never limits broadcasts
        [$game, $handlers] = $this->suite(fullBroadcast: true);
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA, $connB] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $serializer = new JsonBatchSerializer();
        $batchedA = [];
        $batchedB = [];

        // 把 B 移到极远处，A 的移动广播仍应到达 B（全量可见 = 全世界即视野）
        // Move B far away; A's move broadcast must still reach B (full visibility = the whole world is the view)
        $handlers['onMessage']($connB, $serializer->encodeBatch([Message::create('move', ['dx' => 500, 'dy' => 0])]));
        $batchedB = [];

        $handlers['onMessage']($connA, $serializer->encodeBatch([Message::create('move', ['dx' => 1, 'dy' => 0])]));
        $game->exposeFlush();

        $found = false;
        foreach ($batchedB as $blob) {
            foreach ($this->decodeBatch($blob) as $m) {
                if ($m->type === 'entity_moved' && ($m->payload['id'] ?? null) === 'a@ca') {
                    $found = true;
                }
            }
        }
        self::assertTrue($found, '全量广播型 World 必须把移动广播给远处实体对应的连接。');
    }

    public function testCloseConnectionCleansEntityAndBroadcastsLeave(): void
    {
        [$game, $handlers, $world] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedB = [];

        $handlers['onClose']($connA);
        $game->exposeFlush();

        self::assertNull($world->getEntityManager()->get('a@ca'), '断连后实体必须从实体管理器摘除。');
        self::assertContains(['a@ca'], $game->cleanedUp, 'onEntityCleanedUp 必须被调用。');

        $found = false;
        foreach ($batchedB as $blob) {
            foreach ($this->decodeBatch($blob) as $m) {
                if ($m->type === 'entity_leave' && ($m->payload['id'] ?? null) === 'a@ca') {
                    $found = true;
                }
            }
        }
        self::assertTrue($found, '断连必须向视野邻居广播 entity_leave。');
    }

    /**
     * V3（ADR-024 §9）缺省行为不变：未注入跨容器清理回调时，世界 EM 查空（实体已 transfer 进房）
     * 的断连保持旧模板语义——静默跳过实体摘除，不抛错，onEntityCleanedUp 照常调用。
     * V3 (ADR-024 §9) default behavior unchanged: with no cross-container cleanup callback injected, a disconnect
     * whose world EM lookup misses (the entity transferred into a room) keeps the legacy template semantics —
     * entity removal silently skipped, no throw, onEntityCleanedUp still invoked.
     */
    public function testCloseConnectionWithoutCallbackSkipsMissingEntitySilently(): void
    {
        [$game, $handlers, $world] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);

        // 模拟 transfer 进房：世界 EM/AOI 已摘除、registry 映射保留
        // Simulate a room transfer: the world EM/AOI entries are gone, the registry mapping stays
        $game->simulateTransferredIntoRoom('a@ca');

        $handlers['onClose']($connA);
        $game->exposeFlush();

        self::assertContains(['a@ca'], $game->cleanedUp, '缺省 null 时钩子照常调用 the hook still runs with the default null callback');
        $this->addToAssertionCount(1); // 未抛错即通过 passing means nothing was thrown
    }

    /**
     * V3：注入回调后，世界 EM 查空的断连兜底调用跨容器清理（以 entityId 为入参），且 onEntityCleanedUp
     * 照常调用（持久化冲刷由该钩子覆盖）。
     * V3: with a callback injected, a disconnect whose world EM lookup misses invokes the cross-container cleanup
     * fallback (with the entityId), and onEntityCleanedUp still runs (persistence flushing is covered by that hook).
     */
    public function testCloseConnectionInvokesCrossContainerCleanupWhenWorldMisses(): void
    {
        [$game, $handlers] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);

        $invoked = [];
        $game->setCrossContainerCleanup(static function (string $entityId) use (&$invoked): bool {
            $invoked[] = $entityId;

            return true;
        });

        $game->simulateTransferredIntoRoom('a@ca');
        $handlers['onClose']($connA);
        $game->exposeFlush();

        self::assertSame(['a@ca'], $invoked, '世界查空必须兜底调用跨容器清理回调 a world miss must invoke the cross-container cleanup callback');
        self::assertContains(['a@ca'], $game->cleanedUp, '回调路径后钩子照常调用 the hook still runs after the callback path');
    }

    /**
     * V3：实体仍在世界 EM 时走既有世界清理路径，跨容器回调不被调用（世界路径优先，不重复清理）。
     * V3: an entity still in the world EM takes the existing world cleanup path and never reaches the cross-container
     * callback (world path first, no double cleanup).
     */
    public function testCrossContainerCleanupNotInvokedWhenWorldHit(): void
    {
        [$game, $handlers, $world] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedB = [];

        $invoked = [];
        $game->setCrossContainerCleanup(static function (string $entityId) use (&$invoked): bool {
            $invoked[] = $entityId;

            return true;
        });

        $handlers['onClose']($connA);
        $game->exposeFlush();

        self::assertSame([], $invoked, '世界命中时不得调用跨容器回调 the callback must not run when the world hits');
        self::assertNull($world->getEntityManager()->get('a@ca'), '世界路径正常摘除 the world path removes normally');
        self::assertContains(['a@ca'], $game->cleanedUp);
    }

    /**
     * 反作弊钩子缺省 null 行为不变回归：未注入校验器时大步 move 照常应用并广播（旧模板语义逐字节保持）。
     * Anti-cheat-hook default-null regression: with no validator injected, a huge move still applies and broadcasts
     * (the legacy template semantics stay byte-for-byte).
     */
    public function testMoveWithoutValidatorKeepsLegacyBehavior(): void
    {
        [$game, $handlers] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedA = [];
        $batchedB = [];

        $handlers['onMessage']($connA, (new JsonBatchSerializer())->encodeBatch([Message::create('move', ['dx' => 100000, 'dy' => 0])]));
        $game->exposeFlush();

        self::assertSame([], $closedA, '缺省 null 校验器不得产生任何拒绝。The default-null validator must reject nothing.');
        self::assertSame(100000, $game->getWorld()->getEntityManager()->get('a@ca')->getPosition()['x'], '缺省 null 时大步移动必须照常应用。With default null the huge move must apply as before.');
    }

    /**
     * 反作弊钩子：注入校验器后超速 move 被拒——403 error 帧（携带 requestId、原因 overspeed），
     * 实体坐标不动、不广播；随后合法步照常通过（拒绝无副作用）。
     * Anti-cheat hook: with a validator injected an overspeed move is rejected — a 403 error frame (carrying the
     * requestId and the overspeed reason), the entity stays put and nothing broadcasts; a following legal step
     * passes normally (rejections have no side effects).
     */
    public function testMoveValidatorRejectsOverspeedWithErrorFrame(): void
    {
        [$game, $handlers] = $this->suite();
        $game->setMovementValidator(new MovementValidator());
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedA = [];
        $batchedB = [];

        // 超速步：拒绝 + 坐标不动 + 不广播
        // Overspeed step: rejected, coordinates untouched, nothing broadcast
        $handlers['onMessage']($connA, (new JsonBatchSerializer())->encodeBatch([Message::create('move', ['dx' => 100000, 'dy' => 0], 'req-1')]));
        $frames = $this->decodeBatch($batchedA[0]);
        self::assertSame('error', $frames[0]->type);
        self::assertSame(403, $frames[0]->payload['code']);
        self::assertSame('move rejected: overspeed', $frames[0]->payload['message']);
        self::assertSame('req-1', $frames[0]->requestId);
        self::assertSame([], $batchedB, '被拒移动不得向视野广播。A rejected move must not broadcast to the view.');
        self::assertSame(0, $game->getWorld()->getEntityManager()->get('a@ca')->getPosition()['x'], '被拒移动不得改实体坐标。A rejected move must not mutate entity coordinates.');

        // 合法步照常放行（校验器在环但不误伤）
        // A legal step still passes (the validator is in the loop without collateral damage)
        $batchedA = [];
        $handlers['onMessage']($connA, (new JsonBatchSerializer())->encodeBatch([Message::create('move', ['dx' => 1, 'dy' => 0])]));
        $game->exposeFlush();
        self::assertSame([], $closedA);
        self::assertSame(1, $game->getWorld()->getEntityManager()->get('a@ca')->getPosition()['x'], '合法步必须照常应用。A legal step must apply as usual.');
    }

    /**
     * 断连清理接线：closeConnection 模板调用 validator->forget 摘除 entityId 窗口行——
     * 断连前窗口预算耗尽会 rate_limited，断连后同 entityId 立即重开新窗（无泄漏、无残留）。
     * Disconnect-cleanup wiring: the closeConnection template invokes validator->forget to drop the entityId's
     * window row — a spent budget rate-limits before the disconnect, and right after it the same entityId opens a
     * fresh window (no leak, no leftover).
     */
    public function testDisconnectForgetsTheMovementValidatorWindow(): void
    {
        [$game, $handlers] = $this->suite();
        // 预算 1 / 窗 100s：一条 move 即打满且测试期内不滚动 The budget of 1 over a 100s window: one move fills it, no rollover mid-test.
        $validator = new MovementValidator(maxCommandsPerWindow: 1, windowSeconds: 100.0);
        $game->setMovementValidator($validator);
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        [$connA] = $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedA = [];
        $batchedB = [];

        // 一条合法 move 创建并打满窗口 A legal move creates and fills the window.
        $handlers['onMessage']($connA, (new JsonBatchSerializer())->encodeBatch([Message::create('move', ['dx' => 1, 'dy' => 0])]));
        self::assertSame(MovementValidator::REASON_RATE_LIMITED, $validator->validate('a@ca', 1, 0, 1, 0, microtime(true)), '断连前窗口必须残留（预算已耗尽）。Before the disconnect the window must linger (budget spent).');

        $handlers['onClose']($connA);
        $game->exposeFlush();

        self::assertNull($validator->validate('a@ca', 1, 0, 2, 0, microtime(true)), '断连后窗口必须被 forget（重开新窗放行）。After the disconnect the window must be forgotten (a fresh window passes).');
    }

    public function testFlushOutboxHardLimitClosesSlowClient(): void
    {
        [$game, $handlers] = $this->suite();
        $batched = [];
        $closed = [];
        $conn = $this->makeConn('c1', $batched, $closed);
        $handlers['onConnect']($conn);
        $handlers['onMessage']($conn, (new JsonBatchSerializer())->encodeBatch([Message::create('auth', ['uid' => 'a'])]));

        // 换入一个缓冲积压超过硬阈值（10MB）的慢客户端连接
        // Swap in a slow-client connection whose backlog exceeds the hard threshold (10MB)
        $slowConn = $this->createStub(ConnectionInterface::class);
        $slowConn->method('getId')->willReturn('c1');
        $slowConn->method('getSendBufferQueueSize')->willReturn(20 * 1024 * 1024);
        $slowConn->method('isAuthenticated')->willReturn(true);
        $slowConn->method('send')->willReturnCallback(static function (string $p) use (&$batched): void {
            $batched[] = $p;
        });
        $slowConn->method('sendBatch')->willReturnCallback(static function (array $p) use (&$batched): void {
            $batched = array_merge($batched, $p);
        });
        $slowConn->method('close')->willReturnCallback(static function () use (&$closed): void {
            $closed[] = 'c1';
        });
        $game->replaceConnection($slowConn);

        $game->exposeFlush();

        // 硬阈值路径：运行时把连接与实体清理干净（传输层断开由 Workerman 的缓冲写满机制负责，与旧实现一致）
        // The hard-threshold path: the runtime removes the connection and the entity (the transport close is owned by
        // Workerman's buffer-full mechanism, consistent with the previous implementation)
        self::assertSame(0, $game->connectionCount(), '缓冲积压超过硬阈值必须把连接从运行时移除。');
        self::assertContains(['a@c1'], $game->cleanedUp, '硬阈值断开必须走完整清理（onEntityCleanedUp）。');
    }

    public function testViewEventEnvelopeForwardedToTargetWithDecoration(): void
    {
        [$game, $handlers] = $this->suite();
        $batchedA = [];
        $batchedB = [];
        $closedA = [];
        $closedB = [];
        $this->authPair($handlers, $batchedA, $closedA, $batchedB, $closedB);
        $batchedB = [];

        // 模拟 World 发布视野进入信封：source = A 进入 B 的视野
        // Simulate the World publishing a view-enter envelope: source = A entered B's view
        $game->getWorld()->getEventBus()->publishEnvelope(new EventEnvelope(
            source: 'a@ca',
            type: EventEnvelope::TYPE_AOI_ENTER,
            timestamp: microtime(true),
            targetScope: 'b@cb',
            reliable: false,
            droppable: true,
            payload: ['position' => ['x' => 3, 'y' => 0]],
        ));
        $game->exposeFlush();

        $found = false;
        foreach ($batchedB as $blob) {
            foreach ($this->decodeBatch($blob) as $m) {
                if ($m->type === 'entity_enter' && ($m->payload['id'] ?? null) === 'a@ca') {
                    self::assertTrue($m->payload['extra'], 'decorateViewPayload 装饰必须生效。');
                    $found = true;
                }
            }
        }
        self::assertTrue($found, '视野信封必须转发为帧给 targetScope 对应连接。');
    }
}
