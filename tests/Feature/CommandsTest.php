<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Validator;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;

function exportAs(string $format): string
{
    Artisan::call('schema-viewer:export', ['--format' => $format]);

    return Artisan::output();
}

it('exports the whole document as laranex-schema 2.0 JSON', function (): void {
    $text = exportAs('json');
    $json = Json::decode($text);

    expect($json['version'])->toBe('2.0')
        ->and($text)->toBe(Json::canonical($json))
        ->and(Validator::validate($json, strict: true))->toBe([])
        ->and($json['connections'][0]['name'])->toBe('testing');
});

it('exports the ORM declarations with --orm, labelled apart from the foreign keys', function (): void {
    Artisan::call('schema-viewer:export', ['--format' => 'mermaid', '--orm' => true]);
    $mermaid = Artisan::output();
    Artisan::call('schema-viewer:export', ['--format' => 'markdown', '--orm' => true]);

    expect($mermaid)->toContain('posts }o..o| users : "ORM: Post::author (BelongsTo)"')
        ->and(Artisan::output())->toContain('# ORM declarations');
});

it('exports a Mermaid diagram of the database: tables and foreign keys only', function (): void {
    $mermaid = exportAs('mermaid');

    expect($mermaid)->toStartWith('erDiagram
')
        ->and($mermaid)->toContain('    posts {')
        ->and($mermaid)->toMatch('/\n        \w+ user_id FK\n/')
        ->and($mermaid)->toContain('users ||--o{ posts : "user_id"')
        ->and($mermaid)->toContain('categories |o--o{ categories : "parent_id"')
        ->and($mermaid)->not->toContain('categories ||--o{ posts')
        ->and($mermaid)->not->toContain('Post::');
});

it('exports DBML with refs, delete rules and composite keys', function (): void {
    $dbml = exportAs('dbml');

    expect($dbml)->toContain('Table posts {')
        ->and($dbml)->toMatch('/\n  id .+ \[pk, increment, not null\]\n/')
        ->and($dbml)->toContain('    (user_id, role_id) [pk]')
        // MariaDB reports the default update rule as restrict, which InnoDB treats the same as no action.
        ->and($dbml)->toMatch('/Ref posts_user_id_foreign: posts\.user_id > users\.id \[delete: cascade(, update: restrict)?\]/')
        ->and($dbml)->not->toContain('category_id > categories')
        ->and($dbml)->not->toContain('Post::');
});

it('exports DBML for a table with an index on an expression', function (): void {
    $statement = match (testDriver()) {
        'mysql' => 'create index users_lower_email_index on users ((lower(email)))',
        'sqlite', 'pgsql' => 'create index users_lower_email_index on users (lower(email))',
        // MariaDB and SQL Server index computed columns, not expressions.
        default => null,
    };

    if ($statement === null) {
        $this->markTestSkipped(testDriver().' has no expression indexes.');
    }

    DB::statement($statement);

    expect(exportAs('dbml'))->toContain('Table users {')
        ->not->toContain('users_lower_email_index');
});

it('exports Markdown that describes the database only', function (): void {
    $markdown = exportAs('markdown');

    expect($markdown)->toContain('# Database schema: testing ('.(testDriver() === 'pgsql' ? 'postgresql' : testDriver()).')')
        ->and($markdown)->toContain('## posts')
        ->and($markdown)->toMatch('/\| `user_id` \| .+ \|  \|  \| FK → users.id \|  \|/')
        ->and($markdown)->toMatch('/- `posts_user_id_foreign`: \(user_id\) → users \(id\), on update (no action|restrict), on delete cascade/')
        ->and($markdown)->toContain('- `posts_category_id_index` (category_id)')
        ->and($markdown)->not->toContain('Model')
        ->and($markdown)->not->toContain('Hints');
});

it('writes the export to a file', function (): void {
    $path = sys_get_temp_dir().'/schema-'.uniqid().'.mmd';

    $this->artisan('schema-viewer:export', ['--format' => 'mermaid', '--output' => $path])->assertSuccessful();

    expect((string) file_get_contents($path))->toStartWith('erDiagram');
    unlink($path);
});

it('exports the viewer as one standalone HTML file that needs no server', function (): void {
    $folder = sys_get_temp_dir().'/schema-viewer-'.uniqid();

    $this->artisan('schema-viewer:export', ['--format' => 'html', '--output' => $folder])->assertSuccessful();

    $html = (string) file_get_contents($folder.'/index.html');
    $config = offlineData($html);
    preg_match('/<script type="module" id="lsv-offline-app">(.*)<\/script>\n<\/body>/s', $html, $script);
    preg_match('/script-src \'sha256-([^\']+)\'/', $html, $hash);

    expect($html)->toStartWith('<!doctype html>')
        ->and($html)->toContain('<div id="schema-viewer"></div>')
        ->and($html)->toContain('url(data:font/woff2;base64,')
        ->and($html)->toContain('<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22')
        ->and($html)->not->toContain('./assets/')
        ->and($html)->not->toContain('.woff)')
        ->and(substr_count(strtolower($html), '</script'))->toBe(2)
        // The page allows its one script by hash, and nothing from the network.
        ->and($html)->toContain("<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; script-src 'sha256-")
        ->and(base64_encode(hash('sha256', $script[1], true)))->toBe($hash[1])
        ->and($script[1])->toBe(preg_replace('/<(\/?script|!--)/i', '\\x3C$1', (new ViewerAssets)->contents('offline.js')))
        ->and(array_keys($config))->toBe(['document', 'changes', 'defaultConnection'])
        ->and($config['defaultConnection'])->toBeNull()
        ->and($config['document']['version'])->toBe('2.0')
        ->and($config['document']['connections'][0]['name'])->toBe('testing')
        ->and($config['document']['relationships'])->not->toBeEmpty()
        // No editor links: they would carry the application's paths.
        ->and($config)->not->toHaveKey('editor');

    unlink($folder.'/index.html');
    rmdir($folder);
});

it('writes the standalone viewer to a named HTML file', function (): void {
    config()->set('schema-viewer.default_connection', 'testing');
    $path = sys_get_temp_dir().'/schema-'.uniqid().'.html';

    $this->artisan('schema-viewer:export', ['--format' => 'html', '--output' => $path])
        ->expectsOutputToContain('Schema exported to')
        ->assertSuccessful();

    expect(offlineData((string) file_get_contents($path))['defaultConnection'])->toBe('connection:testing');
    unlink($path);
});

it('keeps the document from ending the script it is carried in', function (): void {
    $html = (new HtmlExporter)->export(['note' => '</script><script>alert(1)</script><!--'] + Json::decode(exportAs('json')));

    expect(substr_count(strtolower($html), '</script'))->toBe(2)
        ->and(offlineData($html)['document']['note'])->toBe('</script><script>alert(1)</script><!--');
});

it('reports a missing viewer asset', function (): void {
    expect(fn () => (new HtmlExporter(new ViewerAssets(sys_get_temp_dir().'/no-viewer-'.uniqid())))->export(Json::decode(exportAs('json'))))
        ->toThrow(RuntimeException::class, 'The viewer manifest at');
});

it('rejects an unknown format or connection', function (): void {
    $this->artisan('schema-viewer:export', ['--format' => 'png'])->assertExitCode(2);
    $this->artisan('schema-viewer:export', ['--format' => 'dbml', '--connection' => 'nope'])->assertExitCode(2);
});

it('fails the check on the configured hint kinds', function (): void {
    $this->artisan('schema-viewer:check')
        ->expectsOutputToContain("'table' => 'ghosts', 'kind' => 'model_without_table'")
        ->assertFailed();
});

it('marks only likely hints as likely in the check', function (): void {
    Schema::create('attachments', function (Blueprint $table): void {
        $table->id();
        $table->string('attachable_type');
        $table->unsignedBigInteger('attachable_id');
    });

    Artisan::call('schema-viewer:check');
    $lines = explode("\n", Artisan::output());
    $marked = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, '· likely')));
    $likely = array_filter(app(SchemaViewer::class)->document()->hints, fn ($hint): bool => $hint->kind->certainty() === 'likely');

    expect($marked)->toHaveCount(count($likely))
        ->and($marked)->not->toBeEmpty();

    foreach ($marked as $line) {
        expect(collect($likely)->contains(fn ($hint): bool => str_contains($line, $hint->kind->value)))->toBeTrue();
    }
});

it('passes the check when only other kinds remain', function (): void {
    $this->artisan('schema-viewer:check', ['--fail-on' => ['relation_without_foreign_key']])->assertFailed();

    config()->set('schema-viewer.hints.ignore', [['kind' => 'relation_without_foreign_key'], ['kind' => 'model_without_table'], ['kind' => 'unresolved_relation'], ['kind' => 'foreign_key_without_index']]);

    $this->artisan('schema-viewer:check')->assertSuccessful();
    $this->artisan('schema-viewer:check', ['--fail-on' => ['made_up']])->assertExitCode(2);

    config()->set('schema-viewer.hints.ignore', [['tabel' => 'posts']]);

    $this->artisan('schema-viewer:check')
        ->expectsOutputToContain('Unknown hints.ignore field [tabel]')
        ->assertExitCode(2);
});

/**
 * The data of a standalone HTML export: its document, changes and default connection.
 *
 * @return array<string, mixed>
 */
function offlineData(string $html): array
{
    preg_match('/<script type="application\/json" id="schema-viewer-data">(.+?)<\/script>/s', $html, $match);

    return json_decode($match[1] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
}

/**
 * schema-viewer:check --format=json: the exit code and the decoded document.
 *
 * @param  array<string, mixed>  $options
 * @return array{int, array<string, mixed>, string}
 */
function checkJson(array $options = []): array
{
    $code = Artisan::call('schema-viewer:check', ['--format' => 'json', '--ansi' => true, ...$options]);
    $output = Artisan::output();

    return [$code, json_decode($output, true, 512, JSON_THROW_ON_ERROR), $output];
}

it('prints the check as JSON for scripts and agents', function (): void {
    [$code, $json, $output] = checkJson();
    $hints = app(SchemaViewer::class)->document()->hints;

    expect($code)->toBe(1)
        ->and($output)->not->toContain("\e[")
        ->and($output)->toEndWith("}\n")
        ->and(array_keys($json))->toBe(['version', 'passed', 'failOn', 'summary', 'hints'])
        ->and($json['version'])->toBe(1)
        ->and($json['passed'])->toBeFalse()
        ->and($json['failOn'])->toBe(['foreign_key_without_index', 'unresolved_relation', 'model_without_table'])
        ->and($json['summary']['hints'])->toBe(count($hints))
        ->and($json['summary']['failing'])->toBe(count(array_filter($json['hints'], fn (array $hint): bool => $hint['fails'])))
        ->and(array_sum($json['summary']['bySeverity']))->toBe(count($hints))
        ->and(array_sum($json['summary']['byCertainty']))->toBe(count($hints))
        ->and(array_sum($json['summary']['byKind']))->toBe(count($hints))
        ->and($json['summary']['byKind']['model_without_table'])->toBe(1)
        ->and(array_keys($json['hints'][0]))->toBe(['kind', 'severity', 'certainty', 'connection', 'table', 'columns', 'relation', 'message', 'fails', 'ignore', 'ignorePhp'])
        ->and(array_column($json['hints'], 'message'))->toBe(array_map(fn ($hint): string => $hint->message, $hints));

    Artisan::call('schema-viewer:check');
    $text = Artisan::output();

    foreach ($json['hints'] as $hint) {
        expect($text)->toContain('Ignore it: '.$hint['ignorePhp'])
            ->and($hint['fails'])->toBe(in_array($hint['kind'], $json['failOn'], true));
    }
});

it('marks the failing hints by --fail-on in the JSON', function (): void {
    [$code, $json] = checkJson(['--fail-on' => ['morph_without_map']]);

    expect($code)->toBe(1)
        ->and($json['failOn'])->toBe(['morph_without_map'])
        ->and(collect($json['hints'])->where('fails', true)->pluck('kind')->unique()->values()->all())->toBe(['morph_without_map']);

    config()->set('schema-viewer.hints.ignore', [['connection' => 'test*'], ['table' => 'ghosts']]);
    [$code, $json, $output] = checkJson();

    expect($code)->toBe(0)
        ->and($json['passed'])->toBeTrue()
        ->and($json['hints'])->toBe([])
        ->and($output)->toContain('"bySeverity": {}');
});

it('reports a check that cannot run as JSON', function (array $config, array $options, int $code, string $error): void {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    [$exit, $json, $output] = checkJson($options);

    expect($exit)->toBe($code)
        ->and($json)->toBe(['version' => 1, 'passed' => false, 'error' => $error])
        ->and($output)->not->toContain("\e[");
})->with([
    'an unknown kind' => [[], ['--fail-on' => ['made_up']], 2, 'Unknown hint kind [made_up].'],
    'a bad ignore rule' => [['schema-viewer.hints.ignore' => [['tabel' => 'posts']]], [], 2, 'Unknown hints.ignore field [tabel]; use connection, table, kind, columns, relation, certainty.'],
    'a schema that cannot be read' => [['schema-viewer.package_tables' => 'oauth_*'], [], 1, 'The schema could not be read: schema-viewer.package_tables must be false or a list of table patterns by package name.'],
]);

it('rejects an unknown check format', function (): void {
    $this->artisan('schema-viewer:check', ['--format' => 'xml'])
        ->expectsOutputToContain('Unknown format [xml]. Use text or json.')
        ->assertExitCode(2);
});
