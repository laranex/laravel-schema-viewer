<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * The pivot table of a many-to-many relation.
 */
final class Pivot
{
    /**
     * @param  list<string>  $columns  Extra columns read with withPivot() and withTimestamps().
     * @param  class-string|null  $using  The custom pivot model set with using().
     */
    public function __construct(
        public readonly string $table,
        public readonly string $foreignPivotKey,
        public readonly string $relatedPivotKey,
        public readonly string $parentKey,
        public readonly string $relatedKey,
        public readonly array $columns,
        public readonly ?string $using,
        public readonly string $accessor,
    ) {}
}
