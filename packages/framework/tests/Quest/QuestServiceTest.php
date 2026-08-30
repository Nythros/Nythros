<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Event\EventDispatcher;
use Nythros\Framework\Inventory;
use Nythros\Framework\Quest\InMemoryQuestStore;
use Nythros\Framework\Quest\QuestChain;
use Nythros\Framework\Quest\QuestDefinition;
use Nythros\Framework\Quest\QuestRepository;
use Nythros\Framework\Quest\QuestService;
use PHPUnit\Framework\TestCase;

/**
 * QuestServiceTest - 覆盖任务模块：三类进度源（击杀/收集/对话）、进度幂等（完成后短路/领奖幂等）、
 * 奖励发放走 Inventory 与事件埋点接线触发。
 * Tests covering the quest module: the three progress sources (kill/collect/talk), progress idempotency
 * (post-completion short-circuit / claim idempotency), reward granting through Inventory and the
 * instrumentation-wiring trigger.
 */
final class QuestServiceTest extends TestCase
{
    private QuestRepository $quests;

    private QuestService $service;

    protected function setUp(): void
    {
        $this->quests = new QuestRepository();
        $this->service = new QuestService(new InMemoryQuestStore(), $this->quests);
        $this->quests->register(new QuestDefinition('kill_wolves', '猎狼', QuestDefinition::SOURCE_KILL, 'wolf', 3, [['itemId' => 'gold', 'count' => 100]]));
        $this->quests->register(new QuestDefinition('collect_bones', '集骨', QuestDefinition::SOURCE_COLLECT, 'bone', 5));
        $this->quests->register(new QuestDefinition('talk_elder', '见长老', QuestDefinition::SOURCE_TALK, 'npc-elder', 1));
    }

    public function testKillProgressAccumulatesAndCompletes(): void
    {
        $this->service->reportKill('1001', 'wolf');
        $this->service->reportKill('1001', 'slime');
        self::assertSame(1, $this->service->progressOf('1001', 'kill_wolves')?->count, '非目标怪物不计数。Non-target monsters never count.');

        $this->service->reportKill('1001', 'wolf');
        $this->service->reportKill('1001', 'wolf');

        $progress = $this->service->progressOf('1001', 'kill_wolves');
        self::assertNotNull($progress);
        self::assertSame(3, $progress->count);
        self::assertTrue($progress->completed, '达到 requiredCount 即完成。Reaching requiredCount completes the quest.');
        self::assertFalse($progress->rewarded);
    }

    public function testCollectProgressAccumulatesByPickupCount(): void
    {
        $this->service->reportCollect('1001', 'bone', 2);
        $this->service->reportCollect('1001', 'bone', 3);

        $progress = $this->service->progressOf('1001', 'collect_bones');
        self::assertNotNull($progress);
        self::assertSame(5, $progress->count, '按入包数量累计。Counts accumulate by the picked-up quantity.');
        self::assertTrue($progress->completed);

        $this->service->reportCollect('1001', 'bone', 10);
        self::assertSame(5, $this->service->progressOf('1001', 'collect_bones')?->count, '完成后封顶不再累计。Post-completion counts stay capped.');
    }

    public function testTalkProgressCompletesOnFirstMatch(): void
    {
        $this->service->reportTalk('1001', 'npc-elder');

        $progress = $this->service->progressOf('1001', 'talk_elder');
        self::assertNotNull($progress);
        self::assertTrue($progress->completed, 'requiredCount=1 的对话任务一次即完成。A talk quest with requiredCount=1 completes on the first talk.');

        // 非目标 NPC 不计数。 Non-target NPCs never count.
        $this->service->reportTalk('1001', 'npc-blacksmith');
        self::assertSame(1, $this->service->progressOf('1001', 'talk_elder')?->count);
    }

    public function testCompletedQuestsStopAccumulatingIdempotently(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->reportKill('1001', 'wolf');
        }

        $progress = $this->service->progressOf('1001', 'kill_wolves');
        self::assertNotNull($progress);
        self::assertSame(3, $progress->count, '完成后的击杀不再累计（幂等短路）。Kills past completion stop accumulating (the idempotent short-circuit).');
        self::assertSame(3, $progress->count);
    }

    public function testClaimRewardGrantsThroughInventoryExactlyOnce(): void
    {
        $inventory = new Inventory();
        self::assertFalse($this->service->claimReward('1001', 'kill_wolves', $inventory), '未完成不可领奖。Uncompleted quests cannot be claimed.');

        for ($i = 0; $i < 3; $i++) {
            $this->service->reportKill('1001', 'wolf');
        }

        self::assertTrue($this->service->claimReward('1001', 'kill_wolves', $inventory), '完成后首次领奖成功。The first post-completion claim succeeds.');
        self::assertSame(100, $inventory->count('gold'), '奖励经 Inventory 入包。Rewards enter through Inventory.');

        self::assertFalse($this->service->claimReward('1001', 'kill_wolves', $inventory), '重复领奖幂等拒绝。Repeated claims are idempotently rejected.');
        self::assertSame(100, $inventory->count('gold'), '重复领奖不入包。Repeated claims never re-grant.');
    }

    public function testAttachDispatcherDrivesProgressFromCombatEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $this->service->attachDispatcher($dispatcher);

        // 击杀埋点 → 击杀进度源。
        // The kill instrumentation drives the kill source.
        $dispatcher->dispatch(QuestService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'm1', 'monsterId' => 'wolf']);
        self::assertSame(1, $this->service->progressOf('1001', 'kill_wolves')?->count);

        // 拾取埋点 → 收集进度源。
        // The pickup instrumentation drives the collect source.
        $dispatcher->dispatch(QuestService::EVENT_PICKUP, ['uid' => '1001', 'itemId' => 'bone', 'count' => 5]);
        self::assertTrue($this->service->progressOf('1001', 'collect_bones')?->completed);

        // 负载缺 uid（无归属击杀）静默跳过。
        // Payloads without a uid (unowned kills) are silently skipped.
        $dispatcher->dispatch(QuestService::EVENT_KILL, ['killerUid' => null, 'victimId' => 'm2', 'monsterId' => 'wolf']);
        self::assertSame(1, $this->service->progressOf('1001', 'kill_wolves')?->count);
    }

    public function testPerUidProgressIsIsolated(): void
    {
        $this->service->reportKill('1001', 'wolf');
        $this->service->reportKill('1002', 'wolf');

        self::assertSame(1, $this->service->progressOf('1001', 'kill_wolves')?->count);
        self::assertSame(1, $this->service->progressOf('1002', 'kill_wolves')?->count);
        self::assertCount(1, $this->service->allProgress('1001'));
    }

    public function testChainLocksUntilPredecessorCompletes(): void
    {
        // 注入链配置（P2 链式解锁）：kill_wolves → collect_bones → talk_elder 按序解锁。
        // With a chain config injected (the P2 chained unlocking): kill_wolves → collect_bones → talk_elder unlock in order.
        $service = new QuestService(new InMemoryQuestStore(), $this->quests, [
            new QuestChain('main-line', ['kill_wolves', 'collect_bones', 'talk_elder']),
        ]);

        // 链首恒解锁：kill_wolves 可推进。
        // The chain head is always unlocked: kill_wolves advances.
        $service->reportKill('1001', 'wolf');
        self::assertSame(1, $service->progressOf('1001', 'kill_wolves')?->count);

        // collect_bones 锁定（kill_wolves 未完成）：拾取骨被忽略，无进度记录。
        // collect_bones locked (kill_wolves incomplete): bone pickups are ignored, no progress record.
        $service->reportCollect('1001', 'bone', 5);
        self::assertNull($service->progressOf('1001', 'collect_bones'), '前序未完成时链上任务忽略进度 locked chain quests ignore progress');

        // talk_elder 同样锁定：对话被忽略。
        // talk_elder likewise locked: the talk report is ignored.
        $service->reportTalk('1001', 'npc-elder');
        self::assertNull($service->progressOf('1001', 'talk_elder'), '解锁前对话不计数 the talk quest ignores reports while locked');

        // 完成 kill_wolves（requiredCount=3）→ collect_bones 解锁并完成。
        // Completing kill_wolves (requiredCount=3) unlocks collect_bones, which then completes.
        $service->reportKill('1001', 'wolf');
        $service->reportKill('1001', 'wolf');
        self::assertTrue($service->progressOf('1001', 'kill_wolves')?->completed);
        $service->reportCollect('1001', 'bone', 5);
        self::assertTrue($service->progressOf('1001', 'collect_bones')?->completed, '解锁后收集推进生效 the unlocked collect quest advances');

        // collect_bones 完成后 talk_elder 解锁：一次对话即完成，整链闭环。
        // With collect_bones complete, talk_elder unlocks: one talk completes it and the chain closes.
        $service->reportTalk('1001', 'npc-elder');
        $progress = $service->progressOf('1001', 'talk_elder');
        self::assertNotNull($progress);
        self::assertTrue($progress->completed, '解锁后对话推进生效 the unlocked talk quest advances');
    }
}
