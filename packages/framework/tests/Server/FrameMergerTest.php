<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Server;

use Nythros\Framework\Server\FrameMerger;
use Nythros\Network\ConnectionInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;
use PHPUnit\Framework\TestCase;

/**
 * FrameMergerTest - 覆盖出站帧合并器的状态帧去重、事件帧追加、低优先级过滤与单帧字节配额。
 * 合并器 drain 后每连接产出「一包多帧」的单个批量包，测试经 JsonBatchSerializer 解码后断言。
 * Tests covering the outbound frame merger: STATE-frame dedup, EVENT-frame append, low-priority filtering and
 * the per-frame byte quota. After drain each connection yields a single multi-frame batch packet; assertions
 * decode it through the JsonBatchSerializer.
 */
final class FrameMergerTest extends TestCase
{
    private FrameMerger $merger;
    private JsonBatchSerializer $serializer;
    private ConnectionInterface $connA;
    private ConnectionInterface $connB;

    protected function setUp(): void
    {
        $this->serializer = new JsonBatchSerializer();
        $this->merger = new FrameMerger($this->serializer);
        $this->connA = $this->makeConn('conn-a');
        $this->connB = $this->makeConn('conn-b');
    }

    public function testStateFramesOfSameEntityAreReplacedWithLatestPayload(): void
    {
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 1, 'y' => 1]]);
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 2, 'y' => 2]]);
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 3, 'y' => 3]]);

        $frames = $this->merger->drain(1024 * 1024);
        $messages = $this->decodeAll($frames['conn-a']);
        self::assertCount(1, $messages, '同帧同实体状态帧必须合并为一条。Same-frame same-entity STATE frames must merge into one.');

        self::assertSame('entity_moved', $messages[0]->type);
        self::assertSame(['x' => 3, 'y' => 3], $messages[0]->payload['position'], '只保留本帧最新位置。Only the latest position survives.');
    }

    public function testStateFrameReplacementKeepsFirstEnqueuePosition(): void
    {
        $this->merger->enqueue($this->connA, 'entity_enter', ['id' => 'monster-1', 'position' => ['x' => 0, 'y' => 0]]);
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 1, 'y' => 1]]);
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 9, 'y' => 9]]);
        $this->merger->enqueue($this->connA, 'entity_leave', ['id' => 'monster-2', 'position' => ['x' => 5, 'y' => 5]]);

        $frames = $this->merger->drain(1024 * 1024);
        $types = array_map(static fn (Message $m): string => $m->type, $this->decodeAll($frames['conn-a']));

        self::assertSame(['entity_enter', 'entity_moved', 'entity_leave'], $types, '状态帧替换保留首次入队位置，帧序稳定。State replacement keeps the first-enqueue position; order stays stable.');
    }

    public function testEventFramesAreNeverMerged(): void
    {
        $this->merger->enqueue($this->connA, 'combat:hit', ['target' => 'monster-1', 'dmg' => 10]);
        $this->merger->enqueue($this->connA, 'combat:hit', ['target' => 'monster-1', 'dmg' => 20]);

        $frames = $this->merger->drain(1024 * 1024);
        $messages = $this->decodeAll($frames['conn-a']);
        self::assertCount(2, $messages, '事件帧必须逐条保留（两次攻击 = 两条伤害）。EVENT frames must be kept one by one (two hits = two damage frames).');
    }

    public function testUnknownFrameTypesDefaultToEventHighPriority(): void
    {
        $this->merger->enqueue($this->connA, 'custom:event', ['id' => 'x']);

        $frames = $this->merger->drain(1024 * 1024);
        self::assertCount(1, $this->decodeAll($frames['conn-a']));
    }

    public function testSoftFilterShedsLowPriorityFramesOnly(): void
    {
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 1, 'y' => 1]]);
        $this->merger->enqueue($this->connA, 'entity_enter', ['id' => 'monster-1', 'position' => ['x' => 0, 'y' => 0]]);
        // connB 不受过滤影响：低优先级帧正常保留 Same-frame connB unaffected: low-priority frames survive
        $this->merger->enqueue($this->connB, 'entity_moved', ['id' => 'player-2', 'position' => ['x' => 2, 'y' => 2]]);

        $frames = $this->merger->drain(1024 * 1024, ['conn-a' => true]);

        $a = $this->decodeAll($frames['conn-a']);
        $b = $this->decodeAll($frames['conn-b']);
        self::assertCount(1, $a, '软过滤只丢弃低优先级帧。The soft filter sheds only low-priority frames.');
        self::assertSame('entity_enter', $a[0]->type);
        self::assertCount(1, $b, '未标记的连接不触发过滤。Unflagged connections are not filtered.');
        self::assertSame('entity_moved', $b[0]->type);
    }

    public function testByteQuotaShedsLowPriorityFramesBeyondBudget(): void
    {
        // 极小配额：批量包含 entity_enter + 低优先级 entity_moved 时超限，重编码后仅保留高优先级事件帧
        // A tiny quota: a batch with entity_enter + the low-priority entity_moved exceeds it, so re-encoding keeps only the high-priority event frame
        $enter = $this->serializer->encodeBatch([Message::create('entity_enter', ['id' => 'monster-1', 'position' => ['x' => 0, 'y' => 0]])]);

        $this->merger->enqueue($this->connA, 'entity_enter', ['id' => 'monster-1', 'position' => ['x' => 0, 'y' => 0]]);
        $this->merger->enqueue($this->connA, 'entity_moved', ['id' => 'player-1', 'position' => ['x' => 1, 'y' => 1]]);

        $frames = $this->merger->drain(strlen($enter));

        $messages = $this->decodeAll($frames['conn-a']);
        self::assertCount(1, $messages, '超配额时低优先级帧被丢弃。Over quota, low-priority frames are shed.');
        self::assertSame('entity_enter', $messages[0]->type);
    }

    public function testDrainClearsBuffer(): void
    {
        $this->merger->enqueue($this->connA, 'entity_enter', ['id' => 'monster-1', 'position' => ['x' => 0, 'y' => 0]]);
        $this->merger->drain(1024 * 1024);

        self::assertSame([], $this->merger->drain(1024 * 1024), 'drain 后缓冲必须清空。The buffer must be empty after drain.');
    }

    private function makeConn(string $id): ConnectionInterface
    {
        $conn = $this->createStub(ConnectionInterface::class);
        $conn->method('getId')->willReturn($id);

        return $conn;
    }

    /** 解码一个连接的全部批量包为消息列表（合并器每次 drain 每连接产出一个批量包）。 Decodes a connection's batch packets into messages (drain yields one batch per connection). */
    private function decodeAll(array $blobs): array
    {
        $out = [];
        foreach ($blobs as $blob) {
            foreach ($this->serializer->decodeBatch($blob) as $message) {
                $out[] = $message;
            }
        }

        return $out;
    }
}
