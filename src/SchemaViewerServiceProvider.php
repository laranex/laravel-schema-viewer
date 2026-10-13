<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\ServiceProvider;
use Laranex\LaravelSchemaViewer\Build\BuildAfterMigrations;

class SchemaViewerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schema-viewer.php', 'schema-viewer');

        $this->app->singleton(SchemaViewer::class);
        $this->app->singleton(Snapshot::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (self::flag($this->app, $this->app->make(ConfigRepository::class)->get('schema-viewer.enabled'))) {
            $this->loadRoutesFrom(__DIR__.'/../routes/schema-viewer.php');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\BuildCommand::class,
            Console\ExportCommand::class,
            Console\CheckCommand::class,
        ]);

        $this->app->make(Dispatcher::class)->listen(MigrationsEnded::class, BuildAfterMigrations::class);

        $this->publishes([
            __DIR__.'/../config/schema-viewer.php' => $this->app->configPath('schema-viewer.php'),
        ], ['laravel-schema-viewer', 'laravel-schema-viewer-config']);
    }

    /**
     * A switch that is on in the local environment only while unset, otherwise the configured value: enabled and
     * build_after_migrate.
     */
    public static function flag(Application $app, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return $app->environment('local');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
