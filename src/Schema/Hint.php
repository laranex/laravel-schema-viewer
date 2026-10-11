<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * One hint about one table.
 */
final class Hint implements JsonSerializable
{
    public function __construct(
        public readonly HintKind $kind,
        public readonly string $connection,
        public readonly string $table,
        public readonly string $message,
    ) {}

    /**
     * @return array{kind: string, severity: string, connection: string, table: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->kind->severity(),
            'connection' => $this->connection,
            'table' => $this->table,
            'message' => $this->message,
        ];
    }
}
