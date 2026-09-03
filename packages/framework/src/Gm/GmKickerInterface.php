<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 踢人能力契约（组装层实现接口、framework 消费）：按 uid 断开其全部在线连接，
 * 清理链路（实体摘除/广播/持久化冲刷）由实现方复用既有断连模板。
 * The GM kick capability contract (the assembly layer implements the interface, framework consumes it): disconnects
 * every online connection of a uid; the cleanup chain (entity removal / broadcast / persistence flush) reuses
 * the implementer's existing disconnect template.
 */
interface GmKickerInterface
{
    /**
     * 踢指定 uid 下线，返回实际断开的连接数（不在线返回 0）。
     * Kicks the uid offline and returns how many connections were actually closed (0 when offline).
     *
     * @param string $uid 目标账号 uid The target uid.
     */
    public function kick(string $uid): int;
}
