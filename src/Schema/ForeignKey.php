<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A foreign key constraint.
 */
final class ForeignKey implements JsonSerializable
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $foreignTable,
        public readonly array $foreignColumns,
        public readonly string $onUpdate,
        public readonly string $onDelete,
    ) {}

    /**
     * @return array{name: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>, onUpdate: string, onDelete: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'foreignTable' => $this->foreignTable,
            'foreignColumns' => $this->foreignColumns,
            'onUpdate' => $this->onUpdate,
            'onDelete' => $this->onDelete,
        ];
    }
}
