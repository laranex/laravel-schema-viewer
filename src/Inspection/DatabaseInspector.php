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
use PDO;
use Throwable;

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
     * @param  array{tables: array<string, string>, columns: array<string, array<string, string>>}  $notes  Notes by table name, plain or prefixed with a connection name and a colon, which wins.
     */
    public function inspect(string $name, array $exclude = [], array $framework = [], array $packages = [], array $notes = ['tables' => [], 'columns' => []]): ConnectionSchema
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

            $details = $this->indexDetails($connection, (string) $row['name'], isset($row['schema']) ? (string) $row['schema'] : null);

            $tables[] = new Table(
                name: $qualified,
                comment: isset($row['comment']) && $row['comment'] !== '' ? (string) $row['comment'] : null,
                framework: Str::is($framework, $qualified),
                columns: $this->columns($builder->getColumns($qualified), [...$notes['columns'][$qualified] ?? [], ...$notes['columns'][$name.':'.$qualified] ?? []]),
                indexes: $this->indexes($builder->getIndexes($qualified), $details),
                foreignKeys: $this->foreignKeys($qualified, $builder->getForeignKeys($qualified), $prefix, is_string($defaultSchema) ? $defaultSchema : null),
                package: $this->package($qualified, $packages),
                indexDetails: $details !== null,
                note: $notes['tables'][$name.':'.$qualified] ?? $notes['tables'][$qualified] ?? null,
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
     * @param  array<string, string>  $notes  By column name.
     * @return list<Column>
     */
    private function columns(array $rows, array $notes = []): array
    {
        return array_values(array_map(fn (array $row): Column => new Column(
            name: (string) $row['name'],
            type: (string) $row['type'],
            typeName: (string) $row['type_name'],
            nullable: (bool) $row['nullable'],
            default: isset($row['default']) ? (string) $row['default'] : null,
            autoIncrement: (bool) ($row['auto_increment'] ?? false),
            comment: isset($row['comment']) && $row['comment'] !== '' ? (string) $row['comment'] : null,
            note: $notes[(string) $row['name']] ?? null,
        ), $rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array{partial: bool, expression: bool, included: list<string>, trailing: int}>|null  $details  By lowercased index name.
     * @return list<Index>
     */
    private function indexes(array $rows, ?array $details): array
    {
        return array_values(array_map(function (array $row) use ($details): Index {
            // Laravel 10 reports an expression key part on SQLite as an empty name; the others leave it out.
            $columns = array_values(array_filter($this->strings($row['columns']), fn (string $column): bool => $column !== ''));
            $detail = $details[strtolower((string) $row['name'])] ?? ['partial' => false, 'expression' => false, 'included' => [], 'trailing' => 0];
            $included = $detail['trailing'] > 0
                ? array_slice($columns, -$detail['trailing'])
                : array_values(array_filter($columns, fn (string $column): bool => in_array($column, $detail['included'], true)));

            return new Index(
                name: (string) $row['name'],
                columns: $columns,
                unique: (bool) $row['unique'],
                primary: (bool) $row['primary'],
                type: is_string($row['type'] ?? null) && $row['type'] !== '' ? strtolower($row['type']) : null,
                partial: $detail['partial'],
                expression: $detail['expression'],
                included: $included,
            );
        }, $rows));
    }

    /**
     * What Laravel's index listing leaves out, read from the database's own catalog with one query (SQLite: one per
     * index): which indexes are partial, which have an expression or prefix key part, and which columns are only
     * included. Null when the driver is not one of Laravel's or the catalog cannot be read; the structural hints that
     * need these details then skip the table.
     *
     * @param  string  $table  The table's name in the database, prefix included.
     * @return array<string, array{partial: bool, expression: bool, included: list<string>, trailing: int}>|null
     */
    private function indexDetails(Connection $connection, string $table, ?string $schema): ?array
    {
        try {
            $rows = match ($connection->getDriverName()) {
                'sqlite' => $this->sqliteIndexDetails($connection, $table, $schema ?? 'main'),
                'mysql', 'mariadb' => $connection->select(
                    'select index_name as name, max(column_name is null) as expression, max(sub_part is not null) as prefix '
                    .'from information_schema.statistics where table_schema = coalesce(?, schema()) and table_name = ? group by index_name',
                    [$schema, $table],
                ),
                'pgsql' => $connection->select(
                    'select ic.relname as name, i.indpred is not null as partial, i.indexprs is not null as expression, '
                    // INCLUDE came with PostgreSQL 11.
                    .((int) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION) >= 11 ? 'i.indnatts - i.indnkeyatts' : '0').' as trailing '
                    .'from pg_index i join pg_class tc on tc.oid = i.indrelid join pg_namespace tn on tn.oid = tc.relnamespace '
                    .'join pg_class ic on ic.oid = i.indexrelid where tc.relname = ? and tn.nspname = coalesce(?, current_schema())',
                    [$table, $schema],
                ),
                'sqlsrv' => $connection->select(
                    'select i.name as name, i.has_filter as partial, c.name as included from sys.indexes i '
                    .'join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id and ic.is_included_column = 1 '
                    .'left join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id '
                    .'where i.object_id = object_id(?) '
                    .'union all select i.name, i.has_filter, null from sys.indexes i where i.object_id = object_id(?) and i.name is not null',
                    array_fill(0, 2, ($schema !== null ? '['.str_replace(']', ']]', $schema).'].' : '').'['.str_replace(']', ']]', $table).']'),
                ),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($rows === null) {
            return null;
        }

        $details = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $name = strtolower((string) $row['name']);
            $details[$name] ??= ['partial' => false, 'expression' => false, 'included' => [], 'trailing' => 0];
            $details[$name]['partial'] = $details[$name]['partial'] || (bool) ($row['partial'] ?? false);
            $details[$name]['expression'] = $details[$name]['expression'] || (bool) ($row['expression'] ?? false) || (bool) ($row['prefix'] ?? false);
            $details[$name]['trailing'] = max($details[$name]['trailing'], (int) ($row['trailing'] ?? 0));

            if (is_string($row['included'] ?? null)) {
                $details[$name]['included'][] = $row['included'];
            }
        }

        return $details;
    }

    /**
     * @return list<array{name: string, partial: bool, expression: bool}>
     */
    private function sqliteIndexDetails(Connection $connection, string $table, string $schema): array
    {
        $rows = [];

        foreach ($connection->select('select name, partial from pragma_index_list(?, ?)', [$table, $schema]) as $index) {
            $index = (array) $index;
            $rows[] = [
                'name' => (string) $index['name'],
                'partial' => (bool) $index['partial'],
                // An expression key part has no column: cid -2.
                'expression' => $connection->select('select 1 from pragma_index_xinfo(?, ?) where key = 1 and cid = -2', [(string) $index['name'], $schema]) !== [],
            ];
        }

        return $rows;
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
