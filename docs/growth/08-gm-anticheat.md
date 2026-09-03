# 08 · GM 与反作弊：运营的手和防挂的闸

> **这一阶打通**：白名单命令总线（status/广播/踢人/排空）与移动超速检测。
> **新用 API**（全公开）：`Nythros\Framework\Gm\{GmCommandBus, GmPermissionInterface, GmCommandInterface, GmResult, GmBroadcasterInterface, GmKickerInterface, GmStatusProviderInterface, GmDrainHandlerInterface}`、`Nythros\Framework\Server\MovementValidator`。
> **Redis**：不需要（GM 白名单是配置，反作弊是纯计算）。

## 1. GM 总线：命令注册，权限裁决，能力反查

`GmCommandBus` 是「注册命令 + 派发」的骨架，权限与执行能力都靠接口注入（02 章的依赖倒置再现）：

```php
use Nythros\Framework\Gm\{GmCommandBus, GmPermissionInterface};
use Nythros\Framework\Gm\Command\{StatusCommand, BroadcastCommand, KickCommand, DrainCommand};

// 白名单授权器：你自己的实现（demo 的 StaticGmAuthorizer 就是读一个 uid 列表）
final class UidListGmAuthorizer implements GmPermissionInterface
{
    public function __construct(private readonly array $uids) {}
    public function allows(string $uid, string $command): bool { return in_array($uid, $this->uids, true); }
}

$gmBus = new GmCommandBus(new UidListGmAuthorizer(['alice']));
// 命令的「执行能力」由宿主服务器实现对应接口（status 源、广播器、踢人器、排空钩子）——demo 里全是 MapServer 本身
$gmBus->register(new StatusCommand($game));
$gmBus->register(new BroadcastCommand($game));
$gmBus->register(new KickCommand($game));
$gmBus->register(new DrainCommand($game));
$game->attachGmBus($gmBus);
```

路由一行：

```php
case 'gm:exec': {
    // dispatch 返回 ?GmResult：null = 未注册命令；GmResult 是 {status, message, data} 只读值对象
    $result = $this->gmBus->dispatch($uid, (string) $message->payload['command'], (array) ($message->payload['args'] ?? []));
    $this->send($conn, Message::create('gm:result', [
        'status' => $result?->status ?? 'unknown_command',
        'message' => $result?->message ?? '',
        'data' => $result?->data ?? [],
    ], $message->requestId));

    return;
}
```

> `$game` 要 implements 那四个 `Gm*Interface`（status 数据源/广播/按 uid 踢/排空），每个都是几行——
> demo 的 `MapServer` 就是这么干的（类声明 implements 见其文件头）。别在命令里塞玩法，命令只是「读状态 + 调你已有的能力」。

## 2. 反作弊：给移动装一道超速闸

`MovementValidator` 不 import、不改你的结算——它是注入给 `RealtimeServer` 的一个校验器，超速的 move 帧
被拒（`error 403 overspeed`）但连接不断（可恢复，不误杀）：

```php
use Nythros\Framework\Server\MovementValidator;

// 阈值按你的世界尺度定：单步轴/单步距离/窗口内指令数/窗口秒/窗口累计距离
$game->setMovementValidator(new MovementValidator(
    maxStepAxis: 2, maxStepDistance: 2.5, maxCommandsPerWindow: 30, windowSeconds: 1.0, maxWindowDistance: 10.0,
));
```

`setMovementValidator` 是 `RealtimeServer` 现成的 final 方法（02 章你已经在用它父类的其它方法了）。
之后所有 `move` 帧自动过闸——你不用在路由里手动判速。

## 3. 验收

1. alice（白名单内）发 `gm:exec{command:"status"}` → 收 `gm:result{status:"ok", data:{...}}`；
   bob（不在名单）发同一条 → `gm:result{status:"permission_denied"}`；
2. `gm:exec{command:"broadcast", args:{message:"服务器 5 分钟后维护"}}` → 全频道收到广播帧；
3. 反作弊：脚本用 `client.php` 连发 `move{dx:100,dy:0}`（远超 maxStepDistance）→ 收 `error 403 overspeed`，
   但连接仍在（下一条合法 move 照常生效）。

## 4. demo 对照与常见坑

- demo 装配在 `MapChannelFactory`（GM L484-501 按 `NYTHROS_GM_UIDS` 门控；反作弊 L467-482 按 `NYTHROS_ANTICHEAT`）；
  Web 控制台 `packages/demo/bin/gm-console.php`（复用完整登录链发 gm:exec）。GM 权限模型详见[安全指南](../security.md)。
- **坑 1**：GM 白名单写死进命令实现——权限只在 `GmPermissionInterface` 一处裁决，命令本身不判断身份。
- **坑 2**：反作弊超速直接断连——阈值误伤正常玩家。demo 口径是「拒绝该帧 + 不断连」，别改。
- **坑 3**：把 `status` 命令写成读全局变量——它读的是 `GmStatusProviderInterface` 的宿主能力，保持可替换。
- 下一阶：[09 插件系统](09-plugin.md)。
