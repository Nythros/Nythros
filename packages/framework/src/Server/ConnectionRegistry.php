<?php

declare(strict_types=1);

namespace Nythros\Framework\Server;

use Nythros\Contracts\AOIProviderInterface;
use Nythros\Contracts\EntityManagerInterface;
use Nythros\Contracts\WorldInterface;

/**
 * 连接-实体注册表：维护 connectionId <-> entityId 双向映射，保证两侧查询与删除 O(1) 且无脏数据；
 * 并为每条连接附加「当前容器」维度（ADR-024 §9 V6）：连接 ↔ 实体 ↔ 容器引用（World | RoomInstance）。
 * Connection-entity registry: maintains a bidirectional connectionId <-> entityId mapping, keeping both lookups
 * and removals O(1) with no stale entries; each connection additionally carries a "current container" dimension
 * (ADR-024 §9 V6): connection <-> entity <-> container reference (World | RoomInstance).
 *
 * 容器维度语义：null = 无容器，回落宿主世界——这是唯一合法的「无容器」表达；社交层（SocialServer）等
 * 无空间容器的宿主恒为 null 且合法。容器引用由上层编排（join 入房时标记、离开/销毁时回落），注册表只存取不编排。
 * Container-dimension semantics: null = no container, falling back to the host world — the only legitimate
 * "no container" expression; hosts without spatial containers (SocialServer) stay null legitimately. Container
 * references are orchestrated by the upper layer (marked on room join, reset on leave/destroy); the registry only
 * stores and resolves, never orchestrates.
 */
final class ConnectionRegistry
{
    /** @var array<string, string> connectionId => entityId 连接到实体的正向映射 Forward mapping from connection to entity */
    private array $entityByConnection = [];

    /** @var array<string, string> entityId => connectionId 实体到连接的反向映射 Reverse mapping from entity to connection */
    private array $connectionByEntity = [];

    /** @var array<string, object> connectionId => 当前容器（仅记录非 null 值；缺失键即 null = 回落宿主世界） connectionId => current container (non-null values only; a missing key means null = host-world fallback) */
    private array $containerByConnection = [];

    /**
     * 挂载映射：重复挂载先清旧映射再写入，保证双向表始终一致。
     * Attaches a mapping; re-attaching clears the old mapping first so both tables always stay consistent.
     *
     * @param string $connectionId 连接 ID Connection ID
     * @param string $entityId 实体 ID Entity ID
     */
    public function attach(string $connectionId, string $entityId): void
    {
        // 重复 attach 覆盖：先清理旧的双向映射，保证不残留脏数据
        // Re-attach overwrites: clear the old bidirectional mapping first so no stale data survives
        if (isset($this->entityByConnection[$connectionId])) {
            unset($this->connectionByEntity[$this->entityByConnection[$connectionId]]);
        }
        if (isset($this->connectionByEntity[$entityId])) {
            unset($this->entityByConnection[$this->connectionByEntity[$entityId]]);
        }

        // 重挂即重置容器维度（防御语义）：新挂载连接的容器状态必须从「宿主世界」起算，
        // 防止同 connId 复用或换实体重挂时残留上一实体的容器引用。
        // Re-attaching resets the container dimension (defensive semantics): a freshly mounted connection's
        // container state must start from the host world, so a reused connId or re-attach with another entity
        // never inherits the previous entity's container reference.
        unset($this->containerByConnection[$connectionId]);

        $this->entityByConnection[$connectionId] = $entityId;
        $this->connectionByEntity[$entityId] = $connectionId;
    }

    /**
     * 按连接 ID 查实体 ID，未挂载返回 null。
     * Looks up the entity ID by connection ID; null when not attached.
     */
    public function getEntityId(string $connectionId): ?string
    {
        return $this->entityByConnection[$connectionId] ?? null;
    }

    /**
     * 按实体 ID 查连接 ID，未挂载返回 null。
     * Looks up the connection ID by entity ID; null when not attached.
     */
    public function getConnectionId(string $entityId): ?string
    {
        return $this->connectionByEntity[$entityId] ?? null;
    }

    /**
     * 按连接摘除映射：双向删除后返回原实体 ID，未挂载返回 null。
     * Detaches by connection: removes both directions and returns the former entity ID, or null when not attached.
     */
    public function detachByConnection(string $connectionId): ?string
    {
        $entityId = $this->entityByConnection[$connectionId] ?? null;
        if ($entityId === null) {
            return null;
        }

        unset($this->entityByConnection[$connectionId]);
        unset($this->connectionByEntity[$entityId]);
        unset($this->containerByConnection[$connectionId]);

        return $entityId;
    }

    /**
     * 按实体摘除映射：双向删除后返回原连接 ID，未挂载返回 null。
     * Detaches by entity: removes both directions and returns the former connection ID, or null when not attached.
     */
    public function detachByEntity(string $entityId): ?string
    {
        $connectionId = $this->connectionByEntity[$entityId] ?? null;
        if ($connectionId === null) {
            return null;
        }

        unset($this->connectionByEntity[$entityId]);
        unset($this->entityByConnection[$connectionId]);
        unset($this->containerByConnection[$connectionId]);

        return $connectionId;
    }

    /**
     * 判断连接是否已挂载实体映射（即是否已认证）。
     * Checks whether the connection has an entity mapping attached (i.e. is authenticated).
     */
    public function has(string $connectionId): bool
    {
        return isset($this->entityByConnection[$connectionId]);
    }

    // ── 容器维度（ADR-024 §9 V6） ──
    // Container dimension (ADR-024 §9 V6)

    /**
     * 标记连接的当前容器：$container 为容器引用（World | RoomInstance 等空间宿主），
     * null = 清除记录回落宿主世界（离开房间/房间销毁时的回落路径）。
     * 未挂载实体映射的连接静默忽略（容器维度从属于实体映射，不存在无实体的容器归属）。
     * Marks the connection's current container: $container is the container reference (a spatial host such as
     * World | RoomInstance); null clears the record, falling back to the host world (the reset path on room leave /
     * room destroy). Connections without an entity mapping are silently ignored (the container dimension is
     * subordinate to the entity mapping; container ownership without an entity does not exist).
     */
    public function moveToContainer(string $connectionId, ?object $container): void
    {
        if (!isset($this->entityByConnection[$connectionId])) {
            return;
        }

        if ($container === null) {
            unset($this->containerByConnection[$connectionId]);

            return;
        }

        $this->containerByConnection[$connectionId] = $container;
    }

    /**
     * 按连接查当前容器；null = 无容器记录（回落宿主世界，SocialServer 恒 null 合法）。
     * Looks up the connection's current container; null = no container record (host-world fallback; SocialServer stays null legitimately).
     */
    public function getContainer(string $connectionId): ?object
    {
        return $this->containerByConnection[$connectionId] ?? null;
    }

    /**
     * 解析连接的容器上下文（连接 → 容器 → 容器内 EntityManager/AOI 的路由解析入口，ADR-024 §9 V6）：
     * 有容器记录时 EM/AOI 取自容器本身（RoomInstance 即 WorldInterface 门面）；无记录（或记录非空间宿主）
     * 时整体回落宿主世界。上层路由据此把实体解析与视野判定切换到实体真正所在的容器。
     * Resolves the connection's container context (the connection → container → in-container EntityManager/AOI
     * routing-resolution entry, ADR-024 §9 V6): with a container record the EM/AOI come from the container itself
     * (a RoomInstance is a WorldInterface facade); without one (or with a non-spatial record) everything falls back
     * to the host world. Upper-layer routing switches entity resolution and view checks to the entity's actual
     * container accordingly.
     *
     * @param WorldInterface $host 宿主世界（无容器记录时的回退来源） The host world (the fallback source when no container record exists).
     * @return array{container: object|null, entityManager: EntityManagerInterface, aoi: AOIProviderInterface} container 为容器引用（null = 宿主世界）；entityManager/aoi 恒为生效解析结果 container holds the container reference (null = host world); entityManager/aoi are always the effective resolution.
     */
    public function resolveContainerContext(string $connectionId, WorldInterface $host): array
    {
        $container = $this->containerByConnection[$connectionId] ?? null;
        if ($container instanceof WorldInterface) {
            return [
                'container' => $container,
                'entityManager' => $container->getEntityManager(),
                'aoi' => $container->getAOI(),
            ];
        }

        return [
            'container' => null,
            'entityManager' => $host->getEntityManager(),
            'aoi' => $host->getAOI(),
        ];
    }
}
