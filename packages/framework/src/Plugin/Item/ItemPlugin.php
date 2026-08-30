<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Item;

use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;

/**
 * Item 插件：向 Container 注册 ItemRepository，并订阅 'item.dropped' 作为退订机制的示范。
 * Item plugin: registers ItemRepository into the Container and subscribes to 'item.dropped' as a
 * demonstration of the unsubscribe mechanism.
 *
 * ItemRepository 供 demo CombatService 在 spawnDrops/pickup 做掉落 itemId 合法性校验：
 * 掉落表 roll 出的 itemId 与拾取报文携带的 itemId 均须经 get() 命中才合法，未命中即丢弃。
 * ItemRepository is consumed by the demo CombatService to validate drop itemId legality in
 * spawnDrops/pickup: every itemId rolled by the drop table or carried by a pickup message must hit
 * get() to be legal; misses are dropped.
 *
 * 物品背包与拾取入包逻辑在 demo 层（Inventory），framework 只提供定义与注册表（依赖倒置）。
 * Item inventory and pickup logic live in the demo layer (Inventory); the framework only provides
 * definitions and repositories (dependency inversion).
 */
final class ItemPlugin implements PluginInterface
{
    private const REPOSITORY_ID = 'item.repository';
    private const DROPPED_EVENT = 'item.dropped';

    private ?ItemRepository $repository = null;
    private bool $subscribed = false;

    /**
     * register 保存的监听器句柄，uninstall 用同一引用退订。
     * The listener handle saved by register and reused by uninstall for unsubscription.
     *
     * PHP 闭包每次字面量创建新实例，removeListener 按引用精确匹配，必须持有同一句柄；
     * 若 uninstall 里重新写一遍闭包字面量，将因引用不一致而无法退订。
     * Every closure literal creates a new instance in PHP and removeListener matches by exact reference,
     * so the same handle must be held; rewriting the closure literal in uninstall would fail to unsubscribe.
     *
     * @var (callable(array<string, mixed>): void)|null
     */
    private $listener = null;

    public function name(): string
    {
        return 'item';
    }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->repository ??= new ItemRepository();
        $container->set(self::REPOSITORY_ID, $this->repository);

        if ($this->subscribed) {
            return; // 幂等：重复 register 不重复订阅。 Idempotent: repeated register must not double-subscribe.
        }
        $this->listener = static function (array $payload): void {
            // 退订机制示范监听器（占位；业务逻辑后置 demo 层）。
            // Demonstration listener for the unsubscribe mechanism (placeholder; business logic lives in the demo layer).
        };
        $dispatcher->listen(self::DROPPED_EVENT, $this->listener);
        $this->subscribed = true;
    }

    public function enable(): void
    {
        // 激活运行时行为（demo 阶段无独立运行态，占位）。
        // Activates runtime behavior (no standalone runtime state at the demo stage; placeholder).
    }

    public function disable(): void
    {
        // 暂停运行时行为（demo 阶段无独立运行态，占位）。
        // Pauses runtime behavior (no standalone runtime state at the demo stage; placeholder).
    }

    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        if ($this->subscribed && $this->listener !== null) {
            $dispatcher->removeListener(self::DROPPED_EVENT, $this->listener);
        }
        $this->subscribed = false;
        $this->listener = null;
        $container->remove(self::REPOSITORY_ID);
        $this->repository = null;
    }
}
