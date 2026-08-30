<?php

declare(strict_types=1);

namespace Nythros\Framework\Mail;

/**
 * 邮件服务：发送/列表/附件领取/删除（纯业务，可单测）。
 * Mail service: send / list / attachment claiming / deletion (pure business logic, unit-testable).
 *
 * 附件领取幂等语义：领取走存储层 Lua 原子闸门（claimGate）——并发双领只有一个抢到闸门，
 * 另一个得到 already_claimed；抢到闸门后邮件被并发删除的竞态回滚闸门并返回 not_found。
 * Attachment-claim idempotency: claiming runs through the store's atomic Lua gate (claimGate) — of two concurrent
 * claims exactly one acquires the gate while the other gets already_claimed; if the mail vanishes concurrently
 * after the gate was acquired, the gate is rolled back and not_found returned.
 */
final class MailService
{
    /** 邮件 id 前缀 Mail id prefix. */
    private const MAIL_ID_PREFIX = 'mail-';

    /** @var \Closure(): string 邮件 id 工厂（缺省随机十六进制，可注入固定工厂供测试） Mail-id factory (random hex by default; inject a fixed factory for tests). */
    private readonly \Closure $idFactory;

    /**
     * 构造邮件服务。
     * Create the mail service.
     *
     * @param MailStoreInterface $store 邮件存储 The mail store.
     * @param ?MailNotifierInterface $notifier 在线通知端口；缺省 null = 不通知（离线拉取语义） The online-notification port; default null = no notification (pull-after-login semantics).
     * @param ?\Closure(): string $idFactory 邮件 id 工厂；缺省 mail-{16 hex} The mail-id factory; defaults to mail-{16 hex}.
     */
    public function __construct(
        private readonly MailStoreInterface $store,
        private readonly ?MailNotifierInterface $notifier = null,
        ?\Closure $idFactory = null,
    ) {
        $this->idFactory = $idFactory ?? static fn (): string => self::MAIL_ID_PREFIX . bin2hex(random_bytes(8));
    }

    /**
     * 发送邮件：生成 mailId → 存储 → 在线通知（通知失败不回滚——邮件已持久化，登录后可拉取）。
     * Sends a mail: generate the mailId → persist → notify online (a notification failure never rolls back —
     * the mail is already persisted and pullable after login).
     *
     * @param string $toUid 收件人 uid The recipient uid.
     * @param string $fromUid 发件人 uid（系统邮件用固定标识，如 'system'/'auction'） The sender uid (a fixed marker for system mail, e.g. 'system'/'auction').
     * @param string $title 邮件标题 The mail title.
     * @param string $body 邮件正文 The mail body.
     * @param list<array{itemId: string, count: int}> $attachments 附件列表 Attachment list.
     * @return string 生成的邮件 id The generated mail id.
     * @throws \InvalidArgumentException 附件结构非法 Illegal attachment shape.
     */
    public function send(string $toUid, string $fromUid, string $title, string $body, array $attachments = []): string
    {
        $this->assertAttachments($attachments);

        $mailId = ($this->idFactory)();
        $this->store->insert($toUid, $mailId, $fromUid, $title, $body, $attachments);

        // 通知尽力而为：异常内部消化（对齐「通知失败不回滚」语义）——上抛会让调用方把「已交付」误判为
        // 「未交付」（如 AuctionService::buy 触发退款+恢复挂单补偿，买家同时拿到附件与退款构成双花）；
        // 邮件已持久化，收件人登录后照常拉取
        // Notification is best-effort: exceptions are contained here (aligned with "a notification failure never
        // rolls back") — rethrowing would make callers misread "delivered" as "undelivered" (e.g. AuctionService::buy
        // would refund + restore the listing, handing the buyer both the attachment and a refund — a double spend);
        // the mail is already persisted and pullable after login
        try {
            $this->notifier?->notifyNewMail($toUid, $mailId);
        } catch (\Throwable) {
        }

        return $mailId;
    }

    /**
     * 读取收件箱全部邮件。
     * Reads the whole mailbox.
     *
     * @return list<array{mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}>
     */
    public function list(string $uid): array
    {
        return $this->store->listByUid($uid);
    }

    /**
     * 领取邮件附件（幂等）：not_found = 邮件不存在；already_claimed = 已领取过（幂等命中，
     * 不重复发放）；claimed = 本次领取成功，attachments 为应入包的附件列表。
     * Claims a mail's attachments (idempotent): not_found = the mail does not exist; already_claimed = claimed
     * before (an idempotent hit, nothing re-granted); claimed = granted this time, attachments being the list to
     * put into the bag.
     *
     * @param string $uid 收件人 uid The recipient uid.
     * @param string $mailId 邮件 id The mail id.
     * @return array{status: string, attachments: list<array{itemId: string, count: int}>} 领取结果 The claim verdict.
     */
    public function claimAttachments(string $uid, string $mailId): array
    {
        // 先抢幂等闸门再读记录：闸门是唯一并发正确性来源；读不到记录时回滚闸门防泄漏
        // Acquire the idempotency gate before reading the record: the gate is the sole source of concurrency
        // correctness; a missing record rolls the gate back so it never leaks
        if (!$this->store->claimGate($uid, $mailId)) {
            return ['status' => 'already_claimed', 'attachments' => []];
        }

        $mail = $this->store->get($uid, $mailId);
        if ($mail === null) {
            $this->store->releaseClaimGate($uid, $mailId);

            return ['status' => 'not_found', 'attachments' => []];
        }

        return ['status' => 'claimed', 'attachments' => $mail['attachments']];
    }

    /**
     * 删除邮件。
     * Deletes a mail.
     *
     * @return bool true = 已删除；false = 邮件不存在 true when deleted; false when absent.
     */
    public function delete(string $uid, string $mailId): bool
    {
        return $this->store->delete($uid, $mailId);
    }

    /**
     * 附件结构校验：itemId 非空字符串、count 正整数（服务端内部发送面，严格快速失败）。
     * Validates attachment shapes: non-empty itemId strings and positive integer counts (a server-internal sending
     * surface — fail fast strictly).
     *
     * @param list<mixed> $attachments 待校验附件列表 The attachment list to validate.
     * @throws \InvalidArgumentException 结构非法 Illegal shape.
     */
    private function assertAttachments(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)
                || !is_string($attachment['itemId'] ?? null)
                || $attachment['itemId'] === ''
                || !is_int($attachment['count'] ?? null)
                || $attachment['count'] < 1
            ) {
                throw new \InvalidArgumentException('MailService: 附件结构非法（期望 {itemId: string, count: int>0}）');
            }
        }
    }
}
