<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Mail;

use Nythros\Framework\Mail\MailNotifierInterface;

/**
 * MailService 单测用通知收集器：记录 (uid => [mailId...]) 供断言。
 * A notification collector for MailService unit tests: records uid => [mailId...] for assertions.
 */
final class FakeNotifier implements MailNotifierInterface
{
    /**
     * @param array<string, list<string>> $notified 引用承载的记录表（uid => 通知过的 mailId 列表） The by-reference record table (uid => notified mail ids).
     * @param bool $throwing true = notifyNewMail 一律抛异常（通知故障注入，验证调用方的容错语义） When true, notifyNewMail always throws (an injected notification outage to verify callers' containment).
     */
    public function __construct(public array &$notified, public bool $throwing = false)
    {
    }

    public function notifyNewMail(string $uid, string $mailId): void
    {
        if ($this->throwing) {
            throw new \RuntimeException('FakeNotifier: 注入的通知故障 / injected notification outage');
        }

        $this->notified[$uid][] = $mailId;
    }
}
