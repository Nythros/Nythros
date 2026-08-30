<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

use LogicException;
use Throwable;

/**
 * GM 命令总线：命令注册 / 权限检查 / 分发的最小内核。
 * The GM command bus: the minimal kernel of command registration, permission checking and dispatch.
 *
 * 分发顺序固定：未知命令 → unknown_command；权限拒绝 → permission_denied（命令不执行）；
 * 执行期异常捕获转 error 结果（GM 通道不向连接层抛异常）；成功 → 命令自己的 GmResult。
 * Fixed dispatch order: unknown name → unknown_command; permission denial → permission_denied (the command
 * never runs); execution exceptions are caught into error results (the GM channel never throws into the
 * connection layer); success → the command's own GmResult.
 */
final class GmCommandBus
{
    /** @var array<string, GmCommandInterface> 命令名 => 命令 command name => command */
    private array $commands = [];

    public function __construct(private readonly GmPermissionInterface $permissions)
    {
    }

    /**
     * 注册命令；同名重复注册抛异常（与 PluginRegistry::load 同口径）。
     * Registers a command; duplicate names throw (same convention as PluginRegistry::load).
     */
    public function register(GmCommandInterface $command): void
    {
        $name = $command->name();
        if (isset($this->commands[$name])) {
            throw new LogicException(sprintf('GM 命令重复注册: %s', $name));
        }

        $this->commands[$name] = $command;
    }

    /**
     * 分发一次 GM 命令（永不抛出）。
     * Dispatches one GM command (never throws).
     *
     * @param string $uid 发起者账号 uid The issuing uid.
     * @param string $commandName 命令名 The command name.
     * @param array<int|string, mixed> $payload 协议帧负载（口径对齐 Message::$payload） The protocol-frame payload (aligned with Message::$payload).
     */
    public function dispatch(string $uid, string $commandName, array $payload): GmResult
    {
        $command = $this->commands[$commandName] ?? null;
        if ($command === null) {
            return GmResult::unknownCommand($commandName);
        }

        if (!$this->permissions->allows($uid, $commandName)) {
            return GmResult::permissionDenied($commandName);
        }

        try {
            return $command->execute($payload);
        } catch (Throwable $e) {
            return GmResult::error($e->getMessage());
        }
    }
}
