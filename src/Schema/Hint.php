<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;
use UnexpectedValueException;

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
        public readonly ?string $model = null,
    ) {}

    /**
     * The hint a laranex-schema diagnostic carries in extensions.laravel (see Producer), or null for a diagnostic that
     * is not one of the package's hints. The model is not kept: it only chose the diagnostic's subject.
     *
     * @param  array<mixed>  $diagnostic
     *
     * @throws UnexpectedValueException When extensions.laravel names a hint but does not hold all of it.
     */
    public static function fromDiagnostic(array $diagnostic): ?self
    {
        $extensions = $diagnostic['extensions'] ?? null;
        $laravel = is_array($extensions) ? ($extensions['laravel'] ?? null) : null;

        if (! is_array($laravel) || ! array_key_exists('hint', $laravel)) {
            return null;
        }

        $kind = is_string($laravel['hint']) ? HintKind::tryFrom($laravel['hint']) : null;
        $message = $diagnostic['message'] ?? null;
        $optional = fn (string $key): bool => array_key_exists($key, $laravel) && ($laravel[$key] === null || is_string($laravel[$key]));

        if ($kind === null || ! is_string($message) || ! is_string($laravel['connection'] ?? null) || ! is_string($laravel['table'] ?? null) || ! $optional('columns') || ! $optional('relation')) {
            throw new UnexpectedValueException('a diagnostic\'s extensions.laravel does not hold a whole hint (hint, connection, table, columns and relation)');
        }

        return new self($kind, $laravel['connection'], $laravel['table'], $message, $laravel['columns'], $laravel['relation']);
    }

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
     * @return array{kind: string, severity: string, certainty: string, connection: string, table: string, columns: string|null, relation: string|null, message: string, ignore: array{hint: array<string, string>, kindOnTable: array<string, string>, table: array<string, string>, kind: array<string, string>}}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->kind->severity(),
            'certainty' => $this->kind->certainty(),
            'connection' => $this->connection,
            'table' => $this->table,
            'columns' => $this->columns,
            'relation' => $this->relation,
            'message' => $this->message,
            'ignore' => $this->ignoreRules(),
        ];
    }
}
