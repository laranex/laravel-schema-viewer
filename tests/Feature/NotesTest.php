<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\Schema\Table;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

function notedTable(string $name): ?Table
{
    return app(SchemaViewer::class)->document()->connection('testing')?->table($name);
}

it('gives tables and columns the notes the config names', function (): void {
    config()->set('schema-viewer.notes', [
        'project' => 'The blog behind the tests.',
        'tables' => ['posts' => 'One row per article.', 'testing:users' => 'People who can sign in.', 'users' => 'Overridden.', 'other:posts' => 'Another connection.'],
        'columns' => ['posts' => ['status' => 'draft or published.', 'title' => 'Plain'], 'testing:posts' => ['title' => 'Prefixed wins.']],
    ]);

    $document = app(SchemaViewer::class)->document();
    $posts = $document->connection('testing')?->table('posts');

    expect($document->note)->toBe('The blog behind the tests.')
        ->and($posts?->note)->toBe('One row per article.')
        ->and($document->connection('testing')?->table('users')?->note)->toBe('People who can sign in.')
        ->and($document->connection('testing')?->table('tags')?->note)->toBeNull()
        ->and($posts?->column('status')?->note)->toBe('draft or published.')
        ->and($posts?->column('title')?->note)->toBe('Prefixed wins.')
        ->and($posts?->column('id')?->note)->toBeNull();

    $written = (new Producer)->document($document);
    $table = collect($written['connections'][0]['tables'])->firstWhere('id', 'testing:posts');

    expect($written['note'])->toBe('The blog behind the tests.')
        ->and($table['note'])->toBe('One row per article.')
        ->and(collect($table['columns'])->firstWhere('name', 'status')['note'])->toBe('draft or published.');
});

it('gives relationships the notes the config names, by the table and columns that hold the key', function (): void {
    config()->set('schema-viewer.notes', [
        'relationships' => [
            'posts' => ['user_id' => 'The author.', 'category_id' => 'No foreign key on purpose.'],
            'comments' => ['user_id' => 'Plain.'],
            'testing:comments' => ['user_id' => 'Prefixed wins.'],
        ],
    ]);

    $links = collect(app(SchemaViewer::class)->document()->links);
    $from = fn (string $table, string $columns): ?string => $links->first(fn ($link): bool => $link->from->table === $table && implode(',', $link->from->columns) === $columns)?->note;

    expect($from('posts', 'user_id'))->toBe('The author.')
        ->and($from('posts', 'category_id'))->toBe('No foreign key on purpose.')
        ->and($from('comments', 'user_id'))->toBe('Prefixed wins.')
        ->and($from('taggables', 'tag_id'))->toBeNull();

    // In the document, a relationship carries the note of a line it draws, and a foreign key no relationship covers
    // keeps its own; the shared graph gives each line the note of its first relationship.
    $written = (new Producer)->document(app(SchemaViewer::class)->document());
    $line = fn (string $table, string $column): ?array => collect($written['links'])->first(fn (array $l): bool => $l['from'] === ['table' => 'testing:'.$table, 'columns' => [$column]]);
    $comments = collect($written['connections'][0]['tables'])->firstWhere('id', 'testing:comments');

    expect($line('posts', 'user_id')['note'])->toBe('The author.')
        ->and($line('posts', 'category_id')['note'])->toBe('No foreign key on purpose.')
        ->and(collect($written['relationships'])->firstWhere('id', Models\Post::class.'::author')['note'])->toBe('The author.')
        ->and($line('comments', 'user_id')['relationships'])->toBe([])
        ->and($comments['foreignKeys'][0]['extensions'])->toBe(['laravel' => ['note' => 'Prefixed wins.']]);
});

it('keeps the database comment and adds the note next to it in the exports', function (): void {
    config()->set('schema-viewer.notes', [
        'project' => 'The blog behind the tests.',
        'tables' => ['posts' => 'One row per article.'],
        'columns' => ['posts' => ['title' => 'Shown in bold.', 'status' => 'draft or published.']],
    ]);

    Artisan::call('schema-viewer:export', ['--format' => 'markdown']);
    $markdown = Artisan::output();
    Artisan::call('schema-viewer:export', ['--format' => 'dbml']);
    $dbml = Artisan::output();
    $comment = testDriver() !== 'sqlite' && testDriver() !== 'sqlsrv';

    expect($markdown)->toContain('# Database schema: testing ('.(testDriver() === 'pgsql' ? 'postgresql' : testDriver()).")\n\nThe blog behind the tests.\n\n")
        ->and($markdown)->toContain("## posts\n\nNote: One row per article.\n")
        ->and($markdown)->toMatch('/\| `status` \| .* \| draft or published\. \|\n/')
        ->and($markdown)->toContain($comment ? '| Shown in lists and feeds · Shown in bold. |' : '| Shown in bold. |')
        ->and($dbml)->toStartWith("Project testing {\n  Note: 'The blog behind the tests.'\n}\n\nTable ")
        ->and($dbml)->toContain("  Note: 'One row per article.'")
        ->and($dbml)->toContain($comment ? "note: 'Shown in lists and feeds · Shown in bold.'" : "note: 'Shown in bold.'");
});

it('carries the notes in the snapshot and on the viewer page', function (): void {
    config()->set('schema-viewer.notes', ['project' => 'Read me first.', 'tables' => ['posts' => 'Articles.']]);
    buildSnapshot();

    $config = viewerConfig($this);
    $posts = collect($config['document']['connections'][0]['tables'])->firstWhere('name', 'posts');

    expect($config['document']['note'])->toBe('Read me first.')
        ->and($posts['note'])->toBe('Articles.');
});

it('warns about notes that match nothing, and still builds', function (): void {
    config()->set('schema-viewer.notes', [
        'tables' => ['posts' => 'Articles.', 'postz' => 'Typo.', 'other:posts' => 'No such connection.'],
        'columns' => ['posts' => ['stauts' => 'Typo.', 'status' => 'Fine.']],
        'relationships' => ['posts' => ['user_id' => 'Fine.', 'title' => 'No relationship.']],
    ]);

    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('4 notes match nothing: postz, other:posts, posts.stauts, relationship posts.title.')
        ->assertSuccessful();

    config()->set('schema-viewer.notes', ['tables' => ['posts' => 'Articles.']]);

    $this->artisan('schema-viewer:build')
        ->doesntExpectOutputToContain('match nothing')
        ->assertSuccessful();
});

it('refuses a note that is not a string', function (mixed $notes, string $message): void {
    config()->set('schema-viewer.notes', $notes);

    expect(fn () => app(SchemaViewer::class)->document())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a string' => ['notes', 'schema-viewer.notes must be an array'],
    'a project array' => [['project' => ['x']], 'schema-viewer.notes.project must be a string or null.'],
    'a table note array' => [['tables' => ['posts' => ['x']]], 'schema-viewer.notes.tables must map table names to strings'],
    'a column note number' => [['columns' => ['posts' => ['title' => 1]]], 'schema-viewer.notes.columns must map table names to column notes'],
    'a relationship note number' => [['relationships' => ['posts' => ['user_id' => 1]]], 'schema-viewer.notes.relationships must map table names to notes by the columns that hold the key'],
]);

it('reports invalid notes from the build instead of writing the snapshot', function (): void {
    config()->set('schema-viewer.notes', ['tables' => ['posts' => 1]]);

    $this->artisan('schema-viewer:build')
        ->expectsOutputToContain('schema-viewer.notes.tables must map table names to strings')
        ->assertFailed();

    expect($this->snapshot)->not->toBeFile();
});
