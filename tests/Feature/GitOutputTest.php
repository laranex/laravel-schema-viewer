<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * schema-viewer:build and schema-viewer:export --output inside a Git work tree: a file Git would add gets a warning,
 * a file Git tracks is refused before anything is written, an ignored file and a project outside Git get nothing.
 */

it('warns when the snapshot it writes is not ignored by Git', function () {
    repository();

    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('[schema-viewer.json] is not ignored by Git: add /schema-viewer.json to .gitignore')
        ->expectsOutputToContain('[schema-viewer.changes.json] is not ignored by Git')
        ->assertSuccessful();

    expect(file_exists($this->snapshot))->toBeTrue();
});

it('says nothing when the snapshot is ignored, or outside Git', function () {
    Artisan::call('schema-viewer:build');

    expect(Artisan::output())->not->toContain('Git');

    $root = repository();
    put($root, '.gitignore', "/schema-viewer.json\n/schema-viewer.changes.json\n");
    Artisan::call('schema-viewer:build');

    expect(Artisan::output())->not->toContain('Git');
});

it('refuses to write a snapshot Git tracks, and says how to untrack it', function () {
    $root = repository();
    put($root, 'schema-viewer.json', '{"placeholder":true}');
    commit($root);

    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('git rm --cached -- schema-viewer.json')
        ->assertExitCode(1);

    expect(file_get_contents($this->snapshot))->toBe('{"placeholder":true}')
        ->and(file_exists($root.'/schema-viewer.changes.json'))->toBeFalse();
});

it('checks an export file it writes the same way', function () {
    $root = repository();
    put($root, 'exports/.gitkeep', '');
    put($root, 'exports/tracked.md', 'old');
    commit($root);

    $this->artisan('schema-viewer:export', ['--format' => 'markdown', '--output' => $root.'/exports/tracked.md'])
        ->expectsOutputToContain('git rm --cached -- exports/tracked.md')
        ->assertExitCode(1);

    expect(file_get_contents($root.'/exports/tracked.md'))->toBe('old');

    $this->artisan('schema-viewer:export', ['--format' => 'dbml', '--output' => $root.'/exports/new.dbml'])
        ->expectsOutputToContain('[exports/new.dbml] is not ignored by Git')
        ->assertSuccessful();

    $this->artisan('schema-viewer:export', ['--format' => 'html'])
        ->expectsOutputToContain('[schema-viewer/index.html] is not ignored by Git')
        ->assertSuccessful();
});
