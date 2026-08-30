<?php

declare(strict_types=1);

namespace Nythros\Demo\Plugin;

use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;

/**
 * 击杀播报插件（P12 教程玩具插件，docs/plugin-guide.md 的随教程产物）：完整生命周期示范——
 * register 注册计数服务 + 订阅 combat.kill（保存监听器句柄，uninstall 用同一引用退订），
 * enable/disable 门控计数行为（暂停时事件照收但不计数）。
 * The kill-announcer plugin (the P12 tutorial's toy plugin, the artifact built alongside
 * docs/plugin-guide.md): a full-lifecycle demonstration — register registers a counting service and
 * subscribes to combat.kill (saving the listener handle for uninstall to unsubscribe by the same
 * reference), while enable/disable gate the counting behavior (events still arrive while paused but
 * are not counted).
 *
 * 计数服务经 Container 以 'announcer.service' 暴露，供集成测试与未来装配消费；
 * 行为副作用刻意只到「服务内部状态」为止——不广播协议帧（新增帧须同步 FrameType/PayloadKey 词表，
 * 教程插件不应触碰协议面）。
 * The counting service is exposed via the Container as 'announcer.service' for integration tests and
 * future assembly consumption; its side effects deliberately stop at internal service state — no
 * protocol frames are broadcast (new frames require the FrameType/PayloadKey vocabulary sync, and a
 * tutorial plugin should not touch the protocol surface).
 */
final class AnnouncerPlugin implements PluginInterface
{
    public const SERVICE_ID = 'announcer.service';
    private const KILL_EVENT = CombatService::EVENT_KILL;

    /** @var array<string, int> attackerId => 击杀数 attackerId => kill count. */
    private array $kills = [];

    private bool $active = false;
    private bool $subscribed = false;

    /**
     * register 保存的监听器句柄，uninstall 用同一引用退订（§2.5 闭包引用约定——
     * PHP 闭包每次字面量创建新实例，removeListener 按引用精确匹配）。
     * The listener handle saved by register and reused by uninstall for unsubscription (the §2.5 closure
     * reference convention — every PHP closure literal is a new instance and removeListener matches by exact reference).
     *
     * @var (callable(array<string, mixed>): void)|null
     */
    private $listener = null;

    public function name(): string
    {
        return 'announcer';
    }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        // 计数服务进 Container（幂等：重复 register 不重建、不重置计数）
        // The counting service enters the Container (idempotent: repeated register never rebuilds or resets).
        $container->set(self::SERVICE_ID, $this);

        if ($this->subscribed) {
            return; // 幂等：重复 register 不重复订阅。 Idempotent: repeated register must not double-subscribe.
        }
        $this->listener = function (array $payload): void {
            if (!$this->active) {
                return; // disable 期间事件照收但不计数（暂停语义）。 Events still arrive while disabled but are not counted (the pause semantics).
            }
            // combat.kill 负载键：killerUid（玩家击杀者为 uid，怪物击杀者为 null）/victimId/monsterId/monsterTypeId
            // The combat.kill payload keys: killerUid (the uid for player killers, null for monster kills) /
            // victimId / monsterId / monsterTypeId.
            $killerUid = $payload['killerUid'] ?? null;
            if (is_string($killerUid) && $killerUid !== '') {
                $this->kills[$killerUid] = ($this->kills[$killerUid] ?? 0) + 1;
            }
        };
        $dispatcher->listen(self::KILL_EVENT, $this->listener);
        $this->subscribed = true;
    }

    public function enable(): void
    {
        $this->active = true;
    }

    public function disable(): void
    {
        $this->active = false;
    }

    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        if ($this->subscribed && $this->listener !== null) {
            $dispatcher->removeListener(self::KILL_EVENT, $this->listener);
        }
        $this->subscribed = false;
        $this->listener = null;
        $this->kills = [];
        $this->active = false;
        $container->remove(self::SERVICE_ID);
    }

    /**
     * 查询某击杀者（玩家 uid）的累计击杀数（未击杀返回 0）。
     * Queries a killer's (player uid) cumulative kill count (0 when none).
     */
    public function killsOf(string $attackerUid): int
    {
        return $this->kills[$attackerUid] ?? 0;
    }

    /**
     * 全部击杀计数快照（测试断言用）。
     * A snapshot of all kill counts (for test assertions).
     *
     * @return array<string, int>
     */
    public function kills(): array
    {
        return $this->kills;
    }

    /**
     * 插件是否处于计数态（enable/disable 门控的观察口）。
     * Whether the plugin is counting (the observation port for the enable/disable gate).
     */
    public function isActive(): bool
    {
        return $this->active;
    }
}
