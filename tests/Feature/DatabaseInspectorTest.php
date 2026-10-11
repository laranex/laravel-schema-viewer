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
});

it('tags framework tables and leaves out excluded ones', function (): void {
    $connection = inspectTesting(exclude: ['audit_*']);

    expect($connection->table('audit_logs'))->toBeNull()
        ->and($connection->table('jobs')?->framework)->toBeTrue()
        ->and($connection->table('posts')?->framework)->toBeFalse();
});

it('serializes to the shape the viewer reads', function (): void {
    $json = json_decode((string) json_encode(inspectTesting()), true);

    expect($json)->toHaveKeys(['name', 'driver', 'tables'])
        ->and($json['driver'])->toBe('sqlite')
        ->and($json['tables'][0])->toHaveKeys(['name', 'comment', 'framework', 'columns', 'indexes', 'foreignKeys'])
        ->and($json['tables'][0]['columns'][0])->toHaveKeys(['name', 'type', 'typeName', 'nullable', 'default', 'autoIncrement', 'comment']);
});
