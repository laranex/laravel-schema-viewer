<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * Columns of one table on one connection.
 */
final class Endpoint implements JsonSerializable
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

    /**
     * @return array{connection: string, table: string, columns: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'connection' => $this->connection,
            'table' => $this->table,
            'columns' => $this->columns,
        ];
    }
}
