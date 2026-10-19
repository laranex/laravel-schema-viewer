<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A database table with its columns, indexes and foreign keys.
 */
final class Table
{
    /**
     * @param  string  $name  The name models use: without the connection's table prefix, schema-qualified outside the default schema.
     * @param  list<Column>  $columns
     * @param  list<Index>  $indexes
     * @param  list<ForeignKey>  $foreignKeys
     * @param  string|null  $note  From the notes config, beside the database's own comment.
     * @param  bool  $indexDetails  Whether the indexes' partial, expression and included details could be read.
     * @param  string|null  $schema  The schema, when it is not the connection's default (then $name is qualified with it).
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $comment,
        public readonly bool $framework,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $foreignKeys,
        public readonly ?string $package = null,
        public readonly bool $indexDetails = true,
        public readonly ?string $note = null,
        public readonly ?string $schema = null,
    ) {}

    /**
     * The name without its schema: the name itself for a table in the default schema.
     */
    public function baseName(): string
    {
        return $this->schema !== null && str_starts_with($this->name, $this->schema.'.') ? substr($this->name, strlen($this->schema) + 1) : $this->name;
    }

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function hasColumn(string $name): bool
    {
        return $this->column($name) !== null;
    }

    /**
     * Whether an index starts with these columns, so lookups on them are indexed.
     *
     * @param  list<string>  $columns
     */
    public function isIndexed(array $columns): bool
    {
        foreach ($this->indexes as $index) {
            if ($index->startsWith($columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a unique index covers exactly these columns, on every row: a partial index or one with an expression key
     * part, whose reported columns are not its whole key, does not count.
     *
     * @param  list<string>  $columns
     */
    public function isUnique(array $columns): bool
    {
        foreach ($this->indexes as $index) {
            if (($index->unique || $index->primary) && ! $index->partial && ! $index->expression && $index->keyColumns() === $columns) {
                return true;
            }
        }

        return false;
    }
}
