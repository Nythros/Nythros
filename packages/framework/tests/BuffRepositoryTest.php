<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use Nythros\Framework\Plugin\Buff\BuffDefinition;
use Nythros\Framework\Plugin\Buff\BuffRepository;
use PHPUnit\Framework\TestCase;

/**
 * BuffRepositoryTest - 覆盖 buff 定义注册/查询/全量与未命中 null。
 * Tests covering buff definition registration, lookup, all and null on miss.
 */
final class BuffRepositoryTest extends TestCase
{
    public function testRegisterAndGetReturnTheSameDefinition(): void
    {
        $repository = new BuffRepository();
        $buff = new BuffDefinition('berserk', '狂暴', 10.0, ['atk' => 1.2]);

        $repository->register($buff);

        self::assertSame($buff, $repository->get('berserk'));
    }

    public function testGetUnknownReturnsNull(): void
    {
        $repository = new BuffRepository();

        self::assertNull($repository->get('missing'));
    }

    public function testAllReturnsRegisteredDefinitions(): void
    {
        $repository = new BuffRepository();
        $berserk = new BuffDefinition('berserk', '狂暴', 10.0, ['atk' => 1.2]);
        $regen = new BuffDefinition('regen', '再生', 5.0, ['hpPerSec' => 2]);

        $repository->register($berserk);
        $repository->register($regen);

        self::assertSame(['berserk' => $berserk, 'regen' => $regen], $repository->all());
    }

    public function testRegisterOverridesById(): void
    {
        $repository = new BuffRepository();
        $first = new BuffDefinition('regen', '再生', 5.0, ['hpPerSec' => 2]);
        $second = new BuffDefinition('regen', '强力再生', 8.0, ['hpPerSec' => 4]);

        $repository->register($first);
        $repository->register($second);

        self::assertSame($second, $repository->get('regen'), '同 id 后注册覆盖先注册。A later registration overrides the earlier one.');
        self::assertCount(1, $repository->all());
    }
}
