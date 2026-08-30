<?php

declare(strict_types=1);

namespace Nythros\Framework\Make;

use InvalidArgumentException;
use RuntimeException;

/**
 * make:* 命令公共基类：位置参数 + --key=value 选项解析、模板读取、目标写入。
 * Common base for make:* commands: positional + --key=value parsing, template reading and target writing.
 */
abstract class MakeCommand
{
    /**
     * 解析位置参数与 --key=value 选项；多余位置参数报错。
     * Parses the positional argument and --key=value options; extra positional arguments are rejected.
     *
     * @param list<string> $args 命令行参数 Command-line arguments.
     *
     * @return array{name: ?string, options: array<string, string>}
     */
    protected function parseArgs(array $args): array
    {
        $name = null;
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = explode('=', substr($arg, 2), 2);
                if (count($pair) !== 2 || $pair[1] === '') {
                    throw new InvalidArgumentException(sprintf('选项格式应为 --key=value: %s', $arg));
                }
                $options[$pair[0]] = $pair[1];
            } elseif ($name === null) {
                $name = $arg;
            } else {
                throw new InvalidArgumentException(sprintf('多余的参数: %s', $arg));
            }
        }

        return ['name' => $name, 'options' => $options];
    }

    /**
     * 读取模板文件内容。
     * Reads a template file's contents.
     *
     * @param string $file 相对 templates/ 的模板路径 Template path relative to templates/.
     */
    protected function readTemplate(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/templates/' . $file;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('模板不存在: %s', $path));
        }

        return $contents;
    }

    /**
     * 校验标识符（类名/命名空间段）格式。
     * Validates an identifier (class name / namespace segment) format.
     *
     * @param string $name 待校验标识符 The identifier to validate.
     * @param string $label 参数名（错误提示用） The parameter name used in error messages.
     */
    protected function assertIdentifier(string $name, string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('%s非法: %s', $label, $name));
        }
    }

    /**
     * 校验命名空间格式（允许反斜杠分隔的多段）。
     * Validates a namespace format (multiple backslash-separated segments allowed).
     *
     * @param string $namespace 待校验命名空间 The namespace to validate.
     */
    protected function assertNamespace(string $namespace): void
    {
        $segments = explode('\\', $namespace);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(sprintf('命名空间非法（不得含空段）: %s', $namespace));
            }
            $this->assertIdentifier($segment, '命名空间段');
        }
    }

    /**
     * 读取可选数字选项；缺失时返回默认值。
     * Reads an optional numeric option; returns the default when absent.
     *
     * @param array<string, string> $options 已解析选项 Parsed options.
     * @param string $key 选项名 Option name.
     * @param float $default 默认值 Default value.
     */
    protected function numericOption(array $options, string $key, float $default): float
    {
        $value = $options[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s 必须是数字: %s', $key, $value));
        }

        return (float) $value;
    }

    /**
     * 确保目录存在（递归创建）。
     * Ensures a directory exists (created recursively).
     *
     * @param string $dir 目标目录 The target directory.
     */
    protected function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('无法创建目录: %s', $dir));
        }
    }

    /**
     * 写入文件（父目录自动创建）。
     * Writes a file, creating parent directories as needed.
     *
     * @param string $path 目标文件路径 The target file path.
     * @param string $contents 文件内容 The file contents.
     */
    protected function writeFile(string $path, string $contents): void
    {
        $this->ensureDir(dirname($path));
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('无法写入文件: %s', $path));
        }
    }

    /**
     * 读取 PHP 配置文件（require 返回数组）；文件不存在返回空数组。
     * Loads a PHP config file (require returning an array); an absent file yields an empty array.
     *
     * @param string $path 配置文件路径 The config file path.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $entries = require $path;
        if (!is_array($entries)) {
            throw new InvalidArgumentException(sprintf('配置文件未返回数组: %s', $path));
        }

        return $entries;
    }
}
