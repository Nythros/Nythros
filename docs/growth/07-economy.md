# 07 · 经济：邮件、交易行与货币账本

> **这一阶打通**：给玩家一个「离线也能收到东西」的邮箱、一个挂单买卖的交易行、一个不会透支的钱包。
> **新用 API**（全公开）：`Nythros\Framework\Mail\{MailService, MailStoreInterface, RedisMailStore, MailNotifierInterface}`、`Nythros\Framework\Auction\{AuctionService, AuctionStore, CurrencyLedger}`。
> **Redis**：本阶需要——这些是从设计上就要跨进程/持久化的（`RedisMailStore`、`AuctionStore`、`CurrencyLedger` 都以 Redis 为后端）。`docker compose up -d redis`。

## 1. 三者的装配顺序（有依赖，别颠倒）

```
CurrencyLedger（钱包）  ─┐
MailService（邮箱）      ├─► AuctionService（交易行：卖出先冻结金币、成交经邮箱交货）
                         ┘
```

装配（`bin/map-worker.php`）：

```php
use Nythros\Framework\Auction\{AuctionService, AuctionStore, CurrencyLedger};
use Nythros\Framework\Mail\{MailService, RedisMailStore};

$redis = static function (): \Redis { $r = new \Redis(); $r->connect('127.0.0.1', 6379); return $r; };

$ledger = new CurrencyLedger($redis);                       // \Redis|\Closure 皆可，传工厂便于连接复用/测试
$mail   = new MailService(new RedisMailStore($redis));      // 第二参 ?MailNotifierInterface = 在线即时提醒钩子
$auction = new AuctionService(new AuctionStore($redis), $ledger, $mail);
$game->attachEconomy($ledger, $mail, $auction);
```

`AuctionService` 把「挂单→扣款→发货→到账」编成一条链，中途失败自动回滚（金币走 `CurrencyLedger`，
货走 `MailService`）——这正是为什么交易行**依赖**钱包和邮箱：装配时先 new 好后两个。

## 2. 路由：帧语义对齐 demo（MapServer 的 economy/auction/mail 分支）

```php
case 'economy:deposit': { // 充值/初始金币入账（生产应在可信服务端，不应由客户端直接 deposit）
    $delta = (int) ($message->payload['amount'] ?? 0);
    if ($delta > 0) {
        $this->ledger->deposit($uid, $delta);
    }
    $this->send($conn, Message::create('economy:balance', ['uid' => $uid, 'balance' => $this->ledger->balance($uid)], $message->requestId));

    return;
}
case 'auction:sell': {   // 挂单：{itemId, count, unitPrice} —— 物品从卖家背包冻结（所以要把 Inventory 传进去）
    $inv = $this->inventories[$entityId] ?? new Inventory();
    $auctionId = $this->auction->sell($uid, $inv, (string) $message->payload['itemId'], (int) $message->payload['count'], (int) $message->payload['unitPrice']);
    $this->send($conn, Message::create('auction:listed', ['auctionId' => $auctionId], $message->requestId));

    return;
}
// auction:buy → $this->auction->buy($uid, $auctionId, $price): array（出价与挂单价原子校验，钱货两讫走内部）
// auction:cancel → $this->auction->cancel($uid, $auctionId): bool
// mail:list → Message 'mail:inbox' + $this->mail->list($uid)
// mail:claim → $this->mail->claimAttachments($uid, $mailId): array → 逐件 $inv->add() + markDirty（§2 注）
```

> `MailService` 只负责信与附件的存储/领取，**领出来的物品要落进背包**得你在 `mail:claim` 里
> 桥接 `Inventory` + `markDirty`（三章的循环在这里闭合）。`MailNotifierInterface` 是给「收件即推
> `mail:new` 帧」用的在线提醒钩子——MapServer 实现了它（依赖倒置同 02 章广播器）。

## 3. 验收

1. `economy:deposit{amount:1000}` → 收 `economy:balance{balance:1000}`；
2. 卖家先经 03 章背包有 5 个 potion，`auction:sell{itemId:potion,count:5,unitPrice:10}` → 收 `auction:listed`；另一账号 `auction:buy` 成交；
3. **透支防护**：买家余额不足时 `buy` 拒绝且金币不变——`CurrencyLedger::withdraw` 返回 false 即余额不足，账本不会扣成负数；
4. 重启 Redis 数据不丢（对照 03 章的 `InMemoryStorage` 会丢，体会持久后端的选择）。

## 4. demo 对照与常见坑

- demo 路由 `MapServer` 的 `auction:*`/`mail:*`/`economy:*` 分支；交易行完整语义见[社交与经济](../social-guide.md)。
- **坑 1**：`deposit` 直接信任客户端金额——这是「给玩家印钱」的洞。真实项目里入账只能来自服务端可信源
  （支付回调/GM/邮件附件），教程这里只是为了演示 `CurrencyLedger` 接口。
- **坑 2**：`mail:claim` 领了但没进背包/没 `markDirty`——物品凭空蒸发，重连又「未领取」。领=存储改态 + 背包加物 + 标脏，三件事一起。
- 下一阶：[08 GM 与反作弊](08-gm-anticheat.md)。
