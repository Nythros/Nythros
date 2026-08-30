<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin\Buff;

/**
 * Buff 注册表：按 id 管理 buff 定义，供 demo 效果结算查询（demo 阶段仅注册/查询）。
 * Buff repository: manages buff definitions by id for the demo effect resolution (registration/lookup only at the demo stage).
 */
final class BuffRepository
{
    /**
     * @var array<string, BuffDefinition> id => buff 定义 id => buff definition
     */
    private array $buffs = [];

    /**
     * 注册 buff 定义；同 id 后注册覆盖先注册。
     * Registers a buff definition; a later registration with the same id overrides the earlier one.
     *
     * @param BuffDefinition $buff Buff 定义 The buff definition.
     */
    public function register(BuffDefinition $buff): void
    {
        $this->buffs[$buff->id] = $buff;
    }

    /**
     * 按 id 查询 buff 定义；未注册返回 null。
     * Looks up a buff definition by id; returns null when not registered.
     *
     * @param string $id Buff id The buff id.
     */
    public function get(string $id): ?BuffDefinition
    {
        return $this->buffs[$id] ?? null;
    }

    /**
     * 返回全部 buff 定义（id => BuffDefinition）。
     * Returns all buff definitions (id => BuffDefinition).
     *
     * @return array<string, BuffDefinition>
     */
    public function all(): array
    {
        return $this->buffs;
    }
}
