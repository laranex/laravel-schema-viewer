<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A table index, the primary key included.
 */
final class Index implements JsonSerializable
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly bool $primary,
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
     * @return array{name: string, columns: list<string>, unique: bool, primary: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'primary' => $this->primary,
        ];
    }
}
