<?php

declare(strict_types=1);

namespace Nythros\Framework\Make;

use InvalidArgumentException;

/**
 * make:actor — 生成业务 Actor 骨架（kind → 基类 + 钩子集映射驱动模板渲染）。
 * make:actor — generates business actor skeletons (the kind → base-class + hook-set map drives template rendering).
 */
final class MakeActor extends MakeCommand
{
    /**
     * kind → 基类 + 钩子集映射（模板渲染的唯一依据）。
     * The kind → base class + hook set map (the single source of truth for template rendering).
     *
     * @var array<string, array{base: string, hooks: list<string>}>
     */
    private const KINDS = [
        'player' => ['base' => 'BasePlayer', 'hooks' => ['onTick', 'onDamaged', 'onDeath']],
        'monster' => ['base' => 'BaseMonster', 'hooks' => ['onPatrol', 'onChase', 'onAttack', 'onDead', 'onDeath']],
        'npc' => ['base' => 'BaseNPC', 'hooks' => ['onIdle', 'onInteract']],
    ];

    /**
     * 执行 make:actor：校验参数 → 渲染 kind 对应模板 → 写入 --out/{类名}.php。
     * Runs make:actor: validates arguments → renders the kind's template → writes to --out/{ClassName}.php.
     *
     * @param list<string> $args 命令行参数（类名 + --kind/--ns/--out） Command-line arguments (class name + --kind/--ns/--out).
     *
     * @return string 生成文件路径 The generated file path.
     */
    public function run(array $args): string
    {
        $parsed = $this->parseArgs($args);
        $name = $parsed['name'];
        if ($name === null) {
            throw new InvalidArgumentException('缺少类名参数（用法: make:actor <类名> --kind=... --ns=... --out=...）');
        }
        $this->assertIdentifier($name, '类名');

        $kind = $parsed['options']['kind'] ?? null;
        if ($kind === null) {
            throw new InvalidArgumentException('缺少 --kind 选项（可选值: player|monster|npc）');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException(sprintf('非法 --kind=%s（可选值: player|monster|npc）', $kind));
        }

        $namespace = $parsed['options']['ns'] ?? null;
        if ($namespace === null || $namespace === '') {
            throw new InvalidArgumentException('缺少 --ns 选项（命名空间，如 Nythros\Demo\Game）');
        }
        $this->assertNamespace($namespace);

        $out = $parsed['options']['out'] ?? null;
        if ($out === null || $out === '') {
            throw new InvalidArgumentException('缺少 --out 选项（输出目录）');
        }

        $base = self::KINDS[$kind]['base'];
        $template = $this->readTemplate('actor/' . $kind . '.php.tpl');
        $content = str_replace(
            ['{class}', '{ns}', '{base}'],
            [$name, $namespace, $base],
            $template,
        );

        $target = rtrim($out, '/') . '/' . $name . '.php';
        $this->writeFile($target, $content);

        return $target;
    }
}
