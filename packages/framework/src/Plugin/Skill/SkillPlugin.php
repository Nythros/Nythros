<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Skill;

use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;

/**
 * Skill 插件：向 Container 注册 SkillRepository，并订阅 'skill.cast' 作为退订机制的示范。
 * Skill plugin: registers SkillRepository into the Container and subscribes to 'skill.cast' as a
 * demonstration of the unsubscribe mechanism.
 *
 * SkillRepository 供 demo 战斗结算（CombatService.castSkill）按 skillId 查询倍率/冷却/距离；
 * 技能执行逻辑在 demo 层，framework 只提供定义与注册表（依赖倒置）。
 * SkillRepository is consumed by the demo combat resolution (CombatService.castSkill) to look up a
 * skill's multiplier/cooldown/range; skill execution lives in the demo layer — the framework only
 * provides definitions and repositories (dependency inversion).
 */
final class SkillPlugin implements PluginInterface
{
    private const REPOSITORY_ID = 'skill.repository';
    private const CAST_EVENT = 'skill.cast';

    private ?SkillRepository $repository = null;
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
        return 'skill';
    }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $this->repository ??= new SkillRepository();
        $container->set(self::REPOSITORY_ID, $this->repository);

        if ($this->subscribed) {
            return; // 幂等：重复 register 不重复订阅。 Idempotent: repeated register must not double-subscribe.
        }
        $this->listener = static function (array $payload): void {
            // 退订机制示范监听器（占位；业务逻辑后置 demo 层）。
            // Demonstration listener for the unsubscribe mechanism (placeholder; business logic lives in the demo layer).
        };
        $dispatcher->listen(self::CAST_EVENT, $this->listener);
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
            $dispatcher->removeListener(self::CAST_EVENT, $this->listener);
        }
        $this->subscribed = false;
        $this->listener = null;
        $container->remove(self::REPOSITORY_ID);
        $this->repository = null;
    }
}
