<?php

declare(strict_types=1);

namespace Nythros\Framework\Deploy;

/**
 * deploy.yaml 中的单个服务声明（一个进程块内的一条 "- type: ..." 条目）。
 * A single service declaration in deploy.yaml (one "- type: ..." entry inside a process block).
 */
final class DeployService
{
    /**
     * 构造服务声明。
     * Constructs the service declaration.
     *
     * @param string $type 服务类型：gateway|chat|team|map Service type: gateway|chat|team|map.
     * @param int $port 对外 WebSocket 端口 Public WebSocket port.
     * @param int $count 实例数（count>1 展开为多个相同命令的 worker） Instance count (count>1 expands into multiple workers running the same command).
     * @param ?string $mapId 地图标识（仅 map 服务） Map identifier (map services only).
     * @param ?string $channelId 频道标识（仅 map 服务） Channel identifier (map services only).
     * @param ?string $pidFile 显式 pidFile（Workerman 单实例锁键；null = run-worker 按 type+port 生成，G-5）
     *                          Explicit pidFile (Workerman's singleton-lock key; null = run-worker generates one per type+port, G-5).
     */
    public function __construct(
        public readonly string $type,
        public readonly int $port,
        public readonly int $count = 1,
        public readonly ?string $mapId = null,
        public readonly ?string $channelId = null,
        public readonly ?string $worldType = null,
        public readonly ?string $pidFile = null,
    ) {
    }

    /**
     * 服务实例标识：map 为 {mapId}#{channelId} 编码（ADR 5.1）；其他类型返回 null（注册逻辑 id 由各服务内部持有，
     * 如 chat-1/team-1，不属于部署拓扑契约）。
     * The service instance identifier: map uses the {mapId}#{channelId} encoding (ADR 5.1); other types return null (their logical
     * registry ids, e.g. chat-1/team-1, are held internally by each service and are outside the deployment-topology contract).
     *
     * @return ?string serviceId；非 map 或字段缺失时 null serviceId; null for non-map or missing fields.
     */
    public function serviceId(): ?string
    {
        if ($this->mapId === null || $this->channelId === null) {
            return null;
        }

        return $this->mapId . '#' . $this->channelId;
    }
}
