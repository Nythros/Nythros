<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\BasePlayer;
use Nythros\Framework\Combat\BuffService;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\Buff\BuffDefinition;
use Nythros\Framework\Plugin\Buff\BuffRepository;
use PHPUnit\Framework\TestCase;

/**
 * BuffServiceTest - 覆盖 Buff 状态机正式化：施加/叠加边界矩阵（首次/refresh/stack 封顶/互斥顶替）、
 * 到期 tick（含 DOT 与到期的先后裁决）、DOT 结算与属性修正挂载。
 * Tests covering the formalized buff state machine: the application/stacking boundary matrix (first / refresh /
 * stack-cap / mutex displacement), expiry ticking (including the DOT-before-expiry ordering), DOT settlement and
 * attribute-modifier mounting.
 *
 * @see \Nythros\Framework\Combat\BuffService 类注释的叠加边界矩阵 Stacking boundary matrix in the service docblock.
 */
final class BuffServiceTest extends TestCase
{
    public function testApplyUnregisteredOrNonPositiveDurationReturnsFalse(): void
    {
        $repository = new BuffRepository();
        $service = new BuffService($repository);
        $host = $this->makePlayer();

        self::assertFalse($service->apply('h1', $host, 'missing', 100.0));

        $repository->register(new BuffDefinition('zero_dur', '零时长', 0.0, []));
        self::assertFalse($service->apply('h1', $host, 'zero_dur', 100.0), '时长非正的定义不可施加。Definitions with non-positive duration are not applicable.');
    }

    public function testFirstApplicationRegistersInstanceAndAttributeModifier(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('tough', '坚韧', 10.0, ['attributes' => ['maxHp' => 30]]));
        $host = $this->makePlayer();

        self::assertTrue($service->apply('h1', $host, 'tough', 100.0));

        $instance = $service->instanceOf('h1', 'tough');
        self::assertNotNull($instance);
        self::assertSame(1, $instance->stacks);
        self::assertSame(110.0, $instance->expiresAt, '到期时刻 = now + duration。expiresAt = now + duration.');
        self::assertSame(30, $host->attributeModifierSum('maxHp'));
        self::assertSame(130, $host->maxHp(), '属性修正如入 maxHp 合成。The modifier joins the maxHp composition.');
    }

    public function testRefreshRuleKeepsStacksAndRefreshesExpiry(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('tough', '坚韧', 10.0, ['attributes' => ['maxHp' => 30]]));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'tough', 100.0);
        $service->apply('h1', $host, 'tough', 105.0);

        $instance = $service->instanceOf('h1', 'tough');
        self::assertNotNull($instance);
        self::assertSame(1, $instance->stacks, 'refresh 规则层数不变。The refresh rule keeps stacks unchanged.');
        self::assertSame(115.0, $instance->expiresAt, 'refresh 规则刷新到期时刻。The refresh rule refreshes the expiry.');
        self::assertSame(30, $host->attributeModifierSum('maxHp'), 'refresh 不重复登记修正。Refresh never double-registers modifiers.');
    }

    public function testStackRuleAddsStacksUpToCapAndScalesModifiers(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('rage', '狂暴', 10.0, ['attributes' => ['maxHp' => 10]], BuffDefinition::STACK_STACK, 3));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'rage', 100.0);
        $service->apply('h1', $host, 'rage', 101.0);
        $service->apply('h1', $host, 'rage', 102.0);
        $service->apply('h1', $host, 'rage', 103.0);

        $instance = $service->instanceOf('h1', 'rage');
        self::assertNotNull($instance);
        self::assertSame(3, $instance->stacks, '叠层封顶 maxStacks。Stacking caps at maxStacks.');
        self::assertSame(113.0, $instance->expiresAt, '叠层同时刷新到期时刻。Stacking also refreshes the expiry.');
        self::assertSame(30, $host->attributeModifierSum('maxHp'), '修正随层数线性放大（3 层 × 10）。Modifiers scale linearly with stacks (3 × 10).');

        // 到期全量回退：3 层 × 10 全部摘除。
        // Full rollback on expiry: all 3 stacks × 10 removed.
        $service->tick(200.0, fn (): BasePlayer => $host);
        self::assertSame(0, $host->attributeModifierSum('maxHp'));
        self::assertNull($service->instanceOf('h1', 'rage'));
    }

    public function testMutexGroupDisplacesTheExistingSameGroupInstance(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('fear_a', '恐惧甲', 10.0, [], BuffDefinition::STACK_REFRESH, 1, 'control'));
        $repository->register(new BuffDefinition('fear_b', '恐惧乙', 8.0, [], BuffDefinition::STACK_REFRESH, 1, 'control'));
        $repository->register(new BuffDefinition('other', '无关', 8.0, [], BuffDefinition::STACK_REFRESH, 1, 'other-group'));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'fear_a', 100.0);
        $service->apply('h1', $host, 'other', 100.0);
        $service->apply('h1', $host, 'fear_b', 101.0);

        self::assertNull($service->instanceOf('h1', 'fear_a'), '同互斥组旧实例被顶替。The old same-group instance is displaced.');
        self::assertNotNull($service->instanceOf('h1', 'fear_b'));
        self::assertNotNull($service->instanceOf('h1', 'other'), '不同互斥组不受影响。Other groups stay untouched.');
    }

    public function testTickExpiresInstancesOnlyPastTheirExpiry(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('tough', '坚韧', 5.0, []));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'tough', 100.0);

        // 边界：now == expiresAt 即到期（含等于）。 Boundary: now == expiresAt counts as expired (inclusive).
        $service->tick(104.9, fn (): BasePlayer => $host);
        self::assertNotNull($service->instanceOf('h1', 'tough'), '到期前一刻仍在身。Still live one beat before expiry.');

        $service->tick(105.0, fn (): BasePlayer => $host);
        self::assertNull($service->instanceOf('h1', 'tough'), 'now == expiresAt 即到期。now == expiresAt is expired.');
    }

    public function testDotSettlesPerBeatAndNeverAfterExpiry(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('poison', '中毒', 2.5, ['dot' => ['damage' => 7, 'intervalSeconds' => 1.0]]));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'poison', 100.0);

        $service->tick(100.5, fn (): BasePlayer => $host);
        self::assertSame(100, $host->hp(), '首拍未到不结算。No settlement before the first beat.');

        $service->tick(101.0, fn (): BasePlayer => $host);
        self::assertSame(93, $host->hp(), '第一拍结算 DOT 伤害。The first beat settles the DOT damage.');

        $service->tick(102.0, fn (): BasePlayer => $host);
        self::assertSame(86, $host->hp(), '第二拍再结算一次。A second settlement on the next beat.');

        // t=102.5 到期；t=103 的尾拍不得再结算（过期优先于 DOT 判定）。
        // Expires at t=102.5; the tail beat at t=103 must not settle (expiry precedes the DOT judgment).
        $service->tick(103.0, fn (): BasePlayer => $host);
        self::assertSame(86, $host->hp(), '过期后不结算尾拍。No tail tick settles past expiry.');
        self::assertNull($service->instanceOf('h1', 'poison'));
    }

    public function testDotCanKillTheHost(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('doom', '末日', 10.0, ['dot' => ['damage' => 60, 'intervalSeconds' => 1.0]]));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'doom', 100.0);
        $service->tick(101.0, fn (): BasePlayer => $host);
        $service->tick(102.0, fn (): BasePlayer => $host);

        self::assertTrue($host->isDead(), 'DOT 可致死（takeDamage 模板路径）。DOT can kill (the takeDamage template path).');
    }

    public function testRemoveDispelsActivelyAndIsIdempotentOnMiss(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('tough', '坚韧', 10.0, ['attributes' => ['maxHp' => 30]]));
        $host = $this->makePlayer();

        self::assertFalse($service->remove('h1', $host, 'tough'), '未在身驱散返回 false。Dispelling an absent buff returns false.');

        $service->apply('h1', $host, 'tough', 100.0);
        self::assertTrue($service->remove('h1', $host, 'tough'));

        self::assertNull($service->instanceOf('h1', 'tough'));
        self::assertSame(0, $host->attributeModifierSum('maxHp'), '主动驱散同样回退修正。Active dispel rolls modifiers back too.');
    }

    public function testPurgeHostDropsEverythingSilently(): void
    {
        [$service, $repository] = $this->makeService();
        $repository->register(new BuffDefinition('a', '甲', 10.0, []));
        $repository->register(new BuffDefinition('b', '乙', 10.0, []));
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'a', 100.0);
        $service->apply('h1', $host, 'b', 100.0);
        $service->purgeHost('h1');

        self::assertSame([], $service->instancesOf('h1'), '宿主清理后无残留实例。No instance survives a host purge.');
    }

    public function testBroadcastsAndEventsFireOnApplyAndExpire(): void
    {
        $broadcaster = new RecordingBroadcaster();
        $events = new class () implements EventDispatcherInterface {
            /** @var list<array{event: string, payload: array<string, mixed>}> */
            public array $dispatched = [];

            public function listen(string $event, callable $listener): void
            {
            }

            public function dispatch(string $event, array $payload = []): void
            {
                $this->dispatched[] = ['event' => $event, 'payload' => $payload];
            }

            public function removeListener(string $event, callable $listener): void
            {
            }
        };
        $repository = new BuffRepository();
        $repository->register(new BuffDefinition('tough', '坚韧', 5.0, []));
        $service = new BuffService($repository, $broadcaster, $events);
        $host = $this->makePlayer();

        $service->apply('h1', $host, 'tough', 100.0);
        $service->tick(200.0, fn (): BasePlayer => $host);

        $appliedFrames = array_values(array_filter($broadcaster->direct, static fn (array $d): bool => $d['type'] === 'buff:applied'));
        $expiredFrames = array_values(array_filter($broadcaster->direct, static fn (array $d): bool => $d['type'] === 'buff:expired'));
        self::assertCount(1, $appliedFrames, '施加广播恰好一条 buff:applied。Exactly one buff:applied broadcast on application.');
        self::assertSame('h1', $appliedFrames[0]['entity']);
        self::assertCount(1, $expiredFrames, '到期广播恰好一条 buff:expired。Exactly one buff:expired broadcast on expiry.');
        self::assertSame(['event' => BuffService::EVENT_APPLIED, 'payload' => [
            'targetId' => 'h1', 'buffId' => 'tough', 'stacks' => 1, 'durationSeconds' => 5.0,
        ]], $events->dispatched[0], '施加事件负载口径。The applied-event payload contract.');
        self::assertSame(BuffService::EVENT_EXPIRED, $events->dispatched[1]['event']);
    }

    /**
     * 构造带记录广播器与空事件派发器的服务组。
     * Builds the service group with a recording broadcaster and a no-op event dispatcher.
     *
     * @return array{0: BuffService, 1: BuffRepository}
     */
    private function makeService(): array
    {
        $repository = new BuffRepository();

        return [new BuffService($repository, new RecordingBroadcaster()), $repository];
    }

    private function makePlayer(int $hp = 100, int $maxHp = 100): BasePlayer
    {
        return new class ($hp, $maxHp) extends BasePlayer {
            public function __construct(int $hp, int $maxHp)
            {
                $this->hp = $hp;
                $this->maxHp = $maxHp;
            }
        };
    }
}
