<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

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
        ->and($mermaid)->toContain('        integer user_id FK')
        ->and($mermaid)->toContain('users ||--o{ posts : "user_id"')
        ->and($mermaid)->toContain('categories ||--o{ categories : "parent_id"')
        ->and($mermaid)->not->toContain('categories ||--o{ posts')
        ->and($mermaid)->not->toContain('::');
});

it('exports DBML with refs, delete rules and composite keys', function (): void {
    $dbml = exportAs('dbml');

    expect($dbml)->toContain('Table posts {')
        ->and($dbml)->toContain('  id integer [pk, increment, not null]')
        ->and($dbml)->toContain('    (user_id, role_id) [pk]')
        ->and($dbml)->toContain('Ref posts_user_id_foreign: posts.user_id > users.id [delete: cascade]')
        ->and($dbml)->not->toContain('category_id > categories')
        ->and($dbml)->not->toContain('::');
});

it('exports Markdown that describes the database only', function (): void {
    $markdown = exportAs('markdown');

    expect($markdown)->toContain('# Database schema: testing (sqlite)')
        ->and($markdown)->toContain('## posts')
        ->and($markdown)->toContain('| `user_id` | integer |  |  | FK → users.id |  |')
        ->and($markdown)->toContain('- `posts_user_id_foreign`: (user_id) → users (id), on update no action, on delete cascade')
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
