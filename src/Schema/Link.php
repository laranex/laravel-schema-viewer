<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * One edge between two tables: a foreign key, the relations that use the same columns, or both.
 */
final class Link implements JsonSerializable
{
    /**
     * @param  list<string>  $relations  Relation ids, Model::method.
     */
    public function __construct(
        public readonly string $id,
        public readonly Endpoint $from,
        public readonly Endpoint $to,
        public readonly ?string $foreignKey,
        public readonly array $relations,
        public readonly bool $morph,
    ) {}

    public function state(): LinkState
    {
        return match (true) {
            $this->foreignKey !== null && $this->relations !== [] => LinkState::Both,
            $this->foreignKey !== null => LinkState::ForeignKeyOnly,
            default => LinkState::RelationOnly,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'foreignKey' => $this->foreignKey,
            'relations' => $this->relations,
            'state' => $this->state()->value,
            'morph' => $this->morph,
        ];
    }
}
