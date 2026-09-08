<?php

declare(strict_types=1);

// 热路径 IO 剥离门禁：战斗/移动/视野广播的每 tick/每消息主循环不得持有同步 IO 客户端。
// 设计依据：blueprint/34 性能验收实证「tick 主循环纯内存、IO=0」；CachedQuestStore 写回缓冲与
// ArchivePipeline 紧急合并窗两项改造落地后,把该纪律固化为静态门禁,防功能迭代时把 \Redis/PDO
// 引回战斗/移动热路径（P0-D 类回归）。
//
// 判定口径（保守、零误报优先）：热路径文件不得出现「同步 IO 客户端的类型确定性信号」——
// \Redis 全局类引用、Redis:: 常量、\PDO/PDOStatement/new \Redis/new \PDO。
// 不检测方法名字面量（->query() 是 AOI 空间查询、->get() 是 EntityManager 内存接口,按方法名匹配必误报）。
// 注入抽象（StorageInterface/PlayerTransferStoreInterface 等）背后的传递性 IO 超出静态检测能力,
// 由运行期 world.frame_ms p99 门禁与 soak 哨兵兜底（blueprint/34 机制）——本门禁守的是「手滑直引客户端」。
//
// 例外白名单（每连接级同步点/后端实现/周期任务,允许持有客户端）：见 $hotPathFiles/$whitelist 注释。
//
// 违规 exit 非零。用法：php tools/check-io-free-path.php（全量校验）；--self-test（内置正负向用例）。

/**
 * 扫描关键热路径文件，命中 IO 客户端类型引用即违规。白名单文件跳过。
 * 口径：只检测「文件引用了同步 IO 客户端」的确定性信号——phpredis 全局类 \Redis、其常量 Redis::、
 * PDO 类系与 PDOStatement——而非方法名字面量（->query() 是 AOI 空间查询、->get() 是 EntityManager
 * 内存接口，按方法名匹配必误报）。注入接口（StorageInterface/TokenManager…）经抽象层的传递性 IO
 * 不在本门禁检测能力内——那由运行期 world.frame_ms p99 门禁与 soak 哨兵兜底（blueprint/34 机制）。
 * Scope: detect only deterministic "this file holds a synchronous IO client" signals — the global
 * \Redis class, its Redis:: constants, and the PDO class family — not method-name literals
 * (->query() is the AOI spatial query and ->get() the in-memory EntityManager interface; matching on
 * method names would be all false positives). Transitive IO behind injected abstractions
 * (StorageInterface/TokenManager…) is out of this gate's static reach and stays covered by the
 * runtime world.frame_ms p99 gate and the soak sentinel (the blueprint/34 mechanism).
 *
 * @param list<string> $files 扫描文件清单（相对仓库根） Scan list (repo-relative paths)
 * @param list<string> $whitelist 白名单（相对路径，允许出现 IO 客户端引用） Exempted files
 *
 * @return list<string> 违规列表（格式「文件:行号: 命中信号」） Violation list
 */
function scanIoFreePath(array $files, array $whitelist, string $root): array
{
    $violations = [];
    $ioPattern = '~\\\\Redis\b|(?<![\w\\\\])Redis::|\\\\PDO\b|(?<![\w\\\\])PDOStatement\b|new\s+\\?Redis\b|new\s+\\?PDO\b~';

    foreach ($files as $rel) {
        if (in_array($rel, $whitelist, true)) {
            continue;
        }

        $full = $root . '/' . $rel;
        if (!is_file($full)) {
            $violations[] = "{$rel}（文件不存在，扫描失败）";
            continue;
        }

        $lines = file($full, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (preg_match($ioPattern, $line, $m)) {
                $violations[] = sprintf('%s:%d: 热路径禁止 IO 客户端引用「%s」', $rel, $i + 1, $m[0]);
            }
        }
    }

    return $violations;
}

/**
 * 门禁自测：临时文件构造正负向用例（合规纯内存 vs 违规含 Redis 字面量），断言检测精度。
 */
function runSelfTest(): int
{
    $failures = [];
    $tmp = sys_get_temp_dir() . '/io-free-selftest-' . uniqid();
    mkdir($tmp . '/src', 0777, true);

    try {
        // 合规：纯内存热路径
        file_put_contents($tmp . '/src/Clean.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Test;
class Clean {
    public function update(): void {
        $x = 1 + 1;
    }
}
PHP);
        // 违规：Redis 全局类引用（单行单命中，计数精确可断言）
        // Violation: one reference to the global \Redis class (one line, one hit, exact count)
        file_put_contents($tmp . '/src/Dirty.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Test;
class Dirty {
    private const CLIENT = \Redis::class;
}
PHP);
        // 违规：PDO 构造
        // Violation: a PDO construction
        file_put_contents($tmp . '/src/Db.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Test;
class Db {
    public function save(): void {
        $pdo = new \PDO('mysql:host=localhost');
    }
}
PHP);
        // 白名单：含 \Redis 引用但豁免（每连接同步点/后端实现类）
        // Whitelisted: a \Redis reference that is exempt (per-connection sync point / backend class)
        file_put_contents($tmp . '/src/Allowed.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Test;
class Allowed {
    public function flush(): void {
        $this->client = new \Redis();
    }
}
PHP);

        $clean = scanIoFreePath(['src/Clean.php'], [], $tmp);
        $dirty = scanIoFreePath(['src/Dirty.php'], [], $tmp);
        $db = scanIoFreePath(['src/Db.php'], [], $tmp);
        $notWhitelisted = scanIoFreePath(['src/Allowed.php'], [], $tmp);
        $allowed = scanIoFreePath(['src/Allowed.php'], ['src/Allowed.php'], $tmp);

        if ($clean !== []) {
            $failures[] = '合规纯内存文件误报';
        }
        if (count($dirty) !== 1) {
            $failures[] = 'Redis 违规漏报或多报（应恰 1）';
        }
        if (count($db) !== 1) {
            $failures[] = 'PDO 违规漏报或多报（应恰 1）';
        }
        if ($notWhitelisted === []) {
            $failures[] = '白名单文件未豁免前应当被检出（证明白名单真实生效而非模式漏报）';
        }
        if ($allowed !== []) {
            $failures[] = '白名单文件未豁免';
        }
    } finally {
        array_map('unlink', glob($tmp . '/src/*.php'));
        rmdir($tmp . '/src');
        rmdir($tmp);
    }

    if ($failures !== []) {
        printf("[check-io-free] SELF-TEST FAIL：%s\n", implode('; ', $failures));

        return 1;
    }
    echo "[check-io-free] SELF-TEST PASS\n";

    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$root = dirname(__DIR__);

// 热路径文件清单：tick 主循环、攻击/移动/拾取 handler（World/AOI/Actor/EventBus/Scheduler 已纯内存）。
// 这些文件是性能实证（blueprint/34）的守护面，不得有 Redis/PDO。未来若 tick 路径扩展（新玩法集成进
// Actor::update / Scheduler::runFrame / CombatService），需同步入表并剥离 IO（参照 CachedQuestStore 模式）。
$hotPathFiles = [
    'packages/engine/src/World/World.php',
    'packages/engine/src/Aoi/GridAOI.php',
    'packages/engine/src/Aoi/UniversalAOI.php',
    'packages/engine/src/Actor/SimpleActorSystem.php',
    'packages/engine/src/Event/SimpleEventBus.php',
    'packages/engine/src/Scheduler/RegionScheduler.php',
    'packages/framework/src/Combat/CombatService.php',
    'packages/framework/src/Server/RealtimeServer.php',
    'packages/demo/src/MapServer.php',
    'packages/framework/src/Game/Mmorpg/Respawner.php',
    'packages/framework/src/Game/Mmorpg/CellDensityGovernor.php',
    'packages/framework/src/Game/Horde/HordePlugin.php',
];

$whitelist = [];

$violations = scanIoFreePath($hotPathFiles, $whitelist, $root);

if ($violations !== []) {
    foreach ($violations as $v) {
        echo "[check-io-free] 违规：$v\n";
    }
    printf(
        "[check-io-free] FAIL：%d 处热路径 IO 往返（设计依据 blueprint/34 验收「每 tick 纯内存」；"
        . "剥离方案参照 CachedQuestStore 写回缓冲模式或延后至周期任务）\n",
        count($violations),
    );

    exit(1);
}

printf(
    "[check-io-free] OK：%d 个热路径文件零 Redis/PDO（守护 blueprint/34 性能验收基线）\n",
    count($hotPathFiles),
);

exit(0);
