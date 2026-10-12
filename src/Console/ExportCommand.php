<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Laranex\LaravelSchemaViewer\Export\DbmlExporter;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\Export\MarkdownExporter;
use Laranex\LaravelSchemaViewer\Export\MermaidExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportCommand extends Command
{
    protected $signature = 'schema-viewer:export
        {--format=json : json, mermaid, dbml, markdown or html}
        {--connection= : The connection to export (Mermaid, DBML and Markdown); defaults to the default connection}
        {--output= : Write to this file instead of the terminal; for html, a .html file or a folder (default: schema-viewer)}';

    protected $description = 'Export the database schema and Eloquent relationships as JSON, Mermaid, DBML, Markdown or a standalone HTML viewer';

    public function handle(SchemaViewer $viewer, ConnectionResolverInterface $connections): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'mermaid', 'dbml', 'markdown', 'html'], true)) {
            $this->components->error("Unknown format [{$format}]. Use json, mermaid, dbml, markdown or html.");

            return self::INVALID;
        }

        $document = $viewer->document();
        $name = (string) ($this->option('connection') ?: $connections->getDefaultConnection());
        $connection = $document->connection($name);

        if (! in_array($format, ['json', 'html'], true) && $connection === null) {
            $this->components->error("The schema has no connection [{$name}].");

            return self::INVALID;
        }

        $content = match ($format) {
            'json' => (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            'html' => (new HtmlExporter)->export($document, $viewer->defaultConnection()),
            'mermaid' => (new MermaidExporter)->export($connection),
            'dbml' => (new DbmlExporter)->export($connection),
            'markdown' => (new MarkdownExporter)->export($connection),
        };

        $output = $this->option('output');

        if ($format === 'html') {
            $output = $this->htmlPath(is_string($output) && $output !== '' ? $output : 'schema-viewer');
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
