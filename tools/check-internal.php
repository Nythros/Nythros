<?php

declare(strict_types=1);

// ADR-023/ADR-024 公开符号门禁（双向）：
// ① engine 侧：实现类必须标 @internal，白名单公开面不得标注。
//    判定：Nythros\Contracts 全部、全部 interface、白名单非接口类 = 公开（禁标）；
//          其余 class/enum/trait 必须在类级 docblock 含 @internal。
// ② framework use 扫描半边（D3 兜底）：扫描 framework/src 全部 `use Nythros\...` 导入，
//    对照 engine 扫描产出的公开符号清单（Contracts 全部 + 全部接口 + 白名单非接口类，同一数据源），
//    导入白名单外 engine 实现类即违规。demo/src 与 demo/bin 为组装层，豁免。
// 违规 exit 非零。用法：composer internal（全量校验）；php tools/check-internal.php --list（输出全量分类供审计）；
// php tools/check-internal.php --self-test（内置正负向用例自测门禁自身，临时目录构造 fixture，跑完清理）。

/**
 * 扫描 engine 源码目录，产出公开符号清单与 @internal 标注违规。
 *
 * @param array<int, string> $whitelist 白名单非接口类 FQCN 列表
 *
 * @return array{missing: list<string>, mislabeled: list<string>, public: list<string>, internal: list<string>}
 */
function scanEngine(string $root, array $whitelist): array
{
    $missing = [];
    $mislabeled = [];
    $list = ['public' => [], 'internal' => []];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($file->getPathname());
        if (!preg_match('/namespace\s+([\w\\\\]+);/', $src, $ns)) {
            $missing[] = $file->getPathname() . '（无 namespace 声明）';
            continue;
        }
        if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*(class|interface|enum|trait)\s+(\w+)/m', $src, $sym, PREG_OFFSET_CAPTURE)) {
            $missing[] = $file->getPathname() . '（无类型声明）';
            continue;
        }
        $fqcn = $ns[1] . '\\' . $sym[2][0];
        $hasInternal = str_contains(substr($src, 0, (int) $sym[0][1]), '@internal');
        $isPublic = $sym[1][0] === 'interface' || str_starts_with($ns[1], 'Nythros\\Contracts') || in_array($fqcn, $whitelist, true);
        if ($isPublic) {
            $list['public'][] = $fqcn;
            if ($hasInternal) {
                $mislabeled[] = "{$fqcn}（公开面误标 @internal，文件：{$file->getPathname()}）";
            }
        } else {
            $list['internal'][] = $fqcn;
            if (!$hasInternal) {
                $missing[] = "{$fqcn}（应标未标 @internal，文件：{$file->getPathname()}）";
            }
        }
    }

    return ['missing' => $missing, 'mislabeled' => $mislabeled] + $list;
}

/**
 * 扫描 framework 源码目录的 Nythros 导入，越界引用非公开 engine 符号即违规。
 *
 * @param array<int, string> $publicSymbols engine 扫描产出的公开符号清单
 *
 * @return array{violations: array<string, list<string>>, importCount: int}
 */
function scanFramework(string $fwRoot, array $publicSymbols): array
{
    $fwViolations = [];
    $fwImportCount = 0;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fwRoot));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($file->getPathname());
        if (!preg_match_all('/^use\s+([^;]+);/m', $src, $uses)) {
            continue;
        }
        foreach ($uses[1] as $use) {
            $use = trim($use);
            if (preg_match('/^(?:function|const)\s/', $use)) {
                continue; // use function/const 非类导入，不在可见性口径内
            }
            // 展开分组 use（Nythros\Foo\{A, B}）与逗号多导入（use A, B）
            $targets = [];
            if (preg_match('/^([\w\\\\]+)\{(.*)\}$/', $use, $group)) {
                $prefix = rtrim($group[1], '\\');
                foreach (explode(',', $group[2]) as $leaf) {
                    $targets[] = $prefix . '\\' . ltrim(trim($leaf), '\\'); // 显式补 \ 分隔符，不依赖前缀捕获组是否带尾部反斜杠
                }
            } else {
                foreach (explode(',', $use) as $item) {
                    $targets[] = trim($item);
                }
            }
            foreach ($targets as $target) {
                $target = trim((string) preg_split('/\s+as\s+/i', $target)[0]); // 去 as 别名
                if (!str_starts_with($target, 'Nythros\\') || str_starts_with($target, 'Nythros\\Framework\\')) {
                    continue; // 非 Nythros 导入与 framework 包自身命名空间不在 engine 可见性口径内
                }
                ++$fwImportCount;
                if (!in_array($target, $publicSymbols, true)) {
                    $rel = substr($file->getPathname(), strlen($fwRoot) + 1);
                    $fwViolations[$target][] = $rel;
                }
            }
        }
    }

    return ['violations' => $fwViolations, 'importCount' => $fwImportCount];
}

/**
 * 单条断言：即时输出 PASS/FAIL，失败记入 $failures。
 *
 * @param array<int, string> $failures
 */
function assertCase(bool $cond, string $name, array &$failures): void
{
    echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
    if (!$cond) {
        $failures[] = $name;
    }
}

/**
 * 递归删除目录（SKIP_DOTS 避免 ./.. 干扰），用于 self-test 临时 fixture 清理。
 */
function removeDirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * 门禁脚本自测：临时目录构造正负向 fixture，复用生产扫描逻辑断言判定正确性。
 * 覆盖：① 合规文件（interface/Contracts/白名单/@internal 实现类）
 *       ② 违规文件（应标未标/白名单误标/framework 越界导入）
 *       ③ 分组 use 展开（正向导入公开符号不误报；负向展开符号名精确含分隔符）。
 */
function runSelfTest(): int
{
    $failures = [];
    $tmp = sys_get_temp_dir() . '/check-internal-selftest-' . uniqid();

    try {
        $write = static function (string $rel, string $code) use ($tmp): void {
            $full = $tmp . '/' . $rel;
            $dir = dirname($full);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($full, $code);
        };

        // ── ① 合规文件 ──
        $write('engine/src/Acme/GreeterInterface.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Acme;
interface GreeterInterface {}
PHP);
        $write('engine/src/Nythros/Contracts/Payload.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Contracts;
class Payload {}
PHP);
        $write('engine/src/Nythros/Contracts/Envelope.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Contracts;
interface Envelope {}
PHP);
        $write('engine/src/Nythros/App/AllowedDto.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\App;
class AllowedDto {}
PHP);
        $write('engine/src/Nythros/App/TaggedService.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\App;
/** @internal */
class TaggedService {}
PHP);

        // ── ② 违规文件：应标未标 / 白名单误标 ──
        $write('engine/src/Nythros/App/NakedService.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\App;
class NakedService {}
PHP);
        $write('engine/src/Nythros/App/MislabeledDto.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\App;
/** @internal */
class MislabeledDto {}
PHP);

        // ── framework 侧：合规导入 / 分组 use 正向 / 分组 use 负向 / as 别名越界 ──
        $write('framework/src/Consumer.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Framework;
use Nythros\Contracts\Payload;
use Nythros\App\AllowedDto;
class Consumer {}
PHP);
        $write('framework/src/GroupImportOk.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Framework;
use Nythros\Contracts\{Payload, Envelope};
class GroupImportOk {}
PHP);
        $write('framework/src/GroupImportBad.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Framework;
use Nythros\App\{NakedService, TaggedService};
class GroupImportBad {}
PHP);
        $write('framework/src/AliasedImport.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Nythros\Framework;
use Nythros\App\NakedService as Leaked;
class AliasedImport {}
PHP);

        $testWhitelist = ['Nythros\\App\\AllowedDto', 'Nythros\\App\\MislabeledDto'];
        $engine = scanEngine($tmp . '/engine/src', $testWhitelist);
        $fw = scanFramework($tmp . '/framework/src', $engine['public']);

        // engine 半边判定
        assertCase(count($engine['missing']) === 1 && str_contains($engine['missing'][0], 'Nythros\\App\\NakedService'), '应标未标：NakedService 恰好报一次', $failures);
        assertCase(count($engine['mislabeled']) === 1 && str_contains($engine['mislabeled'][0], 'Nythros\\App\\MislabeledDto'), '白名单误标：MislabeledDto 恰好报一次', $failures);
        $expectedPublic = ['Acme\\GreeterInterface', 'Nythros\\App\\AllowedDto', 'Nythros\\App\\MislabeledDto', 'Nythros\\Contracts\\Envelope', 'Nythros\\Contracts\\Payload'];
        sort($expectedPublic);
        $actualPublic = $engine['public'];
        sort($actualPublic);
        assertCase($actualPublic === $expectedPublic, '公开面清单：interface/Contracts/白名单 全部识别', $failures);
        // internal 清单语义为实现类全集（含应标未标者，后者同时记入 missing）
        $actualInternal = $engine['internal'];
        sort($actualInternal);
        assertCase($actualInternal === ['Nythros\\App\\NakedService', 'Nythros\\App\\TaggedService'], '@internal 清单：实现类全集恰为 NakedService/TaggedService', $failures);

        // framework 半边判定
        assertCase($fw['importCount'] === 7, 'framework 导入计数：4 文件共 7 处 Nythros 导入', $failures);
        $violationKeys = array_keys($fw['violations']);
        sort($violationKeys);
        assertCase($violationKeys === ['Nythros\\App\\NakedService', 'Nythros\\App\\TaggedService'], '越界符号集合精确匹配（无缺分隔符拼接产物）', $failures);
        $nakedFiles = $fw['violations']['Nythros\\App\\NakedService'] ?? [];
        sort($nakedFiles);
        assertCase($nakedFiles === ['AliasedImport.php', 'GroupImportBad.php'], '分组 use 负向 + as 别名均落到 NakedService 名下', $failures);
        assertCase(($fw['violations']['Nythros\\App\\TaggedService'] ?? []) === ['GroupImportBad.php'], '分组 use 展开逐 leaf 精确到 TaggedService', $failures);
        // 分组 use 正向判别：若展开缺分隔符会拼出 Nythros\ContractsPayload 并误报违规
        assertCase(!isset($fw['violations']['Nythros\\ContractsPayload']) && count($violationKeys) === 2, '分组 use 正向导入公开符号无误报', $failures);
    } finally {
        removeDirRecursive($tmp);
    }

    if ($failures !== []) {
        printf("[check-internal] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo "[check-internal] SELF-TEST PASS：正负向用例全过\n";

    return 0;
}

// ── 入口：--self-test 优先（不触碰真实仓库路径）──
if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$root = dirname(__DIR__) . '/packages/engine/src';

// 白名单非接口类（接口与 Contracts 命名空间天然公开，不在此列）。来源：ADR-023 D1 + ADR-024 §2 D-E（形状三值对象比照 Position 先例）。
$whitelist = [
    'Nythros\Actor\BaseActor',                    // framework 四基类文档化继承目标（ADR-023 D1-3）
    'Nythros\Protocol\Frame',                     // protocol 公开面（ADR-023 D1-4）
    'Nythros\Protocol\Message',
    'Nythros\Protocol\ProtocolVocabulary',
    'Nythros\Protocol\ProtocolException',         // protocol 契约异常（ADR-023 D1-4「两异常」）
    'Nythros\Protocol\DecodeException',
    'Nythros\Security\AuthenticationException',   // AuthenticatorInterface 契约异常（ADR-023 D1-7）
    'Nythros\Security\TokenStatus',               // Token 五态值枚举（R2 审查 MAJOR-2 白名单公开化：framework 消费需要，语义类比 Position 先例）
    'Nythros\Network\ConnectionClosedException',  // ConnectionInterface 契约异常（ADR-023 D1-7）
    'Nythros\Entity\Position',                    // 空间坐标值对象（ADR-023 D1-5）
    'Nythros\Cluster\ServiceInstance',            // 服务寻址值对象（ADR-023 D1-6）
    'Nythros\Entity\CircleShape',                 // 形状三值对象（ADR-024 白名单增量）
    'Nythros\Entity\RectangleShape',
    'Nythros\Entity\SectorShape',
];

$result = scanEngine($root, $whitelist);
$missing = $result['missing'];
$mislabeled = $result['mislabeled'];
$list = ['public' => $result['public'], 'internal' => $result['internal']];

if (in_array('--list', $argv, true)) {
    echo "=== 公开面 (" . count($list['public']) . ") ===\n";
    foreach ($list['public'] as $f) {
        echo "  PUBLIC  $f\n";
    }
    echo "=== @internal (" . count($list['internal']) . ") ===\n";
    foreach ($list['internal'] as $f) {
        echo "  INTERNAL $f\n";
    }

    exit(0);
}

// ── framework use 扫描半边（ADR-023 D3 兜底）：framework/src 导入的 Nythros 符号必须落在公开符号清单内 ──
// 公开符号清单即上方 engine 扫描产出的公开面（Contracts 全部 + 全部接口 + 白名单），同一数据源。
$fwResult = scanFramework(dirname(__DIR__) . '/packages/framework/src', $list['public']);
$fwViolations = $fwResult['violations'];
$fwImportCount = $fwResult['importCount'];

if ($missing !== [] || $mislabeled !== [] || $fwViolations !== []) {
    foreach ($missing as $m) {
        echo "[check-internal] 违规（应标未标）：$m\n";
    }
    foreach ($mislabeled as $m) {
        echo "[check-internal] 违规（白名单误标）：$m\n";
    }
    foreach ($fwViolations as $symbol => $files) {
        foreach ($files as $f) {
            echo "[check-internal] 违规（framework 导入非公开 engine 符号）：$symbol ← framework/src/$f\n";
        }
    }
    printf(
        '[check-internal] FAIL：%d 应标未标 / %d 白名单误标 / %d framework 越界导入（口径见 ADR-023/ADR-024）',
        count($missing),
        count($mislabeled),
        array_sum(array_map('count', $fwViolations)),
    );

    exit(1);
}

printf(
    "[check-internal] OK：%d 个实现类已标 @internal，公开面 %d 个符号无标注；framework/src 共 %d 处 Nythros 导入全部合规。\n",
    count($list['internal']),
    count($list['public']),
    $fwImportCount,
);

exit(0);
