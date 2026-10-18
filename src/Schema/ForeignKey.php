<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A foreign key constraint.
 */
final class ForeignKey
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
}
