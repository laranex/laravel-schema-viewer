<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\Link;
use Laranex\LaravelSchemaViewer\Schema\LinkState;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Packages;

function schemaDocument(): SchemaDocument
{
    return app(SchemaViewer::class)->document();
}

function linkBetween(SchemaDocument $document, string $from, string $to): ?Link
{
    foreach ($document->links as $link) {
        if ($link->from->table.'.'.implode(',', $link->from->columns) === $from && $link->to->table.'.'.implode(',', $link->to->columns) === $to) {
            return $link;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function hintsOf(SchemaDocument $document, HintKind $kind): array
{
    return array_values(array_map(fn (Hint $hint): string => $hint->table, array_filter($document->hints, fn (Hint $hint): bool => $hint->kind === $kind)));
}

it('merges a foreign key and the relations on its columns into one link', function (): void {
    $link = linkBetween(schemaDocument(), 'posts.user_id', 'users.id');

    expect($link)->not->toBeNull()
        ->and($link->foreignKey)->not->toBeNull()
        ->and($link->state())->toBe(LinkState::Both)
        ->and($link->relations)->toContain(Models\Post::class.'::author', Models\User::class.'::posts', Models\User::class.'::latestPost', Models\Post::class.'::writer');
});

it('marks links that only one side backs', function (): void {
    $document = schemaDocument();

    expect(linkBetween($document, 'posts.category_id', 'categories.id')?->state())->toBe(LinkState::RelationOnly)
        ->and(linkBetween($document, 'comments.user_id', 'users.id')?->state())->toBe(LinkState::ForeignKeyOnly);
});

it('links pivot tables to both sides and morph columns to their targets', function (): void {
    $document = schemaDocument();

    expect(linkBetween($document, 'role_user.user_id', 'users.id')?->relations)->toContain(Models\User::class.'::roles', Models\Role::class.'::users')
        ->and(linkBetween($document, 'role_user.role_id', 'roles.id')?->state())->toBe(LinkState::Both)
        ->and(linkBetween($document, 'comments.commentable_id', 'posts.id'))
        ->not->toBeNull()
        ->and(linkBetween($document, 'comments.commentable_id', 'posts.id')?->morph)->toBeTrue()
        ->and(linkBetween($document, 'comments.commentable_id', 'posts.id')?->relations)->toContain(Models\Comment::class.'::commentable', Models\Post::class.'::comments')
        ->and(linkBetween($document, 'taggables.taggable_id', 'posts.id')?->morph)->toBeTrue()
        ->and(linkBetween($document, 'taggables.tag_id', 'tags.id')?->state())->toBe(LinkState::Both)
        ->and(linkBetween($document, 'categories.parent_id', 'categories.id')?->state())->toBe(LinkState::Both);
});

it('draws through relations as derived links', function (): void {
    $derived = schemaDocument()->derivedLinks;

    expect($derived)->toHaveCount(1)
        ->and($derived[0]->from)->toBe('countries')
        ->and($derived[0]->to)->toBe('posts')
        ->and($derived[0]->via)->toBe(['users']);
});

it('pairs each relation with its inverse', function (): void {
    $relations = collect(schemaDocument()->relations)->keyBy(fn ($relation) => $relation->id());

    expect($relations[Models\Post::class.'::author']->inverse)->toBe(Models\User::class.'::posts')
        ->and($relations[Models\User::class.'::roles']->inverse)->toBe(Models\Role::class.'::users')
        ->and($relations[Models\Comment::class.'::commentable']->inverse)->toBe(Models\Post::class.'::comments')
        ->and($relations[Models\Tag::class.'::posts']->inverse)->toBe(Models\Post::class.'::tags');
});

it('raises each kind of hint where it applies', function (): void {
    $document = schemaDocument();

    expect(hintsOf($document, HintKind::RelationWithoutForeignKey))->toBe(['posts'])
        ->and(hintsOf($document, HintKind::ForeignKeyWithoutRelation))->toBe(['comments'])
        // MySQL and MariaDB index every foreign key column themselves.
        ->and(in_array('role_user', hintsOf($document, HintKind::ForeignKeyWithoutIndex), true))->toBe(! in_array(testDriver(), ['mysql', 'mariadb'], true))
        ->and(hintsOf($document, HintKind::ModelWithoutTable))->toBe(['ghosts'])
        ->and(hintsOf($document, HintKind::MorphWithoutMap))->toContain('comments', 'taggables')
        ->and(hintsOf($document, HintKind::UnresolvedRelation))->toBe(['posts'])
        // The shared blog's pivot for tags has no primary key, on every database.
        ->and(hintsOf($document, HintKind::TableWithoutPrimaryKey))->toBe(['taggables']);
});

it('gives no morph map hint for a framework table or a table that does not exist', function (): void {
    Models\User::resolveRelationUsing('alerts', fn (Models\User $user) => $user->morphMany(DatabaseNotification::class, 'notifiable'));

    expect(hintsOf(schemaDocument(), HintKind::MorphWithoutMap))->not->toContain('notifications');

    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->morphs('notifiable');
    });

    expect(hintsOf(schemaDocument(), HintKind::MorphWithoutMap))->not->toContain('notifications')
        ->toContain('comments');
});

it('notes relations that join other tables or match a column that is not unique instead of flagging them', function (): void {
    Models\Tag::resolveRelationUsing('namesakes', fn (Models\Tag $tag) => $tag->hasMany(Models\Category::class, 'name', 'name'));
    Models\Comment::resolveRelationUsing('countryAuthor', fn (Models\Comment $comment) => $comment->belongsTo(Models\Country::class, 'commentable_type')->join('users', 'users.country_id', '=', 'countries.id'));

    $document = schemaDocument();
    $custom = array_values(array_filter($document->hints, fn (Hint $hint): bool => $hint->kind === HintKind::CustomRelation));

    expect(hintsOf($document, HintKind::RelationWithoutForeignKey))->toBe(['posts'])
        ->and(array_map(fn (Hint $hint): string => $hint->message, $custom))->toBe([
            'Tag::namesakes() matches categories.name to tags.name, which is not a unique key, so no foreign key can back it. Custom keys, foreign key not checked.',
            'Comment::countryAuthor() joins users, so matching comments.commentable_type to countries.id is only part of the link. Custom query, foreign key not checked.',
        ]);
});

it('puts "a" or "an" before a row by how its table sounds', function (): void {
    $auditLog = new class extends Model
    {
        protected $table = 'audit_logs';
    };
    Models\Post::resolveRelationUsing('auditLog', fn (Models\Post $post) => $post->belongsTo($auditLog::class, 'category_id'));

    $messages = array_map(fn (Hint $hint): string => $hint->message, schemaDocument()->hints);

    expect($messages)->toContain(
        'Category::posts() and Post::category() use posts.category_id, which has no foreign key to categories, so rows can point to a category that no longer exists.',
        'Post::auditLog() uses posts.category_id, which has no foreign key to audit_logs, so rows can point to an audit_log that no longer exists.',
    );
});

afterEach(function (): void {
    // installPackages() swaps Composer's list of installed packages; put the real one back.
    InstalledVersions::reload(require dirname(__DIR__, 2).'/vendor/composer/installed.php');
});

it('tags the tables of installed packages with the package, and still gives them hints', function (): void {
    expect(app(SchemaViewer::class)->packageTables())->toBe([]);

    installPackages('laravel/passport', 'spatie/laravel-permission');
    config()->set('schema-viewer.package_tables', [...config('schema-viewer.package_tables'), 'spatie/laravel-permission' => ['roles', 'permissions']]);

    expect(app(SchemaViewer::class)->packageTables())->toBe([
        'laravel/passport' => ['oauth_*'],
        'spatie/laravel-permission' => ['roles', 'permissions'],
    ]);

    $document = schemaDocument();
    $roles = collect($document->connections[0]->tables)->firstWhere('name', 'roles');

    expect($roles?->package)->toBe('spatie/laravel-permission')
        ->and($roles?->framework)->toBeFalse()
        ->and($roles?->jsonSerialize()['package'])->toBe('spatie/laravel-permission')
        ->and(collect($document->connections[0]->tables)->firstWhere('name', 'posts')?->package)->toBeNull();

    config()->set('schema-viewer.package_tables', false);

    expect(app(SchemaViewer::class)->packageTables())->toBe([]);
});

it('tags the tables of packages the application adds to package_tables', function (): void {
    installPackages('acme/blog');
    config()->set('schema-viewer.package_tables', ['acme/blog' => ['posts', 'comm*'], 'acme/missing' => ['users']]);

    $tables = collect(schemaDocument()->connections[0]->tables)->mapWithKeys(fn ($table) => [$table->name => $table->package]);

    expect(app(SchemaViewer::class)->packageTables())->toBe(['acme/blog' => ['posts', 'comm*']])
        ->and($tables['posts'])->toBe('acme/blog')
        ->and($tables['comments'])->toBe('acme/blog')
        ->and($tables['users'])->toBeNull();
});

it('lists only Laravel\'s own packages by default', function (): void {
    $defaults = (require dirname(__DIR__, 2).'/config/schema-viewer.php')['package_tables'];

    expect(array_keys($defaults))->each->toStartWith('laravel/');
});

it('reads true, from a config published before the list moved into it, as the list the package ships', function (): void {
    installPackages('laravel/passport');
    config()->set('schema-viewer.package_tables', true);

    expect(app(SchemaViewer::class)->packageTables())->toBe(['laravel/passport' => ['oauth_*']]);
});

it('refuses a package_tables value that is not a list of table patterns by package name', function (mixed $value): void {
    config()->set('schema-viewer.package_tables', $value);

    app(SchemaViewer::class)->packageTables();
})->with([
    'a string' => ['oauth_*'],
    'a list without package names' => [[['oauth_*']]],
    'a pattern that is not a string' => [['laravel/passport' => [1]]],
])->throws(InvalidArgumentException::class, 'schema-viewer.package_tables must be false or a list of table patterns by package name.');

it('gives each hint the hints.ignore rules that leave it out, narrowest first', function (): void {
    $hints = collect(schemaDocument()->hints);
    $category = $hints->first(fn (Hint $hint): bool => $hint->kind === HintKind::RelationWithoutForeignKey);
    $broken = $hints->first(fn (Hint $hint): bool => $hint->kind === HintKind::UnresolvedRelation);
    $ghost = $hints->first(fn (Hint $hint): bool => $hint->kind === HintKind::ModelWithoutTable);

    expect($category?->jsonSerialize()['ignore'])->toBe([
        'hint' => ['connection' => 'testing', 'table' => 'posts', 'kind' => 'relation_without_foreign_key', 'columns' => 'category_id'],
        'kindOnTable' => ['connection' => 'testing', 'table' => 'posts', 'kind' => 'relation_without_foreign_key'],
        'table' => ['connection' => 'testing', 'table' => 'posts'],
        'kind' => ['kind' => 'relation_without_foreign_key'],
    ])
        ->and($broken?->ignoreRules()['hint'])->toBe(['kind' => 'unresolved_relation', 'relation' => Models\Post::class.'::broken'])
        ->and($ghost?->ignoreRules()['hint'])->toBe(['connection' => 'testing', 'table' => 'ghosts', 'kind' => 'model_without_table']);
});

it('leaves out the hints that match every field of an ignore rule', function (): void {
    Models\Post::resolveRelationUsing('editor', fn (Models\Post $post) => $post->belongsTo(Models\User::class, 'status'));
    $columns = fn (): array => collect(schemaDocument()->hints)->where('kind', HintKind::RelationWithoutForeignKey)->pluck('columns')->all();

    expect($columns())->toBe(['category_id', 'status']);

    config()->set('schema-viewer.hints.ignore', [['connection' => 'testing', 'table' => 'posts', 'kind' => 'relation_without_foreign_key', 'columns' => 'category_id']]);
    expect($columns())->toBe(['status']);

    config()->set('schema-viewer.hints.ignore', [['kind' => 'relation_without_foreign_key', 'columns' => 'category_id', 'connection' => 'other']]);
    expect($columns())->toBe(['category_id', 'status']);

    config()->set('schema-viewer.hints.ignore', [['kind' => 'unresolved_relation', 'relation' => '\\'.Models\Post::class.'::broken']]);
    expect(hintsOf(schemaDocument(), HintKind::UnresolvedRelation))->toBe([]);

    config()->set('schema-viewer.hints.ignore', [['table' => 'p*'], ['connection' => 'testing', 'table' => 'comments']]);
    $tables = collect(schemaDocument()->hints)->pluck('table');
    expect($tables)->not->toContain('posts')->not->toContain('comments')->toContain('ghosts');

    config()->set('schema-viewer.hints.ignore', [['connection' => 'test*']]);
    expect(schemaDocument()->hints)->toBe([]);
});

it('refuses an ignore rule with an unknown field or kind', function (mixed $rule, string $message): void {
    config()->set('schema-viewer.hints.ignore', [$rule]);

    expect(fn () => schemaDocument())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a string' => ['morph_without_map', 'got "morph_without_map"'],
    'an unknown field' => [['tabel' => 'posts'], 'Unknown hints.ignore field [tabel]'],
    'an unknown kind' => [['kind' => 'morph_map'], 'Unknown hint kind [morph_map]'],
    'an empty value' => [['table' => ''], 'must be a non-empty string'],
    'an unknown certainty' => [['certainty' => 'sure'], 'Unknown certainty [sure] in hints.ignore; use certain or likely.'],
]);

it('leaves out the hints of an ignored certainty', function (): void {
    expect(schemaDocument()->hints)->not->toBe([]);

    config()->set('schema-viewer.hints.ignore', [['certainty' => 'likely']]);
    $likely = collect(schemaDocument()->hints);

    config()->set('schema-viewer.hints.ignore', [['certainty' => 'certain']]);
    $certain = collect(schemaDocument()->hints);

    expect($likely->every(fn (Hint $hint): bool => $hint->kind->certainty() === 'certain'))->toBeTrue()
        ->and($likely)->not->toBeEmpty()
        ->and($certain->every(fn (Hint $hint): bool => $hint->kind->certainty() === 'likely'))->toBeTrue();
});

it('describes the models', function (): void {
    $models = collect(schemaDocument()->models)->keyBy('class');
    $post = $models[Models\Post::class];
    $user = $models[Models\User::class];

    expect($post->table)->toBe('posts')
        ->and($post->connection)->toBe('testing')
        ->and($post->softDeletes)->toBeTrue()
        ->and($post->casts)->toHaveKey('published_at')
        ->and($user->hidden)->toBe(['password'])
        ->and($user->scopes)->toBe(['verified']);
});

it('points each model at its file, only inside the application', function (): void {
    $outside = collect(schemaDocument()->models)->keyBy('class')[Models\Post::class];

    app()->setBasePath(dirname(__DIR__, 2));
    $inside = collect(schemaDocument()->models)->keyBy('class')[Models\Post::class];

    expect($outside->source)->toBeNull()
        ->and($inside->source?->jsonSerialize())->toBe(['file' => 'tests/Fixtures/Models/Post.php', 'line' => 14]);
});

it('serializes the whole document for the viewer', function (): void {
    $json = json_decode((string) json_encode(schemaDocument()), true);

    expect($json)->toHaveKeys(['version', 'generatedAt', 'connections', 'models', 'relations', 'unresolved', 'links', 'derivedLinks', 'hints'])
        ->and($json['version'])->toBe(1)
        ->and($json['links'][0])->toHaveKeys(['id', 'from', 'to', 'foreignKey', 'relations', 'state', 'morph'])
        ->and($json['hints'][0])->toHaveKeys(['kind', 'severity', 'certainty', 'connection', 'table', 'message'])
        ->and(array_slice(array_keys($json['hints'][0]), 0, 3))->toBe(['kind', 'severity', 'certainty']);
});

it('raises no hints on framework tables', function (): void {
    config()->set('schema-viewer.framework_tables', ['comments', 'role_user']);

    $document = schemaDocument();

    expect(hintsOf($document, HintKind::ForeignKeyWithoutRelation))->toBe([])
        ->and(hintsOf($document, HintKind::ForeignKeyWithoutIndex))->not->toContain('role_user');
});

it('notes an unreadable relation from a package instead of failing on it', function (): void {
    require_once dirname(__DIR__).'/Fixtures/Packages/vendor/acme/tokens/src/PackageToken.php';
    app()->setBasePath(dirname(__DIR__).'/Fixtures/Packages');
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/Models', dirname(__DIR__).'/Fixtures/Packages']);

    $document = schemaDocument();
    $package = array_values(array_filter($document->hints, fn (Hint $hint): bool => $hint->kind === HintKind::UnresolvedPackageRelation));

    expect(hintsOf($document, HintKind::UnresolvedRelation))->toBe(['posts'])
        ->and($package)->toHaveCount(1)
        ->and($package[0]->message)->toStartWith(Packages\AppToken::class.'::owner() comes from acme/tokens and could not be read (the viewer calls it on a blank model, with no row loaded)');
});

/**
 * Makes Composer report these packages as installed for the rest of the test; afterEach puts the real list back.
 */
function installPackages(string ...$packages): void
{
    $versions = [];

    foreach ($packages as $package) {
        $versions[$package] = ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'library', 'install_path' => '', 'aliases' => [], 'dev_requirement' => false];
    }

    InstalledVersions::reload([
        'root' => ['name' => 'test/app', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => '', 'aliases' => [], 'dev' => true],
        'versions' => $versions,
    ]);
}
