<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laranex\LaravelSchemaViewer\Contract\Json;

/*
 * schema-viewer:export --snapshot and schema-viewer:check --snapshot read the saved schema-viewer.json instead of the
 * models and the database, and print exactly what the live commands print for the schema it holds.
 */

beforeEach(function (): void {
    expect(Artisan::call('schema-viewer:build'))->toBe(0);
});

/**
 * A command's exit code and what it printed (or wrote to --output).
 *
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function snapshotModeRun(string $command, array $options): array
{
    $code = Artisan::call($command, $options);
    $output = Artisan::output();

    if (isset($options['--output'])) {
        $file = str_ends_with((string) $options['--output'], '.html') || ($options['--format'] ?? null) !== 'html' ? $options['--output'] : $options['--output'].'/index.html';
        $output = (string) file_get_contents((string) $file);
    }

    return [$code, $output];
}

it('exports the snapshot exactly as the live export', function (string $format, ?string $connection, bool $orm, bool $toFile): void {
    $options = array_filter(['--format' => $format, '--connection' => $connection, '--orm' => $orm], fn (mixed $value): bool => $value !== null && $value !== false);
    $root = dirname($this->snapshot);

    [$liveCode, $live] = snapshotModeRun('schema-viewer:export', $toFile ? [...$options, '--output' => $root.'/live.'.$format] : $options);
    [$snapshotCode, $snapshot] = snapshotModeRun('schema-viewer:export', [...$options, '--snapshot' => true, ...($toFile ? ['--output' => $root.'/snapshot.'.$format] : [])]);

    expect($snapshotCode)->toBe(0)
        ->and($liveCode)->toBe(0)
        ->and($live)->not->toBe('')
        ->and($snapshot)->toBe($live);
})->with(['json', 'mermaid', 'dbml', 'markdown'])->with([
    'every connection' => [null],
    'one connection' => ['testing'],
])->with(['schema' => [false], 'with the ORM' => [true]])->with(['to the terminal' => [false], 'to a file' => [true]]);

it('exports the snapshot as the same standalone viewer', function (?string $connection): void {
    $root = dirname($this->snapshot);
    $options = $connection !== null ? ['--connection' => $connection] : [];

    [$liveCode, $live] = snapshotModeRun('schema-viewer:export', ['--format' => 'html', '--output' => $root.'/live.html', ...$options]);
    [$snapshotCode, $snapshot] = snapshotModeRun('schema-viewer:export', ['--format' => 'html', '--output' => $root.'/snapshot', '--snapshot' => true, ...$options]);

    expect([$liveCode, $snapshotCode])->toBe([0, 0])
        ->and($snapshot)->toContain('schema-viewer-data')
        ->and($snapshot)->toBe($live);
})->with(['every connection' => [null], 'one connection' => ['testing']]);

it('exports the snapshot it reads, not the schema now', function (): void {
    config()->set('schema-viewer.notes.project', 'Written after the build.');

    Artisan::call('schema-viewer:export', ['--snapshot' => true]);
    $document = Json::decode(Artisan::output());

    expect($document['note'])->toBeNull()
        ->and($document)->toBe(Json::decode((string) file_get_contents($this->snapshot)));
});

it('refuses a connection the snapshot does not have', function (): void {
    $this->artisan('schema-viewer:export', ['--format' => 'mermaid', '--connection' => 'missing', '--snapshot' => true])
        ->expectsOutputToContain('The schema has no connection [missing].')
        ->assertExitCode(2);
});

it('checks the snapshot exactly as the live check', function (string $format, array $options, array $ignore, int $code): void {
    config()->set('schema-viewer.hints.ignore', $ignore);

    $live = snapshotModeRun('schema-viewer:check', ['--format' => $format, ...$options]);
    $snapshot = snapshotModeRun('schema-viewer:check', ['--format' => $format, '--snapshot' => true, ...$options]);

    expect($live[0])->toBe($code)
        ->and($snapshot)->toBe($live);
})->with(['text', 'json'])->with([
    'failing on the configured kinds' => [[], [], 1],
    'failing on --fail-on' => [['--fail-on' => ['morph_without_map']], [], 1],
    'passing on --fail-on' => [['--fail-on' => ['duplicate_index']], [], 0],
    'passing once ignored' => [[], [['kind' => 'foreign_key_without_index'], ['kind' => 'unresolved_relation'], ['kind' => 'model_without_table'], ['kind' => 'relation_without_foreign_key']], 0],
    'with an ignore rule by relation' => [[], [['relation' => 'Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post::broken']], 1],
]);

it('applies ignore rules added after the build to the snapshot', function (string $format): void {
    $before = snapshotModeRun('schema-viewer:check', ['--format' => $format, '--snapshot' => true]);
    config()->set('schema-viewer.hints.ignore', [['connection' => 'test*', 'table' => 'ghosts'], ['kind' => 'foreign_key_without_index', 'columns' => 'user_id']]);

    $live = snapshotModeRun('schema-viewer:check', ['--format' => $format]);
    $snapshot = snapshotModeRun('schema-viewer:check', ['--format' => $format, '--snapshot' => true]);

    expect($snapshot)->toBe($live)
        ->and($snapshot[1])->not->toBe($before[1])
        ->and($snapshot[1])->not->toContain('ghosts');
})->with(['text', 'json']);

it('cannot bring back a hint that was ignored when the snapshot was built', function (): void {
    config()->set('schema-viewer.hints.ignore', [['table' => 'ghosts']]);
    Artisan::call('schema-viewer:build');
    config()->set('schema-viewer.hints.ignore', []);

    [, $live] = snapshotModeRun('schema-viewer:check', ['--format' => 'json']);
    [, $snapshot] = snapshotModeRun('schema-viewer:check', ['--format' => 'json', '--snapshot' => true]);

    expect($live)->toContain('ghosts')
        ->and($snapshot)->not->toContain('ghosts');
});

it('refuses a snapshot that is missing, damaged or from laravel-schema-viewer 1.x', function (?string $contents, string $error): void {
    $contents === null ? unlink($this->snapshot) : file_put_contents($this->snapshot, $contents);

    $code = Artisan::call('schema-viewer:check', ['--snapshot' => true, '--format' => 'json']);
    $json = Json::decode(Artisan::output());

    expect($code)->toBe(2)
        ->and(array_keys($json))->toBe(['version', 'passed', 'error'])
        ->and($json['passed'])->toBeFalse()
        ->and($json['error'])->toContain($error);

    $this->artisan('schema-viewer:export', ['--snapshot' => true])->expectsOutputToContain($error)->assertExitCode(2);
    $this->artisan('schema-viewer:check', ['--snapshot' => true])->expectsOutputToContain($error)->assertExitCode(2);
})->with([
    'missing' => [null, 'No schema snapshot at [schema-viewer.json] yet. Run php artisan schema-viewer:build.'],
    'not JSON' => ['{', 'The schema snapshot at [schema-viewer.json] cannot be read: it is not JSON'],
    'from laravel-schema-viewer 1.x' => ['{"format": 2, "document": {"version": 1}}', 'The schema snapshot at [schema-viewer.json] was written by laravel-schema-viewer 1.x'],
]);

it('refuses a snapshot whose hints are incomplete', function (): void {
    $document = Json::decode((string) file_get_contents($this->snapshot));

    foreach ($document['diagnostics'] as $i => $diagnostic) {
        if (isset($diagnostic['extensions']['laravel'])) {
            unset($document['diagnostics'][$i]['extensions']['laravel']['table']);
        }
    }

    file_put_contents($this->snapshot, Json::canonical($document));

    $this->artisan('schema-viewer:check', ['--snapshot' => true])
        ->expectsOutputToContain('The schema snapshot at [schema-viewer.json] cannot be read: a diagnostic\'s extensions.laravel does not hold a whole hint')
        ->assertExitCode(2);
});

it('checks the options before reading the snapshot', function (): void {
    unlink($this->snapshot);

    $this->artisan('schema-viewer:check', ['--snapshot' => true, '--fail-on' => ['made_up']])
        ->expectsOutputToContain('Unknown hint kind [made_up].')
        ->assertExitCode(2);
    $this->artisan('schema-viewer:export', ['--snapshot' => true, '--format' => 'xml'])
        ->expectsOutputToContain('Unknown format [xml].')
        ->assertExitCode(2);
});
