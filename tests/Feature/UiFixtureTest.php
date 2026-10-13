<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Snapshot;

/*
 * The browser tests in ui/e2e open the viewer on a real snapshot of the shared blog schema. This keeps that file in
 * step with what schema-viewer:build writes; run with SCHEMA_VIEWER_UPDATE_UI_FIXTURE=1 to write it again.
 */
it('keeps the browser tests\' snapshot in step with the build', function (): void {
    if (testDriver() !== 'sqlite') {
        $this->markTestSkipped('The fixture is the SQLite build.');
    }

    $fixture = dirname(__DIR__, 2).'/ui/e2e/fixtures/blog.json';
    $contents = app(Snapshot::class)->render()['contents'];

    if (getenv('SCHEMA_VIEWER_UPDATE_UI_FIXTURE') === '1') {
        file_put_contents($fixture, $contents);
    }

    expect($fixture)->toBeFile()
        ->and(str_replace("\r\n", "\n", (string) file_get_contents($fixture)))->toBe($contents);
});
