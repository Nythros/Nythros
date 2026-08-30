<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Gm;

use LogicException;
use Nythros\Framework\Gm\Command\BroadcastCommand;
use Nythros\Framework\Gm\Command\KickCommand;
use Nythros\Framework\Gm\Command\StatusCommand;
use Nythros\Framework\Gm\GmBroadcasterInterface;
use Nythros\Framework\Gm\GmCommandBus;
use Nythros\Framework\Gm\GmCommandInterface;
use Nythros\Framework\Gm\GmKickerInterface;
use Nythros\Framework\Gm\GmPermissionInterface;
use Nythros\Framework\Gm\GmResult;
use Nythros\Framework\Gm\GmStatusProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * GmCommandBusTest - 覆盖 GM 最小内核：命令注册/分发/未知命令/权限拒绝、执行异常转 error 结果，
 * 以及 status/broadcast/kick 基础命令行为。
 * GmCommandBusTest - covers the GM minimal kernel: registration/dispatch/unknown command/permission denial,
 * execution exceptions converting into error results, and the status/broadcast/kick basic-command behaviors.
 */
final class GmCommandBusTest extends TestCase
{
    public function testRegisterAndDispatchReachesTheCommand(): void
    {
        $bus = new GmCommandBus(new AllowAllPermissions());
        $command = new EchoingCommand();
        $bus->register($command);

        $result = $bus->dispatch('1001', 'echo', ['k' => 'v']);

        self::assertSame(GmResult::STATUS_OK, $result->status);
        self::assertSame('pong', $result->message);
        self::assertSame([['k' => 'v']], $command->sink, '负载必须原样到达命令。The payload must reach the command untouched.');
    }

    public function testUnknownCommandYieldsUnknownCommand(): void
    {
        $bus = new GmCommandBus(new AllowAllPermissions());

        $result = $bus->dispatch('1001', 'nope', []);

        self::assertSame(GmResult::STATUS_UNKNOWN_COMMAND, $result->status);
        self::assertSame('unknown command: nope', $result->message);
    }

    public function testPermissionDenialShortCircuitsBeforeExecution(): void
    {
        $permissions = new DenyAllPermissions();
        $counter = new Counter();
        $command = new CountingCommand($counter);
        $bus = new GmCommandBus($permissions);
        $bus->register($command);

        $result = $bus->dispatch('1002', 'secret', []);

        self::assertSame(GmResult::STATUS_PERMISSION_DENIED, $result->status);
        self::assertSame([['1002', 'secret']], $permissions->asked, '权限判定必须收到 uid 与命令名。The permission check must receive the uid and command name.');
        self::assertSame(0, $counter->executions, '权限拒绝后命令不得执行。A denied command must never execute.');
    }

    public function testDuplicateRegistrationThrows(): void
    {
        $bus = new GmCommandBus(new AllowAllPermissions());
        $bus->register(new StatusCommand(new StaticStatusProvider()));

        $this->expectException(LogicException::class);

        $bus->register(new StatusCommand(new StaticStatusProvider()));
    }

    public function testCommandExceptionConvertsIntoErrorResult(): void
    {
        $bus = new GmCommandBus(new AllowAllPermissions());
        $bus->register(new ThrowingCommand());

        $result = $bus->dispatch('1001', 'boom', []);

        self::assertSame(GmResult::STATUS_ERROR, $result->status);
        self::assertSame('exploded', $result->message);
    }

    public function testStatusCommandReturnsProviderSnapshot(): void
    {
        $bus = new GmCommandBus(new AllowAllPermissions());
        $bus->register(new StatusCommand(new StaticStatusProvider()));

        $result = $bus->dispatch('1001', 'status', []);

        self::assertSame(GmResult::STATUS_OK, $result->status);
        self::assertSame(['serviceId' => 'map-1#ch-1', 'playerCount' => 7], $result->data);
    }

    public function testBroadcastCommandDeliversThroughTheFacade(): void
    {
        $broadcaster = new RecordingBroadcaster();
        $bus = new GmCommandBus(new AllowAllPermissions());
        $bus->register(new BroadcastCommand($broadcaster));

        $result = $bus->dispatch('1001', 'broadcast', ['message' => 'server restart in 5min']);

        self::assertSame(GmResult::STATUS_OK, $result->status);
        self::assertSame(['server restart in 5min'], $broadcaster->messages, '广播必须经门面送达。The broadcast must deliver through the facade.');

        // 缺 message 字段 → error 结果且不投递
        // A missing message field → error result and no delivery
        $bad = $bus->dispatch('1001', 'broadcast', []);
        self::assertSame(GmResult::STATUS_ERROR, $bad->status);
        self::assertCount(1, $broadcaster->messages, '非法负载不得触发投递。An illegal payload must not trigger delivery.');
    }

    public function testKickCommandClosesByUidAndReportsCount(): void
    {
        $kicker = new RecordingKicker();
        $bus = new GmCommandBus(new AllowAllPermissions());
        $bus->register(new KickCommand($kicker));

        $result = $bus->dispatch('1001', 'kick', ['targetId' => '1002']);

        self::assertSame(GmResult::STATUS_OK, $result->status);
        self::assertSame(['1002'], $kicker->kicked);
        self::assertSame(['count' => 2], $result->data);
        self::assertSame('kicked 2 connection(s)', $result->message);

        // 不在线：0 连接断开仍为 ok（幂等语义）
        // Offline: zero closed connections still yields ok (idempotent semantics)
        $kicker->nextCount = 0;
        $offline = $bus->dispatch('1001', 'kick', ['targetId' => 'offline-guy']);
        self::assertSame(GmResult::STATUS_OK, $offline->status);
        self::assertSame(['count' => 0], $offline->data);

        // 缺 targetId → error 且不调用 kicker
        // A missing targetId → error and the kicker is never called
        $before = count($kicker->kicked);
        $bad = $bus->dispatch('1001', 'kick', []);
        self::assertSame(GmResult::STATUS_ERROR, $bad->status);
        self::assertSame($before, count($kicker->kicked), '非法负载不得触发踢人。An illegal payload must not trigger a kick.');
    }
}

/** 全放行权限假实现。 An always-allow permission fake. */
final class AllowAllPermissions implements GmPermissionInterface
{
    public function allows(string $uid, string $command): bool
    {
        return true;
    }
}

/** 全拒绝权限假实现（记录判定入参）。 An always-deny permission fake recording the verdict inputs. */
final class DenyAllPermissions implements GmPermissionInterface
{
    /** @var list<array{0: string, 1: string}> 判定入参记录 [uid, command] Verdict-input records [uid, command]. */
    public array $asked = [];

    public function allows(string $uid, string $command): bool
    {
        $this->asked[] = [$uid, $command];

        return false;
    }
}

/** 广播记录假实现。 A recording broadcaster fake. */
final class RecordingBroadcaster implements GmBroadcasterInterface
{
    /** @var list<string> 已广播文本 Broadcast texts. */
    public array $messages = [];

    public function broadcast(string $message): void
    {
        $this->messages[] = $message;
    }
}

/** 踢人记录假实现。 A recording kicker fake. */
final class RecordingKicker implements GmKickerInterface
{
    /** @var list<string> 已踢 uid 列表 Kicked uid list. */
    public array $kicked = [];

    /** 下次 kick 返回的连接数 Connection count the next kick returns. */
    public int $nextCount = 2;

    public function kick(string $uid): int
    {
        $this->kicked[] = $uid;

        return $this->nextCount;
    }
}

/** 静态状态源假实现。 A static status-provider fake. */
final class StaticStatusProvider implements GmStatusProviderInterface
{
    public function status(): array
    {
        return ['serviceId' => 'map-1#ch-1', 'playerCount' => 7];
    }
}

/** 执行计数器（可变共享）。 An execution counter (mutable shared). */
final class Counter
{
    public int $executions = 0;
}

/** 回显命令假实现：记录负载并回 pong。 An echoing-command fake: records the payload and replies pong. */
final class EchoingCommand implements GmCommandInterface
{
    /** @var list<array<string, mixed>> 负载接收槽 The payload sink. */
    public array $sink = [];

    public function name(): string
    {
        return 'echo';
    }

    public function execute(array $payload): GmResult
    {
        $this->sink[] = $payload;

        return GmResult::ok('pong');
    }
}

/** 计数命令假实现：统计执行次数。 A counting-command fake: tallies executions. */
final class CountingCommand implements GmCommandInterface
{
    public function __construct(private readonly Counter $counter)
    {
    }

    public function name(): string
    {
        return 'secret';
    }

    public function execute(array $payload): GmResult
    {
        $this->counter->executions++;

        return GmResult::ok();
    }
}

/** 抛异常命令假实现。 A throwing-command fake. */
final class ThrowingCommand implements GmCommandInterface
{
    public function name(): string
    {
        return 'boom';
    }

    public function execute(array $payload): GmResult
    {
        throw new \RuntimeException('exploded');
    }
}
