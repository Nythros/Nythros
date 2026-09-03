# 09 · 插件系统：把玩法做成可插拔的

> **这一阶打通**：插件生命周期（register → enable → disable → uninstall）、事件订阅/退订纪律、配置型插件形态。
> **新用 API**（全公开）：`Nythros\Framework\Plugin\{PluginInterface, PluginRegistry}`、`Nythros\Framework\Container\{Container, ContainerInterface}`、`Nythros\Framework\Event\{EventDispatcher, EventDispatcherInterface}`、`CombatService::EVENT_KILL/EVENT_PICKUP`。
> **Redis**：不需要。

## 1. 为什么是插件而不是往 GameServer 里继续加 if

你到本章应该已经往 `GameServer` 加了 6-7 种路由。demo 的答案是：**机制进 framework、参数进配置/插件、
装配进组装层**。`AnnouncerPlugin`（demo 的 131 行「教程玩具」，`packages/demo/src/Plugin/AnnouncerPlugin.php`）
就是官方示范：订阅击杀事件 → 全服公告。

## 2. 事件总线先接上

`EventDispatcher`（framework 实现）是现成的；`CombatService` 构造第 10 参可注入
`EventDispatcherInterface`（02 章装配处补上）：

```php
use Nythros\Framework\Event\EventDispatcher;

$events = new EventDispatcher();
$combat = new CombatService($world, $game, $skills, $items, $random, $game, $typeIndex, teams: null, dropLifetimeSeconds: 300, events: $events);
$game->attachEvents($events);
```

之后每次击杀内部 `dispatch('combat.kill', [...])`（CombatService L686）。

## 3. 写第一个插件（照抄 AnnouncerPlugin 的解剖）

```php
use Nythros\Framework\Container\ContainerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Nythros\Framework\Plugin\PluginInterface;

final class AnnouncerPlugin implements PluginInterface
{
    private ?\Closure $onKill = null;   // ★ 保存句柄：匿名类不可重复创建，退订必须是同一引用

    public function __construct(private readonly string $template = '{killer} 击杀了 {target}！') {}

    public function name(): string { return 'announcer'; }

    public function register(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        $container->set('announcer.service', $this);                 // ① 服务注册（供路由/其他插件取用）
        $this->onKill = function (array $payload): void {             // ② 订阅（句柄入属性）
            $center = (string) ($payload['targetId'] ?? '');
            // 通过容器里登记的广播能力发帧：$this->game->broadcastToVision($center, 'world:announce', [...])
        };
        $dispatcher->listen('combat.kill', $this->onKill);
    }

    public function enable(): void  { /* 门控：disable 期间的开关位 */ }
    public function disable(): void { /* 同上反操作 */ }

    public function uninstall(ContainerInterface $container, EventDispatcherInterface $dispatcher): void
    {
        if ($this->onKill !== null) {
            $dispatcher->removeListener('combat.kill', $this->onKill); // ③ 退订（同一引用！）
        }
        $container->remove('announcer.service');
    }
}
```

装配与生命周期：

```php
use Nythros\Framework\Container\Container;
use Nythros\Framework\Plugin\PluginRegistry;

$container = new Container();
$registry = new PluginRegistry();
$registry->load(new AnnouncerPlugin(), $container, $events); // 只做 register（重复 load 同名插件直接抛异常）
$registry->enable('announcer');                               // ★ 启用是显式第二步（register ≠ enable）
// 热关闭：$registry->disable('announcer')；卸载：$registry->uninstall('announcer', $container, $events)
```

## 4. 配置型插件（demo 的 MmorpgPlugin 形态）

插件本体只做一件事：`register` 时把配置以约定 id 塞进容器（`$container->set(self::CONFIG_ID, $config)`），
**解析 env/文件的工作留在组装层**，消费方从 `$container->get(...)` 取。这样换数值=换配置，不碰代码——
`make:skill` / 玩法三表热载（quick-start §3.3）都是这个思想的落地。完整讲解在[插件机制](../plugin-guide.md)。

## 5. 验收

1. 装配插件 → 打怪（02 章）→ 怪死瞬间收到 `world:announce` 帧；
2. `$registry->disable('announcer')` → 击杀不再公告；`enable()` → 恢复；
3. **泄漏测试**：`uninstall` 后再击杀 → 无公告（退订生效）。漏掉 `removeListener` 本章就白学了——
   用「注册→卸载→再注册」跑三轮不报错不重复收帧来验证。

## 6. demo 对照与常见坑

- 官方插件三个（Skill/Item/Buff）+ Mmorpg/Horde 配置型插件在 `packages/framework/src/Plugin/`、`Game/`；
  装配 `MapChannelFactory` L210-218。
- **坑 1**：`uninstall` 时 `removeListener('x', function(...) ...)` 现造闭包——不同引用，退不掉（事件双发）。句柄必须存属性。
- **坑 2**：插件里直接 new 服务互相持有——依赖都走 `Container`，否则热卸载会成环。
- **坑 3**：把玩法数值硬编码进插件类——那是配置型插件的反面教材（见[插件机制](../plugin-guide.md) §纪律）。
- 最后一阶，把单进程裂变成集群：[10 集群扩展](10-scale-out.md)。
