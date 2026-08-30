<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Quest;

use Nythros\Framework\Quest\QuestChain;
use Nythros\Framework\Quest\QuestChainRules;
use PHPUnit\Framework\TestCase;

/**
 * QuestChainRulesTest - 任务链纯函数规则测试（R4 mmorpg 试点 → Quest 子系统）：链归属查询、按序解锁
 * （前序未完成锁定）、下一任务推进与链完成判定。
 * QuestChainRulesTest - the quest-chain pure-rule tests (the R4 mmorpg pilot → the Quest subsystem): chain
 * membership, ordered unlocking (locked until every predecessor completes), next-quest advancement and
 * chain-completion verdicts.
 */
final class QuestChainRulesTest extends TestCase
{
    private QuestChain $chain;

    protected function setUp(): void
    {
        $this->chain = new QuestChain('main-line', ['kill_wolves', 'collect_bones', 'talk_elder']);
    }

    public function testChainOfFindsMembership(): void
    {
        $chains = [$this->chain, new QuestChain('side-line', ['gather_herbs'])];
        self::assertSame($this->chain, QuestChainRules::chainOf($chains, 'collect_bones'));
        self::assertSame($chains[1], QuestChainRules::chainOf($chains, 'gather_herbs'));
        self::assertNull(QuestChainRules::chainOf($chains, 'no-such-quest'), '不属于任何链返回 null chainless quests return null');
        self::assertNull(QuestChainRules::chainOf([], 'kill_wolves'), '空链表返回 null an empty chain list returns null');
    }

    public function testFirstQuestIsAlwaysUnlocked(): void
    {
        self::assertTrue(QuestChainRules::isUnlocked($this->chain, [], 'kill_wolves'), '首任务无前序恒解锁 the first quest has no predecessor and is always unlocked');
    }

    public function testLaterQuestsLockedUntilPredecessorsComplete(): void
    {
        // 空完成集：collect_bones/talk_elder 均锁定。
        // Empty completion set: collect_bones/talk_elder are both locked.
        self::assertFalse(QuestChainRules::isUnlocked($this->chain, [], 'collect_bones'));
        self::assertFalse(QuestChainRules::isUnlocked($this->chain, [], 'talk_elder'));

        // kill_wolves 完成 → collect_bones 解锁；talk_elder 仍锁定（collect_bones 未完成）。
        // kill_wolves completed → collect_bones unlocks; talk_elder stays locked (collect_bones incomplete).
        self::assertTrue(QuestChainRules::isUnlocked($this->chain, ['kill_wolves'], 'collect_bones'));
        self::assertFalse(QuestChainRules::isUnlocked($this->chain, ['kill_wolves'], 'talk_elder'));

        // 两个前序完成 → talk_elder 解锁。
        // Both predecessors complete → talk_elder unlocks.
        self::assertTrue(QuestChainRules::isUnlocked($this->chain, ['kill_wolves', 'collect_bones'], 'talk_elder'));
    }

    public function testQuestOutsideChainIsNotUnlockedByMembership(): void
    {
        self::assertFalse(QuestChainRules::isUnlocked($this->chain, [], 'gather_herbs'), '链外任务不属于该链 the quest outside the chain has no membership');
    }

    public function testNextQuestIdReturnsFirstIncomplete(): void
    {
        self::assertSame('kill_wolves', QuestChainRules::nextQuestId($this->chain, []));
        self::assertSame('collect_bones', QuestChainRules::nextQuestId($this->chain, ['kill_wolves']));
        self::assertSame('talk_elder', QuestChainRules::nextQuestId($this->chain, ['kill_wolves', 'collect_bones']));
        self::assertNull(QuestChainRules::nextQuestId($this->chain, ['kill_wolves', 'collect_bones', 'talk_elder']), '链全完成返回 null a fully completed chain returns null');
    }

    public function testIsChainComplete(): void
    {
        self::assertFalse(QuestChainRules::isChainComplete($this->chain, ['kill_wolves']));
        self::assertFalse(QuestChainRules::isChainComplete($this->chain, ['kill_wolves', 'collect_bones']));
        self::assertTrue(QuestChainRules::isChainComplete($this->chain, ['kill_wolves', 'collect_bones', 'talk_elder']));
        self::assertTrue(QuestChainRules::isChainComplete($this->chain, ['kill_wolves', 'collect_bones', 'talk_elder', 'extra']), '完成集超集同样判完成 a superset completion set also counts');
    }
}
