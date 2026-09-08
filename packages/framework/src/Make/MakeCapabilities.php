<?php

declare(strict_types=1);

namespace Nythros\Framework\Make;

use Nythros\Framework\Capability\CapabilityCatalog;
use Nythros\Framework\Plugin\FeatureFlags;

/**
 * make:capabilities —— 能力报告：列出框架全部可装配能力块,标注每一项在当前环境的开关判定
 * （FeatureFlags 三级优先 + 装配 env 门），供开发者「按目标游戏挑积木」时一站看清。
 * 数据源单一:目录来自 CapabilityCatalog,开关判定来自 FeatureFlags——与运行时装配同源,报告不漂移。
 * make:capabilities — the capability report: every assembly-ready block with its resolved status under the
 * current environment (FeatureFlags three-tier precedence plus the assembly env gate), so a developer picking
 * blocks for a game sees one consistent picture. Single source: catalog from CapabilityCatalog, decisions from
 * FeatureFlags — the same pair the runtime assembly consults, so the report cannot drift.
 *
 * 用法 Usage:
 *   php vendor/bin/make make:capabilities [--format=text|json]
 */
final class MakeCapabilities extends MakeCommand
{
    /**
     * 生成能力报告并打印。返回进程退出码（恒 0;纯查询,不改文件）。
     * Renders the capability report and returns the exit code (always 0 — a pure query, no file writes).
     *
     * @param list<string> $args CLI 参数（在 make:capabilities 之后）
     */
    public function run(array $args): int
    {
        ['name' => $name, 'options' => $options] = $this->parseArgs($args);
        if ($name !== null) {
            fwrite(STDERR, "make:capabilities 不接受位置参数（提示: --format=text|json）\n");

            return 1;
        }
        $format = $options['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            fwrite(STDERR, sprintf('未知 --format: %s（期望 text|json）\n', $format));

            return 1;
        }

        $flags = FeatureFlags::fromEnvironment();
        $rows = [];
        foreach (CapabilityCatalog::all() as $capability => $spec) {
            $envGate = $spec['env'] === null ? null : (getenv($spec['env']) === '1' ? 'on' : 'off');
            $rows[] = [
                'capability' => $capability,
                'summary'    => $spec['summary'],
                'layer'      => $spec['layer'],
                'flag'       => $flags->isEnabled($capability) ? 'on' : 'off',
                'env_gate'   => $envGate,
                'entry'      => $spec['entry'],
            ];
        }

        if ($format === 'json') {
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";

            return 0;
        }

        printf("%-14s %-6s %-8s %-24s %s\n", '能力', '开关', 'env门', '所属模块', '说明');
        printf("%s\n", str_repeat('-', 110));
        foreach ($rows as $row) {
            printf(
                "%-14s %-6s %-8s %-24s %s\n",
                $row['capability'],
                $row['flag'],
                $row['env_gate'] ?? '—',
                $row['layer'],
                $row['summary'],
            );
        }
        printf("\n开关:NYTHROS_FEATURES 白名单 / NYTHROS_FEATURE_<大写能力名>=0|1 覆盖(env门之上的第二闸)。\n");

        return 0;
    }
}
