<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * One hint about one table, narrowed down by the columns it is about (comma separated) or the relation method
 * (Class::method) that could not be read.
 */
final class Hint implements JsonSerializable
{
    public function __construct(
        public readonly HintKind $kind,
        public readonly string $connection,
        public readonly string $table,
        public readonly string $message,
        public readonly ?string $columns = null,
        public readonly ?string $relation = null,
    ) {}

    /**
     * The hints.ignore rules that leave out this hint, from the narrowest to the widest.
     *
     * @return array{hint: array<string, string>, kindOnTable: array<string, string>, table: array<string, string>, kind: array<string, string>}
     */
    public function ignoreRules(): array
    {
        $table = ['connection' => $this->connection, 'table' => $this->table];
        $kindOnTable = $table + ['kind' => $this->kind->value];
        $narrowest = match (true) {
            $this->relation !== null => ['kind' => $this->kind->value, 'relation' => $this->relation],
            $this->columns !== null => $kindOnTable + ['columns' => $this->columns],
            default => $kindOnTable,
        };

        return ['hint' => $narrowest, 'kindOnTable' => $kindOnTable, 'table' => $table, 'kind' => ['kind' => $this->kind->value]];
    }

    /**
     * @return array{kind: string, severity: string, connection: string, table: string, columns: string|null, relation: string|null, message: string, ignore: array{hint: array<string, string>, kindOnTable: array<string, string>, table: array<string, string>, kind: array<string, string>}}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->kind->severity(),
            'connection' => $this->connection,
            'table' => $this->table,
            'columns' => $this->columns,
            'relation' => $this->relation,
            'message' => $this->message,
            'ignore' => $this->ignoreRules(),
        ];
    }
}
