<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Inspection\DatabaseInspector;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;

function inspectTesting(array $exclude = [], array $framework = ['jobs', 'migrations']): ConnectionSchema
{
    return app(DatabaseInspector::class)->inspect('testing', $exclude, $framework);
}

it('reads every table of the connection, with or without a model', function (): void {
    $names = array_map(fn ($table) => $table->name, inspectTesting()->tables);

    expect($names)->toContain('countries', 'users', 'role_user', 'posts', 'comments', 'taggables', 'audit_logs', 'jobs')
        ->and($names)->toBe(collect($names)->sort()->values()->all());
});

it('reads columns, indexes and foreign keys', function (): void {
    $posts = inspectTesting()->table('posts');

    expect($posts)->not->toBeNull();

    $columns = collect($posts->columns)->keyBy('name');

    expect($columns['id']->autoIncrement)->toBeTrue()
        ->and($columns['category_id']->nullable)->toBeTrue()
        ->and($columns['title']->nullable)->toBeFalse()
        ->and($columns['status']->default)->toContain('draft')
        ->and($posts->foreignKeys)->toHaveCount(1)
        ->and($posts->foreignKeys[0]->columns)->toBe(['user_id'])
        ->and($posts->foreignKeys[0]->foreignTable)->toBe('users')
        ->and($posts->foreignKeys[0]->foreignColumns)->toBe(['id'])
        ->and($posts->foreignKeys[0]->onDelete)->toBe('cascade')
        ->and($posts->foreignKeys[0]->name)->toBe('posts_user_id_foreign')
        ->and($posts->isIndexed(['category_id']))->toBeTrue()
        ->and($posts->isIndexed(['title']))->toBeFalse();

    // SQLite does not store column comments; the others do.
    if (testDriver() !== 'sqlite' && testDriver() !== 'sqlsrv') {
        expect($columns['title']->comment)->toBe('Shown in lists and feeds');
    }
});

it('tags framework tables and leaves out excluded ones', function (): void {
    $connection = inspectTesting(exclude: ['audit_*']);

    expect($connection->table('audit_logs'))->toBeNull()
        ->and($connection->table('jobs')?->framework)->toBeTrue()
        ->and($connection->table('posts')?->framework)->toBeFalse();
});

it('reads the connection\'s driver and schemas, and keeps tables of the default schema unqualified', function (): void {
    $connection = inspectTesting();

    expect($connection->driver)->toBe(testDriver())
        ->and($connection->table('posts')?->schema)->toBeNull()
        ->and($connection->table('posts')?->baseName())->toBe('posts')
        ->and($connection->schemas === null || in_array($connection->defaultSchema, $connection->schemas, true))->toBeTrue();
});

it('reads each index\'s type and details', function (): void {
    $posts = collect(inspectTesting()->table('posts')?->indexes)->keyBy('name');
    $primary = $posts->first(fn ($index): bool => $index->primary);
    $category = $posts['posts_category_id_index'];

    expect($primary->type)->toBe(match (testDriver()) {
        'sqlite' => null,
        'sqlsrv' => 'clustered',
        default => 'btree',
    })
        ->and($category->columns)->toBe(['category_id'])
        ->and($category->keyColumns())->toBe(['category_id'])
        ->and($category->partial)->toBeFalse()
        ->and($category->expression)->toBeFalse()
        ->and($category->included)->toBe([])
        ->and($category->plain())->toBeTrue()
        ->and($category->typeFamily())->toBe('btree')
        ->and(inspectTesting()->table('posts')?->indexDetails)->toBeTrue();
});
