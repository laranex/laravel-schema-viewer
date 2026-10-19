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

    public static function unreadable(string $path, string $reason): self
    {
        return new self("The schema snapshot at [{$path}] cannot be read: {$reason}. Run php artisan schema-viewer:build to write it again.");
    }

    public static function legacy(string $path): self
    {
        return new self("The schema snapshot at [{$path}] was written by laravel-schema-viewer 1.x, which this version no longer reads. Run php artisan schema-viewer:build to write it again as a laranex-schema 2.0 document.");
    }
}
