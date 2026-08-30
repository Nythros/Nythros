<?php

declare(strict_types=1);

// 定位：packages/demo/run.php — 无网络最小运行示例：实体/Actor/AOI/事件在 5 帧内离线演示。
// Located at: packages/demo/run.php — minimal network-free run: entities/actors/AOI/events demonstrated offline across 5 frames.

require __DIR__ . '/../../vendor/autoload.php';

use Nythros\Actor\BaseActor;
use Nythros\Actor\SimpleActorSystem;
use Nythros\Aoi\GridAOI;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Event\SimpleEventBus;
use Nythros\Kernel\SystemClock;
use Nythros\Scheduler\SimpleScheduler;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;

// 组装世界各子系统：时钟/调度/实体/Actor/事件/AOI（网格 10 单位一格）
// Assemble world subsystems: clock/scheduler/entities/actors/events/AOI (grid cell of 10 units)
$clock = new SystemClock();
$scheduler = new SimpleScheduler();
$entityManager = new SimpleEntityManager();
$actorSystem = new SimpleActorSystem();
$eventBus = new SimpleEventBus();
$aoi = new GridAOI(cellSize: 10);

$world = new World($entityManager, $actorSystem, $aoi, $eventBus, $scheduler);

// 注册玩家实体：入实体管理器 + 入 AOI，两者都持有才可被查询/广播
// Register the player entity: add to both the entity manager and the AOI — it must be in both to be queryable/broadcastable
$player = new BaseEntity('player-1', new Position(0, 0));
$entityManager->add($player);
$aoi->updateEntity($player);

// 订阅移动事件：演示事件总线的发布/订阅
// Subscribe to the move event: demonstrates the event bus publish/subscribe
$eventBus->subscribe('player.moved', function (array $payload): void {
    printf(
        "event player.moved: %s -> (%d, %d)\n",
        $payload['id'],
        $payload['position']['x'],
        $payload['position']['y'],
    );
});

// 匿名 Actor 每帧把目标实体向右平移 1 格：演示 Actor 系统对实体的自主驱动
// Anonymous actor moves its target entity 1 unit right per frame: demonstrates actor-driven entity updates
$mover = new class ($player) extends BaseActor {
    public function __construct(private readonly BaseEntity $target)
    {
    }

    public function update(): void
    {
        $this->target->move(1, 0);

        ['x' => $x, 'y' => $y] = $this->target->getPosition();
        printf("actor update: %s at (%d, %d)\n", $this->target->getId(), $x, $y);
    }
};
$actorSystem->add($mover);

// 主循环跑 5 帧：tick -> world 更新（驱动 Actor）-> AOI 查询 -> 事件发布
// Main loop runs 5 frames: tick -> world update (drives actors) -> AOI query -> event publish
$frame = 0;
while ($frame < 5) {
    $frame++;

    $clock->tick();
    $world->update();

    $nearby = $aoi->query($player);
    printf("frame %d: nearby entities = %d\n", $frame, count($nearby));

    $eventBus->publish('player.moved', [
        'id' => $player->getId(),
        'position' => $player->getPosition(),
    ]);

    usleep(100000);
}
