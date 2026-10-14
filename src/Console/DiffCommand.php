<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Laranex\LaravelSchemaViewer\Diff\SchemaDiff;
use Laranex\LaravelSchemaViewer\Snapshot;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class DiffCommand extends Command
{
    protected $signature = 'schema-viewer:diff
        {base? : An older schema-viewer.json to compare with; without it, the changes the snapshot lists}
        {--against= : Compare the base with this schema-viewer.json instead of the schema now}
        {--format=text : text, json or markdown}';

    protected $description = 'Show what changed in the schema: since the previous build, or between two snapshots';

    public function handle(Snapshot $snapshot): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['text', 'json', 'markdown'], true)) {
            $this->components->error("Unknown format [{$format}]. Use text, json or markdown.");

            return self::INVALID;
        }

        $base = $this->argument('base');
        $against = $this->option('against');

        if (! is_string($base) || $base === '') {
            if (is_string($against) && $against !== '') {
                $this->components->error('--against compares two files: name the older one first, such as schema-viewer:diff base.json --against=schema-viewer.json.');

                return self::INVALID;
            }

            $current = $snapshot->decoded($snapshot->current());

            if ($current === null) {
                $this->components->error(sprintf('No readable schema snapshot at [%s]. Run php artisan schema-viewer:build.', $snapshot->displayPath()));

                return self::INVALID;
            }

            return $this->show($current['changes'], $format);
        }

        $before = $this->read($snapshot, $base);

        if ($before === null) {
            return self::INVALID;
        }

        if (is_string($against) && $against !== '') {
            $after = $this->read($snapshot, $against);

            if ($after === null) {
                return self::INVALID;
            }
        } else {
            try {
                $after = $snapshot->decoded($snapshot->render()['contents']);
            } catch (Throwable $e) {
                $this->components->error('The schema could not be read: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return $this->show(SchemaDiff::diff($before['document'], $after['document'] ?? []), $format);
    }

    /**
     * A snapshot file, relative to the working directory or the project root.
     *
     * @return array{document: array<mixed>, changes: list<array<string, mixed>>|null}|null
     */
    private function read(Snapshot $snapshot, string $path): ?array
    {
        $file = is_file($path) ? $path : $this->laravel->basePath($path);

        if (! is_file($file)) {
            $this->components->error("No file at [{$path}].");

            return null;
        }

        $contents = (string) file_get_contents($file);
        $decoded = $snapshot->decoded(str_starts_with($contents, "\u{FEFF}") ? substr($contents, 3) : $contents);

        if ($decoded === null) {
            $this->components->error("[{$path}] was written by another version of laravel-schema-viewer, or is not a schema snapshot.");
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>|null  $changes
     */
    private function show(?array $changes, string $format): int
    {
        if ($format === 'json') {
            $this->raw(json_encode(['version' => 1, 'changes' => $changes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

            return self::SUCCESS;
        }

        if ($changes === null || $changes === []) {
            $message = $changes === null
                ? 'Nothing to compare yet. Once a later schema-viewer:build finds that tables, columns, indexes, foreign keys, models or relations changed, this lists what changed.'
                : 'No schema changes.';
            $format === 'markdown' ? $this->raw("## Schema changes\n\n{$message}\n") : $this->components->info($message);

            return self::SUCCESS;
        }

        $groups = [];

        foreach ($changes as $change) {
            $table = is_string($change['table'] ?? null) && $change['table'] !== '' ? $change['connection'].'.'.$change['table'] : null;
            $groups[$table ?? match ($change['type']) {
                'connection' => 'Connections',
                'model' => 'Models',
                'relation' => 'Relations',
                default => 'Schema',
            }][] = SchemaDiff::describe($change);
        }

        if ($format === 'markdown') {
            $lines = ['## Schema changes', '', sprintf('%d %s.', count($changes), count($changes) === 1 ? 'change' : 'changes')];

            foreach ($groups as $group => $items) {
                array_push($lines, '', '### '.$this->markdown($group), '', ...array_map(fn (string $item): string => '- '.$this->markdown($item), $items));
            }

            $this->raw(implode("\n", $lines)."\n");

            return self::SUCCESS;
        }

        foreach ($groups as $group => $items) {
            $this->line('<options=bold>'.OutputFormatter::escape($group).'</>');

            foreach ($items as $item) {
                $this->line('  '.OutputFormatter::escape($item));
            }

            $this->newLine();
        }

        $this->components->info(sprintf('%d %s.', count($changes), count($changes) === 1 ? 'change' : 'changes'));

        return self::SUCCESS;
    }

    private function raw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    private function markdown(string $text): string
    {
        return str_replace(['&', '<', '>', '|', "\n"], ['&amp;', '&lt;', '&gt;', '\|', ' '], $text);
    }
}
