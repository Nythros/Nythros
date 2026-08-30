<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\BasePlayer;
use PHPUnit\Framework\TestCase;

/**
 * BasePlayerTest - 覆盖扣血钳制、死亡结算幂等、heal 与连接绑定。
 * Tests covering damage clamping, idempotent death settlement, heal and connection binding.
 */
final class BasePlayerTest extends TestCase
{
    public function testTakeDamageClampsToZeroAndSettlesDeath(): void
    {
        $player = $this->makePlayer();

        $player->takeDamage(150);

        self::assertSame(0, $player->hp());
        self::assertTrue($player->isDead());
        self::assertSame(1, $player->damageCalls, '有效扣血必须触发 onDamaged。Effective damage must trigger onDamaged.');
        self::assertSame(1, $player->deathCalls, '归零那次伤害必须触发 onDeath。The zeroing hit must trigger onDeath.');
    }

    public function testTakeDamageIgnoresNonPositiveAmounts(): void
    {
        $player = $this->makePlayer();

        $player->takeDamage(0);
        $player->takeDamage(-10);

        self::assertSame(100, $player->hp());
        self::assertFalse($player->isDead());
        self::assertSame(0, $player->damageCalls, '无效伤害不得触发 onDamaged。Invalid damage must not trigger onDamaged.');
        self::assertSame(0, $player->deathCalls);
    }

    public function testTakeDamageAfterDeathIsIdempotent(): void
    {
        $player = $this->makePlayer();

        $player->takeDamage(100);
        $player->takeDamage(50);

        self::assertSame(0, $player->hp());
        self::assertSame(1, $player->damageCalls, '已死后再受伤不得重复结算。Damage after death must not settle again.');
        self::assertSame(1, $player->deathCalls);
    }

    public function testOnDeathFiresOnlyOnTheTransitionHit(): void
    {
        $player = $this->makePlayer();

        $player->takeDamage(50);
        self::assertSame(1, $player->damageCalls);
        self::assertSame(0, $player->deathCalls, '未归零不得触发 onDeath。onDeath must not fire before hp reaches zero.');
        self::assertFalse($player->isDead());

        $player->takeDamage(50);
        self::assertSame(2, $player->damageCalls);
        self::assertSame(1, $player->deathCalls, 'onDeath 只在存活→死亡那次触发一次。onDeath fires exactly once on the transition hit.');

        $player->takeDamage(100);
        self::assertSame(1, $player->deathCalls, '已死后不得再次触发 onDeath。onDeath must not fire again after death.');
    }

    public function testHealRestoresHpClampedToMax(): void
    {
        $player = $this->makePlayer();
        $player->takeDamage(30);

        $player->heal(20);
        self::assertSame(90, $player->hp());

        $player->heal(1000);
        self::assertSame(100, $player->hp(), '治疗不得越过 maxHp。Heal must not exceed maxHp.');
    }

    public function testHealDoesNotReviveTheDead(): void
    {
        $player = $this->makePlayer();
        $player->takeDamage(100);

        $player->heal(50);

        self::assertSame(0, $player->hp());
        self::assertTrue($player->isDead(), '已死玩家不得被治疗复活。A dead player must not be revived by healing.');
    }

    public function testAttachConnectionBindsAndDetachClears(): void
    {
        $player = $this->makePlayer();

        self::assertNull($player->connectionId());
        self::assertNull($player->uid());

        $player->attachConnection('conn-1', 'uid-1');

        self::assertSame('conn-1', $player->connectionId());
        self::assertSame('uid-1', $player->uid());

        $player->detachConnection();

        self::assertNull($player->connectionId());
        self::assertNull($player->uid());
    }

    public function testUpdateRunsTheOnTickHook(): void
    {
        $player = $this->makePlayer();

        $player->update();
        $player->update();

        self::assertSame(2, $player->tickCalls, '每帧 update 必须调用一次 onTick。Each update must invoke onTick once.');
    }

    public function testAttributeModifiersJoinTheMaxHpComposition(): void
    {
        $player = $this->makePlayer();
        $player->takeDamage(40);

        $player->addAttributeModifier('maxHp', 30);
        self::assertSame(30, $player->attributeModifierSum('maxHp'));
        self::assertSame(130, $player->maxHp(), '临时修正如入 maxHp 合成。The temporary modifier joins the maxHp composition.');
        self::assertSame(60, $player->hp(), '扩上限不回血。Raising the ceiling never heals.');

        // 对称回退：按同一增量摘除，归零键移除。
        // Symmetric rollback: removed by the same delta; zeroed keys are dropped.
        $player->removeAttributeModifier('maxHp', 30);
        self::assertSame(0, $player->attributeModifierSum('maxHp'));
        self::assertSame(100, $player->maxHp());
        self::assertSame(60, $player->hp(), '压低上限时 hp 收敛。Hp is clamped when the ceiling lowers.');
    }

    public function testZeroDeltaModifierOperationsAreNoOps(): void
    {
        $player = $this->makePlayer();

        $player->addAttributeModifier('maxHp', 0);
        $player->removeAttributeModifier('maxHp', 0);

        self::assertSame(0, $player->attributeModifierSum('maxHp'), '零增量操作为无操作。Zero-delta operations are no-ops.');
    }

    /**
     * 构造记录钩子调用次数的玩家测试替身。
     * Builds a player test double recording hook invocation counts.
     *
     * @param int $hp 初始生命值 Initial hp.
     * @param int $maxHp 最大生命值 Maximum hp.
     */
    private function makePlayer(int $hp = 100, int $maxHp = 100): BasePlayer
    {
        return new class ($hp, $maxHp) extends BasePlayer {
            public int $damageCalls = 0;

            public int $deathCalls = 0;

            public int $tickCalls = 0;

            public function __construct(int $hp, int $maxHp)
            {
                $this->hp = $hp;
                $this->maxHp = $maxHp;
            }

            protected function onTick(): void
            {
                $this->tickCalls++;
            }

            protected function onDamaged(int $amount): void
            {
                $this->damageCalls++;
            }

            protected function onDeath(): void
            {
                $this->deathCalls++;
            }
        };
    }
}
