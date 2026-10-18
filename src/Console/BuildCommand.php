<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Snapshot;
use Throwable;

final class BuildCommand extends Command
{
    use KeepsSchemaOutOfGit;

    protected $signature = 'schema-viewer:build
        {--check : Build in memory and fail when schema-viewer.json is missing or out of date, without writing it}';

    protected $description = 'Read the database and the Eloquent models and write the laranex-schema document the viewer serves';

    public function handle(Snapshot $snapshot, SchemaViewer $viewer): int
    {
        if ($this->option('check')) {
            return $this->check($snapshot);
        }

        if (! $this->keepOutOfGit([$snapshot->path(), $snapshot->changesPath()])) {
            return self::FAILURE;
        }

        try {
            $document = $snapshot->build()['schema'];
        } catch (Throwable $e) {
            $this->components->error('The schema snapshot could not be built: '.$e->getMessage());

            return self::FAILURE;
        }

        $tables = array_sum(array_map(fn (ConnectionSchema $connection): int => count($connection->tables), $document->connections));

        $this->components->info(sprintf(
            'Schema snapshot written to [%s]: %d %s, %d %s, %d %s.',
            $snapshot->displayPath(),
            $tables, $tables === 1 ? 'table' : 'tables',
            count($document->models), count($document->models) === 1 ? 'model' : 'models',
            count($document->relations), count($document->relations) === 1 ? 'relation' : 'relations',
        ));

        $unmatched = $viewer->unmatchedNotes($document);

        if ($unmatched !== []) {
            $this->components->warn(sprintf('%d %s nothing: %s.', count($unmatched), count($unmatched) === 1 ? 'note matches' : 'notes match', implode(', ', $unmatched)));
        }

        return self::SUCCESS;
    }

    /**
     * Whether the snapshot on disk matches the schema now, without writing it.
     */
    private function check(Snapshot $snapshot): int
    {
        try {
            $fresh = $snapshot->render()['contents'];
        } catch (Throwable $e) {
            $this->components->error('The schema snapshot could not be built: '.$e->getMessage());

            return self::FAILURE;
        }

        $path = $snapshot->displayPath();
        $existing = $snapshot->current();

        if ($existing === null) {
            $this->components->error("No schema snapshot at [{$path}]. Run php artisan schema-viewer:build.");

            return self::FAILURE;
        }

        $summary = $snapshot->compare($existing, $fresh);

        if ($summary === null) {
            $this->components->info("Schema snapshot [{$path}] is up to date.");

            return self::SUCCESS;
        }

        $this->components->error("The schema snapshot [{$path}] is out of date. Run php artisan schema-viewer:build.");

        foreach (array_slice($summary, 0, 10) as $line) {
            $this->line('  '.$line);
        }

        if (count($summary) > 10) {
            $this->line(sprintf('  …and %d more', count($summary) - 10));
        }

        return self::FAILURE;
    }
}
