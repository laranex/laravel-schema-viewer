<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A table column as the database reports it, with the note the config gives it.
 */
final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $typeName,
        public readonly bool $nullable,
        public readonly ?string $default,
        public readonly bool $autoIncrement,
        public readonly ?string $comment,
        public readonly ?string $note = null,
        public readonly ?string $collation = null,
        /** @var array{kind: 'stored'|'virtual'|null, expression: string|null}|null */
        public readonly ?array $generated = null,
    ) {}
}
