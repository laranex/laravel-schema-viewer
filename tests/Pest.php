<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
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

/**
 * Run the shared contract/check.mjs (strict validation, the shared graph and inference, canonical form) on a document
 * under Node.js, from the verified tarball; the lines it printed and its exit code. Skipped without Node.js: the
 * package itself never needs it.
 *
 * @return array{int, string}
 */
function nodeContractCheck(string $json): array
{
    contractFiles();
    exec('node --version 2>&1', $version, $missing);

    if ($missing !== 0) {
        test()->markTestSkipped('Node.js is not installed; the contract is still checked by the PHP validator.');
    }

    $root = sys_get_temp_dir().'/schema-viewer-check-'.bin2hex(random_bytes(6));
    $members = UiPackage::members((string) file_get_contents(dirname(__DIR__).'/'.UiPackage::CACHE.'/laranex-schema-viewer-ui-0.3.0.tgz'));

    foreach ($members as $path => $content) {
        if ($path === 'contract/check.mjs' || (str_starts_with($path, 'dist/lib/') && str_ends_with($path, '.js'))) {
            @mkdir(dirname($root.'/'.$path), 0755, true);
            file_put_contents($root.'/'.$path, $content);
        }
    }

    file_put_contents($root.'/document.json', $json);
    exec('node '.escapeshellarg($root.'/contract/check.mjs').' '.escapeshellarg($root.'/document.json').' 2>&1', $output, $code);
    (new Filesystem)->deleteDirectory($root);

    return [$code, implode("\n", $output)];
}
