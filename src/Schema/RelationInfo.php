<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * An Eloquent relation method, read from the relation object it returns.
 */
final class RelationInfo implements JsonSerializable
{
    /**
     * @param  class-string  $model
     * @param  string  $type  The relation class's short name, e.g. HasMany, or the class name of a third-party relation.
     * @param  class-string  $related
     * @param  Endpoint|null  $from  The side holding the foreign key column.
     * @param  Endpoint|null  $to  The side the key points at.
     * @param  list<string>|null  $through  Tables from the parent to the related table, for through relations.
     * @param  list<string>  $constraints  Conditions added inside the relation method, e.g. "status = active".
     * @param  Source|null  $source  The relation method; null for dynamic relations.
     * @param  'one-to-one'|'one-to-many'|'many-to-one'|'many-to-many'  $cardinality  Read from the declaring model's side.
     * @param  string|null  $relatedConnection  The related model's connection.
     * @param  string|null  $relatedTable  The related model's table.
     */
    public function __construct(
        public readonly string $model,
        public readonly string $name,
        public readonly string $type,
        public readonly string $related,
        public readonly ?Endpoint $from,
        public readonly ?Endpoint $to,
        public readonly ?Pivot $pivot,
        public readonly ?Morph $morph,
        public readonly ?array $through,
        public readonly array $constraints,
        public ?string $inverse = null,
        public readonly ?Source $source = null,
        public readonly string $cardinality = 'one-to-many',
        public readonly ?string $relatedConnection = null,
        public readonly ?string $relatedTable = null,
    ) {}

    public function id(): string
    {
        return $this->model.'::'.$this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id(),
            'model' => $this->model,
            'name' => $this->name,
            'type' => $this->type,
            'related' => $this->related,
            'keys' => $this->from && $this->to ? ['from' => $this->from, 'to' => $this->to] : null,
            'pivot' => $this->pivot,
            'morph' => $this->morph,
            'through' => $this->through,
            'constraints' => $this->constraints,
            'inverse' => $this->inverse,
            'source' => $this->source,
        ];
    }
}
