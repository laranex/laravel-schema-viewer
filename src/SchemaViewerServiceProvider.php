<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Support\ServiceProvider;

class SchemaViewerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schema-viewer.php', 'schema-viewer');

        $this->app->singleton(SchemaViewer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/schema-viewer.php' => $this->app->configPath('schema-viewer.php'),
        ], ['laravel-schema-viewer', 'laravel-schema-viewer-config']);
    }
}
