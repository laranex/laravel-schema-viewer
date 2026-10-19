<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * The type and id columns of a polymorphic relation.
 */
final class Morph
{
    /**
     * @param  string|null  $class  The value stored in the type column for this side, when the relation fixes one.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?string $class,
    ) {}
}
