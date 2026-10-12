<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->rebootWith('local', ['cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
});

/**
 * @return list<string>
 */
function servedTables(mixed $test, string $query = ''): array
{
    return collect($test->getJson('/schema-viewer/api/schema'.$query)->assertOk()->json('connections.0.tables'))->pluck('name')->all();
}

it('reads the schema once and serves it from the cache after that', function (): void {
    servedTables($this);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(servedTables($this))->toContain('posts')
        // Only the check for a migrations table runs; no table is read again.
        ->and($queries)->toBeLessThanOrEqual(2);
});

it('reads the schema again on refresh', function (): void {
    servedTables($this);
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());

    expect(servedTables($this))->not->toContain('gadgets')
        ->and(servedTables($this, '?refresh=1'))->toContain('gadgets')
        ->and(servedTables($this))->toContain('gadgets');
});

it('reads the schema again after a migration runs', function (): void {
    Schema::create('migrations', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    servedTables($this);

    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    DB::table('migrations')->insert(['migration' => '2026_10_13_000000_create_gadgets_table', 'batch' => 2]);

    expect(servedTables($this))->toContain('gadgets');
});

it('reads the schema again when the viewer config changes', function (): void {
    servedTables($this);
    config()->set('schema-viewer.exclude_tables', ['posts']);

    expect(servedTables($this))->not->toContain('posts');
});

it('reads the schema on every request when the cache is off', function (): void {
    config()->set('schema-viewer.cache.enabled', false);
    servedTables($this);
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());

    expect(servedTables($this))->toContain('gadgets');
});

it('serves the downloads from the cached schema', function (): void {
    servedTables($this);
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());

    expect($this->get('/schema-viewer/api/export?format=dbml')->assertOk()->getContent())->not->toContain('gadgets')
        ->and($this->get('/schema-viewer/api/export?format=json')->assertOk()->json('name'))->toBe('testing');
});

it('serves every connection\'s downloads at once', function (): void {
    $all = $this->getJson('/schema-viewer/api/export?format=all')->assertOk()->json();

    expect($all)->toHaveKey('testing')
        ->and(array_keys($all['testing']))->toBe(['mermaid', 'dbml', 'markdown'])
        ->and($all['testing']['mermaid'])->toStartWith('erDiagram')
        ->and($all['testing']['dbml'])->toBe($this->get('/schema-viewer/api/export?format=dbml')->getContent());
});

it('reads the schema again when a model file changes', function (): void {
    $file = dirname(__DIR__).'/Fixtures/Models/Tag.php';
    $mtime = (int) filemtime($file);
    servedTables($this);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    try {
        touch($file, $mtime + 60);
        clearstatcache();
        servedTables($this);
    } finally {
        touch($file, $mtime);
    }

    expect($queries)->toBeGreaterThan(2);
});
