<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A table column as the database reports it, with the note the config gives it.
 */
final class Column implements JsonSerializable
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
    ) {}

    /**
     * @return array{name: string, type: string, typeName: string, nullable: bool, default: string|null, autoIncrement: bool, comment: string|null, note: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'typeName' => $this->typeName,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'autoIncrement' => $this->autoIncrement,
            'comment' => $this->comment,
            'note' => $this->note,
        ];
    }
}
