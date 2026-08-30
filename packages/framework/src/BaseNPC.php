<?php

declare(strict_types=1);

namespace Nythros\Framework;

use Nythros\Actor\BaseActor;

/**
 * NPC 基类：静态实体，无主动行为；交互由玩家触发 onInteract。
 * Base NPC class: a static entity without autonomous behavior; interaction is player-triggered via onInteract.
 */
abstract class BaseNPC extends BaseActor
{
    /**
     * @param string $npcId NPC 唯一标识 NPC unique id.
     */
    public function __construct(private readonly string $npcId)
    {
    }

    public function npcId(): string
    {
        return $this->npcId;
    }

    /**
     * 模板方法：静态实体默认空操作，交由子类 onIdle 钩子。
     * Template method: the static entity defaults to no-op, delegating to the onIdle hook.
     */
    final public function update(): void
    {
        $this->onIdle();
    }

    /**
     * 空闲钩子：子类可覆盖实现定时刷新等被动行为。
     * Idle hook: subclasses may override for passive behavior such as periodic refresh.
     */
    protected function onIdle(): void
    {
    }

    /**
     * 交互入口：由玩家触发，子类实现对话/商店等交互内容。
     * Interaction entry point: triggered by a player; subclasses implement dialogue, shops, etc.
     *
     * @param BasePlayer $player 触发交互的玩家 The interacting player.
     */
    public function onInteract(BasePlayer $player): void
    {
    }
}
