<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * The database driver the suite runs on: sqlite locally, or the one CI picked.
 */
function testDriver(): string
{
    return DB::connection('testing')->getDriverName();
}
