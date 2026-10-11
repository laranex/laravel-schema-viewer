<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
        if ($this->enabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/schema-viewer.php');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/schema-viewer.php' => $this->app->configPath('schema-viewer.php'),
        ], ['laravel-schema-viewer', 'laravel-schema-viewer-config']);
    }

    /**
     * Unset: on in the local environment only. Otherwise the configured value.
     */
    private function enabled(): bool
    {
        $enabled = $this->app->make(ConfigRepository::class)->get('schema-viewer.enabled');

        if ($enabled === null || $enabled === '') {
            return $this->app->environment('local');
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }
}
