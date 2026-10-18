<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\UnresolvedRelation;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Packages;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Unsafe;

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
        ->and($relations['author']->cardinality)->toBe('many-to-one')
        ->and($relations['author']->relatedTable)->toBe('users')
        ->and([$relations['author']->from?->connection, $relations['author']->from?->table, $relations['author']->from?->columns])->toBe(['testing', 'posts', ['user_id']])
        ->and([$relations['author']->to?->connection, $relations['author']->to?->table, $relations['author']->to?->columns])->toBe(['testing', 'users', ['id']]);

    $users = resolveRelations(new Models\User)['relations'];

    expect($users['posts']->type)->toBe('HasMany')
        ->and($users['posts']->cardinality)->toBe('one-to-many')
        ->and($users['latestPost']->cardinality)->toBe('one-to-one')
        ->and($users['posts']->from?->table)->toBe('posts')
        ->and($users['posts']->from?->columns)->toBe(['user_id'])
        ->and($users['posts']->to?->table)->toBe('users');
});

it('reads pivot tables, pivot models and pivot columns', function (): void {
    $roles = resolveRelations(new Models\User)['relations']['roles'];

    expect($roles->type)->toBe('BelongsToMany')
        ->and($roles->cardinality)->toBe('many-to-many')
        ->and((array) $roles->pivot)->toBe([
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

    expect((array) $post['comments']->morph)->toBe(['type' => 'commentable_type', 'id' => 'commentable_id', 'class' => Models\Post::class])
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

    expect($post['approvedComments']->constraints)->toBe(['approved = ?'])
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

    expect((array) $relations['author']->source)->toBe(['file' => 'tests/Fixtures/Models/Post.php', 'line' => 20])
        ->and($relations['reviewer']->source)->toBeNull();
});

it('reports a relation that cannot be read instead of failing', function (): void {
    $found = resolveRelations(new Models\Post);

    expect($found['relations'])->not->toHaveKey('broken')
        ->and($found['unresolved'])->toHaveCount(1)
        ->and($found['unresolved'][0]->method)->toBe('broken')
        ->and($found['unresolved'][0]->reason)->toContain('Missing');
});

it('names the package an unreadable inherited relation comes from', function (): void {
    require_once dirname(__DIR__).'/Fixtures/Packages/vendor/acme/tokens/src/PackageToken.php';

    app()->setBasePath(dirname(__DIR__).'/Fixtures/Packages');
    $inVendor = resolveRelations(new Packages\AppToken)['unresolved'];

    app()->setBasePath(dirname(__DIR__).'/Fixtures/Packages/app');
    $outside = resolveRelations(new Packages\AppToken)['unresolved'];

    expect($inVendor)->toHaveCount(1)
        ->and($inVendor[0]->method)->toBe('owner')
        ->and($inVendor[0]->package)->toBe('acme/tokens')
        ->and($outside[0]->package)->toBe('Acme\\Tokens\\PackageToken')
        ->and(resolveRelations(new Models\Post)['unresolved'][0]->package)->toBeNull();
});

it('lists the joins a relation adds among its constraints', function (): void {
    Models\Post::resolveRelationUsing('countryAuthors', fn (Models\Post $post) => $post->belongsTo(Models\User::class, 'user_id')->join('countries', 'countries.id', '=', 'users.country_id'));

    expect(resolveRelations(new Models\Post)['relations']['countryAuthors']->constraints)->toBe(['join countries']);
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

it('never lets a method that writes reach the database', function (): void {
    DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    DB::table('posts')->insert(['user_id' => 1, 'title' => 'First']);

    // Any statement that reached the database would go through the connection's PDO; quoting for the query log may not.
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $tripwire = new class('sqlite::memory:') extends PDO
    {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            throw new LogicException('A query reached the database.');
        }

        public function exec(string $statement): int|false
        {
            throw new LogicException('A query reached the database.');
        }
    };
    $connection->setPdo($tripwire)->setReadPdo($tripwire);

    try {
        $found = resolveRelations(new Unsafe\Wiper);
    } finally {
        $connection->setPdo($pdo)->setReadPdo($pdo);
    }

    expect($connection->pretending())->toBeFalse()
        ->and(collect($found['unresolved'])->pluck('method')->sort()->values()->all())->toBe(['addPost', 'renameUsers', 'wipePosts'])
        ->and(collect($found['unresolved'])->pluck('reason')->unique()->all())->toBe(['It returned int instead of a relation.'])
        ->and(DB::table('users')->pluck('name')->all())->toBe(['Ada'])
        ->and(DB::table('posts')->pluck('title')->all())->toBe(['First']);
});

it('pretends on every open connection, not only the model\'s own', function (): void {
    config()->set('database.connections.side', config('database.connections.testing'));
    $side = DB::connection('side');
    $pretended = false;
    Models\Post::resolveRelationUsing('probe', function (Models\Post $post) use ($side, &$pretended) {
        $pretended = $side->pretending();

        return $post->belongsTo(Models\User::class, 'user_id');
    });

    resolveRelations(new Models\Post);

    expect($pretended)->toBeTrue()
        ->and($side->pretending())->toBeFalse();
});

it('shows bound values in constraints as placeholders', function (): void {
    Models\Post::resolveRelationUsing('secretComments', fn (Models\Post $post) => $post->morphMany(Models\Comment::class, 'commentable')
        ->where('body', 'like', '%s3cret%')
        ->whereIn('approved', [1, 2])
        ->whereNotIn('id', [7])
        ->whereNull('deleted_at')
        ->whereColumn('commentable_id', '>', 'id'));

    expect(resolveRelations(new Models\Post)['relations']['secretComments']->constraints)->toBe([
        'body like ?',
        'approved in (?, ?)',
        'id not in (?)',
        'deleted_at is null',
        'commentable_id > id',
    ]);
});

it('shows the literals in raw conditions as placeholders', function (): void {
    Models\Post::resolveRelationUsing('rawComments', fn (Models\Post $post) => $post->morphMany(Models\Comment::class, 'commentable')
        ->whereRaw("body <> 'it''s s3cret' and approved = 1 and score > -2.5")
        ->whereRaw('"comments"."id" > ? and id2 = 7', [10]));

    expect(resolveRelations(new Models\Post)['relations']['rawComments']->constraints)->toBe([
        'body <> ? and approved = ? and score > ?',
        '"comments"."id" > ? and id2 = ?',
    ]);
});

it('keeps unresolved reasons short and free of paths, connection details and credentials', function (): void {
    app()->setBasePath('/srv/app');
    $fail = fn (string $message) => fn (Models\Post $post) => throw new RuntimeException($message);
    Models\Post::resolveRelationUsing('a', $fail('Cannot open /srv/app/storage/x.sqlite or /etc/secrets/db.ini'));
    Models\Post::resolveRelationUsing('b', $fail('SQLSTATE[HY000] mysql:host=db.internal;dbname=shop failed for \'root\'@\'10.0.0.5\' (using password: [hidden])'));
    Models\Post::resolveRelationUsing('c', $fail("Failed with password=hunter2 at https://admin:pw@db.example.com/x\nStack trace here"));
    Models\Post::resolveRelationUsing('d', $fail(str_repeat('x', 500)));

    $reasons = collect(resolveRelations(new Models\Post)['unresolved'])->pluck('reason', 'method');

    expect($reasons['a'])->toBe('RuntimeException: Cannot open storage/x.sqlite or [path]')
        ->and($reasons['b'])->toBe('RuntimeException: SQLSTATE[HY000] [dsn] failed for [user] (using password: [hidden])')
        ->and($reasons['c'])->toBe('RuntimeException: Failed with password=[hidden] at [url]')
        ->and(mb_strlen($reasons['d']))->toBeLessThanOrEqual(203)
        ->and($reasons['broken'])->toStartWith('Error: Class ');
});
