<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * A method that looks like a relation but could not be read. Package is the Composer package (or the class outside the
 * application) that declares an inherited method, such as laravel/passport for Token::user().
 */
final class UnresolvedRelation
{
    /**
     * @param  class-string  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $method,
        public readonly string $reason,
        public readonly ?string $package = null,
    ) {}
}
