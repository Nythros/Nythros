<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Mail;

use Nythros\Framework\Mail\MailService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeMailStore.php';
require_once __DIR__ . '/FakeNotifier.php';

/**
 * MailService 单元测试：FakeMailStore/FakeNotifier 承载，覆盖发送/领取幂等三态/删除/附件结构校验/在线通知。
 * MailService unit tests on FakeMailStore/FakeNotifier: send, the claim idempotency tri-state, deletion,
 * attachment-shape validation and online notification.
 */
final class MailServiceTest extends TestCase
{
    /**
     * @param list<array{mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}> $mails
     */
    private function service(array &$mails, array &$notified, ?callable $idFactory = null): MailService
    {
        $store = new FakeMailStore($mails);
        $notifier = new FakeNotifier($notified);

        return new MailService($store, $notifier, $idFactory ?? static fn (): string => 'mail-fixed');
    }

    public function testSendPersistsAndNotifies(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);

        $mailId = $service->send('1001', 'auction', '成交', '附件如下', [['itemId' => 'bone', 'count' => 2]]);

        self::assertSame('mail-fixed', $mailId);
        self::assertCount(1, $mails);
        self::assertSame('auction', $mails[0]['from']);
        self::assertSame([['itemId' => 'bone', 'count' => 2]], $mails[0]['attachments']);
        self::assertSame(['1001' => ['mail-fixed']], $notified);
    }

    public function testSendWithoutAttachmentsStillNotifies(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);

        $service->send('1001', 'system', '公告', '无附件');

        self::assertSame([], $mails[0]['attachments']);
        self::assertCount(1, $notified['1001']);
    }

    public function testSendContainsNotifierFailureAndKeepsTheMailPersisted(): void
    {
        // 通知故障注入：send 不得上抛（「通知失败不回滚」语义）——邮件已持久化，登录后可拉取；
        // 上抛会让调用方（如 AuctionService::buy）把已交付误判为未交付而触发补偿（双花缝隙）
        // Injected notification outage: send must not rethrow (the "a notification failure never rolls back"
        // semantics) — the mail stays persisted and pullable after login; rethrowing would make callers (e.g.
        // AuctionService::buy) misread delivered as undelivered and compensate (the double-spend seam)
        $mails = [];
        $notified = [];
        $store = new FakeMailStore($mails);
        $service = new MailService($store, new FakeNotifier($notified, throwing: true), static fn (): string => 'mail-fixed');

        $mailId = $service->send('1001', 'system', 't', 'b', [['itemId' => 'gold', 'count' => 5]]);

        self::assertSame('mail-fixed', $mailId);
        self::assertCount(1, $mails, '通知故障不得影响邮件持久化。A notification outage must not affect mail persistence.');
        self::assertSame([['itemId' => 'gold', 'count' => 5]], $mails[0]['attachments']);
        self::assertSame([], $notified, '故障通知不产生记录。A failed notification records nothing.');
    }

    public function testClaimFirstTimeReturnsAttachments(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $service->send('1001', 'system', 't', 'b', [['itemId' => 'gold', 'count' => 5]]);

        $result = $service->claimAttachments('1001', 'mail-fixed');

        self::assertSame('claimed', $result['status']);
        self::assertSame([['itemId' => 'gold', 'count' => 5]], $result['attachments']);
    }

    public function testClaimTwiceIsIdempotent(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $service->send('1001', 'system', 't', 'b', [['itemId' => 'gold', 'count' => 5]]);

        $first = $service->claimAttachments('1001', 'mail-fixed');
        $second = $service->claimAttachments('1001', 'mail-fixed');

        self::assertSame('claimed', $first['status']);
        // 幂等命中：不重复发放附件
        // An idempotent hit: nothing is re-granted
        self::assertSame('already_claimed', $second['status']);
        self::assertSame([], $second['attachments']);
    }

    public function testClaimMissingMailReturnsNotFoundAndReleasesGate(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);

        $result = $service->claimAttachments('1001', 'mail-none');

        self::assertSame('not_found', $result['status']);
        // 闸门已回滚：后续同 id 邮件到达后可正常领取
        // The gate was rolled back: a later mail with the same id is claimable again
        $service->send('1001', 'system', 't', 'b', []);
        $retry = $service->claimAttachments('1001', 'mail-none');
        self::assertSame('not_found', $retry['status'], '固定工厂下新邮件复用同 id——闸门未泄漏即可反复探测');
    }

    public function testDeleteRemovesMailFromList(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $service->send('1001', 'system', 't', 'b', []);

        self::assertTrue($service->delete('1001', 'mail-fixed'));
        self::assertFalse($service->delete('1001', 'mail-fixed'));
        self::assertSame([], $service->list('1001'));
    }

    public function testIllegalAttachmentShapeIsRejected(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);

        try {
            $service->send('1001', 'system', 't', 'b', [['itemId' => 'gold', 'count' => 0]]);
            self::fail('零数量附件必须被拒绝');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            /** @var list<mixed> $bad 非法形状（缺 count） Illegal shape (missing count). */
            $bad = [['itemId' => 'gold']];
            $service->send('1001', 'system', 't', 'b', $bad);
            self::fail('缺 count 字段的附件必须被拒绝');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
