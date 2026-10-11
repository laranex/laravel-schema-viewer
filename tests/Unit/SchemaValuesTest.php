<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Schema\Endpoint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\Link;
use Laranex\LaravelSchemaViewer\Schema\LinkState;

it('serves lookups on an index\'s leading columns only', function (): void {
    $index = new Index('posts_status_published_at_index', ['status', 'published_at'], false, false);

    expect($index->startsWith(['status']))->toBeTrue()
        ->and($index->startsWith(['status', 'published_at']))->toBeTrue()
        ->and($index->startsWith(['published_at']))->toBeFalse();
});

it('names a link\'s state from what backs it', function (): void {
    $from = new Endpoint('mysql', 'posts', ['user_id']);
    $to = new Endpoint('mysql', 'users', ['id']);

    expect((new Link('a', $from, $to, 'posts_user_id_foreign', ['App\Models\Post::author'], false))->state())->toBe(LinkState::Both)
        ->and((new Link('b', $from, $to, 'posts_user_id_foreign', [], false))->state())->toBe(LinkState::ForeignKeyOnly)
        ->and((new Link('c', $from, $to, null, ['App\Models\Post::author'], false))->state())->toBe(LinkState::RelationOnly)
        ->and($from->key())->toBe('mysql|posts|user_id');
});

it('gives each hint kind a severity', function (): void {
    expect(HintKind::ForeignKeyWithoutIndex->severity())->toBe('warning')
        ->and(HintKind::MorphWithoutMap->severity())->toBe('info')
        ->and(HintKind::ForeignKeyWithoutRelation->severity())->toBe('info');
});
