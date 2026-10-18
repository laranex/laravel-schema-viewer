<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Build;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http as HttpFacade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\EventFake;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\SchemaViewer;

/**
 * Keeps the application's code from reaching the outside world while a command (build, build --check, diff, export or
 * check) reads the models: jobs, queued jobs, mail and notifications are faked, HTTP calls answered by a fake, the
 * default cache kept in memory and, while relation methods run, events faked. Everything is put back afterwards, so a
 * command run from a long-running process (Artisan::call()) leaves the application as it found it.
 */
final class Isolation
{
    /**
     * The cache store the build writes to.
     */
    private const CACHE = 'schema-viewer-build';

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    /**
     * Read the models and the database into a document, isolated: what every command that runs the application's models
     * calls instead of SchemaViewer::document().
     */
    public function document(SchemaViewer $viewer): SchemaDocument
    {
        return $this->run(fn (): SchemaDocument => $viewer->document($this->events(...)));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        /** @var list<Closure(): void> $restore */
        $restore = [];

        try {
            if ($this->app->bound(BusDispatcher::class) && Bus::getFacadeRoot() instanceof QueueingDispatcher) {
                $bus = Bus::getFacadeRoot();
                Bus::fake();
                $restore[] = fn () => Bus::swap($bus);
            }

            if ($this->app->bound('queue')) {
                $queue = Queue::getFacadeRoot();
                Queue::fake();
                $restore[] = fn () => Queue::swap($queue);
            }

            if ($this->app->bound('mail.manager')) {
                $mail = Mail::getFacadeRoot();
                Mail::fake();
                $restore[] = fn () => Mail::swap($mail);
            }

            if ($this->app->bound('Illuminate\\Notifications\\ChannelManager')) {
                $notifications = Notification::getFacadeRoot();
                Notification::fake();
                $restore[] = fn () => Notification::swap($notifications);
            }

            // Http::fake() changes the factory itself, so the fake is set up on a copy.
            if (class_exists('GuzzleHttp\\Client') && ($http = HttpFacade::getFacadeRoot()) instanceof Http) {
                HttpFacade::swap(clone $http);
                HttpFacade::fake();
                $restore[] = fn () => HttpFacade::swap($http);
            }

            // A store of its own, kept in memory and dropped afterwards.
            if ($this->app->bound('cache')) {
                $cache = $this->config->get('cache.default');
                $this->config->set('cache.stores.'.self::CACHE, ['driver' => 'array']);
                $this->config->set('cache.default', self::CACHE);
                $restore[] = function () use ($cache): void {
                    $this->config->set('cache.default', $cache);
                    Cache::forgetDriver(self::CACHE);
                    $this->config->set('cache.stores.'.self::CACHE, null);
                };
            }

            return $callback();
        } finally {
            foreach (array_reverse($restore) as $undo) {
                $undo();
            }
        }
    }

    /**
     * Run the relation methods with events faked. Models are constructed before, so the observers they register at boot
     * still reach the real dispatcher.
     *
     * @param  Closure(): void  $read
     */
    public function events(Closure $read): void
    {
        $events = $this->app->bound('events') ? Event::getFacadeRoot() : null;

        if (! $events instanceof Events) {
            $read();

            return;
        }

        Event::swap(new EventFake($events));

        try {
            $read();
        } finally {
            Event::swap($events);
        }
    }
}
