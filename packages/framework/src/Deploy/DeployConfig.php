<?php

declare(strict_types=1);

namespace Nythros\Framework\Deploy;

/**
 * deploy.yaml 配置模型与解析器（ADR-013 决策 C：deploy.yaml 是服务拓扑唯一事实源）。
 * deploy.yaml configuration model and parser (ADR-013 decision C: deploy.yaml is the single source of truth for the service topology).
 *
 * 解析方案：手写 YAML 子集解析器（零新依赖，仅覆盖本契约所需语法）——
 * 环境无 yaml 扩展；引入 symfony/yaml 会改动根 composer.json/lock（超出 demo 包范围），
 * 且契约结构固定简单，手写解析器可被 DeployConfigTest 全量单测。
 * Parsing approach: a hand-written YAML-subset parser (zero new dependencies, covering only the syntax this contract needs) —
 * the environment has no yaml extension; adding symfony/yaml would touch the root composer.json/lock (outside the demo package
 * scope), and the contract structure is fixed and simple, so a hand-written parser can be fully unit-tested by DeployConfigTest.
 *
 * 支持语法（契约子集）：
 * Supported syntax (the contract subset):
 *   - 行内注释（# 前须有空白）与整行注释、空行；
 *     Inline comments (# must be preceded by whitespace), whole-line comments and blank lines;
 *   - 缩进映射 `key: value` / `key:` + 缩进子块（缩进仅空格，层级任意但同级一致）；
 *     Indented maps `key: value` / `key:` + indented sub-block (spaces only; arbitrary depth, consistent per level);
 *   - 块式列表项映射 `- key: value`（后续更深缩进行作为该项映射的延续）；
 *     Block-style list items `- key: value` (following deeper-indented lines continue that item's map);
 *   - 流式列表项映射 `- {key: value, ...}`（ADR 3.1 示例风格）；
 *     Flow-style list items `- {key: value, ...}` (the ADR 3.1 example style);
 *   - 标量：整数、引号字符串、裸字符串。
 *     Scalars: integers, quoted strings, bare strings.
 *
 * 校验语义（解析失败一律 InvalidArgumentException，消息带行号归因）：
 * Validation semantics (parse failures always throw InvalidArgumentException with a line number for attribution):
 *   - 顶层键白名单 redis/mysql/processes；service 键白名单 type/port/count/mapId/channelId/worldType/pidFile；
 *     Top-level key whitelist redis/mysql/processes; service key whitelist type/port/count/mapId/channelId/worldType/pidFile;
 *   - processes 段必填：部署单元拓扑（社交三角色 gateway/chat/team 与地图/副本 map，ADR-021）；
 *     The processes section is required: the deployment-unit topology (the social trio gateway/chat/team plus
 *     map/dungeon, ADR-021);
 *   - type ∈ gateway|chat|team|map；port 为 1~65535 整数且全局唯一；
 *     type ∈ gateway|chat|team|map; port is a 1-65535 integer and globally unique;
 *   - map 必须声明非空 mapId + channelId（serviceId = {mapId}#{channelId} 全局唯一）；gateway/chat/team 无此要求；
 *     map must declare a non-empty mapId + channelId (serviceId = {mapId}#{channelId} globally unique); the social roles need none;
 *   - count 缺省 1（整数 ≥1）。
 *     count defaults to 1 (integer ≥1).
 */
final class DeployConfig
{
    /** 合法服务类型白名单 Service type whitelist. */
    private const SERVICE_TYPES = ['gateway', 'chat', 'team', 'map'];

    /** 顶层键白名单 Top-level key whitelist. */
    private const TOP_KEYS = ['redis', 'mysql', 'processes'];

    /** service 键白名单 Service key whitelist. */
    private const SERVICE_KEYS = ['type', 'port', 'count', 'mapId', 'channelId', 'worldType', 'pidFile'];

    /** @var array{host: string, port: int} 共享存储层配置（token/注册表/寻址） Shared storage tier configuration (tokens/registry/addressing). */
    private readonly array $redis;

    /** @var array{host: string, port: int, user: string, password: string, dbname: string} 归档落库配置（ArchivePipeline 的 MySqlStorage 目标库） Archive persistence configuration (the MySqlStorage target of ArchivePipeline). */
    private readonly array $mysql;

    /** @var array<string, list<DeployService>> process 名 => 部署单元内的服务实例列表 process name => the deployment unit's service list. */
    private readonly array $processes;

    /**
     * 构造配置模型（仅内部构建：经 parseYaml 的 fromArray 校验后产生）。
     * Constructs the configuration model (internal only: produced by parseYaml's validated fromArray).
     *
     * @param array{host: string, port: int} $redis Redis 连接参数 Redis connection parameters.
     * @param array{host: string, port: int, user: string, password: string, dbname: string} $mysql MySQL 归档连接参数 MySQL archive connection parameters.
     * @param array<string, list<DeployService>> $processes 部署单元拓扑 Deployment-unit topology.
     */
    private function __construct(array $redis, array $mysql, array $processes)
    {
        $this->redis = $redis;
        $this->mysql = $mysql;
        $this->processes = $processes;
    }

    /**
     * 解析 deploy.yaml 文本为配置模型；结构非法时抛 InvalidArgumentException（消息带行号归因）。
     * Parses deploy.yaml text into the configuration model; throws InvalidArgumentException on an illegal structure (with line-number attribution).
     *
     * @param string $yaml 配置文本 Configuration text.
     * @return self 配置模型 The configuration model.
     * @throws \InvalidArgumentException 语法/结构非法 Illegal syntax/structure.
     */
    public static function parseYaml(string $yaml): self
    {
        $lines = self::lex($yaml);
        if ($lines === []) {
            throw new \InvalidArgumentException('DeployConfig: deploy.yaml 为空或仅含注释');
        }

        $index = 0;
        $root = self::parseMap($lines, $index, 0);

        return self::fromArray($root);
    }

    /**
     * Redis 连接参数。
     * Redis connection parameters.
     *
     * @return array{host: string, port: int} host 与 port host and port.
     */
    public function redis(): array
    {
        return $this->redis;
    }

    /**
     * MySQL 归档连接参数（host/port/user/password/dbname）。
     * MySQL archive connection parameters (host/port/user/password/dbname).
     *
     * @return array{host: string, port: int, user: string, password: string, dbname: string} 连接参数 Connection parameters.
     */
    public function mysql(): array
    {
        return $this->mysql;
    }

    /**
     * 部署单元拓扑：process 名 => 服务实例列表（保持 yaml 声明顺序）。
     * The deployment-unit topology: process name => service list (yaml declaration order preserved).
     *
     * @return array<string, list<DeployService>> process 名映射 Process-name map.
     */
    public function processes(): array
    {
        return $this->processes;
    }

    /**
     * 展开为 worker 列表：按 process 声明顺序、每 process 内 service 声明顺序、count 实例数依次展开。
     * Expands into the worker list: processes in declaration order, services within each process in declaration order, then count instances.
     *
     * @return list<DeployWorker> 逐服务逐实例的 worker 序列 The per-service per-instance worker sequence.
     */
    public function workers(): array
    {
        $workers = [];
        foreach ($this->processes as $processName => $services) {
            foreach ($services as $service) {
                for ($instance = 1; $instance <= $service->count; $instance++) {
                    $workers[] = new DeployWorker($processName, $service, $instance);
                }
            }
        }

        return $workers;
    }

    /**
     * 合法 mapId 白名单：按拓扑声明顺序去重收集全部 map 服务的 mapId（供 launch 启动摘要打印，
     * 拓扑即白名单——deploy.yaml 是唯一事实源，无需单独声明）。
     * The allowed mapId whitelist: deduplicated mapIds of every map service in topological declaration order (feeds the launch
     * summary — the topology is the whitelist, since deploy.yaml is the single source of truth, no separate declaration needed).
     *
     * @return list<string> mapId 列表 The mapId list.
     */
    public function mapIds(): array
    {
        $mapIds = [];
        foreach ($this->processes as $services) {
            foreach ($services as $service) {
                if ($service->type === 'map' && $service->mapId !== null && !in_array($service->mapId, $mapIds, true)) {
                    $mapIds[] = $service->mapId;
                }
            }
        }

        return $mapIds;
    }

    /**
     * 构建 worker 的完整启动命令（纯函数：同一 service 声明无论归属哪个 process 块，命令完全一致——
     * map 追加 mapId/channelId/worldType；社交三角色（gateway/chat/team）不追加这些参数，ADR-021）。
     * Builds the complete worker launch command (pure function: the same service declaration produces the identical command no
     * matter which process block it belongs to — a map appends mapId/channelId/worldType; the social trio (gateway/chat/team)
     * appends none of those, ADR-021).
     *
     * @param DeployWorker $worker 目标 worker The target worker.
     * @param string $workerScript run-worker.php 脚本路径 The run-worker.php script path.
     * @param array{host: string, port: int} $redis Redis 连接参数 Redis connection parameters.
     * @param array{host: string, port: int, user: string, password: string, dbname: string}|array{} $mysql MySQL 归档连接参数（缺省 [] = 不追加，run-worker 用 CLI 缺省） MySQL archive connection parameters (default [] = not appended; run-worker falls back to its CLI defaults).
     * @return list<string> argv 数组（PHP_BINARY 开头，可直接 proc_open array 命令） The argv array (starts with PHP_BINARY, usable as a proc_open array command directly).
     */
    public static function buildCommand(DeployWorker $worker, string $workerScript, array $redis, array $mysql = []): array
    {
        $command = [
            PHP_BINARY,
            $workerScript,
            '--service=' . $worker->service->type,
            '--port=' . $worker->service->port,
        ];

        $mapId = $worker->service->mapId;
        $channelId = $worker->service->channelId;
        if ($worker->service->type === 'map' && $mapId !== null && $channelId !== null) {
            $command[] = '--mapId=' . $mapId;
            $command[] = '--channelId=' . $channelId;
            $worldType = $worker->service->worldType;
            if ($worldType !== null && $worldType !== 'aoi') {
                // 全量广播型（副本/竞技场）显式透传；AOI 是 run-worker 缺省，省略
                // A full-broadcast (dungeon/arena) is passed explicitly; AOI is run-worker's default, omitted
                $command[] = '--worldType=' . $worldType;
            }
        }

        // pidFile（G-5）：显式声明时透传覆盖 run-worker 的 type+port 缺省（崩溃重启恢复不受陈旧单实例锁阻碍）
        // pidFile (G-5): passed through when explicitly declared, overriding run-worker's type+port default (crash-restart recovery is never blocked by a stale singleton lock)
        $pidFile = $worker->service->pidFile;
        if ($pidFile !== null) {
            $command[] = '--pidFile=' . $pidFile;
        }

        $command[] = '--redisHost=' . $redis['host'];
        $command[] = '--redisPort=' . $redis['port'];

        // MySQL 归档参数（A-1 落库断链修复）：deploy.yaml 声明 mysql 段时透传，run-worker 据此建连落库
        // MySQL archive parameters (A-1 persistence-chain fix): passed through when deploy.yaml declares the mysql section,
        // giving run-worker the connection for the archive persistence
        if ($mysql !== []) {
            $command[] = '--mysqlHost=' . $mysql['host'];
            $command[] = '--mysqlPort=' . $mysql['port'];
            $command[] = '--mysqlUser=' . $mysql['user'];
            $command[] = '--mysqlPass=' . $mysql['password'];
            $command[] = '--mysqlDb=' . $mysql['dbname'];
        }

        return $command;
    }

    /**
     * 词法切分：逐行剥离注释并记录缩进层级（仅空格缩进，tab 缩进报错）。
     * Lexing: strips comments per line and records the indentation level (spaces only; tab indentation is rejected).
     *
     * @param string $yaml 配置文本 Configuration text.
     * @return list<array{indent: int, text: string, lineNo: int}> 有效行（注释/空行已剔除） Effective lines (comments/blank removed).
     * @throws \InvalidArgumentException tab 缩进 Illegal tab indentation.
     */
    private static function lex(string $yaml): array
    {
        $lines = [];
        $yaml = str_replace(["\r\n", "\r"], "\n", $yaml);

        foreach (explode("\n", $yaml) as $idx => $raw) {
            $lineNo = $idx + 1;

            $indent = 0;
            $length = strlen($raw);
            while ($indent < $length && $raw[$indent] === ' ') {
                $indent++;
            }

            $rest = substr($raw, $indent);
            if (str_starts_with($rest, "\t")) {
                throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：缩进不允许使用 tab（仅空格）', $lineNo));
            }

            // 行内注释：# 前必须有空白（裸值不含 ' #' 的契约内不会误伤）
            // Inline comment: # must be preceded by whitespace (contract values never contain ' #', so no false positives)
            $text = trim($rest);
            $hash = strpos($text, ' #');
            if ($hash !== false) {
                $text = substr($text, 0, $hash);
            }
            $text = rtrim($text);

            if ($text === '' || str_starts_with($text, '#')) {
                continue;
            }

            $lines[] = ['indent' => $indent, 'text' => $text, 'lineNo' => $lineNo];
        }

        return $lines;
    }

    /**
     * 解析一个块：按下一行是否以 '-' 开头分流为列表或映射。
     * Parses a block: dispatches to list or map depending on whether the next line starts with '-'.
     *
     * @param list<array{indent: int, text: string, lineNo: int}> $lines 有效行 Effective lines.
     * @param int $index 当前行游标（引用推进） Current line cursor (advanced by reference).
     * @param int $indent 本块期望缩进 Expected indentation of this block.
     * @return array<int|string, mixed>|list<mixed> 解析结果 Parsed result.
     */
    private static function parseBlock(array $lines, int &$index, int $indent): array
    {
        if ($index < count($lines) && $lines[$index]['indent'] === $indent && str_starts_with($lines[$index]['text'], '-')) {
            return self::parseList($lines, $index, $indent);
        }

        return self::parseMap($lines, $index, $indent);
    }

    /**
     * 解析映射块：同级行必须形如 `key: value`（或 `key:` + 缩进子块），直到缩进更浅或行耗尽。
     * Parses a map block: sibling lines must look like `key: value` (or `key:` + indented sub-block) until a shallower indent or end of input.
     *
     * @param list<array{indent: int, text: string, lineNo: int}> $lines 有效行 Effective lines.
     * @param int $index 当前行游标（引用推进） Current line cursor (advanced by reference).
     * @param int $indent 本块期望缩进 Expected indentation of this block.
     * @return array<string, mixed> 映射结果 The parsed map.
     * @throws \InvalidArgumentException 行格式/缩进非法 Illegal line format/indentation.
     */
    private static function parseMap(array $lines, int &$index, int $indent): array
    {
        $map = [];
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            if ($line['indent'] < $indent) {
                break;
            }
            if ($line['indent'] > $indent) {
                throw new \InvalidArgumentException(sprintf(
                    'DeployConfig 解析失败（第 %d 行）：缩进不一致（期望 %d 空格，实际 %d）',
                    $line['lineNo'],
                    $indent,
                    $line['indent'],
                ));
            }

            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*):(.*)$/', $line['text'], $matches) !== 1) {
                throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：期望 "key: value" 格式', $line['lineNo']));
            }
            $index++;

            $key = $matches[1];
            $rest = trim($matches[2]);

            if ($rest === '') {
                // `key:` + 缩进子块（映射或列表）
                // `key:` + indented sub-block (map or list)
                if ($index >= $count || $lines[$index]['indent'] <= $indent) {
                    throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）："%s" 缺少子内容', $line['lineNo'], $key));
                }
                $childIndent = $lines[$index]['indent'];
                $map[$key] = self::parseBlock($lines, $index, $childIndent);
            } else {
                $map[$key] = self::parseScalar($rest, $line['lineNo']);
            }
        }

        return $map;
    }

    /**
     * 解析列表块：同级行必须形如 `- …`，支持标量项、流式映射项（`- {…}`）与块式映射项（`- key: value` + 更深缩进延续）。
     * Parses a list block: sibling lines must look like `- …`, supporting scalar items, flow-map items (`- {…}`) and block-map
     * items (`- key: value` continued by deeper-indented lines).
     *
     * @param list<array{indent: int, text: string, lineNo: int}> $lines 有效行 Effective lines.
     * @param int $index 当前行游标（引用推进） Current line cursor (advanced by reference).
     * @param int $indent 本列表期望缩进 Expected indentation of this list.
     * @return list<mixed> 列表结果 The parsed list.
     * @throws \InvalidArgumentException 行格式/缩进非法 Illegal line format/indentation.
     */
    private static function parseList(array $lines, int &$index, int $indent): array
    {
        $list = [];
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            if ($line['indent'] < $indent) {
                break;
            }
            if ($line['indent'] > $indent) {
                throw new \InvalidArgumentException(sprintf(
                    'DeployConfig 解析失败（第 %d 行）：缩进不一致（期望 %d 空格，实际 %d）',
                    $line['lineNo'],
                    $indent,
                    $line['indent'],
                ));
            }
            if (!str_starts_with($line['text'], '-')) {
                break;
            }
            $index++;

            $rest = ltrim(substr($line['text'], 1));

            if ($rest === '') {
                // `-` 后空：子块（列表或映射）
                // Empty after `-`: a sub-block (list or map)
                if ($index >= $count || $lines[$index]['indent'] <= $indent) {
                    throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：列表项缺少子内容', $line['lineNo']));
                }
                $childIndent = $lines[$index]['indent'];
                $list[] = self::parseBlock($lines, $index, $childIndent);
                continue;
            }

            if (str_starts_with($rest, '{')) {
                // 流式映射项：`- {type: map, mapId: map-1, channelId: ch-1, port: 18081}`
                // Flow-map item: `- {type: map, mapId: map-1, channelId: ch-1, port: 18081}`
                $list[] = self::parseFlowMap($rest, $line['lineNo']);
                continue;
            }

            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*):(.*)$/', $rest, $matches) === 1) {
                // 块式映射项首键 `- key: value`
                // Block-map item's first key `- key: value`
                $item = [];
                $firstKey = $matches[1];
                $firstRest = trim($matches[2]);

                if ($firstRest === '') {
                    // `- key:` + 更深缩进子块
                    // `- key:` + deeper-indented sub-block
                    if ($index < $count && $lines[$index]['indent'] > $indent && !str_starts_with($lines[$index]['text'], '-')) {
                        $item[$firstKey] = self::parseMap($lines, $index, $lines[$index]['indent']);
                    } else {
                        throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）："%s" 缺少子内容', $line['lineNo'], $firstKey));
                    }
                } else {
                    $item[$firstKey] = self::parseScalar($firstRest, $line['lineNo']);
                }

                // 映射延续行：更深缩进且不以 '-' 开头（同级间缩进必须一致）
                // Continuation lines: deeper-indented and not starting with '-' (must share one indentation level)
                $contIndent = null;
                while ($index < $count && $lines[$index]['indent'] > $indent && !str_starts_with($lines[$index]['text'], '-')) {
                    if ($contIndent === null) {
                        $contIndent = $lines[$index]['indent'];
                    } elseif ($lines[$index]['indent'] !== $contIndent) {
                        throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：列表项映射延续缩进不一致', $lines[$index]['lineNo']));
                    }

                    $contLine = $lines[$index];
                    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*):(.*)$/', $contLine['text'], $contMatches) !== 1) {
                        throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：期望 "key: value" 格式', $contLine['lineNo']));
                    }
                    $index++;

                    $item[$contMatches[1]] = self::parseScalar(trim($contMatches[2]), $contLine['lineNo']);
                }

                $list[] = $item;
                continue;
            }

            $list[] = self::parseScalar($rest, $line['lineNo']);
        }

        return $list;
    }

    /**
     * 解析流式映射项（`{key: value, ...}`，值不含逗号——契约内成立）。
     * Parses a flow-map item (`{key: value, ...}`; values contain no commas — true within the contract).
     *
     * @param string $rest '-' 后内容（以 { 开头） Content after '-' (starts with {).
     * @param int $lineNo 行号（错误归因） Line number (error attribution).
     * @return array<string, mixed> 映射结果 The parsed map.
     * @throws \InvalidArgumentException 格式非法 Illegal format.
     */
    private static function parseFlowMap(string $rest, int $lineNo): array
    {
        if (preg_match('/^\{.*\}$/', $rest) !== 1) {
            throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：流式映射必须是 "{key: value, ...}" 形式', $lineNo));
        }

        $inner = trim(substr($rest, 1, -1));
        if ($inner === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $inner) as $part) {
            $part = trim($part);
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*):(.*)$/', $part, $matches) !== 1) {
                throw new \InvalidArgumentException(sprintf('DeployConfig 解析失败（第 %d 行）：流式映射项必须是 "key: value" 形式', $lineNo));
            }
            $map[$matches[1]] = self::parseScalar(trim($matches[2]), $lineNo);
        }

        return $map;
    }

    /**
     * 解析标量：引号字符串去引号、纯数字转 int、其余原样字符串。
     * Parses a scalar: quoted strings are unquoted, pure digits become int, anything else stays a string.
     *
     * @param string $text 标量文本 Scalar text.
     * @param int $lineNo 行号（错误归因） Line number (error attribution).
     * @return int|string 解析值 Parsed value.
     */
    private static function parseScalar(string $text, int $lineNo): int|string
    {
        $length = strlen($text);
        if ($length >= 2 && (($text[0] === '"' && $text[$length - 1] === '"') || ($text[0] === "'" && $text[$length - 1] === "'"))) {
            return substr($text, 1, -1);
        }

        if (preg_match('/^-?[0-9]+$/', $text) === 1) {
            return (int) $text;
        }

        return $text;
    }

    /**
     * 从解析树构建强类型配置模型（逐字段校验，违反契约抛 InvalidArgumentException）。
     * Builds the strongly-typed configuration model from the parse tree (field-by-field validation; contract violations throw InvalidArgumentException).
     *
     * @param array<string, mixed> $root 顶层映射 The top-level map.
     * @return self 配置模型 The configuration model.
     * @throws \InvalidArgumentException 结构非法 Illegal structure.
     */
    private static function fromArray(array $root): self
    {
        foreach ($root as $key => $_) {
            if (!in_array($key, self::TOP_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('DeployConfig: 未知顶层键 "%s"（允许 redis/mysql/processes）', $key));
            }
        }

        $redisRaw = $root['redis'] ?? null;
        if (!is_array($redisRaw)) {
            throw new \InvalidArgumentException('DeployConfig: 缺少 redis 段（host/port）');
        }
        foreach ($redisRaw as $key => $_) {
            if (!in_array($key, ['host', 'port'], true)) {
                throw new \InvalidArgumentException(sprintf('DeployConfig: redis 段含未知键 "%s"（允许 host/port）', (string) $key));
            }
        }
        $host = $redisRaw['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('DeployConfig: redis.host 必须是非空字符串');
        }
        $port = $redisRaw['port'] ?? null;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('DeployConfig: redis.port 必须是 1~65535 的整数');
        }

        // mysql 段（A-1 落库断链修复）：可选——缺省 127.0.0.1:3306 / root / 空密码 / nythros（与 run-worker CLI 缺省一致）；
        // 声明时逐字段校验（host/port/user/password/dbname），未知键拒绝
        // The mysql section (A-1 persistence-chain fix): optional — defaults 127.0.0.1:3306 / root / empty password / nythros
        // (matching run-worker's CLI defaults); when declared, every field is validated (host/port/user/password/dbname) and unknown keys are rejected
        $mysqlRaw = $root['mysql'] ?? null;
        if ($mysqlRaw === null) {
            $mysql = ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => '', 'dbname' => 'nythros'];
        } else {
            if (!is_array($mysqlRaw)) {
                throw new \InvalidArgumentException('DeployConfig: mysql 段必须是映射（host/port/user/password/dbname）');
            }
            foreach ($mysqlRaw as $key => $_) {
                if (!in_array($key, ['host', 'port', 'user', 'password', 'dbname'], true)) {
                    throw new \InvalidArgumentException(sprintf('DeployConfig: mysql 段含未知键 "%s"（允许 host/port/user/password/dbname）', (string) $key));
                }
            }
            $mysqlHost = $mysqlRaw['host'] ?? null;
            if (!is_string($mysqlHost) || $mysqlHost === '') {
                throw new \InvalidArgumentException('DeployConfig: mysql.host 必须是非空字符串');
            }
            $mysqlPort = $mysqlRaw['port'] ?? null;
            if (!is_int($mysqlPort) || $mysqlPort < 1 || $mysqlPort > 65535) {
                throw new \InvalidArgumentException('DeployConfig: mysql.port 必须是 1~65535 的整数');
            }
            $mysqlUser = $mysqlRaw['user'] ?? null;
            if (!is_string($mysqlUser)) {
                throw new \InvalidArgumentException('DeployConfig: mysql.user 必须是字符串');
            }
            $mysqlPassword = $mysqlRaw['password'] ?? null;
            if (!is_string($mysqlPassword)) {
                throw new \InvalidArgumentException('DeployConfig: mysql.password 必须是字符串');
            }
            $mysqlDb = $mysqlRaw['dbname'] ?? null;
            if (!is_string($mysqlDb) || $mysqlDb === '') {
                throw new \InvalidArgumentException('DeployConfig: mysql.dbname 必须是非空字符串');
            }
            $mysql = ['host' => $mysqlHost, 'port' => $mysqlPort, 'user' => $mysqlUser, 'password' => $mysqlPassword, 'dbname' => $mysqlDb];
        }

        // processes 段（必填，ADR-021）：部署单元拓扑——社交三角色（gateway/chat/team）与地图/副本（map）。
        // The processes section (required, ADR-021): the deployment-unit topology — the social trio (gateway/chat/team) plus maps/dungeons (map).
        $processesRaw = $root['processes'] ?? null;
        if (!is_array($processesRaw) || $processesRaw === []) {
            throw new \InvalidArgumentException('DeployConfig: 缺少 processes 段（部署单元拓扑）');
        }

        $processes = [];
        $usedPorts = [];
        $usedServiceIds = [];
        $usedPidFiles = [];
        foreach ($processesRaw as $processName => $servicesRaw) {
            if (!is_string($processName) || $processName === '') {
                throw new \InvalidArgumentException('DeployConfig: process 名必须是非空字符串');
            }
            if (!is_array($servicesRaw) || $servicesRaw === []) {
                throw new \InvalidArgumentException(sprintf('DeployConfig: process "%s" 缺少 services 列表', $processName));
            }
            if (!array_is_list($servicesRaw)) {
                throw new \InvalidArgumentException(sprintf('DeployConfig: process "%s" 的 services 必须是列表（"- type: ..."）', $processName));
            }

            $services = [];
            foreach ($servicesRaw as $idx => $serviceRaw) {
                if (!is_array($serviceRaw)) {
                    throw new \InvalidArgumentException(sprintf('DeployConfig: process "%s" 第 %d 个 service 必须是映射', $processName, $idx + 1));
                }
                $services[] = self::buildService($serviceRaw, $processName, $idx + 1, $usedPorts, $usedServiceIds, $usedPidFiles);
            }
            $processes[$processName] = $services;
        }

        return new self(['host' => $host, 'port' => $port], $mysql, $processes);
    }

    /**
     * 校验并构建单个服务声明。
     * Validates and builds a single service declaration.
     *
     * @param array<int|string, mixed> $raw 服务原始映射 The raw service map.
     * @param string $processName 所属 process 名（错误归因） The owning process name (error attribution).
     * @param int $index 服务序号（1 起，错误归因） Service ordinal (1-based, error attribution).
     * @param array<int, true> $usedPorts 已占用端口表（引用更新，全局查重） Used-port table (updated by reference, global dedupe).
     * @param array<string, true> $usedServiceIds 已占用 map serviceId 表（引用更新，查重） Used map-serviceId table (updated by reference, dedupe).
     * @param array<string, true> $usedPidFiles 已占用 pidFile 表（引用更新，查重） Used pidFile table (updated by reference, dedupe).
     * @return DeployService 服务模型 The service model.
     * @throws \InvalidArgumentException 声明非法 Illegal declaration.
     */
    private static function buildService(array $raw, string $processName, int $index, array &$usedPorts, array &$usedServiceIds, array &$usedPidFiles): DeployService
    {
        foreach ($raw as $key => $_) {
            if (!is_string($key) || !in_array($key, self::SERVICE_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'DeployConfig: process "%s" 第 %d 个 service 含未知键 "%s"（允许 %s）',
                    $processName,
                    $index,
                    is_string($key) ? $key : (string) $key,
                    implode(',', self::SERVICE_KEYS),
                ));
            }
        }

        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::SERVICE_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'DeployConfig: process "%s" 第 %d 个 service 的 type 必须是 gateway/chat/team/map 之一',
                $processName,
                $index,
            ));
        }

        $port = $raw['port'] ?? null;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf(
                'DeployConfig: process "%s" 第 %d 个 service 的 port 必须是 1~65535 的整数',
                $processName,
                $index,
            ));
        }
        if (isset($usedPorts[$port])) {
            throw new \InvalidArgumentException(sprintf('DeployConfig: 端口 %d 重复声明（端口必须全局唯一）', $port));
        }
        $usedPorts[$port] = true;

        // pidFile（G-5）：可选非空字符串（Workerman 单实例锁键）；缺省 null = run-worker 按 type+port 生成
        // pidFile (G-5): optional non-empty string (Workerman's singleton-lock key); default null = run-worker generates it per type+port
        $pidFile = $raw['pidFile'] ?? null;
        if ($pidFile !== null && (!is_string($pidFile) || $pidFile === '')) {
            throw new \InvalidArgumentException(sprintf(
                'DeployConfig: process "%s" 第 %d 个 service 的 pidFile 必须是非空字符串',
                $processName,
                $index,
            ));
        }
        if ($pidFile !== null && isset($usedPidFiles[$pidFile])) {
            throw new \InvalidArgumentException(sprintf('DeployConfig: pidFile "%s" 重复声明（pidFile 必须全局唯一）', $pidFile));
        }
        if ($pidFile !== null) {
            $usedPidFiles[$pidFile] = true;
        }

        $count = $raw['count'] ?? 1;
        if (!is_int($count) || $count < 1) {
            throw new \InvalidArgumentException(sprintf(
                'DeployConfig: process "%s" 第 %d 个 service 的 count 必须是 ≥1 的整数（缺省 1）',
                $processName,
                $index,
            ));
        }

        $mapId = $raw['mapId'] ?? null;
        $channelId = $raw['channelId'] ?? null;
        // worldType（2a）：aoi|full，缺省 aoi（AOI 主城/野外；full 副本/竞技场全量广播）
        // worldType (2a): aoi|full, defaults to aoi (AOI town/wild; full dungeon/arena full-broadcast)
        $worldType = $raw['worldType'] ?? 'aoi';
        if ($worldType !== 'aoi' && $worldType !== 'full') {
            throw new \InvalidArgumentException(sprintf(
                'DeployConfig: process "%s" 第 %d 个 service 的 worldType 必须是 aoi 或 full',
                $processName,
                $index,
            ));
        }

        // 校验分流：map 必填非空 mapId + channelId（serviceId 全局唯一）；社交三角色（gateway/chat/team）
        // 无 mapId/channelId 要求——连接表进程内自治，注册表身份由运行时自持
        // Validation split: a map requires a non-empty mapId + channelId (globally unique serviceId); the social trio
        // (gateway/chat/team) needs neither — their connection tables are process-local, registry identities self-held
        if ($type === 'map') {
            if (!is_string($mapId) || $mapId === '' || !is_string($channelId) || $channelId === '') {
                throw new \InvalidArgumentException(sprintf(
                    'DeployConfig: process "%s" 第 %d 个 service 是 map，必须声明非空 mapId 与 channelId',
                    $processName,
                    $index,
                ));
            }
            $serviceId = $mapId . '#' . $channelId;
            if (isset($usedServiceIds[$serviceId])) {
                throw new \InvalidArgumentException(sprintf('DeployConfig: map 实例 "%s" 重复声明（serviceId 必须全局唯一）', $serviceId));
            }
            $usedServiceIds[$serviceId] = true;
        }

        return new DeployService($type, $port, $count, is_string($mapId) ? $mapId : null, is_string($channelId) ? $channelId : null, $worldType, $pidFile);
    }
}
