<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm\Command;

use Nythros\Framework\Gm\GmBroadcasterInterface;
use Nythros\Framework\Gm\GmCommandInterface;
use Nythros\Framework\Gm\GmResult;

/**
 * broadcast 命令：经 GmBroadcasterInterface 门面向全服广播一条文本。
 * The broadcast command: broadcasts one text server-wide through the GmBroadcasterInterface facade.
 *
 * 负载约定 payload {message: string}；缺字段/非字符串按 error 结果拒绝（不静默）。
 * Payload convention {message: string}; a missing/non-string field is rejected as an error result (never silent).
 */
final class BroadcastCommand implements GmCommandInterface
{
    public function __construct(private readonly GmBroadcasterInterface $broadcaster)
    {
    }

    public function name(): string
    {
        return 'broadcast';
    }

    public function execute(array $payload): GmResult
    {
        $message = $payload['message'] ?? null;
        if (!is_string($message) || $message === '') {
            return GmResult::error('payload 缺少 message 字段');
        }

        $this->broadcaster->broadcast($message);

        return GmResult::ok('broadcasted');
    }
}
