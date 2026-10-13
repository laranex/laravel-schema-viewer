<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Graph\ColumnType;
use Laranex\LaravelSchemaViewer\Schema\Column;

function typed(string $type, ?string $typeName = null): Column
{
    return new Column('c', $type, $typeName ?? (string) preg_replace('/[\s(].*$/', '', $type), false, null, false, null);
}

it('reduces a type to what decides the values it holds', function (string $driver, Column $column, array $expected): void {
    expect(ColumnType::normalize($driver, $column))->toBe($expected);
})->with([
    'sqlite integer' => ['sqlite', typed('integer'), ['base' => 'integer', 'unsigned' => false, 'params' => '']],
    'sqlite bigint' => ['sqlite', typed('bigint'), ['base' => 'integer', 'unsigned' => false, 'params' => '']],
    'sqlite varchar' => ['sqlite', typed('varchar(36)', 'varchar'), ['base' => 'text', 'unsigned' => false, 'params' => '']],
    'sqlite clob' => ['sqlite', typed('clob'), ['base' => 'text', 'unsigned' => false, 'params' => '']],
    'sqlite blob' => ['sqlite', typed('blob'), ['base' => 'blob', 'unsigned' => false, 'params' => '']],
    'sqlite no type' => ['sqlite', typed('', ''), ['base' => 'blob', 'unsigned' => false, 'params' => '']],
    'sqlite double' => ['sqlite', typed('double'), ['base' => 'real', 'unsigned' => false, 'params' => '']],
    'sqlite numeric' => ['sqlite', typed('numeric(8, 2)', 'numeric'), ['base' => 'numeric', 'unsigned' => false, 'params' => '']],
    'mysql bigint unsigned' => ['mysql', typed('bigint unsigned', 'bigint'), ['base' => 'bigint', 'unsigned' => true, 'params' => '']],
    'mariadb display width' => ['mariadb', typed('bigint(20) unsigned', 'bigint'), ['base' => 'bigint', 'unsigned' => true, 'params' => '']],
    'mysql varchar' => ['mysql', typed('varchar(255)', 'varchar'), ['base' => 'varchar', 'unsigned' => false, 'params' => '255']],
    'mysql decimal' => ['mysql', typed('decimal(8, 2)', 'decimal'), ['base' => 'decimal', 'unsigned' => false, 'params' => '8,2']],
    'pgsql int8' => ['pgsql', typed('bigint', 'int8'), ['base' => 'bigint', 'unsigned' => false, 'params' => '']],
    'pgsql int4' => ['pgsql', typed('integer', 'int4'), ['base' => 'integer', 'unsigned' => false, 'params' => '']],
    'pgsql bpchar' => ['pgsql', typed('character(2)', 'bpchar'), ['base' => 'char', 'unsigned' => false, 'params' => '2']],
    'pgsql varchar' => ['pgsql', typed('character varying(255)', 'varchar'), ['base' => 'varchar', 'unsigned' => false, 'params' => '255']],
    'pgsql float8' => ['pgsql', typed('double precision', 'float8'), ['base' => 'double', 'unsigned' => false, 'params' => '']],
    'sqlsrv nvarchar' => ['sqlsrv', typed('nvarchar(255)', 'nvarchar'), ['base' => 'nvarchar', 'unsigned' => false, 'params' => '255']],
    'sqlsrv bigint' => ['sqlsrv', typed('bigint'), ['base' => 'bigint', 'unsigned' => false, 'params' => '']],
]);

it('compares two columns by their reduced types', function (string $driver, Column $a, Column $b, bool $differ): void {
    expect(ColumnType::differ($driver, $a, $b))->toBe($differ);
})->with([
    'sqlite lengths do not matter' => ['sqlite', typed('varchar(36)', 'varchar'), typed('text'), false],
    'sqlite text and integer' => ['sqlite', typed('varchar', 'varchar'), typed('integer'), true],
    'mysql signed and unsigned' => ['mysql', typed('bigint', 'bigint'), typed('bigint unsigned', 'bigint'), true],
    'mysql lengths' => ['mysql', typed('varchar(36)', 'varchar'), typed('varchar(255)', 'varchar'), true],
    'mysql display width' => ['mariadb', typed('int(11)', 'int'), typed('int(10)', 'int'), false],
    'pgsql aliases' => ['pgsql', typed('bigint', 'int8'), typed('bigint', 'bigserial'), false],
    'pgsql integer and bigint' => ['pgsql', typed('integer', 'int4'), typed('bigint', 'int8'), true],
    'sqlsrv lengths' => ['sqlsrv', typed('nvarchar(36)', 'nvarchar'), typed('nvarchar(255)', 'nvarchar'), true],
]);
