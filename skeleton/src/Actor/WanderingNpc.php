<?php

declare(strict_types=1);

namespace Nythros\Skeleton\Actor;

use Nythros\Framework\BaseNPC;

/**
 * 巡游 NPC：继承 framework 的 BaseNPC，演示 onIdle 钩子。
 * 每 5 帧尝试随机移动一格；超出出生点 ±patrolRadius 的位移被拒绝（NPC 活动在出生点附近）。
 * 移动后通过注入的 onMoved 回调广播 entity_moved（回调由 GameServer 提供，经 AOI 视野路径发送）。
 *
 * Wandering NPC: extends the framework's BaseNPC, demonstrating the onIdle hook.
 * Tries a random one-cell move every 5 frames; displacements beyond spawn ± patrolRadius are rejected
 * (NPCs roam near their spawn). After a move, the injected onMoved callback broadcasts entity_moved
 * (the callback is supplied by GameServer and sends through the AOI view path).
 */
final class WanderingNpc extends BaseNPC
{
    /** 移动间隔（帧数）：每 5 帧尝试移动一次。 Move interval in frames: a move is attempted every 5 frames. */
    private const WANDER_INTERVAL_FRAMES = 5;

    private int $frames = 0;

    /** @var callable(string $npcId, array{x: int, y: int} $position): void 移动后的广播回调 Move-broadcast callback. */
    private $onMoved;

    /**
     * @param string $npcId NPC 唯一标识 NPC unique id.
     * @param callable(string $npcId, array{x: int, y: int} $position): void $onMoved 移动回调 Move callback.
     * @param array{x: int, y: int} $patrolAnchor 出生点锚 Spawn anchor.
     * @param int $patrolRadius 巡逻半径（世界单位） Patrol radius (world units).
     */
    public function __construct(
        string $npcId,
        callable $onMoved,
        private readonly array $patrolAnchor = ['x' => 0, 'y' => 0],
        private readonly int $patrolRadius = 8,
    ) {
        parent::__construct($npcId);
        $this->onMoved = $onMoved;
    }

    /**
     * 空闲钩子（BaseNPC 模板方法每帧调用）：有界随机巡游。
     * Idle hook (called every frame by BaseNPC's template method): bounded random wandering.
     */
    protected function onIdle(): void
    {
        $entity = $this->entity;
        if ($entity === null) {
            return;
        }

        $this->frames++;
        if ($this->frames % self::WANDER_INTERVAL_FRAMES !== 0) {
            return;
        }

        $dx = random_int(-1, 1);
        $dy = random_int(-1, 1);
        if ($dx === 0 && $dy === 0) {
            return;
        }

        $pos = $entity->getPosition();
        if (abs($pos['x'] + $dx - $this->patrolAnchor['x']) > $this->patrolRadius
            || abs($pos['y'] + $dy - $this->patrolAnchor['y']) > $this->patrolRadius) {
            return;
        }

        $entity->move($dx, $dy);
        ($this->onMoved)($entity->getId(), $entity->getPosition());
    }
}
