<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\SideEffects\Noisy;

/*
 * Reading models runs the application's code. Building the document in the current process keeps every query from
 * reaching a database and Eloquent model events from firing; the rest is only faked by schema-viewer:build.
 */

beforeEach(function (): void {
    $this->rebootWith('local', ['models' => [dirname(__DIR__).'/Fixtures/SideEffects']]);

    config()->set('queue.default', 'sync');
    config()->set('cache.default', 'array');

    $audit = tempnam(sys_get_temp_dir(), 'audit');
    config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => $audit, 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::connection('audit')->statement('create table audit (note text)');
    DB::purge('audit');

    DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    Noisy::$calls = [];
    Http::fake();
});

function noisyDocument(): SchemaDocument
{
    return app(SchemaViewer::class)->document();
}

it('keeps a model\'s boot from writing to the database', function (): void {
    noisyDocument();

    expect(Noisy::$calls)->toContain('constructed')
        ->and(DB::table('posts')->count())->toBe(0);
});

it('keeps a relation method from writing to a connection nothing had opened, and closes it again', function (): void {
    noisyDocument();

    expect(array_keys(DB::getConnections()))->not->toContain('audit')
        ->and(DB::connection('audit')->table('audit')->count())->toBe(0);
});

it('keeps a save in a relation method from firing model events', function (): void {
    $fired = [];
    Event::listen('eloquent.created: '.Post::class, function () use (&$fired): void {
        $fired[] = 'created';
    });

    noisyDocument();

    expect($fired)->toBe([])
        ->and(DB::table('posts')->count())->toBe(0);
});

it('leaves the cache, jobs, events, logs and HTTP calls of relation methods to schema-viewer:build', function (): void {
    $seen = [];
    Event::listen('noisy.announced', function () use (&$seen): void {
        $seen[] = 'event';
    });
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$seen): void {
        if ($log->message === 'noisy relation read') {
            $seen[] = 'log';
        }
    });

    noisyDocument();

    expect(Cache::get('noisy.cache'))->toBe('written by a relation method')
        ->and(Cache::get('noisy.job'))->toBe('ran')
        ->and($seen)->toBe(['event', 'log'])
        ->and(Cache::get('noisy.comment'))->not->toBeNull();

    // Laravel's HTTP client needs Guzzle, which Testbench installs on some lanes only.
    Http::assertSentCount(class_exists(Client::class) ? 1 : 0);
});

it('notes a model that cannot be constructed instead of failing the document', function (): void {
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/Models', dirname(__DIR__).'/Fixtures/Unreadable']);

    $document = noisyDocument();
    $hint = collect($document->hints)->first(fn ($hint): bool => $hint->kind === HintKind::UnresolvedRelation && str_contains($hint->message, 'Exploding'));

    expect(collect($document->models)->pluck('class'))->toContain(Post::class)
        ->and($hint?->message)->toBe('Laranex\LaravelSchemaViewer\Tests\Fixtures\Unreadable\Exploding could not be read: constructing it threw RuntimeException: No tenant is set.');
});
