<?php

declare(strict_types=1);

namespace Nythros\Framework\Config;

/**
 * PHP 数组配置文件的「路径 → 行号」映射（P11 schema 校验的行号定位器）：用 tokenizer 扫描源码，
 * 对短数组语法 `[...]` 内的键与标量值记录其点号路径对应源码行，供坏表报错指出行号。
 * A "path → line" map for PHP-array config files (the P11 schema-validation line locator): tokenizes the source
 * and records the dot-path → source line of every key and scalar value inside short-array `[...]` syntax, so a
 * rejected table's errors can point at line numbers.
 *
 * 口径（尽力而为的静态扫描，不求完整 PHP 语法）：
 * - 只识别短数组 `[` / `]`；`array(...)` 长语法与函数调用表达式不展开（路径缺失 → 报错不带行号，不误标）。
 * - 值定位按「首个出现」记线（??=）：同一路径多次出现时保留首个。
 * - 注释/空白跳过；heredoc 内文本不参与（不是标量 token 形态）。
 * Conventions (a best-effort static scan, not a full PHP grammar):
 * - Only short-array `[` / `]` is recognized; the long `array(...)` syntax and call expressions are not expanded
 *   (a missing path renders the error without a line number, never a wrong one).
 * - Values record the first occurrence (`??=`): when a path appears multiple times the first line wins.
 * - Comments/whitespace are skipped; heredoc bodies take no part (not scalar-token shaped).
 */
final class ConfigSourceLines
{
    /** @var array<string, int> 点号路径 => 源码行号（1 起） dot path => source line (1-based). */
    private array $lines;

    /** @param array<string, int> $lines 点号路径 => 行号 dot path => line */
    private function __construct(array $lines)
    {
        $this->lines = $lines;
    }

    /**
     * 从源码构建映射。
     * Builds the map from source code.
     */
    public static function build(string $source): self
    {
        $tokens = token_get_all($source);
        $lines = [];

        // 数组帧栈（平行数组：帧内路径前缀 + 自动索引计数器）：首帧为根哨兵——根级 return 数组本身即
        // 根路径，不消耗自动索引，用「栈深 1」判定。
        // The array-frame stack (parallel arrays: each frame's path prefix + auto-index counter): the first frame is
        // the root sentinel — the top-level return array IS the root path and consumes no auto index, detected by stack depth 1.
        /** @var list<string> $framePaths 帧路径前缀栈（与 $frameAutos 同下标） Frame path-prefix stack (indexed with $frameAutos). */
        $framePaths = [''];
        /** @var list<int> $frameAutos 帧自动索引栈（与 $framePaths 同下标） Frame auto-index stack (indexed with $framePaths). */
        $frameAutos = [0];
        $pendingKey = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                // 非数组 token：`[` 压帧 / `]` 弹帧；其余（, ; ( ) 等）忽略
                // Non-array tokens: `[` pushes a frame / `]` pops one; the rest (, ; ( ) ...) are ignored.
                if ($token === '[') {
                    $top = count($framePaths) - 1;
                    $parentPath = $framePaths[$top];
                    if ($pendingKey !== null) {
                        $framePaths[] = self::childPath($parentPath, $pendingKey);
                        $frameAutos[] = 0;
                        // 显式键帧的行号已在键 token 处记录，压帧时无需重复
                        // An explicit-key frame's line was already recorded at the key token; no re-record on push.
                        $pendingKey = null;
                    } elseif ($top === 0) {
                        // 根级数组：即根配置本身，路径取父帧前缀（''）
                        // The root-level array: the root config itself; the path takes the parent frame's prefix ('').
                        $framePaths[] = $parentPath;
                        $frameAutos[] = 0;
                        $lines[$parentPath] ??= self::tokenLine($token, $tokens, $i);
                    } else {
                        // 无键列表帧不记行号——留给首个内层标量，避免行号回看落到上一列表项的尾部值（差一行）
                        // A key-less list frame records no line — the first inner scalar does, avoiding the look-back
                        // landing on the previous list item's trailing value (one line off).
                        $framePaths[] = self::childPath($parentPath, (string) $frameAutos[$top]);
                        $frameAutos[] = 0;
                        ++$frameAutos[$top];
                    }
                } elseif ($token === ']' && count($framePaths) > 1) {
                    array_pop($framePaths);
                    array_pop($frameAutos);
                }

                continue;
            }

            [$id, $text, $line] = $token;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_LNUMBER || $id === T_DNUMBER) {
                $next = self::nextMeaningfulToken($tokens, $i);
                $isKey = is_array($next) && $next[0] === T_DOUBLE_ARROW;
                $top = count($framePaths) - 1;
                if ($isKey) {
                    // 键：记键路径行（供行级错误定位），随后作为待配对键等待值
                    // Key: records the key path's line (for row-level errors), then waits as the pending key.
                    $pendingKey = self::decodeScalarToken($text);
                    $lines[self::childPath($framePaths[$top], $pendingKey)] ??= $line;

                    continue;
                }
                // 值：显式键消费 pendingKey，否则走自动索引；记值路径行
                // Value: an explicit key consumes the pending key, otherwise the auto index applies; records the value path's line.
                if ($pendingKey !== null) {
                    $key = $pendingKey;
                    $pendingKey = null;
                } else {
                    $key = (string) $frameAutos[$top];
                    ++$frameAutos[$top];
                }
                $lines[self::childPath($framePaths[$top], $key)] ??= $line;
            }
        }

        return new self($lines);
    }

    /**
     * 从文件构建映射；文件不可读返回 null（行号定位是尽力而为，不阻塞主流程）。
     * Builds the map from a file; returns null when unreadable (line resolution is best-effort, never blocking).
     */
    public static function forFile(string $path): ?self
    {
        $source = @file_get_contents($path);

        return $source === false ? null : self::build($source);
    }

    /**
     * 精确路径定位行号；未命中时逐段向上回退（monsters.2.anchor.x → monsters.2.anchor → monsters.2 → monsters），
     * 全部落空返回 null。
     * Resolves a line by exact path; on miss falls back segment by segment toward the root
     * (monsters.2.anchor.x → monsters.2.anchor → monsters.2 → monsters); returns null when all miss.
     */
    public function lineFor(string $path): ?int
    {
        $candidate = $path;
        while ($candidate !== '') {
            if (isset($this->lines[$candidate])) {
                return $this->lines[$candidate];
            }
            $dot = strrpos($candidate, '.');
            $candidate = $dot === false ? '' : substr($candidate, 0, $dot);
        }

        return null;
    }

    /**
     * 已解析路径数（测试断言用）。
     * The resolved-path count (for test assertions).
     */
    public function count(): int
    {
        return count($this->lines);
    }

    /**
     * 取下一个有效 token（跳过空白与注释）。
     * Returns the next meaningful token (skipping whitespace and comments).
     *
     * @param list<array{int, string, int}|string> $tokens
     * @return array{int, string, int}|string|null
     */
    private static function nextMeaningfulToken(array $tokens, int $from): array|string|null
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * 压帧时的行号回看：`[` token 本身可能是字符串形式的标点 token（无行号），
     * 向前找最近一个带行号的 token 兜底。
     * Line look-back on frame push: the `[` token may arrive as a plain punctuation string (no line),
     * so the nearest line-bearing token before it is the fallback.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function tokenLine(string $token, array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            $candidate = $tokens[$i];
            if (is_array($candidate)) {
                return $candidate[2];
            }
        }

        return 1;
    }

    /**
     * 标量 token 文本还原为键名（去引号 + 反转义；数字键原样）。
     * Restores a scalar token's text into a key name (unquote + unescape; numeric keys as-is).
     */
    private static function decodeScalarToken(string $text): string
    {
        if (strlen($text) >= 2 && ($text[0] === "'" || $text[0] === '"')) {
            $inner = substr($text, 1, -1);

            return $text[0] === "'" ? str_replace("\\'", "'", $inner) : stripslashes($inner);
        }

        return $text;
    }

    private static function childPath(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }
}
