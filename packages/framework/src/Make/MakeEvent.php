<?php

declare(strict_types=1);

namespace Nythros\Framework\Make;

use InvalidArgumentException;

/**
 * make:event — 生成事件常量/载荷类骨架（EventDispatcher 派发用）。
 * make:event — generates an event constant/payload class skeleton (for EventDispatcher dispatch).
 */
final class MakeEvent extends MakeCommand
{
    /**
     * 执行 make:event：校验参数 → 渲染事件类 → 写入 --out/{事件名}.php。
     * Runs make:event: validates arguments → renders the event class → writes to --out/{EventName}.php.
     *
     * @param list<string> $args 命令行参数（事件名 + --out + 可选 --ns） Command-line arguments (event name + --out + optional --ns).
     *
     * @return string 生成文件路径 The generated file path.
     */
    public function run(array $args): string
    {
        $parsed = $this->parseArgs($args);
        $name = $parsed['name'];
        if ($name === null) {
            throw new InvalidArgumentException('缺少事件名参数（用法: make:event <事件名> --out=<目录> [--ns=<命名空间>]）');
        }
        $this->assertIdentifier($name, '事件名');

        $out = $parsed['options']['out'] ?? null;
        if ($out === null || $out === '') {
            throw new InvalidArgumentException('缺少 --out 选项（输出目录）');
        }

        $namespace = $parsed['options']['ns'] ?? 'Nythros\Demo\Events';
        $this->assertNamespace($namespace);

        $template = $this->readTemplate('event.php.tpl');
        $content = str_replace(
            ['{class}', '{ns}', '{event}'],
            [$name, $namespace, $this->eventName($name)],
            $template,
        );

        $target = rtrim($out, '/') . '/' . $name . '.php';
        $this->writeFile($target, $content);

        return $target;
    }

    /**
     * 事件类名转点分事件名（如 PlayerKilled → player.killed）。
     * Converts an event class name to a dotted event name (e.g. PlayerKilled → player.killed).
     */
    private function eventName(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '.$0', $name));
    }
}
