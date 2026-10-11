<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // The demo reads the test fixtures: a blog schema with every relation type and a few hints.
        config()->set('schema-viewer.models', [dirname(__DIR__, 3).'/tests/Fixtures/Models']);
        config()->set('schema-viewer.enabled', true);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
