<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Snapshot;
use Throwable;

final class BuildCommand extends Command
{
    protected $signature = 'schema-viewer:build';

    protected $description = 'Read the database and the Eloquent models and write the schema snapshot the viewer serves';

    public function handle(Snapshot $snapshot): int
    {
        try {
            $document = $snapshot->build();
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

        return self::SUCCESS;
    }
}
