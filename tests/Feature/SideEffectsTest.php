<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\SideEffects\Noisy;

/*
 * Reading models runs the application's code. SchemaViewer::document() keeps every query from reaching a database and
 * Eloquent model events from firing; the commands that read the models (build, diff, export and check) also fake the
 * rest. The commands that only read saved snapshots run no model code at all.
 */

beforeEach(function (): void {
    $this->rebootWith('local', ['models' => [dirname(__DIR__).'/Fixtures/SideEffects']]);

    config()->set('queue.default', 'sync');
    config()->set('cache.default', 'array');
    config()->set('mail.default', 'array');

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

/**
 * The messages the array mailer has sent for real.
 */
function sentMail(): int
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
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

it('leaves the cache, jobs, mail, events, logs and HTTP calls of relation methods to the commands', function (): void {
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
        ->and(Cache::get('noisy.comment'))->not->toBeNull()
        ->and(sentMail())->toBe(1);

    // Laravel's HTTP client needs Guzzle, which Testbench installs on some lanes only.
    Http::assertSentCount(class_exists(Client::class) ? 1 : 0);
});

it('says it is building only while it reads the models', function (): void {
    expect(SchemaViewer::building())->toBeFalse();

    noisyDocument();

    expect(Noisy::$calls)->toContain('constructed while building')
        ->and(SchemaViewer::building())->toBeFalse()
        ->and(new Noisy)->toBeInstanceOf(Noisy::class)
        ->and(Noisy::$calls)->toContain('constructed outside a build');

    // calledPosts() sends its request; guardedPosts() skips its own while building.
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://hooks.example.test/guarded');
});

it('notes a model that cannot be constructed instead of failing the document', function (): void {
    config()->set('schema-viewer.models', [dirname(__DIR__).'/Fixtures/Models', dirname(__DIR__).'/Fixtures/Unreadable']);

    $document = noisyDocument();
    $hint = collect($document->hints)->first(fn ($hint): bool => $hint->kind === HintKind::UnresolvedRelation && str_contains($hint->message, 'Exploding'));

    expect(collect($document->models)->pluck('class'))->toContain(Post::class)
        ->and($hint?->message)->toBe('Laranex\LaravelSchemaViewer\Tests\Fixtures\Unreadable\Exploding could not be read: constructing it threw RuntimeException: No tenant is set.');
});

it('keeps the application\'s mail, jobs, events, cache and HTTP calls from the outside world while a command reads the models', function (string $command, array $parameters): void {
    $parameters = array_map(fn (string $value): string => str_replace('{root}', dirname($this->snapshot), $value), $parameters);
    $bus = Bus::getFacadeRoot();
    $mail = Mail::getFacadeRoot();
    $events = Event::getFacadeRoot();
    $http = Http::getFacadeRoot();
    $announced = 0;
    Event::listen('noisy.announced', function () use (&$announced): void {
        $announced++;
    });

    Artisan::call($command, $parameters);

    expect(Cache::get('noisy.cache'))->toBeNull()
        ->and(Cache::get('noisy.job'))->toBeNull()
        ->and(sentMail())->toBe(0)
        ->and($announced)->toBe(0)
        ->and(Bus::getFacadeRoot())->toBe($bus)
        ->and(Mail::getFacadeRoot())->toBe($mail)
        ->and(Event::getFacadeRoot())->toBe($events)
        ->and(Http::getFacadeRoot())->toBe($http)
        ->and(config('cache.default'))->toBe('array');

    Http::assertNothingSent();

    // The command still did its work, and the application's own calls work again afterwards.
    if (isset($parameters['--output'])) {
        expect(json_decode((string) file_get_contents($parameters['--output']), true)['format'])->toBe('laranex-schema');
    } else {
        expect(Artisan::output())->toContain('users');
    }

    event('noisy.announced');
    expect($announced)->toBe(1);
})->with([
    'export' => ['schema-viewer:export', ['--output' => '{root}/export.json']],
    'export to the terminal' => ['schema-viewer:export', ['--format' => 'mermaid']],
    'check' => ['schema-viewer:check', []],
    'check as JSON' => ['schema-viewer:check', ['--format' => 'json']],
]);

it('runs no model code when a command only reads saved snapshots', function (): void {
    Artisan::call('schema-viewer:build');
    $base = dirname($this->snapshot).DIRECTORY_SEPARATOR.'base.json';
    copy($this->snapshot, $base);
    Noisy::$calls = [];

    expect(Artisan::call('schema-viewer:diff'))->toBe(0)
        ->and(Artisan::call('schema-viewer:diff', ['base' => $base, '--against' => $this->snapshot]))->toBe(0)
        ->and(Artisan::output())->toContain('No schema changes.')
        ->and(Noisy::$calls)->toBe([]);
});
