<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A database table with its columns, indexes and foreign keys.
 */
final class Table implements JsonSerializable
{
    /**
     * @param  string  $name  The name models use: without the connection's table prefix, schema-qualified outside the default schema.
     * @param  list<Column>  $columns
     * @param  list<Index>  $indexes
     * @param  list<ForeignKey>  $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $comment,
        public readonly bool $framework,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $foreignKeys,
        public readonly ?string $package = null,
    ) {}

    public function hasColumn(string $name): bool
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return true;
            }
        }

        return false;
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
     * Whether a unique index covers exactly these columns.
     *
     * @param  list<string>  $columns
     */
    public function isUnique(array $columns): bool
    {
        foreach ($this->indexes as $index) {
            if (($index->unique || $index->primary) && $index->columns === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, comment: string|null, framework: bool, package: string|null, columns: list<Column>, indexes: list<Index>, foreignKeys: list<ForeignKey>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'comment' => $this->comment,
            'framework' => $this->framework,
            'package' => $this->package,
            'columns' => $this->columns,
            'indexes' => $this->indexes,
            'foreignKeys' => $this->foreignKeys,
        ];
    }
}
