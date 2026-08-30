<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 命令结果：四态结构化回执（ok / unknown_command / permission_denied / error）。
 * GM command result: a four-state structured receipt (ok / unknown_command / permission_denied / error).
 */
final class GmResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_UNKNOWN_COMMAND = 'unknown_command';

    public const STATUS_PERMISSION_DENIED = 'permission_denied';

    public const STATUS_ERROR = 'error';

    /**
     * @param string $status 状态常量之一 One of the status constants.
     * @param string $message 人类可读信息 A human-readable message.
     * @param array<string, mixed> $data 附加数据（进程内消费；协议回执只取 code/message） Extra data (in-process consumption; the protocol receipt carries code/message only).
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }

    /** @param array<string, mixed> $data 附加数据 Extra data. */
    public static function ok(string $message = 'ok', array $data = []): self
    {
        return new self(self::STATUS_OK, $message, $data);
    }

    public static function unknownCommand(string $name): self
    {
        return new self(self::STATUS_UNKNOWN_COMMAND, sprintf('unknown command: %s', $name));
    }

    public static function permissionDenied(string $name): self
    {
        return new self(self::STATUS_PERMISSION_DENIED, sprintf('permission denied: %s', $name));
    }

    public static function error(string $message): self
    {
        return new self(self::STATUS_ERROR, $message);
    }
}
