<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Validator;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\SideEffects\Noisy;

/**
 * The tables of the first connection in the schema the viewer page carries.
 *
 * @return list<string>
 */
function servedTables(mixed $test): array
{
    return collect(viewerConfig($test)['document']['connections'][0]['tables'])->pluck('name')->all();
}

/**
 * The changes kept beside the snapshot.
 *
 * @return list<array<string, mixed>>|null
 */
function keptChanges(): ?array
{
    return json_decode((string) file_get_contents(base_path('schema-viewer.changes.json')), true)['changes'];
}

it('writes the snapshot to schema-viewer.json in the project root', function (): void {
    buildSnapshot();

    expect(app(Snapshot::class)->path())->toBe(base_path('schema-viewer.json'))
        ->and(app(Snapshot::class)->displayPath())->toBe('schema-viewer.json')
        ->and(base_path('schema-viewer.json'))->toBeFile();
});

it('builds the snapshot as a strictly valid laranex-schema 2.0 document in canonical form', function (): void {
    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('Schema snapshot written to')
        ->assertSuccessful();

    $contents = (string) file_get_contents($this->snapshot);
    $document = Json::decode($contents);

    expect($contents)->toStartWith("{\n  \"comparisons\": [],\n  \"connections\": [")
        ->and($contents)->toBe(Json::canonical($document))
        ->and(Validator::validate($document, strict: true))->toBe([])
        ->and($document)->toMatchArray(['format' => 'laranex-schema', 'version' => '2.0', 'source' => 'combined'])
        ->and($document)->not->toHaveKeys(['downloads', 'changes'])
        ->and(keptChanges())->toBeNull();
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

    expect($config['document']['models'][0]['className'])->toBe(Noisy::class)
        ->and(Noisy::$calls)->toBe([]);
});

it('carries the exact document, empty objects included', function (): void {
    buildSnapshot();

    $html = (string) $this->get('/schema-viewer')->getContent();
    $expected = strtr(rtrim((string) file_get_contents($this->snapshot)), ['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026']);

    expect($html)->toContain('{"document":'.$expected.',"changes":null,')
        ->and(Json::canonical(Json::decode(json_encode(viewerConfig($this)['document']) ?: '')))->toBe((string) file_get_contents($this->snapshot));
});

it('tells how to build the snapshot when there is none', function (): void {
    expect(viewerConfig($this))->toBe([
        'missing' => 'No schema snapshot at [schema-viewer.json] yet. Run php artisan schema-viewer:build.',
        'editor' => null,
        'defaultConnection' => null,
    ]);

    buildSnapshot();

    expect(viewerConfig($this))->toHaveKeys(['document', 'changes'])
        ->and(viewerConfig($this))->not->toHaveKey('missing');
});

it('tells how to build the snapshot again when it is damaged, from laravel-schema-viewer 1.x or another version', function (string $contents, string $reason): void {
    file_put_contents($this->snapshot, $contents);

    $config = viewerConfig($this);

    expect($config['missing'])->toStartWith('The schema snapshot at [schema-viewer.json] ')
        ->and($config['missing'])->toContain($reason)
        ->and($config['missing'])->toEndWith('Run php artisan schema-viewer:build to write it again'.(str_contains($reason, '1.x') ? ' as a laranex-schema 2.0 document.' : '.'))
        ->and($config)->not->toHaveKey('document');
})->with([
    'not JSON' => ['{"format": 2, "docu', 'cannot be read: it is not JSON'],
    'a laravel-schema-viewer 1.x snapshot' => ['{"format": 2, "document": {"version": 1, "connections": []}, "downloads": {}, "changes": null}', 'was written by laravel-schema-viewer 1.x, which this version no longer reads'],
    'laranex-schema 1.0' => ['{"format": "laranex-schema", "version": "1.0"}', 'cannot be read: Unsupported laranex-schema version "1.0"; this reader supports 2.x'],
    'another format' => ['{"format": "something-else"}', 'cannot be read: Not a laranex-schema document'],
    'an invalid 2.0 document' => ['{"format": "laranex-schema", "version": "2.0"}', 'cannot be read: Invalid laranex-schema document: $: missing source'],
]);

it('reads a newer 2.x minor leniently, ignoring what it does not know', function (): void {
    buildSnapshot();
    $document = Json::decode((string) file_get_contents($this->snapshot));
    file_put_contents($this->snapshot, Json::canonical(['version' => '2.7', 'future' => ['field' => true]] + $document));

    expect(viewerConfig($this)['document']['version'])->toBe('2.7');
});

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
        ->expectsOutputToContain('Table gadgets added: 1 column')
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
    $data = Json::decode((string) file_get_contents($this->snapshot));

    $contents = match ($edit) {
        'legacy' => (string) json_encode(['format' => 2, 'document' => ['version' => 1, 'connections' => []], 'downloads' => [], 'changes' => null]),
        'formatting' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'diagnostic' => Json::canonical(['diagnostics' => array_slice($data['diagnostics'], 1)] + $data),
        'model' => Json::canonical(['models' => [['primaryKey' => ['uuid']] + $data['models'][0], ...array_slice($data['models'], 1)]] + $data),
    };
    file_put_contents($this->snapshot, $contents);

    $this->artisan('schema-viewer:build', ['--check' => true])
        ->expectsOutputToContain($line)
        ->assertFailed();
})->with([
    'a laravel-schema-viewer 1.x snapshot' => ['legacy', 'was written by laravel-schema-viewer 1.x'],
    'formatting only' => ['formatting', 'Only its formatting differs.'],
    'only the diagnostics' => ['diagnostic', 'such as the diagnostics'],
    'a model' => ['model', 'Model Laranex\\LaravelSchemaViewer\\Tests\\Fixtures\\Models\\Category changed: primaryKey ["uuid"] → ["id"]'],
]);

it('keeps the listed changes when a build finds only the diagnostics different', function (): void {
    buildSnapshot();
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    buildSnapshot();
    $before = json_decode((string) file_get_contents($this->snapshot), true);
    $changes = keptChanges();

    // A rule or setting change can add or remove hints while the schema stays the same.
    config()->set('schema-viewer.hints.ignore', [['table' => '*']]);
    buildSnapshot();
    $after = json_decode((string) file_get_contents($this->snapshot), true);
    $hints = fn (array $document): array => array_filter($document['diagnostics'], fn (array $d): bool => isset($d['extensions']['laravel']['hint']) && $d['subject']['type'] !== 'document' && $d['subject']['type'] !== 'relationship');

    expect($hints($before))->not->toBe([])
        ->and($hints($after))->toBe([])
        ->and($changes)->not->toBe([])
        ->and(keptChanges())->toBe($changes);
});

it('gives the page no changes that belong to another snapshot', function (): void {
    buildSnapshot();
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    buildSnapshot();

    expect(viewerConfig($this)['changes'])->not->toBeNull();

    // The snapshot built elsewhere, without its changes file.
    file_put_contents($this->snapshot, str_replace('"note": null', '"note": "Built elsewhere."', (string) file_get_contents($this->snapshot)));

    expect(viewerConfig($this)['changes'])->toBeNull();
});

it('lists what changed since the snapshot it replaces, and keeps that list while the schema stays the same', function (): void {
    buildSnapshot();
    $changes = fn (): mixed => keptChanges();

    expect($changes())->toBeNull();

    buildSnapshot();
    expect($changes())->toBeNull();

    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    Schema::table('posts', fn (Blueprint $table) => $table->string('subtitle')->nullable());
    buildSnapshot();
    $first = $changes();

    expect(collect($first)->map(fn (array $change): string => $change['type'].' '.$change['change'].' '.$change['label'].' '.($change['subject'] ?? ''))->all())
        ->toContain('table added gadgets testing:gadgets', 'column added posts.subtitle testing:posts');

    $contents = (string) file_get_contents($this->snapshot);
    buildSnapshot();

    expect((string) file_get_contents($this->snapshot))->toBe($contents)
        ->and($changes())->toBe($first);

    $this->artisan('schema-viewer:build', ['--check' => true])->assertSuccessful();

    expect(viewerConfig($this)['changes'])->toBe($first);
});

it('gives the page no changes before a second build', function (): void {
    buildSnapshot();

    expect(viewerConfig($this))->toHaveKey('changes')
        ->and(viewerConfig($this)['changes'])->toBeNull();
});
