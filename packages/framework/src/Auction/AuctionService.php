<?php

declare(strict_types=1);

namespace Nythros\Framework\Auction;

use Nythros\Framework\Inventory;
use Nythros\Framework\Mail\MailService;

/**
 * 交易行服务：挂单（扣货托管）/购买（Lua 原子结算+邮件交付）/撤单（邮件退回）。
 * Auction service: listing (escrow by debiting the bag) / purchase (atomic Lua settlement + mail delivery) /
 * cancellation (mail return).
 *
 * 资产流语义：
 * - 挂单：卖家背包扣货（托管进 AuctionStore），失败（货不足/参数非法/登记失败回滚）零残留；
 * - 购买：AuctionStore::purchase Lua 原子完成「校验+删单+买家扣款+卖家入账」→ 成功后发货邮件
 *   （附件=所购货物，买家领取幂等由 Mail 模块保障）。发货邮件失败的补偿：反向转账退款 + 恢复挂单
 *   （restore 复用原 auctionId）；补偿本身失败需人工对账——demo 规模接受该残余风险；
 * - 撤单：AuctionStore::cancel Lua 原子「归属校验+删单」→ 退回邮件（附件=原货物）。退回邮件失败的
 *   补偿：恢复挂单。
 * Asset-flow semantics:
 * - Listing: the seller's bag is debited into escrow (the AuctionStore); failures (insufficient goods / illegal
 *   arguments / a rolled-back registration) leave no residue;
 * - Purchase: AuctionStore::purchase's Lua atomically "validates + deletes the listing + debits the buyer + credits
 *   the seller" → on success a delivery mail is sent (attachments = the purchased goods; claim idempotency is the
 *   Mail module's guarantee). Compensation when the delivery mail fails: a reverse transfer refund + listing
 *   restoration (restore reusing the original auctionId); if the compensation itself fails, manual reconciliation
 *   is required — the demo scale accepts this residual risk;
 * - Cancellation: AuctionStore::cancel's Lua atomically "checks ownership + deletes the listing" → a return mail
 *   (attachments = the original goods). Compensation when the return mail fails: restore the listing.
 */
final class AuctionService
{
    /** 系统发件人标识（交易行邮件 from 字段） The system sender marker (the auction mail's from field). */
    public const SYSTEM_SENDER = 'auction';

    /** auctionId 前缀 auctionId prefix. */
    private const AUCTION_ID_PREFIX = 'auc-';

    /** @var \Closure(): string auctionId 工厂（缺省随机十六进制，可注入固定工厂供测试） The auctionId factory (random hex by default; inject a fixed factory for tests). */
    private readonly \Closure $idFactory;

    /**
     * 构造交易行服务。
     * Create the auction service.
     *
     * @param AuctionStore $store 挂单存储 The listing store.
     * @param CurrencyLedger $ledger 货币账本（补偿路径的反向转账走它；主结算在 store 的 Lua 内） The currency ledger (compensation reverse transfers ride it; the main settlement lives inside the store's Lua).
     * @param MailService $mail 邮件服务（交付/退回载体） The mail service (the delivery/return carrier).
     * @param null|\Closure(): string $idFactory auctionId 工厂；缺省 auc-{16 hex} The auctionId factory; defaults to auc-{16 hex}.
     */
    public function __construct(
        private readonly AuctionStore $store,
        private readonly CurrencyLedger $ledger,
        private readonly MailService $mail,
        ?\Closure $idFactory = null,
    ) {
        $this->idFactory = $idFactory ?? static fn (): string => self::AUCTION_ID_PREFIX . bin2hex(random_bytes(8));
    }

    /**
     * 挂单：从背包扣货托管 → 登记挂单。扣货成功但登记失败时回滚背包（托管未落库，货必须回包）。
     * Lists an offer: debit the bag into escrow → register the listing. A registration failure after the debit rolls
     * the bag back (escrow never persisted, the goods must return).
     *
     * @param string $sellerUid 卖家 uid The seller uid.
     * @param Inventory $inventory 卖家背包（调用方持有的进程内实例） The seller's bag (the caller-held in-process instance).
     * @param string $itemId 物品 id Item id.
     * @param int $count 数量（正整数） Count (positive).
     * @param int $price 总价（正整数） Total price (positive).
     * @return string 生成的挂单 id The generated listing id.
     * @throws \InvalidArgumentException 货物不足或参数非法 Insufficient goods or illegal arguments.
     * @throws \LogicException 挂单 id 冲突 An auctionId clash.
     */
    public function sell(string $sellerUid, Inventory $inventory, string $itemId, int $count, int $price): string
    {
        if ($count <= 0 || $price <= 0) {
            throw new \InvalidArgumentException(sprintf('AuctionService: 数量与价格必须为正整数: %d/%d', $count, $price));
        }
        if ($inventory->count($itemId) < $count) {
            throw new \InvalidArgumentException(sprintf('AuctionService: 货物不足: %s x%d', $itemId, $count));
        }

        $auctionId = ($this->idFactory)();
        $inventory->remove($itemId, $count);
        try {
            if (!$this->store->create($auctionId, $sellerUid, $itemId, $count, $price)) {
                throw new \LogicException(sprintf('AuctionService: 挂单 id 冲突: %s', $auctionId));
            }
        } catch (\Throwable $e) {
            $inventory->add($itemId, $count);

            throw $e;
        }

        return $auctionId;
    }

    /**
     * 购买：Lua 原子结算（校验+删单+买家扣款+卖家入账）→ 发货邮件；邮件失败走补偿
     * （反向转账退款 + 恢复挂单）后原样抛出。
     * Buys: atomic Lua settlement (validate + delete the listing + debit the buyer + credit the seller) → delivery
     * mail; a mail failure compensates (reverse-transfer refund + listing restore), then rethrows.
     *
     * @param string $buyerUid 买家 uid The buyer uid.
     * @param string $auctionId 挂单 id The listing id.
     * @param int $price 出价（须与挂单价一致，防篡改比对在 Lua 内） The offered price (must match the listing price; the tamper check runs inside the Lua).
     * @return array{ok: bool, code: string} 结算结果（ok=false 时 code 为失败原因） The settlement verdict (ok=false carries the failure code).
     * @throws \RuntimeException 发货邮件失败且补偿后仍失败（原异常上抛） The delivery mail failed and compensation did not contain it (original exception rethrown).
     */
    public function buy(string $buyerUid, string $auctionId, int $price): array
    {
        $settlement = $this->store->purchase($auctionId, $buyerUid, $price);
        if (!$settlement['ok']) {
            return ['ok' => false, 'code' => $settlement['code']];
        }

        $seller = (string) $settlement['seller'];
        $itemId = $settlement['itemId'];
        $count = $settlement['count'];

        try {
            $this->mail->send(
                $buyerUid,
                self::SYSTEM_SENDER,
                '拍卖行成交',
                sprintf('您购买的 %s x%d 已到账', $itemId, $count),
                [['itemId' => $itemId, 'count' => $count]],
            );
        } catch (\Throwable $e) {
            // 补偿：反向转账退款 + 恢复挂单（复用原 auctionId）；补偿自身异常不吞买家的原始故障归因
            // Compensation: reverse-transfer refund + listing restore (reusing the original auctionId); the
            // compensation's own exceptions never swallow the buyer-facing original failure attribution
            try {
                $this->ledger->withdraw($seller, $price);
                $this->ledger->deposit($buyerUid, $price);
                $this->store->create($auctionId, $seller, $itemId, $count, $price);
            } catch (\Throwable) {
                // 补偿失败：资金与挂单状态需人工对账（残余风险已在类注释声明）
                // Compensation failed: balances and the listing need manual reconciliation (residual risk declared in the class docblock)
            }

            throw $e;
        }

        return ['ok' => true, 'code' => 'ok'];
    }

    /**
     * 撤单：Lua 原子归属校验+删单 → 退回邮件（附件=原货物）；邮件失败恢复挂单后原样抛出。
     * Cancels: atomic Lua ownership check + listing deletion → return mail (attachments = the original goods);
     * a mail failure restores the listing, then rethrows.
     *
     * @param string $sellerUid 卖家 uid The seller uid.
     * @param string $auctionId 挂单 id The listing id.
     * @return bool true = 已撤并发出退回邮件；false = 挂单不存在或非卖家本人 true when cancelled with the return mail sent; false when absent or not the seller.
     * @throws \RuntimeException 退回邮件失败且恢复挂单后仍失败（原异常上抛） The return mail failed and the restore did not contain it (original exception rethrown).
     */
    public function cancel(string $sellerUid, string $auctionId): bool
    {
        // 删单前读快照：退回邮件需要货物信息（Lua 删单后无从补读）
        // Snapshot before deletion: the return mail needs the goods info (a post-deletion read is impossible)
        $listing = $this->store->get($auctionId);
        if ($listing === null || !$this->store->cancel($auctionId, $sellerUid)) {
            return false;
        }

        if ($listing['itemId'] === '' || $listing['count'] <= 0) {
            // 残缺挂单（create 两步间崩溃遗留，仅含 seller）：store 已直接删除并告警；退回邮件无从构造
            // （空 itemId/count=0 的附件会被 assertAttachments 拒绝），此处静默收尾转人工对账
            // An incomplete listing (a crash between create's two writes leaves seller only): the store deleted it
            // outright with an alert; no return mail can be built (an empty-itemId/zero-count attachment would trip
            // assertAttachments), so this winds down silently toward manual reconciliation
            return true;
        }

        try {
            $this->mail->send(
                $sellerUid,
                self::SYSTEM_SENDER,
                '拍卖行撤单退回',
                sprintf('您的挂单 %s（%s x%d）已撤回，货物随附件退回', $auctionId, $listing['itemId'], $listing['count']),
                [['itemId' => $listing['itemId'], 'count' => $listing['count']]],
            );
        } catch (\Throwable $e) {
            // 补偿：恢复挂单（复用原 auctionId）；恢复失败需人工对账（残余风险已在类注释声明）
            // Compensation: restore the listing (reusing the original auctionId); a restore failure needs manual reconciliation (residual risk declared in the class docblock)
            try {
                $this->store->create($auctionId, $sellerUid, $listing['itemId'], $listing['count'], $listing['price']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return true;
    }
}
