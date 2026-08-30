<?php

declare(strict_types=1);

namespace Nythros\Framework\Config;

use InvalidArgumentException;
use LogicException;
use Nythros\Contracts\TimerInterface;
use Nythros\Framework\Event\EventDispatcherInterface;
use Throwable;

/**
 * 配置热载仓库：多 PHP 文件注册、mtime 轮询检测与内存快照原子替换（R3 配置热载基线）。
 * Config hot-reload repository: multi-file registration, mtime polling detection and atomic in-memory
 * snapshot replacement (the R3 config-hot-reload baseline).
 *
 * 语义约定：
 * - 每个注册文件以显式键登记，文件返回的数组成为该键下的配置子树；读取口径与 Config 一致（点号路径）。
 * - check() 轮询各文件 mtime：无变化零事件零开销（除 mtime 读取）；有变化时先把全部变更文件加载进临时
 *   快照，全部成功才一次性替换并逐文件发 config.changed 事件——任一文件加载失败即整体放弃（回滚语义：
 *   旧配置原样保留、不应用部分变更、不发事件），下次轮询自动重试。
 * - 文件被删除（mtime 不可读）视为「待变更」，加载必然失败 → 同样走回滚，运行中配置不会被清空。
 * - 各 map 进程 fork 后各自持有本仓库实例、各自轮询自查 mtime（不做跨进程推送）；定时驱动用
 *   startPolling(TimerInterface) 或由调用方周期触发 check()。
 * Semantics:
 * - Each registered file is keyed explicitly; the array it returns becomes that key's config subtree; reads
 *   follow the same dot-path convention as Config.
 * - check() polls each file's mtime: no change means zero events and near-zero cost (one mtime read per file);
 *   on change, all changed files are loaded into a temporary snapshot first and only a fully successful load
 *   swaps the snapshot in, dispatching one config.changed event per file — any single load failure aborts the
 *   whole batch (rollback semantics: the old config stays intact, no partial application, no events), and the
 *   next poll retries automatically.
 * - A deleted file (unreadable mtime) counts as "pending change"; its load necessarily fails → same rollback,
 *   so a running config is never wiped.
 * - Every map process holds its own repository instance after fork and polls mtimes independently (no
 *   cross-process push); drive it with startPolling(TimerInterface) or trigger check() periodically yourself.
 */
final class ConfigRepository
{
    /** 变更事件名：payload {key: string, path: string}，逐变更文件各发一次。 Change-event name: payload {key, path}, one dispatch per changed file. */
    public const EVENT_CHANGED = 'config.changed';

    private ?Config $config = null;

    /** @var array<string, string> 配置键 => 文件路径 config key => file path */
    private array $files = [];

    /** @var array<string, int|false> 配置键 => 上次加载时的 mtime 快照 config key => mtime snapshot at last load */
    private array $mtimes = [];

    /** @var array<string, ConfigSchema|null> 配置键 => 表结构规则（未声明 schema 的键为 null） config key => table-schema rule (null when undeclared) */
    private array $schemas = [];

    /** @var callable(string):(int|false) mtime 读取器（可注入替身；缺省绕过 stat 缓存直读） mtime reader (injectable fake; default bypasses the stat cache) */
    private $mtimeReader;

    /**
     * @param EventDispatcherInterface $events 变更事件派发器 Change-event dispatcher.
     * @param ?callable $mtimeReader mtime 读取器注入（测试假时钟/假 mtime）；签名 fn(string $path): int|false
     *                               Mtime-reader injection (fake clock/mtime for tests); signature fn(string $path): int|false.
     */
    public function __construct(private readonly EventDispatcherInterface $events, ?callable $mtimeReader = null)
    {
        $this->mtimeReader = $mtimeReader ?? static function (string $path): int|false {
            clearstatcache(true, $path);

            return @filemtime($path);
        };
    }

    /**
     * 注册单文件并立即加载（启动期失败快速抛出，不进静默降级）；重复键抛异常。
     * Registers one file and loads it immediately (startup failures throw fast, never degrade silently); duplicate keys throw.
     *
     * @param string $key 配置键（该文件数组的挂载点） The config key (mount point of the file's array).
     * @param string $path PHP 配置文件路径（须返回 array） The PHP config file path (must return an array).
     * @param ConfigSchema|null $schema 表结构规则：注册期校验 fail-fast（错误带行号），热载期校验失败走回滚。
     *                                  传入后配置产出为 schema 归一化形态（optional 默认值已回填）。
     *                                  The table-schema rule: register-time validation fails fast (errors carry line
     *                                  numbers) and hot-reload validation failures roll back. When provided, the config
     *                                  output is the schema-normalized shape (optional defaults back-filled).
     */
    public function registerFile(string $key, string $path, ?ConfigSchema $schema = null): void
    {
        if (isset($this->files[$key])) {
            throw new LogicException(sprintf('配置键重复注册: %s', $key));
        }

        // 启动期直接复用 Config::fromPhpFile 的校验（缺失/非 array 抛 InvalidArgumentException）
        // Startup reuses Config::fromPhpFile validation (missing/non-array throws InvalidArgumentException)
        $items = Config::fromPhpFile($path)->all();
        if ($schema !== null) {
            $items = $this->validateOrFail($key, $path, $schema, $items);
        }
        $this->files[$key] = $path;
        $this->schemas[$key] = $schema;
        $this->mtimes[$key] = ($this->mtimeReader)($path);

        $merged = $this->config?->all() ?? [];
        $merged[$key] = $items;
        $this->config = new Config($merged);
    }

    /**
     * 注册目录内全部 *.php 配置文件（按文件名排序保证确定性），键取文件名去扩展名；
     * $schemas 按键提供各文件的可选表结构规则。
     * Registers every *.php config file inside a directory (sorted by filename for determinism), keyed by filename
     * without extension; $schemas provides each file's optional table-schema rule by key.
     *
     * @param string $dir 配置目录 The config directory.
     * @param array<string, ConfigSchema>|null $schemas 配置键 => 表结构规则 config key => table-schema rule.
     */
    public function registerDirectory(string $dir, ?array $schemas = null): void
    {
        $paths = glob(rtrim($dir, '/\\') . '/*.php') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            $key = basename($path, '.php');
            $this->registerFile($key, $path, $schemas[$key] ?? null);
        }
    }

    /**
     * 轮询入口：检测 mtime 变化并原子替换；返回是否发生了重载。
     * Polling entry: detects mtime changes and swaps atomically; returns whether a reload happened.
     */
    public function check(): bool
    {
        // 第一遍：收集待变更键与其新 mtime（删除文件的 false 也入列——加载阶段统一失败走回滚）
        // Pass 1: collect pending keys with their new mtimes (a deleted file's false joins too — the load phase fails uniformly into rollback)
        $pending = [];
        foreach ($this->files as $key => $path) {
            $mtime = ($this->mtimeReader)($path);
            if ($mtime !== $this->mtimes[$key]) {
                $pending[$key] = $mtime;
            }
        }

        if ($pending === []) {
            return false;
        }

        // 第二遍：全部加载进临时快照，任一失败整体放弃（旧配置原样保留 = 回滚）；
        // 带 schema 的键先过表结构校验，坏表同样走回滚（旧配置保留、不发事件、下次轮询自动重试——
        // 文件修复后校验通过即恢复热载）。
        // Pass 2: load everything into a temporary snapshot; any failure abandons the whole batch (old config kept
        // intact = rollback). Keys with a schema run table validation first — a rejected table rolls back the same
        // way (old config kept, no events, automatic retry on the next poll — hot reload resumes once the file is fixed).
        $loaded = [];
        foreach (array_keys($pending) as $key) {
            try {
                $items = Config::fromPhpFile($this->files[$key])->all();
            } catch (Throwable) {
                return false;
            }
            $schema = $this->schemas[$key] ?? null;
            if ($schema !== null) {
                if ($schema->errors($items) !== []) {
                    return false;
                }
                $items = $schema->normalized($items);
            }
            $loaded[$key] = $items;
        }

        // 原子替换：内存快照一次成型后整体换入，再逐文件发变更事件
        // Atomic swap: shape the in-memory snapshot in one go, then dispatch one change event per file
        $merged = $this->config?->all() ?? [];
        foreach ($loaded as $key => $items) {
            $merged[$key] = $items;
            $this->mtimes[$key] = $pending[$key];
        }
        $this->config = new Config($merged);

        foreach (array_keys($loaded) as $key) {
            $this->events->dispatch(self::EVENT_CHANGED, ['key' => $key, 'path' => $this->files[$key]]);
        }

        return true;
    }

    /**
     * 定时轮询装配：以持久定时器周期触发 check()（fork 后各进程独立驱动）。
     * Timed-polling assembly: a persistent timer triggers check() periodically (each process drives its own after fork).
     *
     * @param TimerInterface $timer 定时器 Timer.
     * @param float $intervalSeconds 轮询间隔（秒） Polling interval in seconds.
     */
    public function startPolling(TimerInterface $timer, float $intervalSeconds): void
    {
        $timer->add($intervalSeconds, $this->check(...), true);
    }

    /**
     * 注册期表结构校验：错误带行号渲染后抛 InvalidArgumentException（fail-fast）；
     * 校验通过返回 schema 归一化形态（optional 默认值已回填）。
     * Register-time table validation: on failure throws InvalidArgumentException rendered with line numbers
     * (fail-fast); on success returns the schema-normalized shape (optional defaults back-filled).
     *
     * @param array<mixed> $items 文件返回的原始数组 The raw array returned by the file.
     * @return array<mixed> 归一化数组 The normalized array.
     */
    private function validateOrFail(string $key, string $path, ConfigSchema $schema, array $items): array
    {
        $errors = $schema->errors($items);
        if ($errors !== []) {
            throw new InvalidArgumentException(ConfigSchema::renderErrors($errors, $key, $path));
        }

        $normalized = $schema->normalized($items);
        assert(is_array($normalized));

        return $normalized;
    }

    /**
     * 当前配置快照（未注册任何文件时为 null）。
     * The current config snapshot (null before any registration).
     */
    public function config(): ?Config
    {
        return $this->config;
    }

    /**
     * 点号路径读取（口径与 Config 一致；未注册任何文件时返回默认值）。
     * Dot-path read (same convention as Config; returns the default when nothing is registered).
     *
     * @param string $key 点号路径键 Dot-path key.
     * @param mixed $default 未命中时的默认值 The default value when missing.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 不用 ?? 兜底：键存在但值为 null 时必须原样返回 null（与 Config 口径一致）
        // No ?? fallback: a key holding null must return null as-is (same convention as Config)
        $config = $this->config;

        return $config === null ? $default : $config->get($key, $default);
    }

    /**
     * 键是否存在（含值为 null 的键；未注册任何文件时恒 false）。
     * Whether the key exists (null-valued keys included; always false before any registration).
     *
     * @param string $key 点号路径键 Dot-path key.
     */
    public function has(string $key): bool
    {
        return $this->config?->has($key) ?? false;
    }
}
