<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Laranex\LaravelSchemaViewer\Schema\Column;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\ForeignKey;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * Reads every table of a connection through Laravel's schema builder.
 */
final class DatabaseInspector
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /**
     * @param  list<string>  $exclude  Table name patterns never shown.
     * @param  list<string>  $framework  Table name patterns tagged as framework tables.
     * @param  array<string, list<string>>  $packages  Table name patterns tagged with the package that owns them, by package name.
     */
    public function inspect(string $name, array $exclude = [], array $framework = [], array $packages = []): ConnectionSchema
    {
        /** @var Connection $connection */
        $connection = $this->connections->connection($name);
        $builder = $connection->getSchemaBuilder();
        $prefix = $connection->getTablePrefix();

        // Laravel 12+ lists every schema; keep the ones on the search path, like unqualified queries see.
        $schemas = $this->call($builder, 'getCurrentSchemaListing');
        $defaultSchema = $this->call($builder, 'getCurrentSchemaName');

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($schemas) && $schemas !== [] ? $this->call($builder, 'getTables', $schemas) : $builder->getTables();

        $tables = [];

        foreach ($rows as $row) {
            $table = $this->unprefixed((string) $row['name'], $prefix);

            if ($table === null) {
                continue;
            }

            $qualified = $this->qualify($table, isset($row['schema']) ? (string) $row['schema'] : null, is_string($defaultSchema) ? $defaultSchema : null);

            if (Str::is($exclude, $qualified)) {
                continue;
            }

            $tables[] = new Table(
                name: $qualified,
                comment: isset($row['comment']) && $row['comment'] !== '' ? (string) $row['comment'] : null,
                framework: Str::is($framework, $qualified),
                columns: $this->columns($builder->getColumns($qualified)),
                indexes: $this->indexes($builder->getIndexes($qualified)),
                foreignKeys: $this->foreignKeys($qualified, $builder->getForeignKeys($qualified), $prefix, is_string($defaultSchema) ? $defaultSchema : null),
                package: $this->package($qualified, $packages),
            );
        }

        usort($tables, fn (Table $a, Table $b): int => strcmp($a->name, $b->name));

        return new ConnectionSchema($name, $connection->getDriverName(), $tables);
    }

    /**
     * @param  array<string, list<string>>  $packages
     */
    private function package(string $table, array $packages): ?string
    {
        foreach ($packages as $package => $patterns) {
            if (Str::is($patterns, $table)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Calls a schema builder method, or a method signature, that only Laravel 12 and later have.
     */
    private function call(object $builder, string $method, mixed ...$arguments): mixed
    {
        return method_exists($builder, $method) ? $builder->{$method}(...$arguments) : null;
    }

    /**
     * The table name models use, or null for a table outside the connection's prefix.
     */
    private function unprefixed(string $name, string $prefix): ?string
    {
        if ($prefix === '') {
            return $name;
        }

        return str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : null;
    }

    private function qualify(string $table, ?string $schema, ?string $defaultSchema): string
    {
        return $schema !== null && $defaultSchema !== null && $schema !== $defaultSchema ? $schema.'.'.$table : $table;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<Column>
     */
    private function columns(array $rows): array
    {
        return array_values(array_map(fn (array $row): Column => new Column(
            name: (string) $row['name'],
            type: (string) $row['type'],
            typeName: (string) $row['type_name'],
            nullable: (bool) $row['nullable'],
            default: isset($row['default']) ? (string) $row['default'] : null,
            autoIncrement: (bool) ($row['auto_increment'] ?? false),
            comment: isset($row['comment']) && $row['comment'] !== '' ? (string) $row['comment'] : null,
        ), $rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<Index>
     */
    private function indexes(array $rows): array
    {
        return array_values(array_map(fn (array $row): Index => new Index(
            name: (string) $row['name'],
            columns: $this->strings($row['columns']),
            unique: (bool) $row['unique'],
            primary: (bool) $row['primary'],
        ), $rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<ForeignKey>
     */
    private function foreignKeys(string $table, array $rows, string $prefix, ?string $defaultSchema): array
    {
        return array_values(array_map(fn (array $row): ForeignKey => new ForeignKey(
            // SQLite keeps no constraint names; use the name Laravel's migrations would have given it.
            name: ($row['name'] ?? '') !== '' ? (string) $row['name'] : $table.'_'.implode('_', $this->strings($row['columns'])).'_foreign',
            columns: $this->strings($row['columns']),
            foreignTable: $this->qualify(
                $this->unprefixed((string) $row['foreign_table'], $prefix) ?? (string) $row['foreign_table'],
                isset($row['foreign_schema']) ? (string) $row['foreign_schema'] : null,
                $defaultSchema,
            ),
            foreignColumns: $this->strings($row['foreign_columns']),
            onUpdate: strtolower((string) ($row['on_update'] ?? 'no action')),
            onDelete: strtolower((string) ($row['on_delete'] ?? 'no action')),
        ), $rows));
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)) : [];
    }
}
