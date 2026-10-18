<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\Scripts\UiPackage;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * The database driver the suite runs on: sqlite locally, or the one CI picked.
 */
function testDriver(): string
{
    return DB::connection('testing')->getDriverName();
}

/**
 * Write the snapshot the viewer's routes read, as php artisan schema-viewer:build does.
 */
function buildSnapshot(): SchemaDocument
{
    return app(Snapshot::class)->build();
}

/**
 * What the viewer page hands the app: window.__SCHEMA_VIEWER__, with the schema and the downloads when the snapshot is
 * readable.
 *
 * @param  array<string, string>  $headers
 * @return array<string, mixed>
 */
function viewerConfig(mixed $test, array $headers = []): array
{
    $response = $test->get('/schema-viewer', $headers)->assertOk();
    $html = (string) $response->getContent();

    if ($response->headers->get('Content-Encoding') === 'gzip') {
        $html = (string) gzdecode($html);
    }

    preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The shared contract's fixtures and conformance cases (paths below contract/), read from the laranex-schema-viewer-ui
 * tarball that composer ui:fetch cached and checked against ui.lock.json. Without it the test is skipped, unless
 * SCHEMA_VIEWER_CONTRACT=required (as in CI), which fails it instead.
 *
 * @return array<string, string>
 */
function contractFiles(): array
{
    $files = (new UiPackage(dirname(__DIR__)))->contract();

    if ($files === null) {
        $message = 'The shared contract fixtures are not cached: run composer ui:fetch.';

        if (getenv('SCHEMA_VIEWER_CONTRACT') === 'required') {
            throw new RuntimeException($message);
        }

        test()->markTestSkipped($message);
    }

    return $files;
}
