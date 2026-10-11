<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * A method that looks like a relation but could not be read.
 */
final class UnresolvedRelation implements JsonSerializable
{
    /**
     * @param  class-string  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $method,
        public readonly string $reason,
    ) {}

    /**
     * @return array{model: string, method: string, reason: string}
     */
    public function jsonSerialize(): array
    {
        return ['model' => $this->model, 'method' => $this->method, 'reason' => $this->reason];
    }
}
