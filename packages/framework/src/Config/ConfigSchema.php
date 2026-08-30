<?php

declare(strict_types=1);

namespace Nythros\Framework\Config;

use InvalidArgumentException;
use LogicException;

/**
 * 声明式配置表校验器（P11 玩法数据外置的地基）：以类型/区间/枚举/形状规则描述一张配置表的合法形态，
 * 校验失败返回结构化错误（路径 + 消息），由调用方（ConfigRepository/装配层）决定 fail-fast 还是回滚。
 * Declarative config-table validator (the groundwork of the P11 data-externalization batch): describes a table's
 * legal shape with type/range/enum/structure rules; validation failures return structured errors (path + message)
 * and the caller (ConfigRepository / assembly) decides fail-fast vs rollback.
 *
 * 两类节点：标量规则（int/float/string/bool/enum）与结构规则（listOf/shape）。shape 字段缺省必填，
 * `optional(默认值)` 放开为可选并在校验产出中回填默认值（normalized）；`nullable()` 放开 null。
 * 未知字段恒拒绝（allowUnknownFields 显式放开）——内容表字段名拼写错误必须在装配期暴露，而非静默忽略。
 * Two node families: scalar rules (int/float/string/bool/enum) and structure rules (listOf/shape). Shape fields are
 * required by default; `optional(default)` relaxes that and back-fills the default into the normalized output;
 * `nullable()` relaxes null. Unknown fields are always rejected unless allowUnknownFields is set explicitly — a
 * typo'd field name in a content table must surface at assembly time, never be silently ignored.
 */
final class ConfigSchema
{
    private const KIND_INT = 'int';
    private const KIND_FLOAT = 'float';
    private const KIND_STRING = 'string';
    private const KIND_BOOL = 'bool';
    private const KIND_ENUM = 'enum';
    private const KIND_LIST = 'list';
    private const KIND_SHAPE = 'shape';

    /**
     * @param bool $hasDefault 是否声明过默认值（optional() 置位） Whether a default was declared (set by optional()).
     * @param int|float|null $min 数值下界（int/float 用） Numeric lower bound (int/float).
     * @param int|float|null $max 数值上界（int/float 用） Numeric upper bound (int/float).
     * @param int|null $minLength 字符串最小长度（string 用） String minimum length (string).
     * @param string|null $pattern 字符串正则约束（string 用） String regex constraint (string).
     * @param list<string>|null $enumValues 枚举合法值（enum 用） Legal enum values (enum).
     * @param self|null $itemSchema 列表元素规则（listOf 用） List item rule (listOf).
     * @param int|null $minItems 列表最小长度（listOf 用） List minimum length (listOf).
     * @param int|null $maxItems 列表最大长度（listOf 用） List maximum length (listOf).
     * @param array<string, self>|null $fields 形状字段（shape 用） Shape fields (shape).
     * @param bool $allowUnknownFields 是否放过未声明字段 Whether undeclared fields are tolerated.
     * @param bool $required shape 内是否必填 Required inside a shape.
     * @param bool $nullable 是否接受 null Whether null is accepted.
     * @param mixed $default optional() 声明的默认值 The default declared via optional().
     */
    private function __construct(
        private readonly string $kind,
        private readonly bool $hasDefault = false,
        private readonly int|float|null $min = null,
        private readonly int|float|null $max = null,
        private readonly ?int $minLength = null,
        private readonly ?string $pattern = null,
        private readonly ?array $enumValues = null,
        private readonly ?self $itemSchema = null,
        private readonly ?int $minItems = null,
        private readonly ?int $maxItems = null,
        private readonly ?array $fields = null,
        private readonly bool $allowUnknownFields = false,
        private readonly bool $required = true,
        private readonly bool $nullable = false,
        private readonly mixed $default = null,
    ) {
    }

    /**
     * 整数规则（可附区间）。
     * An integer rule (range optional).
     */
    public static function int(int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): self
    {
        return new self(self::KIND_INT, min: $min, max: $max);
    }

    /**
     * 浮点规则（可附区间；整数值自动归一为 float）。
     * A float rule (range optional; integer values normalize to float).
     */
    public static function float(float $min = -PHP_FLOAT_MAX, float $max = PHP_FLOAT_MAX): self
    {
        return new self(self::KIND_FLOAT, min: $min, max: $max);
    }

    /**
     * 字符串规则（可附最小长度与正则约束）。
     * A string rule (minimum length and regex constraint optional).
     */
    public static function string(int $minLength = 0, ?string $pattern = null): self
    {
        return new self(self::KIND_STRING, minLength: $minLength, pattern: $pattern);
    }

    /**
     * 布尔规则。
     * A boolean rule.
     */
    public static function bool(): self
    {
        return new self(self::KIND_BOOL);
    }

    /**
     * 字符串枚举规则（白名单集合）。
     * A string-enum rule (whitelist set).
     */
    public static function enum(string ...$values): self
    {
        return new self(self::KIND_ENUM, enumValues: array_values($values));
    }

    /**
     * 顺序列表规则（元素逐个按 itemSchema 校验；要求 array_is_list）。
     * A sequential-list rule (each item validated against itemSchema; requires array_is_list).
     */
    public static function listOf(self $itemSchema, ?int $minItems = null, ?int $maxItems = null): self
    {
        return new self(self::KIND_LIST, itemSchema: $itemSchema, minItems: $minItems, maxItems: $maxItems);
    }

    /**
     * 形状规则（关联数组：字段名 => 字段规则；未知字段恒拒绝，除非 allowUnknownFields）。
     * A shape rule (an associative array: field name => field rule; unknown fields always rejected unless allowUnknownFields).
     *
     * @param array<string, self> $fields 字段规则集 The field rules.
     */
    public static function shape(array $fields, bool $allowUnknownFields = false): self
    {
        return new self(self::KIND_SHAPE, fields: $fields, allowUnknownFields: $allowUnknownFields);
    }

    /**
     * 放开为可选字段：缺省时以声明的默认值回填（仅 shape 内有意义）。
     * Relaxes the field to optional: back-fills the declared default when absent (meaningful only inside a shape).
     */
    public function optional(mixed $default): self
    {
        return new self(
            $this->kind,
            hasDefault: true,
            min: $this->min,
            max: $this->max,
            minLength: $this->minLength,
            pattern: $this->pattern,
            enumValues: $this->enumValues,
            itemSchema: $this->itemSchema,
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            fields: $this->fields,
            allowUnknownFields: $this->allowUnknownFields,
            required: false,
            nullable: $this->nullable,
            default: $default,
        );
    }

    /**
     * 接受 null（校验通过且原样透传，不参与默认值回填）。
     * Accepts null (passes validation and passes through as-is; takes no part in default back-fill).
     */
    public function nullable(): self
    {
        return new self(
            $this->kind,
            hasDefault: $this->hasDefault,
            min: $this->min,
            max: $this->max,
            minLength: $this->minLength,
            pattern: $this->pattern,
            enumValues: $this->enumValues,
            itemSchema: $this->itemSchema,
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            fields: $this->fields,
            allowUnknownFields: $this->allowUnknownFields,
            required: $this->required,
            nullable: true,
            default: $this->default,
        );
    }

    /**
     * 纯校验：返回结构化错误列表（path => 定位点、message => 违规原因），空列表 = 合法。
     * Pure validation: returns structured errors (path locates, message explains); an empty list means valid.
     *
     * @return list<array{path: string, message: string}>
     */
    public function errors(mixed $value, string $path = ''): array
    {
        $errors = [];
        $this->normalize($value, $path, $errors);

        return $errors;
    }

    /**
     * 归一化产出：校验通过后返回回填默认值的副本（config 消费方拿到的即该形态）；未先通过 errors() 校验
     * 直接调用时抛异常兜底（防静默吃掉坏表）。
     * Normalized output: after validation passes, returns a default-back-filled copy (the shape config consumers
     * read); throws as a backstop when called without passing errors() first (a bad table must never be swallowed).
     */
    public function normalized(mixed $value, string $path = ''): mixed
    {
        $errors = [];
        $normalized = $this->normalize($value, $path, $errors);
        if ($errors !== []) {
            throw new InvalidArgumentException(sprintf(
                '配置校验失败（%s 处）：首条 %s',
                count($errors),
                self::renderOne($errors[0], null),
            ));
        }

        return $normalized;
    }

    /**
     * 错误渲染（带行号定位）：按错误路径在源文件里定位行号，定位不到则不带行号输出。
     * Error rendering (line-located): resolves each error's line in the source file; errors without a resolvable
     * line render without one.
     *
     * @param list<array{path: string, message: string}> $errors 结构化错误 Structured errors.
     * @param string $key 配置键（消息标识用） The config key (for message identification).
     * @param string $file 源文件路径（用于行号定位） Source file path (for line resolution).
     */
    public static function renderErrors(array $errors, string $key, string $file): string
    {
        $lines = ConfigSourceLines::forFile($file);

        return sprintf('配置校验失败: %s (%s)（%d 处）%s', $key, $file, count($errors), implode('', array_map(
            static fn (array $error): string => "\n  - " . self::renderOne($error, $lines),
            $errors,
        )));
    }

    /**
     * 单条错误渲染：行号（可定位时）+ 路径 + 消息。
     * Renders one error: line (when resolvable) + path + message.
     *
     * @param array{path: string, message: string} $error 结构化错误 The structured error.
     */
    private static function renderOne(array $error, ?ConfigSourceLines $lines): string
    {
        $line = $lines?->lineFor((string) $error['path']);

        return sprintf('%s%s', $line === null ? '' : sprintf('第 %d 行 ', $line), sprintf('%s：%s', $error['path'] === '' ? '<root>' : $error['path'], $error['message']));
    }

    /**
     * 递归校验 + 归一化：产出回填默认值的副本，同时收集结构化错误（调用方二选一消费）。
     * Recursive validate + normalize: produces a default-back-filled copy while collecting structured errors
     * (the caller consumes either side).
     *
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalize(mixed $value, string $path, array &$errors): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        return match ($this->kind) {
            self::KIND_INT => $this->normalizeInt($value, $path, $errors),
            self::KIND_FLOAT => $this->normalizeFloat($value, $path, $errors),
            self::KIND_STRING => $this->normalizeString($value, $path, $errors),
            self::KIND_BOOL => $this->normalizeBool($value, $path, $errors),
            self::KIND_ENUM => $this->normalizeEnum($value, $path, $errors),
            self::KIND_LIST => $this->normalizeList($value, $path, $errors),
            self::KIND_SHAPE => $this->normalizeShape($value, $path, $errors),
            default => throw new LogicException(sprintf('未知校验规则类型: %s', $this->kind)),
        };
    }

    /**
     * @return list<string> 区间违规消息（空 = 无违规） Range-violation messages (empty = none).
     */
    private function rangeViolations(int|float $value): array
    {
        $violations = [];
        if ($this->min !== null && $value < $this->min) {
            $violations[] = sprintf('应 ≥ %s', self::describeBound($this->min));
        }
        if ($this->max !== null && $value > $this->max) {
            $violations[] = sprintf('应 ≤ %s', self::describeBound($this->max));
        }

        return $violations;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeInt(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_int($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为 int，实际 %s', self::describe($value))];

            return $value;
        }
        foreach ($this->rangeViolations($value) as $violation) {
            $errors[] = ['path' => $path, 'message' => $violation];
        }

        return $value;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeFloat(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_int($value) && !is_float($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为 float，实际 %s', self::describe($value))];

            return $value;
        }
        foreach ($this->rangeViolations($value) as $violation) {
            $errors[] = ['path' => $path, 'message' => $violation];
        }

        return (float) $value;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeString(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_string($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为 string，实际 %s', self::describe($value))];

            return $value;
        }
        if ($this->minLength !== null && strlen($value) < $this->minLength) {
            $errors[] = ['path' => $path, 'message' => sprintf('长度应 ≥ %d，实际 %d', $this->minLength, strlen($value))];
        }
        if ($this->pattern !== null && preg_match($this->pattern, $value) !== 1) {
            $errors[] = ['path' => $path, 'message' => sprintf('应匹配 %s，实际 "%s"', $this->pattern, $value)];
        }

        return $value;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeBool(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_bool($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为 bool，实际 %s', self::describe($value))];

            return $value;
        }

        return $value;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeEnum(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_string($value) || !in_array($value, $this->enumValues ?? [], true)) {
            $errors[] = ['path' => $path, 'message' => sprintf(
                '应为 %s 之一，实际 %s',
                implode('|', $this->enumValues ?? []),
                self::describe($value),
            )];

            return $value;
        }

        return $value;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeList(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为顺序列表（array_is_list），实际 %s', self::describe($value))];

            return $value;
        }
        if ($this->minItems !== null && count($value) < $this->minItems) {
            $errors[] = ['path' => $path, 'message' => sprintf('条目数应 ≥ %d，实际 %d', $this->minItems, count($value))];
        }
        if ($this->maxItems !== null && count($value) > $this->maxItems) {
            $errors[] = ['path' => $path, 'message' => sprintf('条目数应 ≤ %d，实际 %d', $this->maxItems, count($value))];
        }

        $itemSchema = $this->itemSchema;
        if ($itemSchema === null) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $index => $item) {
            $normalized[] = $itemSchema->normalize($item, self::childPath($path, (string) $index), $errors);
        }

        return $normalized;
    }

    /**
     * @param list<array{path: string, message: string}> $errors 结构化错误收集器（引用累积） The structured-error collector (accumulated by reference).
     */
    private function normalizeShape(mixed $value, string $path, array &$errors): mixed
    {
        if (!is_array($value)) {
            $errors[] = ['path' => $path, 'message' => sprintf('应为 object（关联数组），实际 %s', self::describe($value))];

            return $value;
        }

        $fields = $this->fields ?? [];
        $normalized = $value;
        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $value)) {
                $normalized[$name] = $field->normalize($value[$name], self::childPath($path, $name), $errors);

                continue;
            }
            if ($field->hasDefault) {
                $normalized[$name] = $field->default;

                continue;
            }
            if ($field->required) {
                $errors[] = ['path' => self::childPath($path, $name), 'message' => '缺失（必填字段）'];
            }
        }

        if (!$this->allowUnknownFields) {
            foreach (array_keys($value) as $key) {
                if (is_int($key)) {
                    $errors[] = ['path' => self::childPath($path, (string) $key), 'message' => '形状内出现数字键（应为字段名）'];

                    continue;
                }
                if (!array_key_exists($key, $fields)) {
                    $errors[] = ['path' => self::childPath($path, $key), 'message' => '未知字段（表结构未声明）'];
                }
            }
        }

        return $normalized;
    }

    /**
     * 子路径拼接：根路径为空串时直接用子段，否则点号连接。
     * Joins a child path: uses the segment directly at the empty root, dot-joins otherwise.
     */
    private static function childPath(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }

    /**
     * 值类型简述（错误消息用）：标量带值、复合只报形态与规模。
     * A short value description (for error messages): scalars carry the value, composites report shape and size only.
     */
    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return sprintf('array(%d 项)', count($value));
        }

        return self::describeScalar($value);
    }

    private static function describeScalar(int|float|string|bool|null $value): string
    {
        if (is_string($value)) {
            $clipped = strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value;

            return sprintf('string("%s")', $clipped);
        }

        return match (true) {
            is_int($value) => sprintf('int(%d)', $value),
            is_float($value) => sprintf('float(%s)', (string) $value),
            is_bool($value) => sprintf('bool(%s)', $value ? 'true' : 'false'),
            default => 'null',
        };
    }

    /**
     * 区间边界渲染（纯数字，不带类型前缀——错误消息读感优先）。
     * Renders a range bound (a bare number, no type prefix — error-message readability first).
     */
    private static function describeBound(int|float $bound): string
    {
        $rendered = (string) $bound;

        return str_contains($rendered, '.') ? rtrim(rtrim($rendered, '0'), '.') : $rendered;
    }
}
