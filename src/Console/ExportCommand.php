<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\Export\Exporters;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportCommand extends Command
{
    protected $signature = 'schema-viewer:export
        {--format=json : json, mermaid, dbml, markdown or html}
        {--connection= : The connection to export: Mermaid, DBML and Markdown default to the default connection, JSON to every connection}
        {--orm : Mermaid and Markdown: also the Eloquent relationships and inferred associations, labelled apart from the foreign keys}
        {--output= : Write to this file instead of the terminal; for html, a .html file or a folder (default: schema-viewer)}';

    protected $description = 'Export the database schema and Eloquent relationships as laranex-schema JSON, Mermaid, DBML, Markdown or a standalone HTML viewer';

    public function handle(SchemaViewer $viewer, ConnectionResolverInterface $connections): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'mermaid', 'dbml', 'markdown', 'html'], true)) {
            $this->components->error("Unknown format [{$format}]. Use json, mermaid, dbml, markdown or html.");

            return self::INVALID;
        }

        $schema = $viewer->document();
        $document = (new Producer)->document($schema);
        $option = $this->option('connection');
        $name = is_string($option) && $option !== '' ? $option : ($format === 'json' || $format === 'html' ? null : $connections->getDefaultConnection());

        if ($name !== null && $schema->connection($name) === null) {
            $this->components->error("The schema has no connection [{$name}].");

            return self::INVALID;
        }

        $warnings = [];

        if ($format === 'html') {
            $default = $viewer->defaultConnection();
            $content = (new HtmlExporter)->export($document, null, $name !== null ? Producer::connectionId($name) : ($default !== 'all' ? Producer::connectionId($default) : null));
        } else {
            try {
                ['content' => $content, 'warnings' => $warnings] = Exporters::all()[$format]->export($document, $name !== null ? [Producer::connectionId($name)] : null, (bool) $this->option('orm'));
            } catch (InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::INVALID;
            }
        }

        $output = $this->option('output');

        foreach ($warnings as $warning) {
            $this->note($warning['message'], is_string($output) && $output !== '');
        }

        if ($format === 'html') {
            $output = $this->htmlPath(is_string($output) && $output !== '' ? $output : 'schema-viewer');

            if ($output === null) {
                return self::FAILURE;
            }
        }

        if (is_string($output) && $output !== '') {
            if (@file_put_contents($output, $content) === false) {
                $this->components->error("Could not write [{$output}].");

                return self::FAILURE;
            }

            $this->components->info("Schema exported to [{$output}].");

            return self::SUCCESS;
        }

        $this->output->write($content, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * A line on standard error, so it never mixes into an export written to standard output; without a standard error
     * (Artisan::call), only when the export goes to a file.
     */
    private function note(string $message, bool $toFile): void
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln('<fg=gray>Note:</> '.$message);
        } elseif ($toFile) {
            $this->line('<fg=gray>Note:</> '.$message);
        }
    }

    /**
     * The standalone viewer goes to the given .html file, or to index.html inside the given folder, which is created
     * when missing. Relative paths start at the application's base path.
     */
    private function htmlPath(string $output): ?string
    {
        if (! preg_match('#^([a-zA-Z]:)?[\\\\/]#', $output)) {
            $output = $this->laravel->basePath($output);
        }

        if (str_ends_with(strtolower($output), '.html')) {
            return $output;
        }

        if (! is_dir($output) && ! @mkdir($output, 0755, true)) {
            $this->components->error("Could not create [{$output}].");

            return null;
        }

        return rtrim($output, '\\/').DIRECTORY_SEPARATOR.'index.html';
    }
}
