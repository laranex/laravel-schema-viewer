<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use RuntimeException;

/**
 * The schema snapshot is missing or cannot be read; the message says how to build it.
 */
final class SnapshotUnavailable extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("No schema snapshot at [{$path}] yet. Run php artisan schema-viewer:build.");
    }

    public static function unreadable(string $path): self
    {
        return new self("The schema snapshot at [{$path}] cannot be read: it is damaged or was written by another version. Run php artisan schema-viewer:build.");
    }
}
