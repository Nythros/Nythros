<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

use Nythros\Demo\Plugin\AnnouncerPlugin;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Container\Container;
use Nythros\Framework\Event\EventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * AnnouncerPluginTest - P12 教程玩具插件的生命周期验收（docs/plugin-guide.md 教程产物的行为锁定）：
 * register 幂等（服务进 Container + 不重复订阅）、enable 门控计数、disable 暂停（事件照收不计数）、
 * uninstall 同引用退订 + Container 清理。
 * AnnouncerPluginTest - the P12 tutorial toy plugin's lifecycle acceptance (locking the behavior of the
 * artifact built in docs/plugin-guide.md): idempotent register (service into the Container + no double
 * subscription), the enable gate on counting, disable pausing (events arrive but are not counted), and
 * uninstall unsubscribing by the same reference + Container cleanup.
 */
final class AnnouncerPluginTest extends TestCase
{
    public function testFullLifecycleCountsOnlyWhileEnabled(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $plugin = new AnnouncerPlugin();

        // register：服务进 Container + 订阅（未 enable，计数不生效）
        // register: the service enters the Container + subscription (not enabled yet, so counting is inert).
        $plugin->register($container, $dispatcher);
        self::assertSame($plugin, $container->get(AnnouncerPlugin::SERVICE_ID));
        self::assertFalse($plugin->isActive());

        // enable 前的击杀不计
        // Kills before enable are not counted.
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);
        self::assertSame(0, $plugin->killsOf('1001'));

        // enable 后逐次计数
        // Counting accumulates per kill after enable.
        $plugin->enable();
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-2']);
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1002', 'victimId' => 'monster-1']);
        self::assertSame(2, $plugin->killsOf('1001'));
        self::assertSame(1, $plugin->killsOf('1002'));

        // disable = 暂停：事件照收但不计数；重新 enable 恢复
        // disable = pause: events arrive but are not counted; re-enable resumes.
        $plugin->disable();
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);
        self::assertSame(2, $plugin->killsOf('1001'));
        $plugin->enable();
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);
        self::assertSame(3, $plugin->killsOf('1001'));

        // uninstall：同引用退订（再 dispatch 不计数）+ Container 清理 + 状态回收
        // uninstall: unsubscribe by the same reference (later dispatches don't count) + Container cleanup + state reset.
        $plugin->uninstall($container, $dispatcher);
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);
        self::assertSame([], $plugin->kills());
        self::assertFalse($container->has(AnnouncerPlugin::SERVICE_ID));
    }

    public function testRegisterIsIdempotent(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $plugin = new AnnouncerPlugin();

        $plugin->register($container, $dispatcher);
        $plugin->enable();
        $plugin->register($container, $dispatcher); // 重复 register 不重建服务、不重置计数、不重复订阅
        $plugin->register($container, $dispatcher);
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => '1001', 'victimId' => 'monster-1']);

        // 若重复订阅，同一击杀会被计两次
        // With a double subscription the same kill would count twice.
        self::assertSame(1, $plugin->killsOf('1001'));
        self::assertSame($plugin, $container->get(AnnouncerPlugin::SERVICE_ID));
    }

    public function testKillsWithoutKillerUidAreIgnored(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher();
        $plugin = new AnnouncerPlugin();
        $plugin->register($container, $dispatcher);
        $plugin->enable();

        // 怪物击杀（killerUid = null）不计入
        // Monster kills (killerUid = null) are not counted.
        $dispatcher->dispatch(CombatService::EVENT_KILL, ['killerUid' => null, 'victimId' => '1001@conn-1']);

        self::assertSame([], $plugin->kills());
    }
}
