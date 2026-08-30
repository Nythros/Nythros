<?php

declare(strict_types=1);

namespace Nythros\Demo\Tests;

use Nythros\Aoi\GridAOI;
use Nythros\Aoi\UniversalAOI;
use Nythros\Contracts\WorldType;
use Nythros\Demo\MapServer;
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Event\SimpleEventBus;
use Nythros\Framework\Combat\CombatService;
use Nythros\Framework\Combat\DropTable;
use Nythros\Framework\Combat\EntityTypeIndex;
use Nythros\Framework\Combat\SystemRandomSource;
use Nythros\Framework\Plugin\Item\ItemDefinition;
use Nythros\Framework\Plugin\Item\ItemRepository;
use Nythros\Framework\Plugin\Skill\SkillRepository;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Scheduler\RegionScheduler;
use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;
use Nythros\World\SimpleEntityManager;
use Nythros\World\World;
use PHPUnit\Framework\TestCase;

/**
 * MapServerFullBroadcastTest - 覆盖全量广播型 World（副本）下的 MapServer 广播语义：
 * broadcastToVision 发给全部已认证连接（无 AOI 视野裁剪），且构造一个 AOI 型对照验证差异。
 * Tests the full-broadcast World (dungeon) MapServer semantics: broadcastToVision reaches every authenticated
 * connection (no AOI view pruning), contrasted with an AOI-type World.
 */
final class MapServerFullBroadcastTest extends TestCase
{
    private function makeConn(string $id, array &$batched): ConnectionInterface
    {
        $conn = $this->createStub(ConnectionInterface::class);
        $conn->method('getId')->willReturn($id);
        $conn->method('getSendBufferQueueSize')->willReturn(0);
        $conn->method('isAuthenticated')->willReturn(true);
        $conn->method('send')->willReturnCallback(static function (string $payload) use (&$batched): void {
            $batched[] = $payload;
        });
        $conn->method('sendBatch')->willReturnCallback(static function (array $payloads) use (&$batched): void {
            $batched = array_merge($batched, $payloads);
        });
        $conn->method('close')->willReturnCallback(static function (): void {
        });
        $conn->method('getRemoteAddress')->willReturn('127.0.0.1:1');
        $conn->method('getLastMessageTime')->willReturn(microtime(true));
        $conn->method('markAuthenticated')->willReturnCallback(static function (): void {
        });
        $conn->method('isClosed')->willReturn(false);
        $conn->method('markInternal')->willReturnCallback(static function (): void {
        });
        $conn->method('isInternal')->willReturn(false);
        $conn->method('onBufferFull')->willReturnCallback(static function (callable $h): void {
        });
        $conn->method('onBufferDrain')->willReturnCallback(static function (callable $h): void {
        });

        return $conn;
    }

    private function makeServer(array &$handlers): ServerInterface
    {
        $server = $this->createStub(ServerInterface::class);
        $server->method('onConnect')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onConnect'] = $h;
        });
        $server->method('onMessage')->willReturnCallback(static function (callable $h) use (&$handlers): void {
            $handlers['onMessage'] = $h;
        });

        return $server;
    }

    private function makeTokens(): TokenManagerInterface
    {
        return new class () implements TokenManagerInterface {
            public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string
            {
                return str_repeat('a', 64);
            }
            public function consume(string $token, string $scope): TokenStatus
            {
                return TokenStatus::Valid;
            }
            public function peek(string $token): ?TokenRecord
            {
                // auth 必须真实成功并挂载实体：本测试按「实体 → 连接」映射做广播（与 AOI 型一致），
                // 若 peek 返回 null 则 handleAuth 落到 auth_failed，无实体可映射，广播自然到不了任何连接。
                // (旧实现的全量广播直接遍历连接、以 isAuthenticated() 桩兜底，掩盖了 auth 失败——本修复让测试诚实。)
                // Auth must genuinely succeed and mount entities: broadcasts here map entity → connection (same as the
                // AOI type); a null peek would make handleAuth fall into auth_failed with no entity to map, so
                // broadcasts reach nobody. (The old full-mode path iterated connections directly and the
                // isAuthenticated() stub masked the auth failure — this fix makes the test truthful.)
                return new TokenRecord('1001', 'map-1', ['map'], 0.0, 9999999999.0);
            }
        };
    }

    /**
     * 构造 MapServer（返回 [MapServer, ServerInterface]）；$fullBroadcast 决定 World 类型（AOI 传 GridAOI，全量传 UniversalAOI）。
     * Builds a MapServer (returns [MapServer, ServerInterface]); $fullBroadcast picks the World type (AOI passes a GridAOI, full passes a UniversalAOI).
     *
     * @return array{0: MapServer, 1: ServerInterface, 2: array<string, callable>}
     */
    private function buildMap(bool $fullBroadcast, array &$batchedA, array &$batchedB, array &$handlers): array
    {
        // 世界类型（AOI / 全量广播）：AOI 注入 GridAOI；全量注入 UniversalAOI（全量 = 全世界即视野）——两种都有 AOI
        // World type (AOI / full broadcast): AOI injects a GridAOI; full injects a UniversalAOI (full = the whole
        // world is the view) — both World types always have an AOI
        $entityManager = new SimpleEntityManager();
        $aoi = $fullBroadcast ? new UniversalAOI($entityManager) : new GridAOI(10);
        $world = new World(
            $entityManager,
            new \Nythros\Actor\SimpleActorSystem(),
            $aoi,
            new SimpleEventBus(50000),
            new RegionScheduler(100.0),
            $fullBroadcast ? WorldType::FULL_BROADCAST : WorldType::AOI,
        );
        $handlers = [];
        $server = $this->makeServer($handlers);
        $skills = new SkillRepository();
        $items = new ItemRepository();
        $items->register(new ItemDefinition('gold', '金币', ItemDefinition::TYPE_CURRENCY));
        $map = new MapServer(
            $server,
            MapCodec::create(),
            $this->makeTokens(),
            $world,
            new ConnectionRegistry(),
            dropTable: new DropTable(['gold' => 1]),
            typeIndex: new EntityTypeIndex(),
            skills: $skills,
            random: new SystemRandomSource(),
        );
        // 依赖循环规避：CombatService 以 $map 本身构造后回填（spawnMonster 需要战斗依赖）
        // Circular-dependency avoidance: CombatService is built against $map itself and back-filled (spawnMonster needs it)
        $map->attachCombat(new CombatService($world, $map, $skills, $items, new SystemRandomSource()));
        $map->register();

        return [$map, $server, $handlers];
    }

    public function testFullBroadcastReachesAllAuthenticatedConnections(): void
    {
        $batchedA = [];
        $batchedB = [];
        $handlers = [];
        [$map, $server, $handlers] = $this->buildMap(true, $batchedA, $batchedB, $handlers);

        $connA = $this->makeConn('f-a', $batchedA);
        $connB = $this->makeConn('f-b', $batchedB);

        // 两个玩家 auth（全量型 World：join 快照 = 全世界 entity_enter，帧末 flush；auth_ok 直接发出）
        // Two players auth (full-broadcast World: the join snapshot = whole-world entity_enter, flushed at frame
        // end; auth_ok goes out directly)
        $authA = MapCodec::create()->encodeBatch([\Nythros\Protocol\Message::create('auth', ['token' => 't'], 'r1')]);
        $authB = MapCodec::create()->encodeBatch([\Nythros\Protocol\Message::create('auth', ['token' => 't'], 'r2')]);

        // 连接登记（onConnect 回调把连接写入 MapServer 连接表）Register the connections via the onConnect callback
        $handlers['onConnect']($connA);
        $handlers['onConnect']($connB);

        // 经 server 注册的 onMessage 派发 auth（真实 dispatch → handleAuth → auth_ok 入队）
        // Dispatch auth via the server-registered onMessage handler (real dispatch → handleAuth → auth_ok enqueued)
        $handlers['onMessage']($connA, $authA);
        $handlers['onMessage']($connB, $authB);

        // 触发 flushOutbox（私有）让 auth_ok 出站；然后 spawn 怪物 → monster:spawned 全量到两个连接
        // Trigger flushOutbox (private) so auth_ok goes out; then spawn a monster → monster:spawned reaches both
        $flush = new \ReflectionMethod(MapServer::class, 'flushOutbox');
        $flush->invoke($map);

        // 清理 auth_ok 已发出：两个连接都应有 auth_ok
        self::assertNotEmpty($batchedA, 'connA 应收到 auth_ok。');
        self::assertNotEmpty($batchedB, 'connB 应收到 auth_ok。');
        $batchedA = [];
        $batchedB = [];

        // spawn 怪物（全量可见）→ monster:spawned 应广播给 A 和 B 两个已认证连接
        // Spawn a monster (full visibility) → monster:spawned broadcasts to both authenticated connections
        $map->spawnMonster('monster-1', 100, ['x' => 5, 'y' => 5], 'slime');
        $flush->invoke($map);

        // 测试 MapServer 用 MapCodec（二进制）：出站 batch 是二进制包，用 MapCodec 解码
        // The test MapServer uses MapCodec (binary): outbound batches are binary packets, decoded via MapCodec
        $decoder = MapCodec::create();
        $framesA = [];
        foreach ($batchedA as $blob) {
            foreach ($decoder->decodeBatch($blob) as $m) {
                $framesA[] = $m;
            }
        }
        $framesB = [];
        foreach ($batchedB as $blob) {
            foreach ($decoder->decodeBatch($blob) as $m) {
                $framesB[] = $m;
            }
        }

        $spawnedA = array_values(array_filter($framesA, static fn ($f): bool => ($f->type ?? null) === 'monster:spawned'));
        $spawnedB = array_values(array_filter($framesB, static fn ($f): bool => ($f->type ?? null) === 'monster:spawned'));

        self::assertCount(1, $spawnedA, '全量广播型下 monster:spawned 应到达 connA。');
        self::assertCount(1, $spawnedB, '全量广播型下 monster:spawned 应到达 connB（无AOI裁剪）。');
        self::assertSame('monster-1', $spawnedB[0]->payload['id']);
    }
}
