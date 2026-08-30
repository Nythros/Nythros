<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\BaseMonster;
use PHPUnit\Framework\TestCase;

/**
 * BaseMonsterTest - 覆盖 AI 状态分发、enterState 白名单、DEAD 终态与死亡结算闭环。
 * Tests covering AI state dispatch, enterState whitelist, the DEAD terminal state and the closed death settlement.
 */
final class BaseMonsterTest extends TestCase
{
    public function testConstructInitializesHpToMaxHp(): void
    {
        $monster = $this->makeMonster(80);

        self::assertSame('monster-1', $monster->monsterId());
        self::assertSame(80, $monster->hp());
        self::assertSame(80, $monster->maxHp());
        self::assertFalse($monster->isDead());
    }

    public function testInitialStateIsPatrol(): void
    {
        $monster = $this->makeMonster();

        self::assertSame(BaseMonster::STATE_PATROL, $monster->aiState());
        self::assertNull($monster->targetId());
    }

    public function testUpdateDispatchesToPatrolByDefault(): void
    {
        $monster = $this->makeMonster();

        $monster->update();

        self::assertSame([BaseMonster::STATE_PATROL], $monster->hooks);
    }

    public function testUpdateDispatchesHooksByAiState(): void
    {
        $monster = $this->makeMonster();

        $monster->enterState(BaseMonster::STATE_CHASE);
        $monster->update();
        self::assertSame([BaseMonster::STATE_CHASE], $monster->hooks);

        $monster->hooks = [];
        $monster->enterState(BaseMonster::STATE_ATTACK);
        $monster->update();
        self::assertSame([BaseMonster::STATE_ATTACK], $monster->hooks);

        $monster->hooks = [];
        $monster->enterState(BaseMonster::STATE_DEAD);
        $monster->update();
        self::assertSame([BaseMonster::STATE_DEAD], $monster->hooks, 'DEAD 状态每帧只走 onDead。The DEAD state only runs onDead per frame.');
    }

    public function testEnterStateRejectsIllegalState(): void
    {
        $monster = $this->makeMonster();

        $this->expectException(InvalidArgumentException::class);

        $monster->enterState('flying');
    }

    public function testDeadIsTerminalAndCannotBeLeft(): void
    {
        $monster = $this->makeMonster();
        $monster->enterState(BaseMonster::STATE_DEAD);

        $monster->enterState(BaseMonster::STATE_PATROL);
        $monster->enterState(BaseMonster::STATE_CHASE);

        self::assertSame(BaseMonster::STATE_DEAD, $monster->aiState(), 'DEAD 为终态，不得迁出。DEAD is terminal and must never be left.');
    }

    public function testSetTargetSetsAndClears(): void
    {
        $monster = $this->makeMonster();

        $monster->setTarget('player-1');
        self::assertSame('player-1', $monster->targetId());

        $monster->setTarget(null);
        self::assertNull($monster->targetId());
    }

    public function testTakeDamageSettlesDeathWithDeadStateAndOnDeath(): void
    {
        $monster = $this->makeMonster(100);

        $monster->takeDamage(70);
        self::assertSame(30, $monster->hp());
        self::assertFalse($monster->isDead());
        self::assertSame(0, $monster->deathCalls);

        $monster->takeDamage(50);
        self::assertSame(0, $monster->hp());
        self::assertTrue($monster->isDead());
        self::assertSame(BaseMonster::STATE_DEAD, $monster->aiState(), '归零必须迁移 DEAD。Zeroing must transition to DEAD.');
        self::assertSame(1, $monster->deathCalls, 'onDeath 只在存活→死亡那次触发一次。onDeath fires exactly once on the transition hit.');
    }

    public function testTakeDamageAfterDeathIsIdempotent(): void
    {
        $monster = $this->makeMonster(100);
        $monster->takeDamage(100);
        $monster->takeDamage(30);

        self::assertSame(0, $monster->hp());
        self::assertSame(BaseMonster::STATE_DEAD, $monster->aiState());
        self::assertSame(1, $monster->deathCalls, '已死后不得重复结算。Damage after death must not settle again.');
    }

    public function testTakeDamageIgnoresNonPositiveAmounts(): void
    {
        $monster = $this->makeMonster(100);

        $monster->takeDamage(0);
        $monster->takeDamage(-5);

        self::assertSame(100, $monster->hp());
        self::assertSame(0, $monster->deathCalls);
        self::assertSame(BaseMonster::STATE_PATROL, $monster->aiState());
    }

    public function testHealClampsToMaxHp(): void
    {
        $monster = $this->makeMonster(100);
        $monster->takeDamage(40);

        $monster->heal(30);
        self::assertSame(90, $monster->hp());

        $monster->heal(1000);
        self::assertSame(100, $monster->hp(), '治疗不得越过 maxHp。Heal must not exceed maxHp.');
    }

    public function testHealDoesNotReviveTheDead(): void
    {
        $monster = $this->makeMonster(100);
        $monster->takeDamage(100);

        $monster->heal(50);

        self::assertSame(0, $monster->hp());
        self::assertSame(BaseMonster::STATE_DEAD, $monster->aiState(), '已死怪物不得被治疗复活。A dead monster must not be revived by healing.');
    }

    /**
     * 构造记录钩子调用的怪物测试替身。
     * Builds a monster test double recording hook invocations.
     *
     * @param int $maxHp 最大生命值 Maximum hp.
     */
    private function makeMonster(int $maxHp = 100): BaseMonster
    {
        return new class ($maxHp) extends BaseMonster {
            /** @var list<string> */
            public array $hooks = [];

            public int $deathCalls = 0;

            public function __construct(int $maxHp)
            {
                parent::__construct('monster-1', $maxHp);
            }

            protected function onPatrol(): void
            {
                $this->hooks[] = self::STATE_PATROL;
            }

            protected function onChase(): void
            {
                $this->hooks[] = self::STATE_CHASE;
            }

            protected function onAttack(): void
            {
                $this->hooks[] = self::STATE_ATTACK;
            }

            protected function onDead(): void
            {
                $this->hooks[] = self::STATE_DEAD;
            }

            protected function onDeath(): void
            {
                $this->deathCalls++;
            }
        };
    }
}
