<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A table column as the database reports it.
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
    ) {}

    /**
     * @return array{name: string, type: string, typeName: string, nullable: bool, default: string|null, autoIncrement: bool, comment: string|null}
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
        ];
    }
}
