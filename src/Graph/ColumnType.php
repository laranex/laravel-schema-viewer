<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Graph;

use Laranex\LaravelSchemaViewer\Schema\Column;

/**
 * A column's type reduced to what decides whether two columns hold the same values: the base type, whether it is
 * unsigned, and its parameters (length, precision). Spellings that mean the same type compare equal.
 */
final class ColumnType
{
    /**
     * MySQL and MariaDB integer types, whose parameter is only a display width.
     */
    private const MYSQL_INTEGERS = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    /**
     * PostgreSQL's names for the same type.
     */
    private const PGSQL_ALIASES = [
        'int8' => 'bigint', 'bigserial' => 'bigint', 'serial8' => 'bigint',
        'int4' => 'integer', 'int' => 'integer', 'serial' => 'integer', 'serial4' => 'integer',
        'int2' => 'smallint', 'smallserial' => 'smallint', 'serial2' => 'smallint',
        'bpchar' => 'char', 'character' => 'char',
        'character varying' => 'varchar',
        'float8' => 'double', 'double precision' => 'double',
        'float4' => 'real',
        'bool' => 'boolean',
        'timestamptz' => 'timestamp with time zone',
    ];

    /**
     * @return array{base: string, unsigned: bool, params: string}
     */
    public static function normalize(string $driver, Column $column): array
    {
        $type = strtolower(trim($column->type));
        $typeName = strtolower(trim($column->typeName));

        return match ($driver) {
            'sqlite' => ['base' => self::affinity($type), 'unsigned' => false, 'params' => ''],
            'mysql', 'mariadb' => [
                'base' => $typeName,
                'unsigned' => str_contains($type, 'unsigned'),
                'params' => in_array($typeName, self::MYSQL_INTEGERS, true) ? '' : self::params($type),
            ],
            'pgsql' => ['base' => self::PGSQL_ALIASES[$typeName] ?? $typeName, 'unsigned' => false, 'params' => self::params($type)],
            default => ['base' => $typeName, 'unsigned' => false, 'params' => self::params($type)],
        };
    }

    /**
     * Whether two columns hold different kinds of values.
     */
    public static function differ(string $driver, Column $a, Column $b): bool
    {
        return self::normalize($driver, $a) !== self::normalize($driver, $b);
    }

    /**
     * SQLite's type affinity, by its rules: lengths and spellings do not matter.
     */
    private static function affinity(string $type): string
    {
        return match (true) {
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'char') || str_contains($type, 'clob') || str_contains($type, 'text') => 'text',
            $type === '' || str_contains($type, 'blob') => 'blob',
            str_contains($type, 'real') || str_contains($type, 'floa') || str_contains($type, 'doub') => 'real',
            default => 'numeric',
        };
    }

    /**
     * The parameters in the first parentheses, without spaces: "255", "8,2".
     */
    private static function params(string $type): string
    {
        return preg_match('/\(([^)]*)\)/', $type, $match) === 1 ? str_replace(' ', '', $match[1]) : '';
    }
}
