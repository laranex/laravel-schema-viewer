<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use InvalidArgumentException;
use stdClass;

/**
 * What every text exporter shares, a port of laranex-schema-viewer-ui's export/common.ts: the connections in scope, the
 * database facts of each table (in a combined document, what only the ORM declares is left out), uniqueness and
 * warnings. The exporters read a laranex-schema 2.x document and give the same bytes and warnings as the shared
 * viewer's Download dialog.
 */
final class ExportContext
{
    /**
     * @var list<array<string, mixed>>
     */
    public readonly array $connections;

    /**
     * More than one connection in one file: table names are qualified by their connection.
     */
    public readonly bool $several;

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $tables = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $connectionOf = [];

    /**
     * @var array<string, true>
     */
    public array $inScope = [];

    /**
     * @var list<array{code: string, subject: array{type: string, id: string|null, label: string|null}, message: string}>
     */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>|null  $connectionIds  Connection ids, in any order; every connection when null.
     */
    public function __construct(public readonly array $doc, ?array $connectionIds = null)
    {
        $this->connections = self::select($doc, $connectionIds);
        $this->several = count($this->connections) > 1;

        foreach ($doc['connections'] as $connection) {
            foreach ($connection['tables'] as $table) {
                $this->tables[$table['id']] = $table;
                $this->connectionOf[$table['id']] = $connection;
            }
        }

        foreach ($this->connections as $connection) {
            foreach ($connection['tables'] as $table) {
                $this->inScope[$table['id']] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>|null  $ids
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException For a connection id the document does not have.
     */
    public static function select(array $doc, ?array $ids): array
    {
        if ($ids === null) {
            return $doc['connections'];
        }

        $unknown = array_values(array_diff($ids, array_column($doc['connections'], 'id')));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown connection: '.implode(', ', $unknown));
        }

        return array_values(array_filter($doc['connections'], fn (array $c): bool => in_array($c['id'], $ids, true)));
    }

    public function warn(string $code, string $type, ?string $id, ?string $label, string $message): void
    {
        $this->warnings[] = ['code' => $code, 'subject' => ['type' => $type, 'id' => $id, 'label' => $label], 'message' => $message];
    }

    /**
     * The warnings about the document as a whole every database format shares: ORM-only sources and incomplete
     * inspections.
     */
    public function sourceWarnings(): void
    {
        $declared = $this->doc['source'] === 'declared';

        if ($declared) {
            $this->warn('declared_source', 'document', null, null, 'This document holds ORM declarations only; the file describes the declared schema, not an inspected database.');
        }

        foreach ($this->connections as $c) {
            $coverage = $declared ? $c['coverage']['declared'] : $c['coverage']['database'];

            if (is_array($coverage) && $coverage['complete'] !== true) {
                $gaps = [];

                foreach (self::members($coverage['features']) as $feature => $state) {
                    if ($state === 'partial' || $state === 'unavailable') {
                        $gaps[] = "{$feature}: {$state}";
                    }
                }

                $detail = $gaps !== [] ? 'incomplete ('.implode(', ', $gaps).')' : 'incomplete';
                $this->warn('incomplete_coverage', 'connection', $c['id'], $c['name'], sprintf('The %s of %s %s %s; the file holds only what was read.', $declared ? 'declarations' : 'database inspection', $c['name'], $declared ? 'are' : 'is', $detail));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    public function declaredOnly(array $entity): bool
    {
        return $this->doc['source'] === 'combined' && ! in_array('database', $entity['provenance'], true);
    }

    /**
     * schema.table, or table.
     *
     * @param  array<string, mixed>  $table
     */
    public static function tableLabel(array $table): string
    {
        return ($table['schema'] !== null && $table['schema'] !== '' ? $table['schema'].'.' : '').$table['name'];
    }

    /**
     * A table's label in the file: qualified by its connection's name when the file holds several connections.
     *
     * @param  array<string, mixed>  $table
     */
    public function labelOf(array $table): string
    {
        return $this->several ? ($this->connectionOf[$table['id']]['name'] ?? '').'.'.self::tableLabel($table) : self::tableLabel($table);
    }

    /**
     * A connection's tables as the database has them, in document order.
     *
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     */
    public function tablesOf(array $connection): array
    {
        $tables = [];

        foreach ($connection['tables'] as $table) {
            if ($this->declaredOnly($table)) {
                $this->warn('skipped_entity', 'table', $table['id'], $this->labelOf($table), sprintf('Table %s is declared only by the ORM, not in the database; it is left out.', $this->labelOf($table)));

                continue;
            }

            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * The tables of every connection in scope.
     *
     * @return list<array<string, mixed>>
     */
    public function scopedTables(): array
    {
        return array_merge([], ...array_map($this->tablesOf(...), $this->connections));
    }

    /**
     * One table as the database has it, with the warnings about what was left out.
     *
     * @param  array<string, mixed>  $table
     */
    public function view(array $table): TableView
    {
        $label = $this->labelOf($table);
        $skip = fn (string $type, ?string $id, string $what) => $this->warn('skipped_entity', $type, $id, $label, "{$what} is declared only by the ORM, not in the database; it is left out.");
        $this->drift("table {$label}", 'table', $table['id'], $label, $table);

        $columns = [];

        foreach ($table['columns'] as $c) {
            $this->drift("column {$label}.{$c['name']}", 'column', $table['id'], "{$label}.{$c['name']}", $c);
            $this->declaredOnly($c) ? $skip('column', $table['id'], "Column {$label}.{$c['name']}") : $columns[] = $c;
        }

        $indexes = [];

        foreach ($table['indexes'] as $i) {
            $this->drift('index '.($i['name'] ?? 'unnamed'), 'index', $i['id'], $label, $i);
            $this->declaredOnly($i) ? $skip('index', $i['id'], 'Index '.($i['name'] ?? 'unnamed')." on {$label}") : $indexes[] = $i;
        }

        $constraints = [];

        foreach ($table['constraints'] as $c) {
            $this->drift('constraint '.($c['name'] ?? 'unnamed'), 'constraint', $c['id'], $label, $c);
            $this->declaredOnly($c) ? $skip('constraint', $c['id'], 'Constraint '.($c['name'] ?? 'unnamed')." on {$label}") : $constraints[] = $c;
        }

        $foreignKeys = [];

        foreach ($table['foreignKeys'] as $fk) {
            $name = $fk['name'] ?? 'unnamed';
            $this->drift("foreign key {$name}", 'foreignKey', $fk['id'], $label, $fk);
            $target = $this->tables[$fk['foreignTable']] ?? null;

            if ($this->declaredOnly($fk)) {
                $skip('foreignKey', $fk['id'], "Foreign key {$name} on {$label}");
            } elseif ($target === null || $this->declaredOnly($target)) {
                $this->warn('skipped_entity', 'foreignKey', $fk['id'], $label, sprintf('Foreign key %s on %s references a table %s; it is left out.', $name, $label, $target !== null ? 'the database does not have' : 'the document does not have'));
            } elseif (array_filter($fk['foreignColumns'], fn (string $c): bool => array_filter($target['columns'], fn (array $col): bool => $col['name'] === $c && ! $this->declaredOnly($col)) === []) !== []) {
                $this->warn('skipped_entity', 'foreignKey', $fk['id'], $label, sprintf('Foreign key %s on %s references %s, which %s does not have; it is left out.', $name, $label, implode(', ', $fk['foreignColumns']), $this->labelOf($target)));
            } else {
                $foreignKeys[] = ['fk' => $fk, 'target' => $target];
            }
        }

        return new TableView($table, $this->connectionOf[$table['id']], $label, $columns, $indexes, $foreignKeys, $constraints);
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function drift(string $what, string $type, ?string $id, string $label, array $entity): void
    {
        $drift = $entity['drift'] ?? [];

        if (is_array($drift) && $drift !== []) {
            $this->warn('lossy_conversion', $type, $id, $label, sprintf("The database and the ORM disagree on %s's %s; the file has the database's value.", $what, implode(', ', array_column($drift, 'field'))));
        }
    }

    /**
     * A referential action in lower-case words, as DBML and Markdown write them.
     */
    public static function actionWords(?string $action): ?string
    {
        return $action === null ? null : strtolower($action);
    }

    /**
     * A class name without its namespace or module path.
     */
    public static function shortClass(string $name): string
    {
        $parts = preg_split('/[\\\\.]/', $name);

        return is_array($parts) ? (string) end($parts) : $name;
    }

    /**
     * The download name of an export: schema-<connection>.<extension>, every character outside [A-Za-z0-9_-] replaced
     * by "-"; schema-all.<extension> for several connections.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<string>|null  $connections
     */
    public static function fileName(array $doc, string $extension, ?array $connections = null): string
    {
        $chosen = self::select($doc, $connections);

        return 'schema-'.(count($chosen) === 1 ? (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $chosen[0]['name']) : 'all').'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    public static function members(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }
}
