<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A table index, the primary key included.
 */
final class Index
{
    /**
     * @param  list<string>  $columns  The columns as Laravel reports them: expression key parts are left out, and included columns are listed too.
     * @param  string|null  $type  The index method, lowercased: btree, hash, gin, gist, fulltext, spatial, clustered, nonclustered; null when the database does not say (SQLite).
     * @param  bool  $partial  Whether it covers only the rows a WHERE clause (a SQL Server filter) picks.
     * @param  bool  $expression  Whether a key part is an expression, such as lower(email), or a MySQL prefix, such as name(10).
     * @param  list<string>  $included  Columns stored in the index but not part of its key (PostgreSQL and SQL Server INCLUDE).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly bool $primary,
        public readonly ?string $type = null,
        public readonly bool $partial = false,
        public readonly bool $expression = false,
        public readonly array $included = [],
    ) {}

    /**
     * Whether the index can serve lookups on these columns: they are its leading columns.
     *
     * @param  list<string>  $columns
     */
    public function startsWith(array $columns): bool
    {
        return array_slice($this->columns, 0, count($columns)) === $columns;
    }

    /**
     * The key columns: the columns without the included ones, in their order.
     *
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return array_values(array_filter($this->columns, fn (string $column): bool => ! in_array($column, $this->included, true)));
    }

    /**
     * Whether the index is a plain one on whole columns: not partial, no expression or prefix, nothing included.
     */
    public function plain(): bool
    {
        return ! $this->partial && ! $this->expression && $this->included === [] && $this->columns !== [];
    }

    /**
     * The kind of structure behind the index: btree for the ordinary ones (SQL Server's clustered and nonclustered
     * included), else its type. Only indexes of one family can stand in for each other.
     */
    public function typeFamily(): string
    {
        return in_array($this->type, [null, 'btree', 'clustered', 'nonclustered'], true) ? 'btree' : $this->type;
    }
}
