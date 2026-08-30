<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

use InvalidArgumentException;
use Nythros\Framework\Config\ConfigSchema;
use PHPUnit\Framework\TestCase;

/**
 * ConfigSchemaTest - 覆盖声明式配置表校验器：标量规则（类型/区间/长度/枚举）、结构规则（listOf/shape）、
 * optional 默认值回填与 normalized 归一化、未知字段拒绝、错误路径与行号渲染。
 * ConfigSchemaTest - covers the declarative config-table validator: scalar rules (type/range/length/enum),
 * structure rules (listOf/shape), optional default back-fill and normalized output, unknown-field rejection,
 * error paths and line-number rendering.
 */
final class ConfigSchemaTest extends TestCase
{
    public function testScalarRulesReportTypedErrors(): void
    {
        $schema = ConfigSchema::shape([
            'count' => ConfigSchema::int(min: 1, max: 10),
            'ratio' => ConfigSchema::float(min: 0.0, max: 1.0),
            'name' => ConfigSchema::string(minLength: 2),
            'kind' => ConfigSchema::enum('a', 'b'),
            'flag' => ConfigSchema::bool(),
        ]);

        $errors = $schema->errors([
            'count' => 0,
            'ratio' => 'half',
            'name' => 'x',
            'kind' => 'c',
            'flag' => 1,
        ]);

        self::assertSame([
            ['path' => 'count', 'message' => '应 ≥ 1'],
            ['path' => 'ratio', 'message' => '应为 float，实际 string("half")'],
            ['path' => 'name', 'message' => '长度应 ≥ 2，实际 1'],
            ['path' => 'kind', 'message' => '应为 a|b 之一，实际 string("c")'],
            ['path' => 'flag', 'message' => '应为 bool，实际 int(1)'],
        ], $errors);
    }

    public function testFloatAcceptsIntAndNormalizes(): void
    {
        $schema = ConfigSchema::shape(['ratio' => ConfigSchema::float(min: 0.0)]);

        $normalized = $schema->normalized(['ratio' => 2]);

        self::assertSame(['ratio' => 2.0], $normalized);
    }

    public function testShapeRequiredOptionalAndNullable(): void
    {
        $schema = ConfigSchema::shape([
            'must' => ConfigSchema::int(),
            'opt' => ConfigSchema::int()->optional(7),
            'nil' => ConfigSchema::string()->nullable()->optional(null),
        ]);

        // 缺省回填：optional 字段缺失时以声明默认值补齐
        // Default back-fill: a missing optional field is filled with its declared default
        $normalized = $schema->normalized(['must' => 1]);
        self::assertSame(['must' => 1, 'opt' => 7, 'nil' => null], $normalized);

        // 必填缺失即报错，路径精确到字段
        // A missing required field errors with a field-precise path
        $errors = $schema->errors([]);
        self::assertSame([['path' => 'must', 'message' => '缺失（必填字段）']], $errors);
    }

    public function testUnknownFieldRejectedUnlessAllowed(): void
    {
        $schema = ConfigSchema::shape(['a' => ConfigSchema::int()]);

        self::assertSame(
            [['path' => 'b', 'message' => '未知字段（表结构未声明）']],
            $schema->errors(['a' => 1, 'b' => 2]),
        );

        $lenient = ConfigSchema::shape(['a' => ConfigSchema::int()], allowUnknownFields: true);
        self::assertSame([], $lenient->errors(['a' => 1, 'b' => 2]));
    }

    public function testListOfValidatesItemsWithIndexedPaths(): void
    {
        $schema = ConfigSchema::listOf(ConfigSchema::shape(['hp' => ConfigSchema::int(min: 1)]), minItems: 1, maxItems: 3);

        self::assertSame(
            [['path' => '1.hp', 'message' => '应 ≥ 1']],
            $schema->errors([['hp' => 5], ['hp' => 0]]),
        );

        self::assertSame(
            [['path' => '', 'message' => '条目数应 ≤ 3，实际 4']],
            $schema->errors([[ 'hp' => 1], ['hp' => 1], ['hp' => 1], ['hp' => 1]]),
        );

        // 非顺序列表（字符串键）即非法——内容表必须是行列表
        // A non-list (string-keyed) array is invalid — content tables must be row lists
        self::assertSame(
            [['path' => '', 'message' => '应为顺序列表（array_is_list），实际 array(1 项)']],
            $schema->errors(['row-1' => ['hp' => 1]]),
        );
    }

    public function testNestedPathsAndNullables(): void
    {
        $schema = ConfigSchema::listOf(ConfigSchema::shape([
            'anchor' => ConfigSchema::shape([
                'x' => ConfigSchema::int(),
                'y' => ConfigSchema::int(),
            ]),
            'note' => ConfigSchema::string()->nullable(),
        ]));

        $errors = $schema->errors([
            ['anchor' => ['x' => 1, 'y' => 'east'], 'note' => null],
        ]);

        self::assertSame([['path' => '0.anchor.y', 'message' => '应为 int，实际 string("east")']], $errors);
    }

    public function testNormalizedThrowsWhenCalledWithoutValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConfigSchema::int()->normalized('nope');
    }

    public function testRenderErrorsLocatesLineNumbers(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nythros_schema_');
        file_put_contents($file, "<?php return [\n  'monsters' => [\n    ['hp' => -1],\n  ],\n];\n");

        try {
            $schema = ConfigSchema::shape([
                'monsters' => ConfigSchema::listOf(ConfigSchema::shape(['hp' => ConfigSchema::int(min: 1)])),
            ]);
            $message = ConfigSchema::renderErrors($schema->errors(require $file), 'config', $file);

            // 行号 3：['hp' => -1] 所在行（HP 违规值所在行被精确定位）
            // Line 3: the ['hp' => -1] row (the offending value's line is precisely located)
            self::assertStringContainsString('第 3 行 monsters.0.hp：应 ≥ 1', $message);
            self::assertStringContainsString('monsters', $message);
        } finally {
            @unlink($file);
        }
    }
}
