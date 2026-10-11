<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * The type and id columns of a polymorphic relation.
 */
final class Morph implements JsonSerializable
{
    /**
     * @param  string|null  $class  The value stored in the type column for this side, when the relation fixes one.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?string $class,
    ) {}

    /**
     * @return array{type: string, id: string, class: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'class' => $this->class];
    }
}
