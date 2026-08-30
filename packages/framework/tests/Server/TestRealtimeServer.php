<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Server;

use Nythros\Contracts\WorldInterface;
use Nythros\Entity\BaseEntity;
use Nythros\Entity\Position;
use Nythros\Framework\BasePlayer;
use Nythros\Framework\Server\ConnectionRegistry;
use Nythros\Framework\Server\RealtimeServer;
use Nythros\Network\ConnectionInterface;
use Nythros\Network\ServerInterface;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\Message;

/** 测试用 RealtimeServer 子类：暴露钩子调用记录与私有能力。 Test RealtimeServer subclass: exposes hook-call records and private capabilities. */
final class TestRealtimeServer extends RealtimeServer
{
    public int $authCalls = 0;

    /** @var list<array{0: string}> onEntityCleanedUp 收到的 entityId entityIds seen by onEntityCleanedUp. */
    public array $cleanedUp = [];

    public function __construct(ServerInterface $server, WorldInterface $world)
    {
        parent::__construct($server, new JsonBatchSerializer(), $world, new ConnectionRegistry());
    }

    public function getWorld(): WorldInterface
    {
        return $this->world;
    }

    public function exposeFlush(): void
    {
        $this->flushOutbox();
    }

    public function replaceConnection(ConnectionInterface $conn): void
    {
        $this->connections[$conn->getId()] = $conn;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * 模拟实体经 transfer 进房后的世界侧状态：从世界 EM 与 AOI 摘除（registry 映射保留）。
     * Simulates the world-side state after the entity transferred into a room: removed from the world EM and AOI
     * (the registry mapping stays).
     */
    public function simulateTransferredIntoRoom(string $entityId): void
    {
        $entity = $this->entityManager->get($entityId);
        if ($entity !== null) {
            $this->aoi->remove($entity);
            $this->entityManager->remove($entityId);
        }
    }

    protected function handleAuthenticated(ConnectionInterface $conn, Message $message): void
    {
        switch ($message->type) {
            case 'move':
                $this->handleMove($conn, $message);

                return;
            case 'whoami':
                $this->send($conn, Message::create('iam', ['id' => $this->registry->getEntityId($conn->getId())], $message->requestId));

                return;
        }

        $this->unknownType($conn, $message);
    }

    protected function handleAuthMessage(ConnectionInterface $conn, Message $message): void
    {
        $this->authCalls++;
        $uid = $message->payload['uid'] ?? 'tester';
        $entityId = sprintf('%s@%s', $uid, $conn->getId());
        $entity = new BaseEntity($entityId, new Position(0, 0));
        $this->entityManager->add($entity);
        $this->aoi->updateEntity($entity);
        $actor = new class () extends BasePlayer {
        };
        $actor->bindEntity($entity);
        $this->mountPlayer($conn, $entity, $actor);
        $this->send($conn, Message::create('auth_ok', ['id' => $entityId], $message->requestId));
    }

    protected function decorateViewPayload(string $sourceEntityId, array $payload): array
    {
        $payload['extra'] = true;

        return $payload;
    }

    protected function onEntityCleanedUp(ConnectionInterface $conn, string $entityId): void
    {
        $this->cleanedUp[] = [$entityId];
    }
}
