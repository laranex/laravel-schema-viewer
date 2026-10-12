<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;

/**
 * Where a model or relation method is declared: a path relative to the application's root, and a line. Files outside
 * the root have no source, so no absolute path ever leaves the server.
 */
final class Source implements JsonSerializable
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
    ) {}

    /**
     * @param  ReflectionClass<object>|ReflectionMethod  $reflection
     */
    public static function of(ReflectionClass|ReflectionMethod $reflection, string $root): ?self
    {
        $file = $reflection->getFileName();
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        if (! is_string($file) || ! str_starts_with($path = str_replace('\\', '/', $file), $root)) {
            return null;
        }

        return new self(substr($path, strlen($root)), (int) $reflection->getStartLine());
    }

    /**
     * @return array{file: string, line: int}
     */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file, 'line' => $this->line];
    }
}
