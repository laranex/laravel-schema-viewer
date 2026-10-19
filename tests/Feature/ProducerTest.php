<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\Contract\Validator;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Analytics\Event;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

/**
 * The laranex-schema 2.0 document of the test schema.
 *
 * @return array<string, mixed>
 */
function contractDocument(): array
{
    return (new Producer)->document(app(SchemaViewer::class)->document());
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>|null
 */
function entity(array $document, string $collection, string $id): ?array
{
    $items = $collection === 'tables' ? array_merge(...array_column($document['connections'], 'tables')) : $document[$collection];

    return collect($items)->firstWhere('id', $id);
}

/**
 * A second connection, analytics, with its own users table and an events table whose model relates to the default
 * connection's users.
 */
function withAnalytics(): void
{
    config()->set('database.connections.analytics', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/Models', dirname(__DIR__).'/Fixtures/Analytics']);

    Schema::connection('analytics')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('fingerprint')->unique();
    });

    Schema::connection('analytics')->create('events', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->foreignId('visitor_id')->constrained('users');
        $table->string('name');
    });
}

it('writes a strictly valid, canonical laranex-schema 2.0 document', function () {
    $document = contractDocument();

    expect(Validator::validate($document, strict: true))->toBe([])
        ->and($document)->toMatchArray([
            'format' => 'laranex-schema',
            'version' => '2.0',
            'source' => 'combined',
            'generator' => ['name' => 'laravel-schema-viewer', 'orm' => 'eloquent'],
            'comparisons' => [],
            'migrations' => [],
        ])
        // Equal schemas are equal bytes: nothing depends on when or where it was built.
        ->and(Json::canonical(contractDocument()))->toBe(Json::canonical($document))
        ->and(Json::canonical($document))->not->toContain('generatedAt');
});

it('passes the shared contract check under Node.js', function () {
    [$code, $output] = nodeContractCheck(Json::canonical(contractDocument()));

    expect($output)->toContain('✓')->and($code)->toBe(0);
});

it('names connections, tables, indexes, foreign keys, models and relationships by rule', function () {
    $document = contractDocument();
    $posts = entity($document, 'tables', 'testing:posts');

    expect($document['connections'][0])->toMatchArray(['id' => 'connection:testing', 'name' => 'testing', 'driver' => testDriver() === 'pgsql' ? 'postgresql' : testDriver()])
        ->and($posts)->toMatchArray(['name' => 'posts', 'schema' => null, 'primaryKey' => ['id'], 'provenance' => ['database'], 'owner' => ['kind' => 'application', 'package' => null]])
        ->and(collect($posts['foreignKeys'])->pluck('id')->all())->toContain('testing:posts:fk:posts_user_id_foreign')
        ->and(collect($posts['indexes'])->firstWhere('backs.kind', 'primary_key'))->toMatchArray(['unique' => true, 'parts' => [['collation' => null, 'column' => 'id', 'expression' => null, 'length' => null, 'nulls' => null, 'opclass' => null, 'order' => null]]])
        ->and(entity($document, 'models', Models\Post::class))->toMatchArray(['className' => Models\Post::class, 'tables' => ['testing:posts'], 'primaryKey' => ['id']])
        ->and(entity($document, 'relationships', Models\Post::class.'::author'))->toMatchArray([
            'name' => 'author', 'model' => Models\Post::class, 'table' => 'testing:posts', 'relatedModel' => Models\User::class, 'relatedTable' => 'testing:users',
            'type' => 'BelongsTo', 'cardinality' => 'many-to-one', 'collection' => false,
            'keys' => ['from' => ['table' => 'testing:posts', 'columns' => ['user_id']], 'to' => ['table' => 'testing:users', 'columns' => ['id']]],
        ]);
});

it('keeps Eloquent facts and hint ignore rules in extensions', function () {
    $document = contractDocument();
    $comment = entity($document, 'models', Models\Comment::class);
    $hint = collect($document['diagnostics'])->firstWhere('kind', 'relationship_without_foreign_key');

    expect($comment['extensions']['eloquent'])->toMatchArray(['connection' => 'testing', 'table' => 'comments', 'keyType' => 'int', 'incrementing' => true, 'timestamps' => true, 'softDeletes' => false])
        ->and($comment['extensions']['eloquent']['casts'])->toMatchArray(['approved' => 'boolean'])
        ->and($hint)->toMatchArray(['category' => 'lint', 'severity' => 'warning', 'certainty' => 'certain', 'subject' => ['type' => 'column', 'id' => 'testing:posts', 'columns' => ['category_id'], 'label' => 'posts.category_id']])
        ->and($hint['extensions']['laravel'])->toBe([
            'hint' => 'relation_without_foreign_key',
            'connection' => 'testing',
            'table' => 'posts',
            'columns' => 'category_id',
            'relation' => null,
            'ignore' => ['connection' => 'testing', 'table' => 'posts', 'kind' => 'relation_without_foreign_key', 'columns' => 'category_id'],
            'ignorePhp' => "['connection' => 'testing', 'table' => 'posts', 'kind' => 'relation_without_foreign_key', 'columns' => 'category_id'],",
        ])
        ->and(collect($document['diagnostics'])->firstWhere('kind', 'model_without_table')['subject'])->toMatchArray(['type' => 'model', 'id' => Models\Ghost::class]);
});

it('draws links and inferred associations by the shared rules', function () {
    $document = contractDocument();
    $roles = entity($document, 'relationships', Models\User::class.'::roles');
    $comments = collect($document['links'])->first(fn (array $link): bool => $link['from'] === ['table' => 'testing:comments', 'columns' => ['user_id']]);

    expect($roles['pivot'])->toBe(['table' => 'testing:role_user', 'parentColumns' => ['id'], 'parentPivotColumns' => ['user_id'], 'relatedColumns' => ['id'], 'relatedPivotColumns' => ['role_id'], 'columns' => ['assigned_at']])
        ->and(collect($document['links'])->where('from.table', 'testing:role_user')->count())->toBe(2)
        ->and(collect($document['derivedLinks'])->pluck('relationship')->all())->toBe([Models\Country::class.'::posts'])
        // A foreign key no relation covers is an inferred association, never a relationship.
        ->and($comments)->toMatchArray(['state' => 'foreign_key', 'relationships' => []])
        ->and(collect($document['inferredAssociations'])->pluck('links')->flatten()->all())->toContain($comments['id']);
});

it('says what the inspection could not read', function () {
    $connection = contractDocument()['connections'][0];

    expect($connection['coverage']['database'])->toMatchArray(['complete' => false])
        ->and($connection['coverage']['database']['features'])->toMatchArray(['tables' => 'complete', 'columns' => 'complete', 'foreignKeys' => 'complete', 'checkConstraints' => 'unavailable', 'uniqueConstraints' => 'partial'])
        ->and($connection['coverage']['declared'])->toMatchArray(['complete' => false, 'features' => ['tables' => 'not_applicable', 'models' => 'complete', 'relationships' => 'partial']]);
});

it('keeps every connection apart in one document, with relationships across them', function () {
    withAnalytics();
    $document = contractDocument();
    $event = Event::class;

    expect(Validator::validate($document, strict: true))->toBe([])
        ->and(array_column($document['connections'], 'id'))->toBe(['connection:testing', 'connection:analytics'])
        // Two users tables, told apart by id.
        ->and(entity($document, 'tables', 'analytics:users')['name'])->toBe('users')
        ->and(entity($document, 'tables', 'testing:users')['name'])->toBe('users')
        // A related model without a connection of its own is read on the declaring model's connection, as Laravel queries it.
        ->and(entity($document, 'relationships', $event.'::user')['keys']['to'])->toBe(['table' => 'analytics:users', 'columns' => ['id']])
        ->and(entity($document, 'relationships', $event.'::member')['keys'])->toBe(['from' => ['table' => 'analytics:events', 'columns' => ['user_id']], 'to' => ['table' => 'testing:users', 'columns' => ['id']]])
        ->and(collect($document['links'])->first(fn (array $l): bool => $l['from']['table'] === 'analytics:events' && $l['to']['table'] === 'testing:users'))->toMatchArray(['state' => 'relationship', 'foreignKey' => null])
        ->and(collect($document['links'])->first(fn (array $l): bool => $l['from'] === ['table' => 'analytics:events', 'columns' => ['visitor_id']]))->toMatchArray(['state' => 'both', 'foreignKey' => 'analytics:events:fk:events_visitor_id_foreign', 'to' => ['table' => 'analytics:users', 'columns' => ['id']]]);

    [$code, $output] = nodeContractCheck(Json::canonical($document));

    expect($output)->toContain('✓')->and($code)->toBe(0);
});

it('notes a relation whose related model or keys the schema does not have', function () {
    Schema::table('posts', function (Blueprint $table): void {
        $table->dropIndex(['category_id']);
        $table->dropColumn('category_id');
    });
    $document = contractDocument();
    $category = entity($document, 'relationships', Models\Post::class.'::category');

    expect($category['keys'])->toBeNull()
        ->and(collect($document['diagnostics'])->first(fn (array $d): bool => $d['kind'] === 'unresolved_relationship' && $d['subject']['id'] === Models\Post::class.'::category')['message'])
        ->toBe('Post::category() uses posts.category_id, which the testing connection does not have.')
        ->and(Validator::validate($document, strict: true))->toBe([]);
})->skip(fn (): bool => testDriver() === 'sqlite' && version_compare(app()->version(), '11.0', '<'), 'Laravel 10 cannot drop SQLite columns without doctrine/dbal.');
