<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function diffOutput(array $parameters = []): array
{
    $code = Artisan::call('schema-viewer:diff', $parameters);

    return [$code, Artisan::output()];
}

it('prints the changes the snapshot lists, without reading the database', function (): void {
    [$code, $output] = diffOutput();
    expect($code)->toBe(2)
        ->and($output)->toContain('No schema snapshot at [schema-viewer.json] yet. Run php artisan schema-viewer:build.');

    buildSnapshot();
    [$code, $output] = diffOutput();
    expect($code)->toBe(0)
        ->and($output)->toContain('Nothing to compare yet.');

    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    buildSnapshot();
    Schema::drop('gadgets');

    [$code, $output] = diffOutput();
    expect($code)->toBe(0)
        ->and($output)->toContain('testing.gadgets')
        ->and($output)->toContain('Table gadgets added: 1 column');

    [, $json] = diffOutput(['--format' => 'json']);
    expect(json_decode($json, true))->toEqual(['version' => 2, 'changes' => [['type' => 'table', 'change' => 'added', 'subject' => 'testing:gadgets', 'label' => 'gadgets', 'message' => '1 column', 'connection' => 'testing', 'table' => 'gadgets']]]);
});

it('compares an older snapshot with the schema now', function (): void {
    buildSnapshot();
    $base = dirname($this->snapshot).'/base.json';
    copy($this->snapshot, $base);
    Schema::table('posts', fn (Blueprint $table) => $table->string('subtitle')->nullable());

    [$code, $output] = diffOutput(['base' => $base]);

    expect($code)->toBe(0)
        ->and($output)->toContain('testing.posts')
        ->and($output)->toContain('Column posts.subtitle added');

    // Relative to the project root, and the snapshot is not written.
    $before = (string) file_get_contents($this->snapshot);
    [$code, $markdown] = diffOutput(['base' => 'base.json', '--format' => 'markdown']);

    expect($code)->toBe(0)
        ->and($markdown)->toStartWith("## Schema changes\n\n1 change.\n\n### testing.posts\n\n- Column posts.subtitle added: ")
        ->and((string) file_get_contents($this->snapshot))->toBe($before);
});

it('compares two snapshot files without the database or the models', function (): void {
    buildSnapshot();
    $base = dirname($this->snapshot).'/base.json';
    copy($this->snapshot, $base);
    Schema::create('gadgets', fn (Blueprint $table) => $table->id());
    buildSnapshot();
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/Unreadable']);

    [$code, $json] = diffOutput(['base' => $base, '--against' => $this->snapshot, '--format' => 'json']);
    [, $same] = diffOutput(['base' => $base, '--against' => $base]);
    [, $markdown] = diffOutput(['base' => $base, '--against' => $base, '--format' => 'markdown']);

    expect($code)->toBe(0)
        ->and(collect(json_decode($json, true)['changes'])->pluck('table')->all())->toBe(['gadgets'])
        ->and($same)->toContain('No schema changes.')
        ->and($markdown)->toBe("## Schema changes\n\nNo schema changes.\n");
});

it('refuses a base it cannot read', function (string $contents, string $message): void {
    buildSnapshot();
    file_put_contents(dirname($this->snapshot).'/base.json', $contents);

    $this->artisan('schema-viewer:diff', ['base' => 'base.json'])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);
})->with([
    'a laravel-schema-viewer 1.x snapshot' => ['{"format": 2, "document": {"version": 1}}', 'The schema snapshot at [base.json] was written by laravel-schema-viewer 1.x, which this version no longer reads.'],
    'laranex-schema 1.0' => ['{"format": "laranex-schema", "version": "1.0"}', 'Unsupported laranex-schema version "1.0"; this reader supports 2.x'],
    'not JSON' => ['{"format', 'The schema snapshot at [base.json] cannot be read: it is not JSON'],
]);

it('refuses a missing base, --against without a base and an unknown format', function (): void {
    $this->artisan('schema-viewer:diff', ['base' => 'nope.json'])->expectsOutputToContain('No file at [nope.json].')->assertExitCode(2);
    $this->artisan('schema-viewer:diff', ['--against' => 'x.json'])->expectsOutputToContain('--against compares two files')->assertExitCode(2);
    $this->artisan('schema-viewer:diff', ['--format' => 'xml'])->expectsOutputToContain('Unknown format [xml]. Use text, json or markdown.')->assertExitCode(2);

    buildSnapshot();
    $this->artisan('schema-viewer:diff', ['base' => 'schema-viewer.json', '--against' => 'nope.json'])->expectsOutputToContain('No file at [nope.json].')->assertExitCode(2);
});

it('reports a schema that cannot be read when comparing with now', function (): void {
    buildSnapshot();
    config()->set('schema-viewer.package_tables', 'oauth_*');

    $this->artisan('schema-viewer:diff', ['base' => 'schema-viewer.json'])
        ->expectsOutputToContain('The schema could not be read: schema-viewer.package_tables must be false')
        ->assertExitCode(1);
});
