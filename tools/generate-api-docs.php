<?php

declare(strict_types=1);

// 公开 API 一览生成器（docs/api-reference.md）。
// 扫描 packages/engine/src 与 packages/framework/src，经反射枚举公开符号（interface、
// Nythros\Contracts 命名空间、未标 @internal 的白名单实现类——口径与 tools/check-internal.php
// 的公开面门禁一致：非接口非 Contracts 类必须标 @internal，CI 强制，因此「未标 @internal」
// 即公开面，无需重复维护白名单），按命名空间分组输出类/接口摘要与公开方法签名表。
// 用法：php tools/generate-api-docs.php          生成 docs/api-reference.md
//       php tools/generate-api-docs.php --check  再生成并与现有文件比对，有差异 exit 1（CI 门禁）
//       php tools/generate-api-docs.php --self-test  内置冒烟自测

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * 扫描一个源码根目录，返回其中的公开符号 FQCN 清单。
 *
 * @return array<int, string>
 */
function scanPublicSymbols(string $srcRoot): array
{
    $symbols = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (!preg_match('/namespace\s+([\w\\\\]+);/', $src, $ns)) {
            continue;
        }
        if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*(class|interface|enum|trait)\s+(\w+)/m', $src, $sym, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $fqcn = $ns[1] . '\\' . $sym[2][0];
        $isInterface = $sym[1][0] === 'interface';
        $isContracts = str_starts_with($ns[1], 'Nythros\\Contracts');
        $docblock = substr($src, 0, (int) $sym[0][1]);
        $isInternal = str_contains($docblock, '@internal');
        if ($isInterface || $isContracts || !$isInternal) {
            if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                $symbols[] = $fqcn;
            }
        }
    }

    sort($symbols);

    return $symbols;
}

/**
 * 提取 docblock 的摘要行（首个非 tag、非空白、非注释修饰的文本行）。
 */
function docblockSummary(ReflectionClass|ReflectionMethod $reflector): string
{
    $doc = $reflector->getDocComment();
    if ($doc === false) {
        return '';
    }
    foreach (explode("\n", $doc) as $line) {
        $line = trim($line, " \t*/\r");
        if ($line === '' || $line === '/**' || str_starts_with($line, '@')) {
            continue;
        }
        // 类 docblock 里 @internal 等已在过滤阶段处理；摘要取首行，去掉 {@...} 内联标签保留文本
        $line = (string) preg_replace('/\{@\w+\s+([^}]*)\}/', '$1', $line);

        return trim($line);
    }

    return '';
}

/**
 * 方法签名的可读形态：visibility 名(参数...): 返回类型。
 */
function methodSignature(ReflectionMethod $method): string
{
    $params = [];
    foreach ($method->getParameters() as $param) {
        $type = $param->hasType() ? (string) $param->getType() : 'mixed';
        $default = '';
        if ($param->isDefaultValueAvailable()) {
            $value = $param->getDefaultValue();
            $default = ' = ' . (is_array($value) ? '[...]' : var_export($value, true));
            if (is_string($value) && strlen($value) > 24) {
                $default = " = '...'";
            }
        }
        $variadic = $param->isVariadic() ? '...' : '';
        $params[] = sprintf('%s$%s%s%s', $type === 'mixed' ? '' : $type . ' ', $variadic, $param->getName(), $default);
    }
    $return = $method->hasReturnType() ? ': ' . $method->getReturnType() : '';

    return sprintf('%s(%s)%s', $method->getName(), implode(', ', $params), $return);
}

/**
 * 渲染单个符号的条目。
 */
function renderSymbol(ReflectionClass $class): string
{
    $kind = match (true) {
        $class->isInterface() => 'interface',
        $class->isEnum() => 'enum',
        $class->isTrait() => 'trait',
        default => 'class',
    };
    $lines = [];
    $title = $class->isInterface() || $class->isEnum()
        ? $class->getName()
        : $class->getShortName();
    $lines[] = "#### `$title`";
    $meta = [];
    $summary = docblockSummary($class);
    if ($summary !== '') {
        $meta[] = $summary;
    }
    $parent = $class->getParentClass();
    if ($parent !== false) {
        $meta[] = 'extends `' . $parent->getName() . '`';
    }
    $interfaces = $class->getInterfaceNames();
    if ($interfaces !== []) {
        $meta[] = 'implements `' . implode('`, `', $interfaces) . '`';
    }
    if ($class->isAbstract() && $kind === 'class') {
        $meta[] = 'abstract';
    }
    $lines[] = implode(' · ', array_map(
        static fn (string $m): string => str_starts_with($m, '`') ? $m : preg_replace('/`([^`]+)`/', '`$1`', $m) ?? $m,
        $meta
    ));

    if ($class->isEnum()) {
        $enum = new ReflectionEnum($class->getName());
        $cases = array_map(
            static fn (ReflectionEnumUnitCase $case): string => '`' . $case->getName() . '`',
            $enum->getCases()
        );
        if ($cases !== []) {
            $lines[] = '- Cases: ' . implode(', ', $cases);
        }
    }

    $methods = array_values(array_filter(
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class->getName()
    ));
    usort($methods, static fn (ReflectionMethod $a, ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));
    if ($methods !== []) {
        $lines[] = '';
        $lines[] = '| 方法 | 说明 |';
        $lines[] = '|---|---|';
        foreach ($methods as $m) {
            $sig = str_replace('|', '\\|', methodSignature($m));
            $desc = str_replace('|', '\\|', docblockSummary($m));
            $static = $m->isStatic() ? '`static` ' : '';
            $lines[] = "| {$static}`{$sig}` | {$desc} |";
        }
    }

    return implode("\n", $lines);
}

/**
 * 渲染整份 API 一览。
 *
 * @param array<string, string> $roots 包名 => 源码根
 */
function renderApiDoc(array $roots): string
{
    $out = [];
    $out[] = '# 公开 API 一览（API Reference）';
    $out[] = '';
    $out[] = '> **本文件由脚本生成，请勿手工编辑**：`php tools/generate-api-docs.php`。';
    $out[] = '> 收录口径与 `tools/check-internal.php` 公开面门禁一致：interface 全部、`Nythros\Contracts` 命名空间全部、';
    $out[] = '> 未标 `@internal` 的类/枚举（ADR-023/024）。`@internal` 实现类不构成 API 承诺，业务层只依赖 Contracts 接口。';
    $out[] = '> 指南（用法与教程）见 [docs/ 索引](https://github.com/nythros/nythros/tree/master#文档索引)；本文件只做「有什么、叫什么、签名单什么」的索引。';
    $out[] = '> 摘要中的 P 编号（P9/P11/P15…）是阶段验收记录的追溯锚点，对应 [blueprint/](https://github.com/nythros/nythros/tree/master/blueprint) 目录的编号验收文档。';
    $out[] = '';

    $totalCount = 0;
    foreach ($roots as $package => $root) {
        $symbols = scanPublicSymbols($root);
        $totalCount += count($symbols);
        $out[] = "## {$package}";
        $out[] = '';
        $byNamespace = [];
        foreach ($symbols as $fqcn) {
            $byNamespace[substr($fqcn, 0, (int) strrpos($fqcn, '\\'))][] = $fqcn;
        }
        ksort($byNamespace);
        foreach ($byNamespace as $namespace => $classes) {
            $out[] = "### `{$namespace}`";
            $out[] = '';
            foreach ($classes as $fqcn) {
                $out[] = renderSymbol(new ReflectionClass($fqcn));
                $out[] = '';
            }
        }
    }

    array_splice($out, 7, 0, [sprintf('%d 个公开符号（engine + framework）。', $totalCount), '']);

    return implode("\n", $out) . "\n";
}

/**
 * 冒烟自测：不构造 fixture，直接扫真实仓库断言关键公开符号被收录、@internal 实现类未被收录。
 */
function runSelfTest(): int
{
    $root = dirname(__DIR__);
    $engine = scanPublicSymbols($root . '/packages/engine/src');
    $framework = scanPublicSymbols($root . '/packages/framework/src');
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };
    $assert(count($engine) > 10 && count($framework) > 10, '公开符号数量级合理');
    $assert(in_array('Nythros\Contracts\WorldInterface', $engine, true), 'Contracts 契约被收录（WorldInterface）');
    $assert(in_array('Nythros\Protocol\Frame', $engine, true), '白名单实现类被收录（Protocol\\Frame）');
    $assert(!in_array('Nythros\Protocol\BinaryBatchSerializer', $engine, true), '@internal 实现类未收录（BinaryBatchSerializer）');
    $assert(!in_array('Nythros\Framework\Social\SocialService', $framework, true) || str_contains(
        (string) (new ReflectionClass('Nythros\Framework\Social\SocialService'))->getDocComment(),
        '@internal'
    ) === false, 'framework 服务收录与 @internal 标注一致');
    $doc = renderApiDoc(['nythros/engine' => $root . '/packages/engine/src', 'nythros/framework' => $root . '/packages/framework/src']);
    $assert(str_contains($doc, '# 公开 API 一览'), '渲染产物含标题');
    $assert(!str_contains($doc, 'Nythros\\Protocol\\BinaryBatchSerializer'), '渲染产物不含 @internal 实现类（BinaryBatchSerializer）');
    $assert(!str_contains($doc, 'Nythros\\Persistence\\MySqlStorage'), '渲染产物不含 @internal 实现类（MySqlStorage）');

    if ($failures !== []) {
        printf("[generate-api-docs] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo '[generate-api-docs] SELF-TEST PASS' . "\n";

    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$root = dirname(__DIR__);
$doc = renderApiDoc([
    'nythros/engine' => $root . '/packages/engine/src',
    'nythros/framework' => $root . '/packages/framework/src',
]);
$target = $root . '/docs/api-reference.md';

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current !== $doc) {
        echo "[generate-api-docs] FAIL：docs/api-reference.md 与代码不一致，运行 php tools/generate-api-docs.php 再生成。\n";
        exit(1);
    }
    echo "[generate-api-docs] OK：docs/api-reference.md 与代码一致。\n";
    exit(0);
}

file_put_contents($target, $doc);
echo '[generate-api-docs] OK：已写入 docs/api-reference.md（' . count(explode("\n", $doc)) . " 行）。\n";
