<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\Link;
use Laranex\LaravelSchemaViewer\Schema\LinkState;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

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
        ->and(hintsOf($document, HintKind::ForeignKeyWithoutIndex))->toContain('role_user')
        ->and(hintsOf($document, HintKind::ModelWithoutTable))->toBe(['ghosts'])
        ->and(hintsOf($document, HintKind::MorphWithoutMap))->toContain('comments', 'taggables')
        ->and(hintsOf($document, HintKind::UnresolvedRelation))->toBe(['posts']);
});

it('leaves out ignored hints', function (): void {
    config()->set('schema-viewer.hints.ignore', ['morph_without_map', 'model_without_table:ghosts']);

    $document = schemaDocument();

    expect(hintsOf($document, HintKind::MorphWithoutMap))->toBe([])
        ->and(hintsOf($document, HintKind::ModelWithoutTable))->toBe([])
        ->and(hintsOf($document, HintKind::RelationWithoutForeignKey))->toBe(['posts']);
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

it('serializes the whole document for the viewer', function (): void {
    $json = json_decode((string) json_encode(schemaDocument()), true);

    expect($json)->toHaveKeys(['version', 'generatedAt', 'connections', 'models', 'relations', 'unresolved', 'links', 'derivedLinks', 'hints'])
        ->and($json['version'])->toBe(1)
        ->and($json['links'][0])->toHaveKeys(['id', 'from', 'to', 'foreignKey', 'relations', 'state', 'morph'])
        ->and($json['hints'][0])->toHaveKeys(['kind', 'severity', 'connection', 'table', 'message']);
});
