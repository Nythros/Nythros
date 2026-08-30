<?php

declare(strict_types=1);

namespace Nythros\Security\Tests;

use Nythros\Cluster\RedisServiceRegistry;
use Nythros\Security\RedisTokenStore;
use PHPUnit\Framework\TestCase;

/**
 * RedisExtensionGuardTest - ext-redis 运行时守卫冒烟：
 * 有扩展环境下守卫必须放行（工厂闭包形式构造成功且不触发真实连接）。
 * 缺扩展分支（抛 InvalidArgumentException）在本仓库 CI 环境无法模拟——extension_loaded 为内置函数
 * 不可 mock；该分支与 MySqlStorage 的 pdo_mysql 守卫同构，需在无 phpredis 的环境中人工验证一次。
 * RedisExtensionGuardTest - the ext-redis runtime guard smoke test:
 * with the extension loaded, the guard must pass (construction via a factory closure succeeds without
 * opening a real connection). The missing-extension branch (throwing InvalidArgumentException) cannot be
 * simulated in this repo's CI — extension_loaded is a built-in and not mockable; that branch mirrors
 * MySqlStorage's pdo_mysql guard and needs one manual verification on a phpredis-less environment.
 */
final class RedisExtensionGuardTest extends TestCase
{
    public function testGuardPassesAndConstructionStaysLazyWhenExtensionLoaded(): void
    {
        if (!extension_loaded('redis')) {
            // 无扩展环境：守卫分支即真实路径，本冒烟无意义（守卫会按设计抛 InvalidArgumentException）
            // Extension-less environment: the guard branch is the real path here (the guard throws InvalidArgumentException by design), so this smoke test is moot
            self::markTestSkipped('本环境未加载 ext-redis，跳过守卫放行冒烟');
        }

        // 守卫放行：两个 Redis 实现均可经工厂闭包构造（惰性，不建连）——
        // 若守卫误伤（如错误地无条件抛出），此处构造即失败
        // Guard passes: both Redis implementations construct via factory closures (lazy, no connection) —
        // if the guard misfires (e.g. throws unconditionally), construction fails right here
        $tokenStore = new RedisTokenStore(static fn (): \Redis => throw new \LogicException('守卫冒烟不应触发建连'));
        $registry = new RedisServiceRegistry(static fn (): \Redis => throw new \LogicException('守卫冒烟不应触发建连'));

        self::assertInstanceOf(RedisTokenStore::class, $tokenStore);
        self::assertInstanceOf(RedisServiceRegistry::class, $registry);
    }
}
