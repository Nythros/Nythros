<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 全服广播能力契约（starter-kit 实现接口、framework 消费——VisionBroadcasterInterface 倒置先例）：
 * 广播范围与投递设施由实现方决定（map 频道 = 本进程全部连接；跨进程全服广播属后续批次）。
 * The GM server-broadcast capability contract (starter-kit implements the interface, framework consumes it —
 * the VisionBroadcasterInterface inversion precedent): the broadcast scope and delivery facility are the
 * implementer's call (a map channel = every connection in this process; cross-process whole-server broadcast
 * belongs to a later batch).
 */
interface GmBroadcasterInterface
{
    /**
     * 向本服务全部在线客户端广播一条 GM 消息。
     * Broadcasts one GM message to every online client of this service.
     *
     * @param string $message 广播文本 The broadcast text.
     */
    public function broadcast(string $message): void;
}
