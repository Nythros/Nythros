<?php

declare(strict_types=1);

namespace Nythros\Skeleton\Actor;

use Nythros\Framework\BasePlayer;

/**
 * 玩家 Actor：继承 framework 的 BasePlayer，演示模板方法模式。
 * 当前套件无战斗系统，onTick 留空即可；接入战斗后在这里做冷却递减、属性同步等帧逻辑
 * （takeDamage/heal/onDamaged/onDeath 由 BasePlayer 模板方法闭环）。
 *
 * Player actor: extends the framework's BasePlayer, demonstrating the template-method pattern.
 * This kit has no combat system, so onTick stays empty; wire cooldown decay / stat sync here later
 * (takeDamage/heal/onDamaged/onDeath are closed by BasePlayer's template methods).
 */
final class PlayerActor extends BasePlayer
{
    protected function onTick(): void
    {
    }
}
