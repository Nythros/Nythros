<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm\Command;

use Nythros\Framework\Gm\GmCommandInterface;
use Nythros\Framework\Gm\GmKickerInterface;
use Nythros\Framework\Gm\GmResult;

/**
 * kick 命令：经 GmKickerInterface 门面按 uid 踢下线。
 * The kick command: kicks a uid offline through the GmKickerInterface facade.
 *
 * 负载约定 payload {targetId: string}（复用协议既有 targetId 字段）；缺字段/非字符串按 error 结果拒绝。
 * Payload convention {targetId: string} (reusing the protocol's existing targetId key); a missing/non-string
 * field is rejected as an error result.
 */
final class KickCommand implements GmCommandInterface
{
    public function __construct(private readonly GmKickerInterface $kicker)
    {
    }

    public function name(): string
    {
        return 'kick';
    }

    public function execute(array $payload): GmResult
    {
        $targetId = $payload['targetId'] ?? null;
        if (!is_string($targetId) || $targetId === '') {
            return GmResult::error('payload 缺少 targetId 字段');
        }

        $closed = $this->kicker->kick($targetId);

        return GmResult::ok(sprintf('kicked %d connection(s)', $closed), ['count' => $closed]);
    }
}
