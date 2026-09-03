<?php

declare(strict_types=1);

namespace Nythros\Framework\Gm;

/**
 * GM 命令契约：最小内核的命令单元——名字 + 执行。
 * GM command contract: the minimal kernel's command unit — a name plus an execution.
 *
 * 身份与权限不在此处：白名单/角色判定由 GmPermissionInterface 承担（组装层实现身份侧，
 * framework 只提供能力，ADR 裁决「GM 白名单留在组装层」）。
 * Identity and permission live elsewhere: whitelist/role verdicts belong to GmPermissionInterface (the
 * the assembly layer implements the identity side; framework provides capability only, per the ruling that the
 * GM whitelist stays in the assembly layer).
 */
interface GmCommandInterface
{
    /** 命令名（分发键，如 status/broadcast/kick）。 The command name (the dispatch key, e.g. status/broadcast/kick). */
    public function name(): string;

    /**
     * 执行命令：返回结构化结果；抛出的异常由 CommandBus 捕获转 error 结果。
     * Executes the command and returns a structured result; thrown exceptions are caught by the CommandBus
     * and converted into error results.
     *
     * @param array<int|string, mixed> $payload 协议帧负载（字段按命令约定，口径对齐 Message::$payload） The protocol-frame payload (fields per command convention, aligned with Message::$payload).
     */
    public function execute(array $payload): GmResult;
}
