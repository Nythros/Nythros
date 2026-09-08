<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Quest;

use Nythros\Framework\Quest\CachedQuestStore;
use Nythros\Framework\Quest\InMemoryQuestStore;
use Nythros\Framework\Quest\QuestProgress;
use Nythros\Framework\Quest\QuestRepository;
use Nythros\Framework\Quest\QuestService;
use Nythros\Framework\Quest\QuestStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * 记录往返的会话后端桩（InMemory 委托 + 计数）:断言 onSessionOpen/onSessionClose 恰对应
 * 「一次预热读 / 一次脏回写」——会话参与者契约的行为锁。
 * A counting session backend (delegating to InMemory) that locks the participant contract's behavior:
 * onSessionOpen = exactly one preheat read, onSessionClose = the dirty write-back lands.
 */
final class CountingSessionBackend implements QuestStoreInterface
{
    public int $allReads = 0;

    public int $writes = 0;

    private InMemoryQuestStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryQuestStore();
    }

    public function save(QuestProgress $progress): void
    {
        $this->writes++;
        $this->inner->save($progress);
    }

    public function get(string $uid, string $questId): ?QuestProgress
    {
        return $this->inner->get($uid, $questId);
    }

    public function all(string $uid): array
    {
        $this->allReads++;

        return $this->inner->all($uid);
    }

    public function delete(string $uid, string $questId): void
    {
        $this->inner->delete($uid, $questId);
    }
}

/**
 * QuestService 会话参与者契约测试（路线图③）：实现 SessionParticipantInterface、open=预热、
 * close=回写+释放、重复 close/open 幂等。MapServer 生命周期统一驱动由 demo 测试与 E2E 覆盖。
 * Session-participant contract tests for QuestService (roadmap item 3): implements the interface, open preheats,
 * close writes back and releases, repeated open/close stay idempotent. MapServer's unified lifecycle driving is
 * covered by the demo tests and E2E.
 */
final class QuestServiceSessionTest extends TestCase
{
    private function service(CountingSessionBackend $backend): QuestService
    {
        return new QuestService(new CachedQuestStore($backend), new QuestRepository());
    }

    public function testImplementsSessionParticipantContract(): void
    {
        $service = $this->service(new CountingSessionBackend());

        self::assertInstanceOf(\Nythros\Framework\Persistence\SessionParticipantInterface::class, $service);
    }

    public function testOpenPreheatsAndCloseFlushes(): void
    {
        $backend = new CountingSessionBackend();
        $store = new CachedQuestStore($backend);
        $service = new QuestService($store, new QuestRepository());

        $service->onSessionOpen('u1');
        self::assertSame(1, $backend->allReads, 'open 恰一次整批预热读');

        // 运行期进度上报不回写后端（热路径零往返）:同一写回缓冲实例直接入脏
        // Runtime progress must not touch the backend: dirty lands on the same write-back buffer instance
        $store->save(new QuestProgress('u1', 'kill_wolves', 1, false, false));
        self::assertSame(0, $backend->writes, '脏记录只在冲刷点回写');

        $service->onSessionClose('u1');
        self::assertSame(1, $backend->writes, 'close 回写脏进度');

        // 重复 close 幂等（已无脏可写）
        // Repeated close is a no-op (nothing dirty left)
        $service->onSessionClose('u1');
        self::assertSame(1, $backend->writes);

        // close 淘汰了会话缓存 → 再 open 是「新会话」,重预热读恰为应然（重连语义）;同一会话内重复 open 幂等
        // Close evicted the session: re-open is a fresh session and legitimately re-reads (reconnect semantics);
        // repeated open within one session stays idempotent
        $service->onSessionOpen('u1');
        self::assertSame(2, $backend->allReads, 'close 后重开=新会话,重新预热');
        $service->onSessionOpen('u1');
        self::assertSame(2, $backend->allReads, '同会话重复 open 不重读');
    }

    public function testPlainBackendParticipationIsInert(): void
    {
        // 非写回后端（直连 store）:open/close 契约零操作,行为与接入前等价
        // A non-write-back backend: the hooks are inert, behavior stays pre-integration
        $backend = new InMemoryQuestStore();
        $service = new QuestService($backend, new QuestRepository());

        $service->onSessionOpen('u1');
        $service->reportCollect('u1', 'bone', 1); // 无定义匹配,仅验证不抛
        $service->onSessionClose('u1');

        self::assertSame([], $service->allProgress('u1'));
    }
}
