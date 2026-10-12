<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\UnresolvedRelation;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

/**
 * @return array{relations: array<string, RelationInfo>, unresolved: list<UnresolvedRelation>}
 */
function resolveRelations(Model $model): array
{
    $found = app(RelationResolver::class)->resolve($model);

    return [
        'relations' => collect($found['relations'])->keyBy('name')->all(),
        'unresolved' => $found['unresolved'],
    ];
}

it('reads belongsTo and hasMany keys from the relation objects', function (): void {
    $relations = resolveRelations(new Models\Post)['relations'];

    expect($relations['author']->type)->toBe('BelongsTo')
        ->and($relations['author']->related)->toBe(Models\User::class)
        ->and($relations['author']->from?->jsonSerialize())->toBe(['connection' => 'testing', 'table' => 'posts', 'columns' => ['user_id']])
        ->and($relations['author']->to?->jsonSerialize())->toBe(['connection' => 'testing', 'table' => 'users', 'columns' => ['id']]);

    $users = resolveRelations(new Models\User)['relations'];

    expect($users['posts']->type)->toBe('HasMany')
        ->and($users['posts']->from?->table)->toBe('posts')
        ->and($users['posts']->from?->columns)->toBe(['user_id'])
        ->and($users['posts']->to?->table)->toBe('users');
});

it('reads pivot tables, pivot models and pivot columns', function (): void {
    $roles = resolveRelations(new Models\User)['relations']['roles'];

    expect($roles->type)->toBe('BelongsToMany')
        ->and($roles->pivot?->jsonSerialize())->toBe([
            'table' => 'role_user',
            'foreignPivotKey' => 'user_id',
            'relatedPivotKey' => 'role_id',
            'parentKey' => 'id',
            'relatedKey' => 'id',
            'columns' => ['assigned_at'],
            'using' => Models\RoleUser::class,
            'accessor' => 'assignment',
        ]);
});

it('reads both sides of polymorphic relations', function (): void {
    $post = resolveRelations(new Models\Post)['relations'];
    $comment = resolveRelations(new Models\Comment)['relations'];
    $tag = resolveRelations(new Models\Tag)['relations'];

    expect($post['comments']->morph?->jsonSerialize())->toBe(['type' => 'commentable_type', 'id' => 'commentable_id', 'class' => Models\Post::class])
        ->and($post['comments']->from?->table)->toBe('comments')
        ->and($comment['commentable']->type)->toBe('MorphTo')
        ->and($comment['commentable']->morph?->type)->toBe('commentable_type')
        ->and($comment['commentable']->from?->columns)->toBe(['commentable_id'])
        ->and($post['tags']->pivot?->table)->toBe('taggables')
        ->and($post['tags']->morph?->id)->toBe('taggable_id')
        ->and($tag['posts']->type)->toBe('MorphToMany')
        ->and($tag['posts']->morph?->id)->toBe('taggable_id')
        ->and($tag['posts']->pivot?->foreignPivotKey)->toBe('tag_id');
});

it('reads through relations and self references', function (): void {
    $country = resolveRelations(new Models\Country)['relations'];
    $category = resolveRelations(new Models\Category)['relations'];

    expect($country['posts']->type)->toBe('HasManyThrough')
        ->and($country['posts']->through)->toBe(['countries', 'users', 'posts'])
        ->and($category['parent']->from?->table)->toBe('categories')
        ->and($category['parent']->to?->table)->toBe('categories');
});

it('lists the conditions a relation adds', function (): void {
    $post = resolveRelations(new Models\Post)['relations'];
    $user = resolveRelations(new Models\User)['relations'];

    expect($post['approvedComments']->constraints)->toBe(['approved = true'])
        ->and($post['comments']->constraints)->toBe([])
        ->and($user['latestPost']->constraints)->toContain('one of many');
});

it('finds relations without a return type and dynamic relations', function (): void {
    Models\Post::resolveRelationUsing('reviewer', fn (Models\Post $post) => $post->belongsTo(Models\User::class, 'user_id'));

    $relations = resolveRelations(new Models\Post)['relations'];

    expect($relations)->toHaveKeys(['writer', 'reviewer'])
        ->and($relations['writer']->type)->toBe('BelongsTo');
});

it('points each relation at the method that declares it', function (): void {
    app()->setBasePath(dirname(__DIR__, 2));
    Models\Post::resolveRelationUsing('reviewer', fn (Models\Post $post) => $post->belongsTo(Models\User::class, 'user_id'));

    $relations = resolveRelations(new Models\Post)['relations'];

    expect($relations['author']->source?->jsonSerialize())->toBe(['file' => 'tests/Fixtures/Models/Post.php', 'line' => 20])
        ->and($relations['reviewer']->source)->toBeNull();
});

it('reports a relation that cannot be read instead of failing', function (): void {
    $found = resolveRelations(new Models\Post);

    expect($found['relations'])->not->toHaveKey('broken')
        ->and($found['unresolved'])->toHaveCount(1)
        ->and($found['unresolved'][0]->method)->toBe('broken')
        ->and($found['unresolved'][0]->reason)->toContain('Missing');
});

it('never calls methods that are not relations and runs no query', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach ([new Models\Post, new Models\User, new Models\Country, new Models\Comment, new Models\Tag, new Models\Category] as $model) {
        resolveRelations($model);
    }

    expect($queries)->toBe(0);
});
