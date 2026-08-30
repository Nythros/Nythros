<?php

declare(strict_types=1);

namespace Nythros\Framework\Mail;

/**
 * 邮件存储契约（Redis 持久，无 TTL）。
 * Mail store contract (Redis-backed, no TTL).
 *
 * 易失风险声明：邮件承载托管资产（交易行交付/撤单退回的附件），存储为 Redis 键——Redis 实例
 * 未开启持久化时进程重启即丢失全部邮件与托管资产。demo 规模接受该风险（照 GuildStore 先例的
 * 存储档位），生产部署必须启用 RDB/AOF 或替换为持久存储实现。
 * Volatility notice: mail carries escrowed assets (auction delivery / cancel-return attachments) stored as
 * Redis keys — without Redis persistence a process restart loses every mail and its escrowed assets. The demo
 * scale accepts this risk (the same storage tier as the GuildStore precedent); production deployments must
 * enable RDB/AOF or swap in a durable implementation.
 */
interface MailStoreInterface
{
    /**
     * 写入一封邮件（mailId 已由调用方生成并保证唯一；重复写入覆盖旧值）。
     * Inserts one mail (mailId is generated and kept unique by the caller; a duplicate overwrites).
     *
     * @param string $toUid 收件人 uid The recipient uid.
     * @param string $mailId 邮件唯一 id Unique mail id.
     * @param string $fromUid 发件人 uid（系统邮件用固定标识） The sender uid (a fixed marker for system mail).
     * @param string $title 邮件标题 The mail title.
     * @param string $body 邮件正文 The mail body.
     * @param list<array{itemId: string, count: int}> $attachments 附件列表（可为空） Attachment list (may be empty).
     */
    public function insert(string $toUid, string $mailId, string $fromUid, string $title, string $body, array $attachments): void;

    /**
     * 读取单封邮件。
     * Reads one mail.
     *
     * @return ?array{mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}
     *         邮件记录；不存在 null The mail record; null when absent.
     */
    public function get(string $uid, string $mailId): ?array;

    /**
     * 读取收件箱全部邮件（按 sentAt 升序）。
     * Reads the whole mailbox (ascending by sentAt).
     *
     * @return list<array{mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}>
     */
    public function listByUid(string $uid): array;

    /**
     * 领取幂等闸门（Lua 原子 SISMEMBER+SADD）：true = 首次领取（闸门已抢到）；false = 已领取过。
     * The claim idempotency gate (atomic Lua SISMEMBER+SADD): true = first claim (gate acquired); false = already claimed.
     *
     * @return bool true = 首次领取 true when this is the first claim.
     */
    public function claimGate(string $uid, string $mailId): bool;

    /**
     * 释放领取闸门（补偿路径：抢到闸门后邮件被并发删除等失败回滚）。
     * Releases the claim gate (the compensation path: the gate was acquired but the mail vanished concurrently).
     */
    public function releaseClaimGate(string $uid, string $mailId): void;

    /**
     * 删除邮件（同时清理领取闸门残留）。
     * Deletes the mail (clearing any leftover claim-gate entry too).
     *
     * @return bool true = 已删除；false = 邮件不存在 true when deleted; false when the mail does not exist.
     */
    public function delete(string $uid, string $mailId): bool;
}
