<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A through relation, drawn from the parent table to the far table.
 */
final class DerivedLink implements JsonSerializable
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

    /**
     * @return array{id: string, connection: string, from: string, to: string, via: list<string>, relation: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'connection' => $this->connection,
            'from' => $this->from,
            'to' => $this->to,
            'via' => $this->via,
            'relation' => $this->relation,
        ];
    }
}
