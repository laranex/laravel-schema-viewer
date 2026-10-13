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
 * The schema the viewer's API serves.
 *
 * @return list<string>
 */
function servedTables(mixed $test): array
{
    return collect($test->getJson('/schema-viewer/api/schema')->assertOk()->json('connections.0.tables'))->pluck('name')->all();
}

/**
 * What the viewer page hands the app.
 *
 * @return array<string, mixed>
 */
function pageConfig(mixed $test): array
{
    preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/', (string) $test->get('/schema-viewer')->assertOk()->getContent(), $match);

    return json_decode($match[1], true);
}

it('writes the snapshot to schema-viewer.json in the project root unless told otherwise', function (): void {
    config()->set('schema-viewer.snapshot', null);
    expect(app(Snapshot::class)->path())->toBe(base_path('schema-viewer.json'));

    config()->set('schema-viewer.snapshot', 'storage/schema.json');
    expect(app(Snapshot::class)->path())->toBe(base_path('storage/schema.json'))
        ->and(app(Snapshot::class)->displayPath())->toBe('storage/schema.json');
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

    $this->get('/schema-viewer')->assertOk();
    $this->getJson('/schema-viewer/api/schema')->assertOk()->assertJsonPath('models.0.class', Noisy::class);
    $this->get('/schema-viewer/api/export?format=dbml')->assertOk();
    $this->getJson('/schema-viewer/api/export?format=all')->assertOk();

    expect(Noisy::$calls)->toBe([]);
});

it('answers 304 when the browser already has the snapshot, and compresses it with gzip', function (): void {
    buildSnapshot();

    $first = $this->getJson('/schema-viewer/api/schema')->assertOk();
    $etag = (string) $first->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])->get('/schema-viewer/api/schema')->assertStatus(304);

    $gzip = $this->withHeaders(['Accept-Encoding' => 'gzip, deflate, br'])->get('/schema-viewer/api/schema')
        ->assertOk()
        ->assertHeader('Content-Encoding', 'gzip')
        ->assertHeader('Vary', 'Accept-Encoding');

    expect(gzdecode((string) $gzip->getContent()))->toBe($first->getContent())
        ->and($gzip->headers->get('ETag'))->not->toBe($etag);

    buildSnapshot();

    $this->withHeaders(['If-None-Match' => $etag])->get('/schema-viewer/api/schema')->assertOk();
});

it('keeps empty objects and the exact document when serving the snapshot', function (): void {
    $document = buildSnapshot()->jsonSerialize();
    unset($document['generatedAt']);

    expect($this->getJson('/schema-viewer/api/schema')->getContent())->toBe(json_encode($document, JSON_UNESCAPED_SLASHES));
});

it('serves each connection\'s downloads from the snapshot', function (): void {
    buildSnapshot();

    $all = $this->getJson('/schema-viewer/api/export?format=all')->assertOk()->json();

    expect(array_keys($all['testing']))->toBe(['mermaid', 'dbml', 'markdown'])
        ->and($this->get('/schema-viewer/api/export?format=json')->assertOk()->json('name'))->toBe('testing');
});

it('tells how to build the snapshot when there is none', function (): void {
    $message = 'No schema snapshot at ['.str_replace('\\', '/', $this->snapshot).'] yet. Run php artisan schema-viewer:build.';

    $this->getJson('/schema-viewer/api/schema')->assertStatus(503)->assertJsonPath('message', $message);
    $this->getJson('/schema-viewer/api/export?format=all')->assertStatus(503)->assertJsonPath('message', $message);

    expect(pageConfig($this)['missing'])->toBe($message);

    buildSnapshot();

    expect(pageConfig($this)['missing'])->toBeNull();
});

it('tells how to build the snapshot again when it is damaged or from another version', function (string $contents): void {
    mkdir(dirname($this->snapshot), 0755, true);
    file_put_contents($this->snapshot, $contents);

    $this->getJson('/schema-viewer/api/schema')
        ->assertStatus(503)
        ->assertJsonPath('message', 'The schema snapshot at ['.str_replace('\\', '/', $this->snapshot).'] cannot be read: it is damaged or was written by another version. Run php artisan schema-viewer:build.');
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
    $file = tempnam(sys_get_temp_dir(), 'not-a-folder');
    config()->set('schema-viewer.snapshot', $file.DIRECTORY_SEPARATOR.'schema-viewer.json');

    try {
        $this->artisan('schema-viewer:build')
            ->expectsOutputToContain('The schema snapshot could not be built')
            ->assertFailed();
    } finally {
        @unlink($file);
    }
});
