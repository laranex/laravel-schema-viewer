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

it('exports a Mermaid diagram with foreign keys solid and relation-only links dotted', function (): void {
    $mermaid = exportAs('mermaid');

    expect($mermaid)->toStartWith("erDiagram\n")
        ->and($mermaid)->toContain('    posts {')
        ->and($mermaid)->toContain('        integer user_id FK')
        ->and($mermaid)->toContain('users ||--o{ posts : "Post::author(), Post::writer(), User::latestPost(), User::posts()"')
        ->and($mermaid)->toContain('categories ||..o{ posts : "Category::posts(), Post::category()"');
});

it('exports DBML with refs, delete rules and composite keys', function (): void {
    $dbml = exportAs('dbml');

    expect($dbml)->toContain('Table posts {')
        ->and($dbml)->toContain('  id integer [pk, increment, not null]')
        ->and($dbml)->toContain('    (user_id, role_id) [pk]')
        ->and($dbml)->toContain('Ref: posts.user_id > users.id [delete: cascade]')
        ->and($dbml)->toContain('Ref: posts.category_id > categories.id // relation only, no foreign key: Category::posts(), Post::category()');
});

it('exports Markdown that reads as a reference for people and agents', function (): void {
    $markdown = exportAs('markdown');

    expect($markdown)->toContain('# Database schema: testing (sqlite)')
        ->and($markdown)->toContain('## posts')
        ->and($markdown)->toContain('Model: `Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post`')
        ->and($markdown)->toContain('| `user_id` | integer |  |  | FK → users.id |  |')
        ->and($markdown)->toContain('- `author()` belongsTo `User` on posts.user_id → users.id (foreign key posts_user_id_foreign)')
        ->and($markdown)->toContain('- `category()` belongsTo `Category` on posts.category_id → categories.id (no foreign key)')
        ->and($markdown)->toContain('- `roles()` belongsToMany `Role` through pivot `role_user` (user_id, role_id)')
        ->and($markdown)->toContain('## Hints');
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
