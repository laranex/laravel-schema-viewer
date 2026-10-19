<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A through relation, drawn from the parent table to the far table.
 */
final class DerivedLink
{
    /**
     * @param  list<string>  $via  The intermediate tables.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $connection,
        public readonly string $from,
        public readonly string $to,
        public readonly array $via,
        public readonly string $relation,
    ) {}
}
