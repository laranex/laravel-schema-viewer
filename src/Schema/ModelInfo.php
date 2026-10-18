<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * What an Eloquent model declares about its table.
 */
final class ModelInfo
{
    /**
     * @param  class-string  $class
     * @param  array<string, string>  $casts
     * @param  list<string>  $fillable
     * @param  list<string>  $guarded
     * @param  list<string>  $hidden
     * @param  list<string>  $appends
     * @param  list<string>  $scopes
     * @param  list<string>  $globalScopes
     * @param  list<string>  $observers
     */
    public function __construct(
        public readonly string $class,
        public readonly string $connection,
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly string $keyType,
        public readonly bool $incrementing,
        public readonly bool $timestamps,
        public readonly bool $softDeletes,
        public readonly array $casts,
        public readonly array $fillable,
        public readonly array $guarded,
        public readonly array $hidden,
        public readonly array $appends,
        public readonly array $scopes,
        public readonly array $globalScopes,
        public readonly array $observers,
        public readonly ?Source $source = null,
    ) {}
}
