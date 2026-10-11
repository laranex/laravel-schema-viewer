<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * Short names for relations and classes in exports.
 */
final class Names
{
    /**
     * App\Models\Post::author becomes Post::author().
     */
    public static function relation(string $id): string
    {
        [$class, $method] = array_pad(explode('::', $id, 2), 2, '');

        return self::class($class).'::'.$method.'()';
    }

    public static function class(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
