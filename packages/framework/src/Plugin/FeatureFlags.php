<?php

declare(strict_types=1);

namespace Nythros\Framework\Plugin;

/**
 * 能力开关表（声明式装配的第一块地基）：把「哪些能力启用」从代码注释里解放出来。
 * 两级判定（优先级从高到低）：
 * 1. 单能力覆盖 —— NYTHROS_FEATURE_<NAME 大写>=0/1（如 NYTHROS_FEATURE_MMORPG=0），白名单/默认都压不过它;
 * 2. 白名单 —— NYTHROS_FEATURES="quest,combat,mail"：一旦设置，未列出的能力默认关闭;
 * 3. 缺省 —— 无以上配置时全部启用（与接入前逐行为等价，存量部署零影响）。
 * Feature flag table (the first foundation of declarative assembly): lifts "which capabilities are on" out of
 * commented-out code. Two precedence tiers: per-feature override (NYTHROS_FEATURE_<NAME>=0/1 beats everything),
 * whitelist (once NYTHROS_FEATURES is set, unlisted features default off), and default-on otherwise (byte-for-byte
 * equivalent to pre-integration behavior — zero impact on existing deployments).
 *
 * 消费方是 PluginRegistry（FeaturePluginInterface 探测到能力名的插件走开关判定）；组装层也可注入
 * 自建表（fromWhitelist）实现配置文件驱动。类不读全局——环境变量只在 fromEnvironment() 一处解析。
 * Consumed by PluginRegistry (plugins exposing FeaturePluginInterface consult it); assemblies may build their
 * own table (fromWhitelist) for config-file driven wiring. No global reads outside fromEnvironment().
 */
final class FeatureFlags
{
    /** 白名单 env 键（逗号分隔的能力名列表） Whitelist env key (comma-separated feature names). */
    public const ENV_WHITELIST = 'NYTHROS_FEATURES';

    /** 单能力覆盖 env 前缀（NYTHROS_FEATURE_<UPPERNAME>=0|1） Per-feature override env prefix. */
    public const ENV_OVERRIDE_PREFIX = 'NYTHROS_FEATURE_';

    /**
     * @param list<string>|null $whitelist null = 未启用白名单（全默认开）；[] = 显式空白名单（全默认关）
     *                                     null = whitelist unset (everything defaults on); [] = explicit empty (all off)
     * @param array<string, bool> $overrides 能力名 => 显式开/关（优先级最高） feature name => explicit on/off (top priority)
     */
    public function __construct(
        private readonly ?array $whitelist,
        private readonly array $overrides = [],
    ) {
    }

    /**
     * 从进程环境变量解析（组装入口，Workerman 常驻进程启动期读一次）。
     * Build from process environment (the assembly entry, read once during boot under Workerman).
     */
    public static function fromEnvironment(): self
    {
        $raw = getenv(self::ENV_WHITELIST);
        $whitelist = null;
        if (is_string($raw)) {
            $whitelist = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
        }

        $overrides = [];
        foreach ((array) $_ENV + (array) $_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value) || !str_starts_with($key, self::ENV_OVERRIDE_PREFIX)) {
                continue;
            }
            $feature = strtolower(substr($key, strlen(self::ENV_OVERRIDE_PREFIX)));
            if ($feature === '' || !preg_match('/^[a-z0-9_-]+$/', $feature)) {
                continue;
            }
            $overrides[$feature] = ($value === '1' || strtolower($value) === 'true');
        }

        return new self($whitelist, $overrides);
    }

    /**
     * 该能力是否启用。无覆盖、无白名单时按 $default（缺省开）。
     * Whether a feature is enabled: overrides, then whitelist, then the default (on).
     */
    public function isEnabled(string $feature, bool $default = true): bool
    {
        if (array_key_exists($feature, $this->overrides)) {
            return $this->overrides[$feature];
        }

        if ($this->whitelist !== null) {
            return in_array($feature, $this->whitelist, true);
        }

        return $default;
    }

    /**
     * 白名单（null=未启用白名单）。只读观测面（测试/诊断）。
     * The whitelist (null = unset). Read-only observation for tests/diagnostics.
     *
     * @return list<string>|null
     */
    public function whitelist(): ?array
    {
        return $this->whitelist;
    }
}
