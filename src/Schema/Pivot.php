<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * The pivot table of a many-to-many relation.
 */
final class Pivot implements JsonSerializable
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'table' => $this->table,
            'foreignPivotKey' => $this->foreignPivotKey,
            'relatedPivotKey' => $this->relatedPivotKey,
            'parentKey' => $this->parentKey,
            'relatedKey' => $this->relatedKey,
            'columns' => $this->columns,
            'using' => $this->using,
            'accessor' => $this->accessor,
        ];
    }
}
