<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * Columns of one table on one connection.
 */
final class Endpoint
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $table,
        public readonly array $columns,
    ) {}

    public function key(): string
    {
        return $this->connection.'|'.$this->table.'|'.implode(',', $this->columns);
    }
}
