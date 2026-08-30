<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

require_once __DIR__ . '/../../framework/tests/FakeCluster.php';

use Nythros\Aoi\GridAOI;
use Nythros\Contracts\ActorInterface;
use Nythros\Contracts\ActorSystemInterface;
use Nythros\Contracts\ClockInterface;
use Nythros\Contracts\SchedulerInterface;
use Nythros\Contracts\TimerInterface;
use Nythros\Contracts\WorldInterface;
use Nythros\Demo\MapServer;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Actor\PlayerActor;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Tests\FakeServiceRegistry;
use Nythros\Framework\Tests\FakeTokenManager;
use Nythros\Network\ConnectionClosedException;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerTest - MapServer 消息流集成测试：认证、移动广播、清理与连接异常兜底。
 * 组装策略：Server/Token/Connection/ActorSystem 用 stub（回调捕获与调用记录），
 * World 用真实 GridAOI + SimpleEntityManager + SimpleEventBus + RegionScheduler 组装（参照 WorldCorrectnessTest 风格），
 * EventBus 为真实实例以保证 register 订阅与 flush 分发链路完整；
 * Clock/Timer 注入测试内 Fake 实现，由测试显式 tick 驱动 50ms 世界帧（tick N 投递 flush 任务、tick N+1 的 runFrame 执行）。
 * MapServer message-flow integration tests: auth, move broadcast, cleanup and connection-exception fallback.
 * Assembly strategy: Server/Token/Connection/ActorSystem are stubbed (callback capture and call recording);
 * the World is assembled from a real GridAOI + SimpleEntityManager + SimpleEventBus + RegionScheduler (WorldCorrectnessTest style),
 * with a real EventBus so register subscriptions and flush dispatch run on the real path;
 * Clock/Timer are injected as in-file fakes driven explicitly by the test (tick N submits the flush task, tick N+1's runFrame runs it).
 */
final class MapServerTest extends TestCase
{
    public function testAuthPublishesBidirectionalEnterToBothPeers(): void
    {
        $h = $this->buildHarness();

        // A、B 先后认证：B 认证时 handleAuth 首次登记 AOI 得到 entered=[A]，按双向信封语义直接广播
        // 邻居视角（A 收「B 进入你的视野」）与自身视角（B 收「A 进入你的视野」），均入 outbox 帧末发送
        // A then B authenticate: when B authenticates, handleAuth's first AOI registration yields entered=[A], broadcast directly with bidirectional envelope semantics —
        // the neighbor view (A receives "B entered your view") and the self view (B receives "A entered your view"), both buffered in the outbox for frame-end delivery
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');

        // 两帧：帧 N 的世界更新（同格 fast path，无新差分）投递 flush 任务，帧 N+1 的 runFrame 执行 flush 把 outbox 中的双向 enter 批量发送
        // Two frames: frame N's world update (same-cell fast path, no new deltas) submits the flush task; frame N+1's runFrame executes flush, batch-sending the bidirectional enters from the outbox
        $h->tick();
        $h->tick();

        // auth_ok 直接 send（不经 outbox）
        // auth_ok is sent directly (never through the outbox)
        $authOkA = self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_ok');
        self::assertCount(1, $authOkA);
        self::assertSame('1001@conn-a', $authOkA[0]->payload['id']);

        $authOkB = self::messagesOfType(MapServerHarness::decodeFrames($h->sentB), 'auth_ok');
        self::assertCount(1, $authOkB);
        self::assertSame('1002@conn-b', $authOkB[0]->payload['id']);

        // 双向 enter：A 收到 B 进入视野（邻居视角），B 收到 A 进入视野（自身视角，reviewer MAJOR 修复后补发）
        // Bidirectional enter: A receives "B entered" (neighbor view) and B receives "A entered" (self view, back-filled by the reviewer MAJOR fix)
        $enterA = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertCount(1, $enterA);
        self::assertSame('1002@conn-b', $enterA[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $enterA[0]->payload['position']);

        $enterB = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_enter');
        self::assertCount(1, $enterB);
        self::assertSame('1001@conn-a', $enterB[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $enterB[0]->payload['position']);
    }

    public function testAuthSnapshotSyncsFullNeighborhoodInSameFlushBatch(): void
    {
        $h = $this->buildHarness();

        // A、B 已认证并完成首轮 flush：清空既有 enter 帧与 sendBatch 计数后开始快照断言
        // A and B authenticate and complete the first flush round; clear the enter frames and sendBatch counts before asserting the snapshot
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');
        $h->tick();
        $h->tick();
        $h->batchedA = [];
        $h->batchedB = [];
        $h->sendBatchCounts = [];

        // C 认证：entered=[A,B] 只驱动邻居视角广播；C 的自身视角 = 九宫格全量快照（A、B 各一条 entity_enter）
        // C authenticates: entered=[A,B] drives only the neighbor-view broadcast; C's self view is the full 3x3-neighborhood snapshot (one entity_enter each for A and B)
        $h->tokens->records['token-c'] = new TokenRecord('1003', 'map-1', ['map'], 0.0, 999.0);
        $h->connect($h->connC);
        $h->auth($h->connC, 'token-c');

        // 快照帧与 entered 广播同批等待：flush 任务尚未执行，outbox 缓冲对外不可见
        // Snapshot frames wait in the same batch as the entered broadcast: the flush task has not run yet, so the outbox buffer is not visible
        self::assertSame([], $h->batchedA);
        self::assertSame([], $h->batchedB);
        self::assertSame([], $h->batchedC);

        $h->tick();
        $h->tick();

        // 自身视角快照：C 恰好收到 A、B 两条 entity_enter（含全部邻居、不重复）
        // Self-view snapshot: C receives exactly one entity_enter each for A and B (all neighbors covered, no duplicates)
        $enterC = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedC), 'entity_enter');
        self::assertCount(2, $enterC);
        self::assertSame(
            ['1001@conn-a', '1002@conn-b'],
            array_map(static fn (Message $m): mixed => $m->payload['id'], $enterC),
        );

        // 邻居视角：A、B 各收到 1 条「C 进入视野」（entered 增量驱动）
        // Neighbor view: A and B each receive one "C entered" (entered-delta driven)
        $enterA = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertCount(1, $enterA);
        self::assertSame('1003@conn-c', $enterA[0]->payload['id']);

        $enterB = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_enter');
        self::assertCount(1, $enterB);
        self::assertSame('1003@conn-c', $enterB[0]->payload['id']);

        // 同批 flush：一次 flushOutbox 内每连接恰好一次 sendBatch——快照帧（C 的 2 条）与 entered 广播（A、B 各 1 条）同批发出
        // Same-batch flush: exactly one sendBatch per connection within one flushOutbox — the snapshot frames (C's two) and the entered broadcast (one each for A and B) go out in the same batch
        self::assertSame(['conn-a' => 1, 'conn-b' => 1, 'conn-c' => 1], $h->sendBatchCounts);
    }

    public function testMoveBroadcastsEntityMovedViaFrameEndFlush(): void
    {
        $h = $this->buildHarness();

        // 双方认证并跑完两帧 flush，清空既有 enter 帧后开始移动断言
        // Both authenticate and complete two frames of flush; clear the enter frames before asserting the move
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');
        $h->tick();
        $h->tick();
        $h->batchedA = [];
        $h->batchedB = [];

        // A 移动 (1,0)：同格移动走 AOI fast path 无视野事件；entity_moved 入 outbox，帧末 flush 批量广播给九宫格邻居 B
        // A moves (1,0): a same-cell move takes the AOI fast path with no visibility events; entity_moved enters the outbox and is batch-broadcast to the 3x3 neighbor B at the frame-end flush
        $h->move($h->connA, 1, 0);
        $h->tick();
        $h->tick();

        $moved = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_moved');
        self::assertCount(1, $moved);
        self::assertSame('1001@conn-a', $moved[0]->payload['id']);
        self::assertSame(['x' => 1, 'y' => 0], $moved[0]->payload['position']);

        // 无 move 回执：移动者 A 自己收不到 entity_moved（客户端以广播为准）
        // No move ack: the mover A never receives entity_moved (clients rely on the broadcast)
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_moved'));
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'entity_moved'));
    }

    public function testCleanupRemovesRegistryAoiEntityAndActor(): void
    {
        $h = $this->buildHarness();

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->tick();
        $h->tick();

        $h->close($h->connA);

        // 四路清理断言：registry 双向映射、实体表、Actor 全部摘除
        // Four-way cleanup assertions: registry bidirectional mapping, entity table and actor are all removed
        self::assertFalse($h->registry->has('conn-a'));
        self::assertNull($h->registry->getEntityId('conn-a'));
        self::assertNull($h->registry->getConnectionId('1001@conn-a'));
        self::assertNull($h->world->getEntityManager()->get('1001@conn-a'));
        self::assertCount(1, $h->removedActors);
        self::assertInstanceOf(PlayerActor::class, $h->removedActors[0]);
        self::assertSame('1001@conn-a', $h->removedActors[0]->getPlayerId());

        // AOI 已移除（行为断言）：B 随后认证时九宫格为空，经两帧 flush 后双方都收不到 entity_enter
        // AOI removal (behavioral assertion): when B authenticates afterwards its 3x3 neighborhood is empty, so after two frames of flush neither side receives entity_enter
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');
        $h->tick();
        $h->tick();
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_enter'));
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_enter'));
    }

    public function testCleanupBroadcastsEntityLeaveToNeighbors(): void
    {
        $h = $this->buildHarness();

        // A、B 同格认证并跑完两帧 flush（清空 enter 帧），关闭 A：cleanup 摘 AOI 前向九宫格邻居 B 广播 entity_leave
        // A and B authenticate in the same cell and complete two frames of flush (enter frames discarded), then A closes: cleanup broadcasts entity_leave to the 3x3 neighbor B before removing from AOI
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');
        $h->tick();
        $h->tick();
        $h->batchedA = [];
        $h->batchedB = [];

        $h->close($h->connA);
        // 两帧：帧 N 投递 flush 任务，帧 N+1 的 runFrame 执行 flush 把 entity_leave 批量发送给 B
        // Two frames: frame N submits the flush task; frame N+1's runFrame executes flush, batch-sending entity_leave to B
        $h->tick();
        $h->tick();

        $leaves = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_leave');
        self::assertCount(1, $leaves);
        self::assertSame('1001@conn-a', $leaves[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $leaves[0]->payload['position']);

        // A 的九宫格唯一邻居是 B；A 自己已摘除，收不到任何 entity_leave
        // B is A's only 3x3 neighbor; A itself is already detached and receives nothing
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_leave'));
        self::assertSame([], self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'entity_leave'));
    }

    public function testPeriodicResyncReBroadcastsNeighborSnapshot(): void
    {
        $h = $this->buildHarness(snapshotResyncIntervalSeconds: 1.0);

        // A、B 认证并跑完两帧 flush（清空既有 enter 帧）后开始重同步断言
        // A and B authenticate and complete two frames of flush (enter frames discarded) before the resync assertions
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');
        $h->tick();
        $h->tick();
        $h->batchedA = [];
        $h->batchedB = [];

        // 再两帧：帧 N 的重同步回调把九宫格全量快照入 outbox，帧 N+1 的 runFrame 执行 flush 批量发送
        // Two more frames: frame N's resync callback enqueues the full 3x3 snapshot into the outbox; frame N+1's runFrame flushes it
        $h->tick();
        $h->tick();

        // 重同步快照：A 收到 B 的 entity_enter（id/position 匹配），B 收到 A 的 entity_enter
        // Resync snapshot: A receives B's entity_enter (id/position match), B receives A's entity_enter
        $enterA = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertNotEmpty($enterA);
        self::assertSame('1002@conn-b', $enterA[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $enterA[0]->payload['position']);

        $enterB = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedB), 'entity_enter');
        self::assertNotEmpty($enterB);
        self::assertSame('1001@conn-a', $enterB[0]->payload['id']);
        self::assertSame(['x' => 0, 'y' => 0], $enterB[0]->payload['position']);
    }

    public function testFlushCatchesConnectionClosedExceptionAndCleansUp(): void
    {
        // connB 的 sendBatch 抛 ConnectionClosedException：模拟对端已断但 close 事件未触达
        // connB's sendBatch throws ConnectionClosedException: simulating a peer that died before its close event arrived
        $h = $this->buildHarness(connBThrowsOnSendBatch: true);

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');

        // A 移动：entity_moved 帧进入 B 的 outbox 分组；flush 时 B 的 sendBatch 抛出即触发四路清理，异常不外溢
        // A moves: the entity_moved frame lands in B's outbox group; when flush hits B's sendBatch exception the four-way cleanup runs and the exception never escapes
        $h->move($h->connA, 1, 0);

        $h->tick();
        $h->tick();

        // 兜底清理已执行：B 的 registry 映射、实体与 Actor 全部移除，且 flush 正常走完（未抛出）
        // The fallback cleanup ran: B's registry mapping, entity and actor are all removed, and flush completed without throwing
        self::assertFalse($h->registry->has('conn-b'));
        self::assertNull($h->registry->getEntityId('conn-b'));
        self::assertNull($h->world->getEntityManager()->get('1002@conn-b'));
        self::assertCount(1, $h->removedActors);
        self::assertInstanceOf(PlayerActor::class, $h->removedActors[0]);
        self::assertSame('1002@conn-b', $h->removedActors[0]->getPlayerId());

        // 清理路径不调用 conn->close()：连接对端已死，摘除表项即可
        // The cleanup path never calls conn->close(): the peer is already dead, dropping the table entry suffices
        self::assertSame([], $h->closedConns);

        // 同帧其余连接不受影响：A 仍能收到 B 进入视野的 enter（双向事件的邻居视角）
        // Other connections in the same frame are unaffected: A still receives B's enter (neighbor view of the bidirectional events)
        $enterA = self::messagesOfType(MapServerHarness::decodeFrames($h->batchedA), 'entity_enter');
        self::assertCount(1, $enterA);
        self::assertSame('1002@conn-b', $enterA[0]->payload['id']);
    }

    public function testMapMismatchRejectsWith403AndDoesNotConsume(): void
    {
        $h = $this->buildHarness();
        // token 签发给 map-2 却连到 map-1 频道：MAJOR-1 mapId 比对失败
        // Token issued for map-2 but presented to the map-1 channel: the MAJOR-1 comparison fails
        $h->tokens->records['token-wrong-map'] = new TokenRecord('1001', 'map-2', ['map'], 0.0, 999.0);

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-wrong-map');

        $failures = self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_failed');
        self::assertCount(1, $failures);
        self::assertSame(403, $failures[0]->payload['code']);
        self::assertSame('map_mismatch', $failures[0]->payload['reason']);

        // 连接已断开且实体未挂载（比对失败即断开，无脏状态）
        // The connection closed with no entity mounted (mismatch closes immediately, no dirty state)
        self::assertSame(['conn-a'], $h->closedConns);
        self::assertFalse($h->registry->has('conn-a'));

        // 不 consume：'map' scope 未写墓碑，token 保留可重连正确频道
        // Not consumed: no 'map'-scope tombstone was written, the token remains valid for the correct channel
        self::assertSame([], $h->tokens->consumeCalls);
    }

    public function testMapMismatchTokenCanAuthenticateOnCorrectMap(): void
    {
        $tokens = new FakeTokenManager();
        $tokens->records['token-x'] = new TokenRecord('1001', 'map-2', ['map'], 0.0, 999.0);

        // 撞错频道 map-1：403 map_mismatch 且不 consume
        // Hitting the wrong map-1 channel: 403 map_mismatch with no consumption
        $wrong = $this->buildHarness(tokens: $tokens, mapId: 'map-1', serviceId: 'map-1#ch-1');
        $wrong->connect($wrong->connA);
        $wrong->auth($wrong->connA, 'token-x');
        self::assertSame([], $tokens->consumeCalls);

        // 拿同一 token 重连正确频道 map-2：consume 判 Valid，正常进图（无需重新登录）
        // Reconnecting to the correct map-2 channel with the same token: consume verdicts Valid and the player enters normally (no re-login needed)
        $right = $this->buildHarness(tokens: $tokens, mapId: 'map-2', serviceId: 'map-2#ch-1');
        $right->connect($right->connA);
        $right->auth($right->connA, 'token-x');

        self::assertCount(1, $tokens->consumeCalls);
        self::assertSame(['token' => 'token-x', 'scope' => 'map'], $tokens->consumeCalls[0]);
        $authOk = self::messagesOfType(MapServerHarness::decodeFrames($right->sentA), 'auth_ok');
        self::assertCount(1, $authOk);
        self::assertSame('1001@conn-a', $authOk[0]->payload['id']);
    }

    public function testAuthFailedUnauthorizedReasonWithCodeField(): void
    {
        $h = $this->buildHarness();
        // token 有效但 scopes 不含 'map'：consume 判 Unauthorized（不消费任何标记）
        // Token valid but its scopes lack 'map': consume verdicts Unauthorized (nothing consumed)
        $h->tokens->records['token-no-map-scope'] = new TokenRecord('1001', 'map-1', ['chat'], 0.0, 999.0);
        $h->tokens->consumeResults['token-no-map-scope'] = TokenStatus::Unauthorized;

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-no-map-scope');

        $failures = self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_failed');
        self::assertCount(1, $failures);
        self::assertSame(403, $failures[0]->payload['code']);
        self::assertSame('unauthorized', $failures[0]->payload['reason']);
        self::assertSame(['conn-a'], $h->closedConns);
    }

    public function testAuthFailedInvalidReasonWhenPeekInvisible(): void
    {
        $h = $this->buildHarness();
        // peek 不可见（token 不存在）：直接 consume 归因五态，Invalid 兜底
        // Peek invisible (unknown token): fall straight to consume for the five-state verdict, Invalid as fallback
        $h->tokens->consumeResults['token-unknown'] = TokenStatus::Invalid;

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-unknown');

        $failures = self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_failed');
        self::assertCount(1, $failures);
        self::assertSame(403, $failures[0]->payload['code']);
        self::assertSame('invalid', $failures[0]->payload['reason']);
        self::assertSame(['conn-a'], $h->closedConns);
    }

    public function testPlayerCountTracksAuthAndCleanupViaHeartbeat(): void
    {
        $h = $this->buildHarness();

        // onWorkerStart 已触发：register meta 初始 playerCount=0
        // Worker start already fired: register meta starts with playerCount=0
        self::assertSame(0, $h->serviceRegistry->registers[0]['meta']['playerCount']);

        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        $h->connect($h->connB);
        $h->auth($h->connB, 'token-b');

        // fake timer 触发全部回调（含 5s 心跳）：auth_ok +1 ×2 → playerCount=2
        // The fake timer fires every callback (including the 5s heartbeat): two auth_ok increments → playerCount=2
        $h->timer->trigger();
        self::assertSame(2, $h->serviceRegistry->heartbeats[0]['meta']['playerCount']);

        // 关闭已认证连接 A：cleanup -1，下次心跳上报 1
        // Closing authenticated A: cleanup decrements, the next heartbeat reports 1
        $h->close($h->connA);
        $h->timer->trigger();
        self::assertSame(1, $h->serviceRegistry->heartbeats[1]['meta']['playerCount']);
    }

    /**
     * 出生保护窗口（R4 出生保护批）：auth 挂载即激活——新认证玩家 isSpawnProtected() 为真，
     * 且按帧倒数（驱动世界 tick 后保护随帧数递减直至失效）。
     * Spawn-protection window (the R4 spawn-protection batch): activated on auth mount — a freshly authenticated
     * player reports isSpawnProtected() true, counting down per frame (driving world ticks decays the protection
     * until it expires).
     */
    public function testAuthActivatesSpawnProtectionWindow(): void
    {
        $h = $this->buildHarness();
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');

        $actor = $h->mapServer->getActor('1001@conn-a');
        self::assertInstanceOf(PlayerActor::class, $actor);
        self::assertTrue($actor->isSpawnProtected(), 'auth 挂载即进入出生保护 protection starts at auth mount');

        // 驱动 60 帧（3s @ 50ms）玩家 Actor tick：保护恰好耗尽
        // Drive 60 player-actor ticks (3s at 50ms): the protection expires exactly
        for ($i = 0; $i < PlayerActor::SPAWN_PROTECTION_FRAMES; $i++) {
            $actor->update();
        }
        self::assertFalse($actor->isSpawnProtected(), '60 帧后保护失效 the protection expires after 60 frames');
    }

    public function testWorkerStartRegistersAndWorkerStopUnregisters(): void
    {
        $h = $this->buildHarness(serviceId: 'map-1#ch-2', wsAddress: 'ws://127.0.0.1:9002');

        // onWorkerStart 已在 buildHarness 手动触发：register 携带完整 meta（channelId 由 serviceId 编码解析）
        // Worker start already fired inside buildHarness: register carries the full meta (channelId parsed from the serviceId encoding)
        self::assertCount(1, $h->serviceRegistry->registers);
        $register = $h->serviceRegistry->registers[0];
        self::assertSame('map', $register['type']);
        self::assertSame('map-1#ch-2', $register['id']);
        self::assertSame('map-1', $register['meta']['mapId']);
        self::assertSame('ch-2', $register['meta']['channelId']);
        self::assertSame('ws://127.0.0.1:9002', $register['meta']['wsAddress']);
        self::assertSame(0, $register['meta']['playerCount']);
        self::assertSame('serving', $register['meta']['status']);

        // 心跳 Timer 带 playerCount 调 registry->heartbeat（meta 原子合并上报）
        // The heartbeat timer calls registry->heartbeat carrying playerCount (atomic meta merge reporting)
        $h->timer->trigger();
        self::assertCount(1, $h->serviceRegistry->heartbeats);
        self::assertSame('map', $h->serviceRegistry->heartbeats[0]['type']);
        self::assertSame('map-1#ch-2', $h->serviceRegistry->heartbeats[0]['id']);

        // onWorkerStop → unregister
        ($h->onWorkerStop)();
        self::assertCount(1, $h->serviceRegistry->unregisters);
        self::assertSame(['type' => 'map', 'id' => 'map-1#ch-2'], $h->serviceRegistry->unregisters[0]);
    }

    public function testFlushTaskGoesThroughInterfaceAddTaskToRegion(): void
    {
        // flushRegion null → addTask 路径
        // flushRegion null → the addTask path
        $defaultScheduler = new RecordingScheduler();
        $h = $this->buildHarness(scheduler: $defaultScheduler);
        $h->timer->trigger();
        self::assertCount(1, $defaultScheduler->addTaskCalls);
        self::assertSame([], $defaultScheduler->addTaskToRegionCalls);

        // flushRegion 'network' → addTaskToRegion 接口路径（不再 instanceof 收窄 RegionScheduler）
        // flushRegion 'network' → the addTaskToRegion interface path (no more instanceof narrowing on RegionScheduler)
        $regionScheduler = new RecordingScheduler();
        $h2 = $this->buildHarness(scheduler: $regionScheduler, flushRegion: 'network');
        $h2->timer->trigger();
        self::assertSame([], $regionScheduler->addTaskCalls);
        self::assertCount(1, $regionScheduler->addTaskToRegionCalls);
        self::assertSame('network', $regionScheduler->addTaskToRegionCalls[0]['region']);
    }

    /**
     * 组装完整 MapServer 测试依赖并返回线束。
     * Builds the full MapServer dependency stack and returns the harness.
     *
     * @param bool $connBThrowsOnSendBatch true 时 connB 的 sendBatch 抛 ConnectionClosedException（兜底场景） When true, connB's sendBatch throws ConnectionClosedException (fallback scenario).
     * @param ?FakeTokenManager $tokens 自定义 token fake；null = 默认（token-a/token-b 签发 map-1） Custom token fake; null = the default (token-a/token-b issued for map-1).
     * @param string $serviceId 实例标识（编码 {mapId}#{channelId}） Instance identifier ({mapId}#{channelId} encoding).
     * @param string $mapId 本实例地图标识（MAJOR-1 比对基准） This instance's map identifier (MAJOR-1 baseline).
     * @param ?SchedulerInterface $scheduler 自定义调度器；null = RegionScheduler（真实执行 flush） Custom scheduler; null = RegionScheduler (runs flush for real).
     * @param ?string $flushRegion flush 任务投递的调度分区名 Flush-task scheduler region name.
     * @param string $wsAddress 注册 meta 上报的对外地址 Public address reported in register meta.
     * @param ?float $snapshotResyncIntervalSeconds 视野快照周期重同步间隔（秒）；null = 不注册重同步定时器 Periodic vision-snapshot resync interval in seconds; null = no resync timer registered.
     */
    private function buildHarness(
        bool $connBThrowsOnSendBatch = false,
        ?FakeTokenManager $tokens = null,
        string $serviceId = 'map-1#ch-1',
        string $mapId = 'map-1',
        ?SchedulerInterface $scheduler = null,
        ?string $flushRegion = null,
        string $wsAddress = 'ws://127.0.0.1:18081',
        ?float $snapshotResyncIntervalSeconds = null,
        ?int $minClientVersion = null,
    ): MapServerHarness {
        $h = new MapServerHarness();

        $h->connA = $this->createStub(ConnectionInterface::class);
        $h->connA->method('getId')->willReturn('conn-a');
        $h->connA->method('getSendBufferQueueSize')->willReturn(0);
        $h->connA->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentA[] = $payload;
        });
        $h->connA->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedA = array_merge($h->batchedA, $payloads);
            $h->sendBatchCounts['conn-a'] = ($h->sendBatchCounts['conn-a'] ?? 0) + 1;
        });
        $h->connA->method('close')->willReturnCallback(static function () use ($h): void {
            $h->closedConns[] = 'conn-a';
        });

        $h->connB = $this->createStub(ConnectionInterface::class);
        $h->connB->method('getId')->willReturn('conn-b');
        $h->connB->method('getSendBufferQueueSize')->willReturn(0);
        $h->connB->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentB[] = $payload;
        });
        if ($connBThrowsOnSendBatch) {
            $h->connB->method('sendBatch')->willThrowException(new ConnectionClosedException('conn-b closed'));
        } else {
            $h->connB->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
                $h->batchedB = array_merge($h->batchedB, $payloads);
                $h->sendBatchCounts['conn-b'] = ($h->sendBatchCounts['conn-b'] ?? 0) + 1;
            });
        }
        $h->connB->method('close')->willReturnCallback(static function () use ($h): void {
            $h->closedConns[] = 'conn-b';
        });

        // connC：第三个连接，供「auth 快照含全部邻居 + 同批 flush」断言（快照/entered 广播均计入 batchedC 与 sendBatch 计数）
        // connC: a third connection for the "auth snapshot covers all neighbors + same-batch flush" assertions (snapshot/entered broadcasts both land in batchedC and the sendBatch counts)
        $h->connC = $this->createStub(ConnectionInterface::class);
        $h->connC->method('getId')->willReturn('conn-c');
        $h->connC->method('getSendBufferQueueSize')->willReturn(0);
        $h->connC->method('send')->willReturnCallback(static function (string $payload) use ($h): void {
            $h->sentC[] = $payload;
        });
        $h->connC->method('sendBatch')->willReturnCallback(static function (array $payloads) use ($h): void {
            $h->batchedC = array_merge($h->batchedC, $payloads);
            $h->sendBatchCounts['conn-c'] = ($h->sendBatchCounts['conn-c'] ?? 0) + 1;
        });
        $h->connC->method('close')->willReturnCallback(static function () use ($h): void {
            $h->closedConns[] = 'conn-c';
        });

        $server = $this->createStub(ServerInterface::class);
        $server->method('onWorkerStart')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onWorkerStart = $handler;
        });
        $server->method('onWorkerStop')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onWorkerStop = $handler;
        });
        $server->method('onConnect')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onConnect = $handler;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onMessage = $handler;
        });
        $server->method('onClose')->willReturnCallback(static function (callable $handler) use ($h): void {
            $h->onClose = $handler;
        });

        // token fake：记录 peek/consume 调用（供 map_mismatch 不 consume 与五态断言）
        // Token fake: records peek/consume calls (for the map_mismatch no-consume and five-state assertions)
        $h->tokens = $tokens ?? new FakeTokenManager();
        if ($tokens === null) {
            $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);
            $h->tokens->records['token-b'] = new TokenRecord('1002', 'map-1', ['map'], 0.0, 999.0);
        }

        // 集群 fake：记录 register/heartbeat/unregister 调用
        // Cluster fakes: record register/heartbeat/unregister calls
        $h->serviceRegistry = new FakeServiceRegistry();

        // ActorSystem 用 stub 记录 remove 调用，供清理四路断言
        // The ActorSystem is stubbed to record remove calls for the four-way cleanup assertions
        $actorSystem = $this->createStub(ActorSystemInterface::class);
        $actorSystem->method('remove')->willReturnCallback(static function (ActorInterface $actor) use ($h): void {
            $h->removedActors[] = $actor;
        });

        $h->registry = new ConnectionRegistry();
        $h->world = new World(new SimpleEntityManager(), $actorSystem, new GridAOI(10), new SimpleEventBus(), $scheduler ?? new RegionScheduler());
        $h->timer = new FakeTimer();
        $h->clock = new FakeClock();

        $mapServer = new MapServer(
            $server,
            new JsonBatchSerializer(),
            $h->tokens,
            $h->world,
            $h->registry,
            clock: $h->clock,
            timer: $h->timer,
            flushRegion: $flushRegion,
            serviceId: $serviceId,
            mapId: $mapId,
            serviceRegistry: $h->serviceRegistry,
            wsAddress: $wsAddress,
            snapshotResyncIntervalSeconds: $snapshotResyncIntervalSeconds,
        );
        $h->mapServer = $mapServer;
        $mapServer->register();

        // 手动触发 worker start：fake timer 只记录世界 tick 与心跳回调，由测试显式驱动
        // Manually fire worker start: the fake timer merely records the world tick and heartbeat callbacks, driven explicitly by the tests
        $mapServer->setMinClientVersion($minClientVersion);
        ($h->onWorkerStart)();

        return $h;
    }

    /**
     * 协议版本守卫（ADR-027）：启用最低版本后，auth 缺 version / 版本过低被拒绝且 token 不消费。
     * The protocol-version guard (ADR-027): with a minimum version enabled, an auth missing the version or
     * carrying a too-old one is rejected without consuming the token.
     */
    public function testVersionGuardRejectsOldClientsWithoutConsumingToken(): void
    {
        $h = $this->buildHarness(minClientVersion: 2);
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);

        // 缺 version
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        // 版本过低
        $h->connect($h->connB);
        ($h->onMessage)($h->connB, MapServerHarness::frame('auth', ['token' => 'token-a', 'version' => 1], 'auth-v1'));
        // 达标版本放行
        $h->connect($h->connC);
        ($h->onMessage)($h->connC, MapServerHarness::frame('auth', ['token' => 'token-a', 'version' => 2], 'auth-v2'));

        $failedA = self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_failed');
        self::assertCount(1, $failedA);
        self::assertSame('client_version_too_old', $failedA[0]->payload['reason']);
        $failedB = self::messagesOfType(MapServerHarness::decodeFrames($h->sentB), 'auth_failed');
        self::assertCount(1, $failedB);
        self::assertSame('client_version_too_old', $failedB[0]->payload['reason']);
        self::assertContains('conn-a', $h->closedConns);
        self::assertContains('conn-b', $h->closedConns);
        // token 未消费（与准入守卫同口径）：两次拒绝后 connC 仍能用同一 token 通过
        // The token was never consumed (the same convention as the admission guard): after two rejections the
        // same token still authenticates connC.
        $authOkC = self::messagesOfType(MapServerHarness::decodeFrames($h->sentC), 'auth_ok');
        self::assertCount(1, $authOkC);
    }

    /**
     * 版本守卫缺省关闭：未设置最低版本时，缺 version 的 auth 行为与接入前完全一致。
     * The guard is off by default: with no minimum configured, an auth without a version behaves exactly as before.
     */
    public function testVersionGuardIsOffByDefault(): void
    {
        $h = $this->buildHarness();
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);
        $h->connect($h->connA);
        $h->auth($h->connA, 'token-a');
        self::assertCount(1, self::messagesOfType(MapServerHarness::decodeFrames($h->sentA), 'auth_ok'));
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
 * MapServerTest 测试线束：集中存放可变状态（各连接发送帧捕获、回调句柄、移除记录）与消息驱动工具。
 * Test harness for MapServerTest: holds mutable state (per-connection frame captures, callback handles, removal records) and message-driving helpers.
 */
final class MapServerHarness
{
    public ConnectionInterface $connA;
    public ConnectionInterface $connB;
    public ConnectionInterface $connC;
    public WorldInterface $world;
    public ConnectionRegistry $registry;
    public FakeTimer $timer;
    public FakeClock $clock;

    /** 组装好的被测 MapServer（Actor 表断言等使用） The assembled MapServer under test (actor-table assertions etc.). */
    public MapServer $mapServer;

    /** token fake：peek/consume 调用记录 Token fake: peek/consume call records. */
    public FakeTokenManager $tokens;

    /** 服务注册表 fake：register/heartbeat/unregister 调用记录 Service-registry fake: register/heartbeat/unregister call records. */
    public FakeServiceRegistry $serviceRegistry;

    /** @var null|callable worker start 回调 Worker-start callback. */
    public $onWorkerStart = null;

    /** @var null|callable worker stop 回调（unregister 清理钩子） Worker-stop callback (unregister cleanup hook). */
    public $onWorkerStop = null;

    /** @var null|callable 连接建立回调 Connect callback. */
    public $onConnect = null;

    /** @var null|callable 消息回调 Message callback. */
    public $onMessage = null;

    /** @var null|callable 连接关闭回调 Close callback. */
    public $onClose = null;

    /** @var list<string> connA 经 send 直接发送的帧 Frames sent directly to connA via send. */
    public array $sentA = [];

    /** @var list<string> connA 经 sendBatch 批量发送的帧 Frames batch-sent to connA via sendBatch. */
    public array $batchedA = [];

    /** @var list<string> connB 经 send 直接发送的帧 Frames sent directly to connB via send. */
    public array $sentB = [];

    /** @var list<string> connB 经 sendBatch 批量发送的帧 Frames batch-sent to connB via sendBatch. */
    public array $batchedB = [];

    /** @var list<string> connC 经 send 直接发送的帧 Frames sent directly to connC via send. */
    public array $sentC = [];

    /** @var list<string> connC 经 sendBatch 批量发送的帧 Frames batch-sent to connC via sendBatch. */
    public array $batchedC = [];

    /** @var array<string, int> 每连接 sendBatch 调用次数（同批 flush 断言：一次 flushOutbox 内每连接恰好一次聚合发送） Per-connection sendBatch call counts (same-batch assertions: exactly one aggregated send per connection within one flushOutbox). */
    public array $sendBatchCounts = [];

    /** @var list<ActorInterface> 被 actorSystem->remove 移除的 Actor 记录 Actors removed via actorSystem->remove. */
    public array $removedActors = [];

    /** @var list<string> 被调用 close() 的连接 id Connection ids whose close() was called. */
    public array $closedConns = [];

    /**
     * 驱动一帧世界 tick（等价于 50ms 定时器到期：clock tick + world update + 投递 flush 任务）。
     * Drives one world tick (equivalent to the 50ms timer firing: clock tick + world update + flush task submission).
     */
    public function tick(): void
    {
        $this->timer->trigger();
    }

    /**
     * 模拟连接建立。
     * Simulates a connection being established.
     */
    public function connect(ConnectionInterface $conn): void
    {
        ($this->onConnect)($conn);
    }

    /**
     * 模拟连接关闭。
     * Simulates a connection closing.
     */
    public function close(ConnectionInterface $conn): void
    {
        ($this->onClose)($conn);
    }

    /**
     * 发送 auth 消息。
     * Sends an auth message.
     */
    public function auth(ConnectionInterface $conn, string $token, string $requestId = 'auth-1'): void
    {
        ($this->onMessage)($conn, self::frame('auth', ['token' => $token], $requestId));
    }

    /**
     * 发送 move 消息。
     * Sends a move message.
     */
    public function move(ConnectionInterface $conn, int $dx, int $dy, string $requestId = 'move-1'): void
    {
        ($this->onMessage)($conn, self::frame('move', ['dx' => $dx, 'dy' => $dy], $requestId));
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

    /**
     * 把帧字节列表解码为消息列表（走真实 JsonBatchSerializer 链路：每个元素可能是单帧对象或多帧批量包）。
     * Decodes a list of frame bytes into messages (via the real JsonBatchSerializer path: each element may be a
     * single frame object or a multi-frame batch packet).
     *
     * @param list<string> $frames 帧字节列表 Frame bytes.
     * @return list<Message> 已解码消息 Decoded messages.
     */
    public static function decodeFrames(array $frames): array
    {
        $serializer = new JsonBatchSerializer();
        $out = [];
        foreach ($frames as $frame) {
            foreach ($serializer->decodeBatch($frame) as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }
}

/**
 * FakeTimer - 测试定时器：只记录回调不真正定时，由测试经 trigger 手动驱动。
 * FakeTimer - test timer: records callbacks without real timing, driven manually by tests via trigger.
 */
final class FakeTimer implements TimerInterface
{
    /** @var list<callable> 已登记的回调 Registered callbacks. */
    private array $callbacks = [];

    public function add(float $intervalSeconds, callable $callback, bool $persistent = true): int
    {
        $this->callbacks[] = $callback;

        return count($this->callbacks);
    }

    public function cancel(int $timerId): void
    {
        // 测试不需要取消语义，空操作 No cancellation semantics needed in tests; no-op
    }

    /**
     * 手动触发全部已登记回调（模拟定时器逐次到期）。
     * Manually fires every registered callback (simulating timer expirations).
     */
    public function trigger(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }
}

/**
 * FakeClock - 测试时钟：每次 tick 推进固定 50ms，供世界 tick 链路使用。
 * FakeClock - test clock: advances a fixed 50ms per tick, used by the world tick path.
 */
final class FakeClock implements ClockInterface
{
    /** @var float 当前时钟时间（秒） Current clock time in seconds. */
    private float $current = 0.0;

    public function tick(): void
    {
        $this->current += 0.05;
    }

    public function now(): float
    {
        return $this->current;
    }

    public function deltaTime(): float
    {
        return 0.05;
    }
}

/**
 * RecordingScheduler - 记录 addTask/addTaskToRegion 调用但不执行任务的调度器（供 flush 接口路径断言）。
 * RecordingScheduler - a scheduler that records addTask/addTaskToRegion calls without running tasks (for the flush interface-path assertions).
 */
final class RecordingScheduler implements SchedulerInterface
{
    /** @var list<callable> addTask 调用记录 addTask call records. */
    public array $addTaskCalls = [];

    /** @var list<array{region: string, task: callable}> addTaskToRegion 调用记录 addTaskToRegion call records. */
    public array $addTaskToRegionCalls = [];

    public function addTask(callable $task, int $priority = 0): void
    {
        $this->addTaskCalls[] = $task;
    }

    public function addTaskToRegion(string $region, callable $task, int $priority = 0): void
    {
        $this->addTaskToRegionCalls[] = ['region' => $region, 'task' => $task];
    }

    public function runFrame(): void
    {
        // 本测试只断言调用记录，不执行任务 The tests only assert call records, never run tasks
    }
}
