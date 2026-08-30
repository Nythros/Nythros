<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Auction;

use Nythros\Framework\Auction\AuctionService;
use Nythros\Framework\Auction\AuctionStore;
use Nythros\Framework\Auction\CurrencyLedger;
use Nythros\Framework\Inventory;
use Nythros\Framework\Mail\MailService;
use Nythros\Framework\Tests\Mail\FakeMailStore;
use Nythros\Framework\Tests\Mail\FakeNotifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Mail/FakeMailStore.php';
require_once __DIR__ . '/../Mail/FakeNotifier.php';

/**
 * AuctionService 集成测试（真 Redis + FakeMailStore）：挂单/购买/撤单/余额结算/并发互斥/撤单邮件补偿。
 * 依赖 127.0.0.1:6379 可用，不可用时整体跳过。
 * AuctionService integration tests (real Redis + FakeMailStore): listing / purchase / cancellation / balance
 * settlement / concurrent-purchase mutual exclusion / the cancel-return mail compensation.
 * Requires Redis on 127.0.0.1:6379, skips entirely when unavailable.
 *
 * 并发互斥口径：Redis Lua 单线程原子——「余额恰好只够一次购买」的两笔购买请求，无论真实到达顺序如何，
 * 恰有一笔成功、另一笔面对已删挂单得 no_listing；本测试以串行双购模拟该竞态的两种结局。
 * Mutual-exclusion contract: Redis Lua is single-threaded atomic — of two purchase requests whose balances cover
 * exactly one, exactly one succeeds and the other faces the deleted listing with no_listing regardless of arrival
 * order; this test simulates both outcomes of that race serially.
 */
final class AuctionServiceTest extends TestCase
{
    private ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }
        if ($connected !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 AuctionService 集成测试');
        }

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        if (@$this->redis->ping() !== true) {
            $this->markTestSkipped('Redis 127.0.0.1:6379 不可用，跳过 AuctionService 集成测试');
        }

        $this->prefix = 'nythros:test:' . bin2hex(random_bytes(8)) . ':ec:';
    }

    protected function tearDown(): void
    {
        if ($this->redis === null) {
            return;
        }

        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
        $this->redis = null;
    }

    /**
     * @param list<array{toUid: string, mailId: string, from: string, title: string, body: string, attachments: list<array{itemId: string, count: int}>, sentAt: float}> $mails 邮件表（引用） The mail table (by reference).
     * @param array<string, list<string>> $notified 通知记录（引用） The notification records (by reference).
     */
    private function service(array &$mails, array &$notified): AuctionService
    {
        $mailStore = new FakeMailStore($mails);
        $notifier = new FakeNotifier($notified);
        $mail = new MailService($mailStore, $notifier);

        return new AuctionService(
            new AuctionStore($this->redis, $this->prefix),
            new CurrencyLedger($this->redis, $this->prefix),
            $mail,
        );
    }

    private function ledger(): CurrencyLedger
    {
        return new CurrencyLedger($this->redis, $this->prefix);
    }

    private function store(): AuctionStore
    {
        return new AuctionStore($this->redis, $this->prefix);
    }

    public function testSellEscrowsGoodsFromInventory(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('bone', 3);

        $auctionId = $service->sell('1001', $bag, 'bone', 2, 100);

        // 托管：背包扣货 + 挂单落库
        // Escrow: the bag debited and the listing persisted
        self::assertSame(1, $bag->count('bone'));
        $listing = $this->store()->get($auctionId);
        self::assertNotNull($listing);
        self::assertSame('1001', $listing['seller']);
        self::assertSame('bone', $listing['itemId']);
        self::assertSame(2, $listing['count']);
        self::assertSame(100, $listing['price']);
    }

    public function testSellInsufficientGoodsThrowsWithoutMutation(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('bone', 1);

        try {
            $service->sell('1001', $bag, 'bone', 2, 100);
            self::fail('货不足必须被拒绝');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame(1, $bag->count('bone'), '失败路径背包零变更');
    }

    public function testBuySettlesBalancesAndDeliversByMail(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $sellerBag = new Inventory();
        $sellerBag->add('potion', 5);
        $auctionId = $service->sell('1001', $sellerBag, 'potion', 2, 300);
        $this->ledger()->deposit('1002', 500);

        $result = $service->buy('1002', $auctionId, 300);

        self::assertTrue($result['ok'], 'code=' . $result['code']);

        // 余额结算：买家扣款、卖家入账
        // Balance settlement: buyer debited, seller credited
        self::assertSame(200, $this->ledger()->balance('1002'));
        self::assertSame(300, $this->ledger()->balance('1001'));

        // 邮件交付：附件=所购货物
        // Mail delivery: attachments = the purchased goods
        self::assertCount(1, $mails);
        self::assertSame('1002', $mails[0]['toUid']);
        self::assertSame([['itemId' => 'potion', 'count' => 2]], $mails[0]['attachments']);

        // 挂单已删：重复购买得 no_listing
        // The listing is gone: a repeat purchase yields no_listing
        $repeat = $service->buy('1003', $auctionId, 300);
        self::assertFalse($repeat['ok']);
        self::assertSame('no_listing', $repeat['code']);
    }

    public function testBuySelfPurchasePriceMismatchAndShortBalanceAreRejected(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('bone', 1);
        $auctionId = $service->sell('1001', $bag, 'bone', 1, 100);

        // 自购拦截 Self-purchase guard.
        $selfBuy = $service->buy('1001', $auctionId, 100);
        self::assertFalse($selfBuy['ok']);
        self::assertSame('self_purchase', $selfBuy['code']);

        // 价格不符（防篡改比对） Price mismatch (the tamper check).
        $this->ledger()->deposit('1002', 1000);
        $mismatch = $service->buy('1002', $auctionId, 90);
        self::assertFalse($mismatch['ok']);
        self::assertSame('price_mismatch', $mismatch['code']);

        // 余额不足 Insufficient balance.
        $short = $service->buy('1003', $auctionId, 100);
        self::assertFalse($short['ok']);
        self::assertSame('insufficient_balance', $short['code']);

        // 全部拒绝路径后挂单与余额零变更 Every rejected path leaves the listing and balances untouched.
        self::assertNotNull($this->store()->get($auctionId));
        self::assertSame(1000, $this->ledger()->balance('1002'));
        self::assertSame(0, $this->ledger()->balance('1003'));
    }

    public function testConcurrentPurchaseMutualExclusion(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('bone', 1);
        $auctionId = $service->sell('1001', $bag, 'bone', 1, 100);

        // 两买家各存恰好一次购买的金额：竞态下恰有一人成功
        // Two buyers each holding exactly one purchase's worth: exactly one wins the race
        $this->ledger()->deposit('1002', 100);
        $this->ledger()->deposit('1003', 100);

        $first = $service->buy('1002', $auctionId, 100);
        $second = $service->buy('1003', $auctionId, 100);

        $winner = $first['ok'] ? '1002' : ($second['ok'] ? '1003' : null);
        self::assertNotNull($winner, '竞态必须恰有一名赢家');
        $loser = $winner === '1002' ? '1003' : '1002';
        $loserResult = $loser === '1002' ? $first : $second;

        self::assertFalse($loserResult['ok']);
        self::assertSame('no_listing', $loserResult['code'], '输家面对已删挂单');

        // 资金守恒：赢家扣 100、卖家入账 100、输家分文未动
        // Money conserved: the winner debited 100, the seller credited 100, the loser untouched
        self::assertSame(0, $this->ledger()->balance($winner));
        self::assertSame(100, $this->ledger()->balance($loser));
        self::assertSame(100, $this->ledger()->balance('1001'));

        // 恰一封交付邮件（发给赢家）
        // Exactly one delivery mail (to the winner)
        self::assertCount(1, $mails);
        self::assertSame($winner, $mails[0]['toUid']);
    }

    public function testBuyWithFailingNotifierDeliversWithoutCompensation(): void
    {
        // 通知器故障注入：发货邮件已持久化但在线通知抛异常——不得触发退款+恢复挂单补偿
        // （MailService 内部消化通知异常，buy 视为交付成功；否则买家同时拿到附件与退款构成双花）
        // Injected notifier outage: the delivery mail persists but the online notice throws — no refund+restore
        // compensation may fire (MailService contains the notification exception, so buy counts as delivered;
        // otherwise the buyer would hold both the attachment and a refund — a double spend)
        $mails = [];
        $notified = [];
        $mail = new MailService(new FakeMailStore($mails), new FakeNotifier($notified, throwing: true));
        $service = new AuctionService(
            new AuctionStore($this->redis, $this->prefix),
            new CurrencyLedger($this->redis, $this->prefix),
            $mail,
        );
        $sellerBag = new Inventory();
        $sellerBag->add('potion', 1);
        $auctionId = $service->sell('1001', $sellerBag, 'potion', 1, 100);
        $this->ledger()->deposit('1002', 200);

        $result = $service->buy('1002', $auctionId, 100);

        self::assertTrue($result['ok'], '通知故障不得让购买失败。A notification outage must not fail the purchase.');
        self::assertSame(100, $this->ledger()->balance('1002'), '买家只扣款一次（无退款补偿）。The buyer is debited exactly once (no refund compensation).');
        self::assertSame(100, $this->ledger()->balance('1001'), '卖家入账不被回滚。The seller credit is never rolled back.');
        self::assertNull($this->store()->get($auctionId), '挂单不得恢复（无恢复补偿）。The listing must stay deleted (no restore compensation).');
        self::assertCount(1, $mails, '交付邮件已持久化。The delivery mail stays persisted.');
    }

    public function testCancelReturnsGoodsByMail(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('sword', 1);
        $auctionId = $service->sell('1001', $bag, 'sword', 1, 500);

        self::assertTrue($service->cancel('1001', $auctionId));

        // 撤单邮件补偿：货物随附件退回卖家
        // The cancel-return mail compensation: goods returned to the seller as attachments
        self::assertCount(1, $mails);
        self::assertSame('1001', $mails[0]['toUid']);
        self::assertSame(AuctionService::SYSTEM_SENDER, $mails[0]['from']);
        self::assertSame([['itemId' => 'sword', 'count' => 1]], $mails[0]['attachments']);

        // 挂单已删 Non-listing after cancel.
        self::assertNull($this->store()->get($auctionId));
    }

    public function testCancelIncompleteListingDeletesItDirectlyWithoutThrowing(): void
    {
        // 残缺挂单（create 的 HSETNX+hMSet 两步间进程崩溃遗留，仅含 seller）：撤单必须直接删除不抛异常——
        // 旧路径会以空 itemId/count=0 构造退回邮件（assertAttachments 抛）→ restore 也抛被吞 → 静默对账
        // An incomplete listing (a crash between create's HSETNX and hMSet leaves seller only): cancelling must
        // delete it outright without throwing — the legacy path would build a return mail with an empty
        // itemId/zero count (assertAttachments throws), then restore throws too and gets swallowed
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $auctionId = 'auc-broken';
        $this->redis->hSet($this->prefix . 'auction:' . $auctionId, 'seller', '1001');

        self::assertTrue($service->cancel('1001', $auctionId), '残缺挂单撤单必须直接删除且返回 true。Cancelling an incomplete listing must delete it outright and return true.');
        self::assertNull($this->store()->get($auctionId), '残缺挂单已被删除。The incomplete listing is gone.');
        self::assertSame([], $mails, '残缺挂单不构造退回邮件。No return mail is built for an incomplete listing.');

        // 非卖家本人对残缺挂单照常拒绝（归属校验先于完整性闸门）
        // A non-seller is still rejected on an incomplete listing (ownership precedes the integrity gate)
        $this->redis->hSet($this->prefix . 'auction:auc-broken2', 'seller', '1001');
        self::assertFalse($service->cancel('1002', 'auc-broken2'), '非卖家本人不得删除残缺挂单。A non-seller must not delete an incomplete listing.');
    }

    public function testCancelOthersListingOrMissingListingIsRejected(): void
    {
        $mails = [];
        $notified = [];
        $service = $this->service($mails, $notified);
        $bag = new Inventory();
        $bag->add('bone', 1);
        $auctionId = $service->sell('1001', $bag, 'bone', 1, 100);

        // 非卖家本人拒绝 Ownership guard rejects non-sellers.
        self::assertFalse($service->cancel('1002', $auctionId));
        self::assertNotNull($this->store()->get($auctionId));

        // 不存在的挂单拒绝 Missing listings are rejected.
        self::assertFalse($service->cancel('1001', 'auc-none'));

        // 拒绝路径零邮件 Zero mails on rejected paths.
        self::assertSame([], $mails);
    }
}
