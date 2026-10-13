<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Build;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Laranex\LaravelSchemaViewer\SchemaViewerServiceProvider;
use Laranex\LaravelSchemaViewer\Snapshot;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Builds the snapshot again after migrations run from the console, when build_after_migrate is on (unset: in the local
 * environment) and the viewer is enabled. It never fails the migration: a build that fails prints one line instead.
 */
final class BuildAfterMigrations
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly Snapshot $snapshot,
        private readonly ?OutputInterface $output = null,
    ) {}

    public function handle(MigrationsEnded $event): void
    {
        // Laravel 11 and later tell whether the migrations only pretended to run.
        $options = get_object_vars($event)['options'] ?? [];

        if (! $this->app->runningInConsole() || (is_array($options) && ($options['pretend'] ?? false) === true)
            || ! SchemaViewerServiceProvider::flag($this->app, $this->config->get('schema-viewer.build_after_migrate'))
            || ! SchemaViewerServiceProvider::flag($this->app, $this->config->get('schema-viewer.enabled'))) {
            return;
        }

        $output = $this->output ?? new ConsoleOutput;

        try {
            $this->snapshot->build();
            $output->writeln("  <info>Schema viewer</info> snapshot written to [{$this->snapshot->displayPath()}].");
        } catch (Throwable $e) {
            $output->writeln("  <comment>Schema viewer</comment> could not build its snapshot ({$e->getMessage()}). Run php artisan schema-viewer:build.");
        }
    }
}
