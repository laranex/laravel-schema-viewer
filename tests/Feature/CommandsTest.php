<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;

function exportAs(string $format): string
{
    Artisan::call('schema-viewer:export', ['--format' => $format]);

    return Artisan::output();
}

it('exports the whole document as JSON', function (): void {
    $json = json_decode(exportAs('json'), true);

    expect($json['version'])->toBe(1)
        ->and($json['connections'][0]['name'])->toBe('testing');
});

it('exports a Mermaid diagram of the database: tables and foreign keys only', function (): void {
    $mermaid = exportAs('mermaid');

    expect($mermaid)->toStartWith('erDiagram
')
        ->and($mermaid)->toContain('    posts {')
        ->and($mermaid)->toMatch('/\n        \w+ user_id FK\n/')
        ->and($mermaid)->toContain('users ||--o{ posts : "user_id"')
        ->and($mermaid)->toContain('categories ||--o{ categories : "parent_id"')
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

it('exports Markdown that describes the database only', function (): void {
    $markdown = exportAs('markdown');

    expect($markdown)->toContain('# Database schema: testing ('.testDriver().')')
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
    preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/', $html, $match);
    $config = json_decode($match[1], true);

    expect($html)->toStartWith('<!doctype html>')
        ->and($html)->toContain('<div id="root"></div>')
        ->and($html)->toContain('url(data:font/woff2;base64,')
        ->and($html)->not->toContain('./assets/')
        ->and(substr_count(strtolower($html), '</script'))->toBe(2)
        ->and($config)->not->toHaveKey('schemaUrl')
        ->and($config['defaultConnection'])->toBe('all')
        ->and($config['schema']['connections'][0]['name'])->toBe('testing')
        ->and($config['schema']['relations'])->not->toBeEmpty()
        ->and($config['downloads']['testing']['mermaid'])->toStartWith('erDiagram')
        ->and($config['downloads']['testing']['dbml'])->toContain('Table posts {')
        ->and($config['downloads']['testing']['markdown'])->toContain('## posts');

    unlink($folder.'/index.html');
    rmdir($folder);
});

it('writes the standalone viewer to a named HTML file', function (): void {
    config()->set('schema-viewer.default_connection', 'testing');
    $path = sys_get_temp_dir().'/schema-'.uniqid().'.html';

    $this->artisan('schema-viewer:export', ['--format' => 'html', '--output' => $path])
        ->expectsOutputToContain('Schema exported to')
        ->assertSuccessful();

    expect((string) file_get_contents($path))->toContain('window.__SCHEMA_VIEWER__')
        ->toContain('"defaultConnection":"testing"');
    unlink($path);
});

it('reports a missing viewer asset', function (): void {
    expect(fn () => (new HtmlExporter(sys_get_temp_dir().'/no-dist-'.uniqid()))->export(app(SchemaViewer::class)->document()))
        ->toThrow(RuntimeException::class, 'The viewer asset [app.js] is missing');
});

it('rejects an unknown format or connection', function (): void {
    $this->artisan('schema-viewer:export', ['--format' => 'png'])->assertExitCode(2);
    $this->artisan('schema-viewer:export', ['--format' => 'dbml', '--connection' => 'nope'])->assertExitCode(2);
});

it('fails the check on the configured hint kinds', function (): void {
    $this->artisan('schema-viewer:check')
        ->expectsOutputToContain('model_without_table')
        ->assertFailed();
});

it('passes the check when only other kinds remain', function (): void {
    $this->artisan('schema-viewer:check', ['--fail-on' => ['relation_without_foreign_key']])->assertFailed();

    config()->set('schema-viewer.hints.ignore', ['relation_without_foreign_key', 'model_without_table', 'unresolved_relation', 'foreign_key_without_index']);

    $this->artisan('schema-viewer:check')->assertSuccessful();
    $this->artisan('schema-viewer:check', ['--fail-on' => ['made_up']])->assertExitCode(2);
});
