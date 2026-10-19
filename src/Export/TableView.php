<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * One table as the database has it (export/common.ts's TableView): its database columns, indexes, constraints and the
 * foreign keys whose targets the document has.
 */
final class TableView
{
    /**
     * @param  array<string, mixed>  $table
     * @param  array<string, mixed>  $connection
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array{fk: array<string, mixed>, target: array<string, mixed>}>  $foreignKeys
     * @param  list<array<string, mixed>>  $constraints
     */
    public function __construct(
        public readonly array $table,
        public readonly array $connection,
        public readonly string $label,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $foreignKeys,
        public readonly array $constraints,
    ) {}

    /**
     * @return list<string>
     */
    public function primaryKey(): array
    {
        return $this->table['primaryKey'];
    }

    /**
     * Unique on exactly these columns, in any order, by the database's primary key, indexes and constraints (the rule
     * the diagram's line ends use).
     *
     * @param  list<string>  $columns
     */
    public function unique(array $columns): bool
    {
        if ($columns === []) {
            return false;
        }

        $same = fn (array $other): bool => count($other) === count($columns) && array_diff($columns, $other) === [];

        if ($same($this->primaryKey())) {
            return true;
        }

        foreach ($this->indexes as $i) {
            $plain = array_filter($i['parts'], fn (array $p): bool => $p['column'] === null || $p['length'] !== null) === [];

            if ($i['unique'] && ! $i['partial'] && ! $i['expression'] && $plain && $same(array_column($i['parts'], 'column'))) {
                return true;
            }
        }

        foreach ($this->constraints as $c) {
            if ($c['kind'] === 'unique' && $c['expression'] === null && $same($c['columns'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single-column uniqueness of a column, other than the primary key: an index or a constraint.
     *
     * @return array<string, mixed>|null
     */
    public function uniqueColumn(string $name): ?array
    {
        foreach ($this->indexes as $i) {
            if ($i['unique'] && ! $i['partial'] && self::plainColumns($i) === [$name] && ($i['backs']['kind'] ?? null) !== 'primary_key') {
                return $i;
            }
        }

        foreach ($this->constraints as $c) {
            if ($c['kind'] === 'unique' && $c['expression'] === null && $c['columns'] === [$name]) {
                return $c;
            }
        }

        return null;
    }

    /**
     * The key columns of an index when every part is a whole column (no expression, no prefix length), else null.
     *
     * @param  array<string, mixed>  $index
     * @return list<string>|null
     */
    public static function plainColumns(array $index): ?array
    {
        if ($index['expression']) {
            return null;
        }

        foreach ($index['parts'] as $p) {
            if ($p['column'] === null || $p['length'] !== null) {
                return null;
            }
        }

        return array_column($index['parts'], 'column');
    }
}
