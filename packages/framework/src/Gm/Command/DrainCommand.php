<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm\Command;

use Nythros\Framework\Gm\GmCommandInterface;
use Nythros\Framework\Gm\GmDrainHandlerInterface;
use Nythros\Framework\Gm\GmResult;

/**
 * drain 命令（P16 动态扩缩容）：标记本服务 draining——目录服务停止路由新会话，存量连接不受影响，
 * 在场玩家归零后即可停机摘除（scale-in 编排由外部驱动，如 map-rolling 的 watch 模式）。
 * The drain command (the P16 dynamic scaling): marks this service draining — the directory stops routing new
 * sessions while existing connections stay unaffected; once the player count reaches zero the worker can be
 * stopped and removed (the scale-in orchestration is externally driven, e.g. map-rolling's watch pattern).
 */
final class DrainCommand implements GmCommandInterface
{
    public function __construct(private readonly GmDrainHandlerInterface $handler)
    {
    }

    public function name(): string
    {
        return 'drain';
    }

    public function execute(array $payload): GmResult
    {
        $drained = $this->handler->drain();

        return $drained
            ? GmResult::ok('drain', ['status' => 'draining'])
            : GmResult::error('drain: already draining or cluster not assembled');
    }
}
