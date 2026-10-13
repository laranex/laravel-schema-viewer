<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\SideEffects\Noisy;

/**
 * The tables of the first connection in the schema the viewer page carries.
 *
 * @return list<string>
 */
function servedTables(mixed $test): array
{
    return collect(viewerConfig($test)['schema']['connections'][0]['tables'])->pluck('name')->all();
}

it('writes the snapshot to schema-viewer.json in the project root', function (): void {
    buildSnapshot();

    expect(app(Snapshot::class)->path())->toBe(base_path('schema-viewer.json'))
        ->and(app(Snapshot::class)->displayPath())->toBe('schema-viewer.json')
        ->and(base_path('schema-viewer.json'))->toBeFile();
});

it('builds the snapshot as readable JSON with the document and every download', function (): void {
    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('Schema snapshot written to')
        ->assertSuccessful();

    $contents = (string) file_get_contents($this->snapshot);
    $data = json_decode($contents, true);

    expect($contents)->toStartWith("{\n    \"format\": 2,\n    \"document\": {\n        \"version\": 1,")
        ->and($contents)->toEndWith("}\n")
        ->and(array_keys($data))->toBe(['format', 'document', 'downloads'])
        ->and(array_keys($data['downloads']['testing']))->toBe(['mermaid', 'dbml', 'markdown'])
        ->and($data['downloads']['testing']['mermaid'])->toStartWith('erDiagram');
});

it('writes the same file for the same schema, without when it was built', function (): void {
    $read = function (): string {
        buildSnapshot();

        return (string) file_get_contents($this->snapshot);
    };

    expect($read())->toBe($read())
        ->and($read())->not->toContain('generatedAt');
});

it('replaces the snapshot in one step and leaves no temporary file', function (): void {
    buildSnapshot();
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    buildSnapshot();

    expect(glob(dirname($this->snapshot).'/{,.}*.tmp', GLOB_BRACE))->toBe([])
        ->and(servedTables($this))->toContain('gadgets');
});

it('serves only the snapshot: tables changed later show after the next build', function (): void {
    buildSnapshot();
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());

    expect(servedTables($this))->not->toContain('gadgets');

    buildSnapshot();

    expect(servedTables($this))->toContain('gadgets');
});

it('runs no model code in any web request', function (): void {
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/SideEffects']);
    Http::fake();
    buildSnapshot();
    Noisy::$calls = [];

    $config = viewerConfig($this);
    viewerConfig($this, ['Accept-Encoding' => 'gzip']);

    expect($config['schema']['models'][0]['class'])->toBe(Noisy::class)
        ->and(Noisy::$calls)->toBe([]);
});

it('carries the exact document, empty objects included', function (): void {
    $document = buildSnapshot()->jsonSerialize();
    unset($document['generatedAt']);

    $html = (string) $this->get('/schema-viewer')->getContent();
    $expected = strtr((string) json_encode($document, JSON_UNESCAPED_SLASHES), ['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026']);

    expect($html)->toContain('"schema":'.$expected.',"downloads":');
});

it('carries each connection\'s Mermaid, DBML and Markdown downloads', function (): void {
    buildSnapshot();

    $downloads = viewerConfig($this)['downloads'];

    expect(array_keys($downloads))->toBe(['testing'])
        ->and(array_keys($downloads['testing']))->toBe(['mermaid', 'dbml', 'markdown'])
        ->and($downloads['testing']['mermaid'])->toStartWith('erDiagram');
});

it('tells how to build the snapshot when there is none', function (): void {
    expect(viewerConfig($this))->toBe([
        'defaultConnection' => 'all',
        'editor' => null,
        'missing' => 'No schema snapshot at [schema-viewer.json] yet. Run php artisan schema-viewer:build.',
    ]);

    buildSnapshot();

    expect(viewerConfig($this))->toHaveKeys(['schema', 'downloads'])
        ->and(viewerConfig($this)['missing'])->toBeNull();
});

it('tells how to build the snapshot again when it is damaged or from another version', function (string $contents): void {
    file_put_contents($this->snapshot, $contents);

    $config = viewerConfig($this);

    expect($config['missing'])->toBe('The schema snapshot at [schema-viewer.json] cannot be read: it is damaged or was written by another version. Run php artisan schema-viewer:build.')
        ->and($config)->not->toHaveKey('schema');
})->with([
    'not JSON' => '{"format": 2, "docu',
    'another format' => '{"format": 1, "document": {"connections": []}, "downloads": {}}',
    'a connection without downloads' => '{"format": 2, "document": {"connections": [{"name": "testing"}]}, "downloads": {}}',
]);

it('keeps the application\'s jobs, events, cache and HTTP calls from the outside world while building', function (): void {
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/SideEffects']);
    config()->set('queue.default', 'sync');
    config()->set('cache.default', 'array');
    $bus = Bus::getFacadeRoot();
    $events = Event::getFacadeRoot();
    $http = Http::getFacadeRoot();
    $announced = 0;
    Event::listen('noisy.announced', function () use (&$announced): void {
        $announced++;
    });

    $this->artisan('schema-viewer:build')->assertSuccessful();

    expect(Cache::get('noisy.cache'))->toBeNull()
        ->and(Cache::get('noisy.job'))->toBeNull()
        ->and($announced)->toBe(0)
        ->and(Bus::getFacadeRoot())->toBe($bus)
        ->and(Event::getFacadeRoot())->toBe($events)
        ->and(Http::getFacadeRoot())->toBe($http)
        ->and(config('cache.default'))->toBe('array');

    // Put back: the application's own calls work again after the build.
    event('noisy.announced');
    expect($announced)->toBe(1);
});

it('reports a build that fails', function (): void {
    // A folder where the file goes cannot be replaced by it.
    mkdir($this->snapshot);

    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('The schema snapshot could not be built')
        ->assertFailed();
});

it('checks that the snapshot is up to date without writing it', function (): void {
    buildSnapshot();
    $before = (string) file_get_contents($this->snapshot);
    $time = filemtime($this->snapshot);

    $this->artisan('schema-viewer:build', ['--check' => true])
        ->expectsOutputToContain('Schema snapshot [schema-viewer.json] is up to date.')
        ->assertSuccessful();

    Schema::create('gadgets', fn (Blueprint $table) => $table->id());

    $this->artisan('schema-viewer:build', ['--check' => true])
        ->expectsOutputToContain('The schema snapshot [schema-viewer.json] is out of date. Run php artisan schema-viewer:build and commit it.')
        ->expectsOutputToContain('Tables added: testing.gadgets.')
        ->assertFailed();

    clearstatcache();
    expect((string) file_get_contents($this->snapshot))->toBe($before)
        ->and(filemtime($this->snapshot))->toBe($time);
});

it('fails the check when there is no snapshot', function (): void {
    $this->artisan('schema-viewer:build', ['--check' => true])
        ->expectsOutputToContain('No schema snapshot at [schema-viewer.json]. Run php artisan schema-viewer:build and commit it.')
        ->assertFailed();

    expect($this->snapshot)->not->toBeFile();
});

it('reads a snapshot checked out with CRLF line endings or a BOM as up to date', function (): void {
    buildSnapshot();
    file_put_contents($this->snapshot, "\u{FEFF}".str_replace("\n", "\r\n", (string) file_get_contents($this->snapshot)));

    $this->artisan('schema-viewer:build', ['--check' => true])->assertSuccessful();
});

it('says what kind of difference the check found', function (string $edit, string $line): void {
    buildSnapshot();
    $data = json_decode((string) file_get_contents($this->snapshot), true);

    $contents = match ($edit) {
        'format' => (string) json_encode(['format' => 1] + $data),
        'formatting' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'hint' => (string) json_encode(['document' => ['hints' => array_slice($data['document']['hints'], 1)] + $data['document']] + $data, JSON_PRETTY_PRINT),
        'model' => (string) json_encode(['document' => ['models' => array_slice($data['document']['models'], 1)] + $data['document']] + $data, JSON_PRETTY_PRINT),
    };
    file_put_contents($this->snapshot, $contents);

    $this->artisan('schema-viewer:build', ['--check' => true])
        ->expectsOutputToContain($line)
        ->assertFailed();
})->with([
    'another format' => ['format', 'It was written by another version of laravel-schema-viewer.'],
    'formatting only' => ['formatting', 'Only its formatting differs.'],
    'a hint' => ['hint', 'Hints: 1 added, 0 removed.'],
    'a model' => ['model', 'Models: 1 added, 0 removed.'],
]);
