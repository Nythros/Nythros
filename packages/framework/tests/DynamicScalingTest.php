<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/FakeCluster.php';
require_once __DIR__ . '/FakeSocial.php';
require_once __DIR__ . '/SocialServiceTest.php';

use Nythros\Cluster\ServiceInstance;
use Nythros\Framework\Gm\Command\DrainCommand;
use Nythros\Framework\Gm\GmDrainHandlerInterface;
use Nythros\Framework\Social\SocialService;
use Nythros\Protocol\Message;
use PHPUnit\Framework\TestCase;

/**
 * DynamicScalingTest - P16 动态扩缩容的路由过滤与 drain 生命周期验收：
 * selectChannel 跳过 draining/stopping 与满员实例（声明 maxCapacity 时）、DrainCommand 的
 * ok/error 两态与幂等语义。
 * DynamicScalingTest - the P16 dynamic scaling's routing-filter and drain-lifecycle acceptance: selectChannel
 * skips draining/stopping and at-capacity instances (when maxCapacity is declared), and DrainCommand's
 * ok/error states with idempotent semantics.
 */
final class DynamicScalingTest extends TestCase
{
    public function testSelectChannelSkipsDrainingAndStoppingInstances(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1', 'status' => 'draining']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 0, 'wsAddress' => 'ws://ch2', 'status' => 'stopping']);
        $h->registry->discoveries['map']['map-1#ch-3'] = new ServiceInstance('map-1#ch-3', ['mapId' => 'map-1', 'channelId' => 'ch-3', 'playerCount' => 5, 'wsAddress' => 'ws://ch3']);

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        // draining/stopping 全被过滤：唯一存活频道 ch-3 尽管 playerCount=5 仍被选中
        // draining/stopping are all filtered: the only live channel ch-3 is picked despite playerCount=5.
        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('ch-3', $ok[0]->payload['map']['channelId']);
    }

    public function testSelectChannelSkipsCapacityFullInstances(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 2, 'wsAddress' => 'ws://ch1', 'maxCapacity' => 2]);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 1, 'wsAddress' => 'ws://ch2', 'maxCapacity' => 2]);

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        // ch-1 满员（2/2）被跳过：落到未满的 ch-2
        // ch-1 is full (2/2) and skipped: lands on the non-full ch-2.
        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('ch-2', $ok[0]->payload['map']['channelId']);
    }

    public function testSelectChannelReturnsNullWhenEveryChannelDrained(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1', 'status' => 'draining']);

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        // 全频道 draining：auth_failed 503 no available channel（扩缩容的排队/拒绝边界）
        // Every channel draining: auth_failed 503 no available channel (the scaling's queue/reject boundary).
        $errors = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $errors);
        self::assertSame(503, $errors[0]->payload['code']);
    }

    public function testDrainCommandOkAndIdempotentStates(): void
    {
        $handler = new class () implements GmDrainHandlerInterface {
            public bool $draining = false;

            public function drain(): bool
            {
                if ($this->draining) {
                    return false;
                }
                $this->draining = true;

                return true;
            }

            public function isDraining(): bool
            {
                return $this->draining;
            }
        };
        $command = new DrainCommand($handler);

        $first = $command->execute([]);
        self::assertSame('ok', $first->status);
        self::assertSame(['status' => 'draining'], $first->data);

        // 重复 drain 幂等：error 结果（已 draining）
        // A repeated drain is idempotent: an error result (already draining).
        $second = $command->execute([]);
        self::assertSame('error', $second->status);
    }

    /**
     * SocialService 测试 harness（与 SocialServiceTest::buildHarness 同构的最小切片，直接组装避免
     * 跨 TestCase 实例化）。
     * A SocialService test harness (the minimal slice mirroring SocialServiceTest::buildHarness, assembled
     * directly to avoid cross-TestCase instantiation).
     */
    private function buildHarness(): SocialServiceHarness
    {
        $h = new SocialServiceHarness();
        $h->gateway = new FakeConnectionHub();
        $h->tokens = new FakeTokenManager();
        $h->registry = new FakeServiceRegistry();
        $h->authenticator = new FakeSocialAuthenticator();
        $h->location = new FakeLocationStore();
        $h->guild = new FakeGuildStore();
        $h->team = new FakeTeamStore();
        $h->friend = new FakeFriendStore();

        $h->service = new SocialService(
            $h->gateway,
            $h->tokens,
            $h->registry,
            $h->authenticator,
            $h->location,
            $h->guild,
            $h->team,
            new \Nythros\Protocol\JsonSerializer(),
            ['map-1'],
            [],
            $h->friend,
        );

        return $h;
    }

    private static function messagesOfType(array $messages, string $type): array
    {
        return array_values(array_filter($messages, static fn (Message $m): bool => $m->type === $type));
    }
}
