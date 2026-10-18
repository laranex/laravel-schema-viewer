<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * One edge between two tables: a foreign key, the relations that use the same columns, or both.
 */
final class Link
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
        public readonly ?string $note = null,
    ) {}

    public function state(): LinkState
    {
        return match (true) {
            $this->foreignKey !== null && $this->relations !== [] => LinkState::Both,
            $this->foreignKey !== null => LinkState::ForeignKeyOnly,
            default => LinkState::RelationOnly,
        };
    }
}
