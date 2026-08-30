<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Container\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * ContainerTest - 覆盖实例/工厂注册、延迟装配缓存、未命中异常与卸载。
 * Tests covering instance/factory registration, lazy assembly caching, unknown-id exceptions and removal.
 */
final class ContainerTest extends TestCase
{
    public function testSetAndGetReturnTheSameInstance(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->set('db', $service);

        self::assertSame($service, $container->get('db'));
    }

    public function testFactoryResolvesLazilyAndCaches(): void
    {
        $container = new Container();
        $calls = 0;
        $container->factory('db', static function () use (&$calls): stdClass {
            $calls++;

            return new stdClass();
        });

        self::assertSame(0, $calls, 'has/get 之前工厂不得执行。The factory must not run before resolution.');

        $first = $container->get('db');
        $second = $container->get('db');

        self::assertSame($first, $second, '工厂结果必须缓存，重复 get 返回同一实例。Factory results must be cached; repeated gets return the same instance.');
        self::assertSame(1, $calls, '工厂只装配一次。The factory assembles exactly once.');
    }

    public function testGetUnknownThrows(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);

        $container->get('missing');
    }

    public function testHasReflectsRegistration(): void
    {
        $container = new Container();
        $container->set('db', new stdClass());
        $container->factory('cache', static fn (): stdClass => new stdClass());

        self::assertTrue($container->has('db'));
        self::assertTrue($container->has('cache'));
        self::assertFalse($container->has('missing'));
    }

    public function testRemoveUnregistersBothFactoriesAndInstances(): void
    {
        $container = new Container();
        $container->set('db', new stdClass());
        $container->factory('cache', static fn (): stdClass => new stdClass());

        $container->remove('db');
        $container->remove('cache');
        $container->remove('missing');

        self::assertFalse($container->has('db'));
        self::assertFalse($container->has('cache'));

        $this->expectException(InvalidArgumentException::class);
        $container->get('db');
    }

    public function testSetOverridesAFactory(): void
    {
        $container = new Container();
        $container->factory('db', static fn (): stdClass => new stdClass());
        $instance = new stdClass();

        $container->set('db', $instance);

        self::assertSame($instance, $container->get('db'), 'set 必须覆盖既有工厂。set must override an existing factory.');
    }

    public function testFactoryOverridesAnInstance(): void
    {
        $container = new Container();
        $container->set('db', new stdClass());
        $factoryResult = new stdClass();

        $container->factory('db', static fn (): stdClass => $factoryResult);

        self::assertSame($factoryResult, $container->get('db'), 'factory 必须覆盖既有实例。factory must override an existing instance.');
    }
}
