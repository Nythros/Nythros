# 01 · 把 uid 直通换成真认证

> **这一阶打通**：token 的签发与消费（多 scope、一次性、防重放）。
> **新用 API**：`Nythros\Security\{TokenManagerInterface, TokenManager, TokenStoreInterface, InMemoryTokenStore, TokenStatus}`（全部公开）。
> **Redis**：不需要——`InMemoryTokenStore` 单进程足够；换 `RedisTokenStore` 是[第 10 章](10-scale-out.md)的事（跨进程消费才需要共享存储）。

## 1. 现状与目标

skeleton 的 `handleAuthMessage` 是 **uid 直通**：客户端说 `auth{uid:"alice"}` 就进。这在单进程 demo 里无妨，
但真实世界需要「登录一次、凭票通行多个服务」：Social 登录**签发** token（map/chat/team 三个 scope），
Map 频道**消费** map scope——一次性，重放即拒。

本阶在骨架进程内把这条语义做出来（签发+消费同一进程），第 10 章拆到多进程时只是把存储换成 Redis。

## 2. 装配：注入 TokenManager

`bin/map-worker.php` 现有装配（`new GameServer(...)`）之后加一行注入：

```php
use Nythros\Security\InMemoryTokenStore;
use Nythros\Security\TokenManager;

$tokens = new TokenManager(new InMemoryTokenStore()); // 生产替换：new RedisTokenStore($redisFactory)
$game = new GameServer($server, new JsonBatchSerializer(), $world, $npcSeeds, $clock, $timer);
$game->attachTokenManager($tokens);   // 你自己的 setter（下一步定义）
```

## 3. 接线：签发与消费

在你的 `GameServer` 里加一个属性与 setter，把 `handleAuthMessage` 从「读 uid」升级为「账密→签发」，
并新增「票据入场」路径（对照 demo 的 `MapServer::handleAuthMessage` L1378）：

```php
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenStatus;

/** 开发用账号表；生产请接你的账号系统（demo 用 StaticAuthenticator 占位，见 security.md §5） */
private const ACCOUNTS = ['alice' => 'pw-alice', 'bob' => 'pw-bob'];

private ?TokenManagerInterface $tokens = null;

public function attachTokenManager(TokenManagerInterface $tokens): void
{
    $this->tokens = $tokens;
}

protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void
{
    // 路径一：账密登录 → 签发多 scope token（本进程自用的 map + 为未来 chat/team 预留）
    if (isset($message->payload['password'])) {
        $uid = (string) ($message->payload['uid'] ?? '');
        $password = (string) $message->payload['password'];
        if ((self::ACCOUNTS[$uid] ?? null) !== $password) {
            $this->send($conn, Message::create('auth_failed', ['code' => 401], $message->requestId));
            $conn->close();

            return;
        }
        $token = $this->tokens->issue($uid, $this->mapId, ['map', 'chat', 'team'], 30);
        // 本进程继续入场（同进程签发即消费）；多进程场景客户端会拿 token 去连别的进程——第 10 章
        $message = Message::create('auth', ['token' => $token], $message->requestId);
    }

    // 路径二：持票入场 —— peek 校验目标地图（不匹配**不消费**，票留着去别的频道），再消费 map scope
    $token = (string) ($message->payload['token'] ?? '');
    $record = $this->tokens->peek($token);
    if ($record === null || $record->mapId !== $this->mapId) {
        $this->send($conn, Message::create('auth_failed', ['code' => 403, 'reason' => 'map_mismatch'], $message->requestId));
        $conn->close();

        return;
    }
    $status = $this->tokens->consume($token, 'map');
    if ($status !== TokenStatus::Valid) {
        $this->send($conn, Message::create('auth_failed', [
            'code' => 403,
            'reason' => match ($status) {
                TokenStatus::Expired => 'expired',
                TokenStatus::Replayed => 'replayed',   // 一次性：拿同一张票进第二次 → 拒
                default => 'unauthorized',
            },
        ], $message->requestId));
        $conn->close();

        return;
    }

    // 以下与骨架原逻辑相同：$uid = $record->uid 走实体/PlayerActor 挂载 + auth_ok + NPC 快照
}
```

要点：`issue` 的 scopes 白名单只认 `map/chat/team`，空数组 = 缺省全量；TTL 30 秒起步（demo 同值），
票据语义 = 进场凭证而非会话凭据，掉线重连要回登录服务换新票。

## 4. 验收

```bash
php bin/launch.php            # 照常起主城 + 副本
php client.php alice 18081    # 旧客户端发 auth{uid} 无 password → 走不了票，应收到 auth_failed
```

改造你的 `client.php` 首发帧（一步到位的最小改法）：

```php
// 原来：Message::create('auth', ['uid' => $uid])
// 改为：Message::create('auth', ['uid' => $uid, 'password' => 'pw-alice'])
```

预期：`auth_ok{id}` 正常回来，后续 move/视野广播与之前完全一致。再手动重放验证一次性——
用同一个 token 起第二个连接：应收到 `auth_failed{reason:"replayed"}`（这是本阶最有教育意义的一步）。

## 5. demo 对照与下一步

demo 的同款逻辑在 `packages/demo/src/MapServer.php` `handleAuthMessage`（L1378 起，多了准入守卫与
版本守卫）；签发侧在 `packages/framework/src/Social/SocialService.php`（`handleAuth` 完整握手）。
下一阶：[02 战斗](02-combat.md)。
