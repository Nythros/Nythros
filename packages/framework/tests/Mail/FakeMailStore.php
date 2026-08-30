<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Mail;

use Nythros\Framework\Mail\MailStoreInterface;

/**
 * MailService 单测用内存邮件存储（数组模拟，语义对齐 RedisMailStore）。
 * An in-memory mail store for MailService unit tests (array-backed, semantics aligned with RedisMailStore).
 */
final class FakeMailStore implements MailStoreInterface
{
    /** @var array<string, true> uid:mailId => 已领取标记 The claimed markers keyed by "uid:mailId". */
    public array $claimed = [];

    /**
     * @param list<array{toUid: string, mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}> $mails 共享邮件表（引用语义供断言） The shared mail table (by reference for assertions).
     */
    public function __construct(public array &$mails)
    {
    }

    public function insert(string $toUid, string $mailId, string $fromUid, string $title, string $body, array $attachments): void
    {
        $this->mails[] = [
            'toUid' => $toUid,
            'mailId' => $mailId,
            'from' => $fromUid,
            'title' => $title,
            'body' => $body,
            'attachments' => $attachments,
            'sentAt' => microtime(true),
        ];
    }

    public function get(string $uid, string $mailId): ?array
    {
        foreach ($this->mails as $mail) {
            if ($mail['toUid'] === $uid && $mail['mailId'] === $mailId) {
                unset($mail['toUid']);

                return $mail;
            }
        }

        return null;
    }

    public function listByUid(string $uid): array
    {
        $out = [];
        foreach ($this->mails as $mail) {
            if ($mail['toUid'] !== $uid) {
                continue;
            }
            $entry = $mail;
            unset($entry['toUid']);
            $out[] = $entry;
        }
        usort($out, static fn (array $a, array $b): int => $a['sentAt'] <=> $b['sentAt']);

        return $out;
    }

    public function claimGate(string $uid, string $mailId): bool
    {
        $key = $uid . ':' . $mailId;
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed[$key] = true;

        return true;
    }

    public function releaseClaimGate(string $uid, string $mailId): void
    {
        unset($this->claimed[$uid . ':' . $mailId]);
    }

    public function delete(string $uid, string $mailId): bool
    {
        $found = false;
        foreach ($this->mails as $index => $mail) {
            if ($mail['toUid'] === $uid && $mail['mailId'] === $mailId) {
                unset($this->mails[$index]);
                $found = true;
            }
        }
        if (!$found) {
            return false;
        }
        $this->releaseClaimGate($uid, $mailId);

        return true;
    }
}
