<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\Export\Exporters;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportCommand extends Command
{
    use KeepsSchemaOutOfGit;

    protected $signature = 'schema-viewer:export
        {--format=json : json, mermaid, dbml, markdown or html}
        {--connection= : The connection to export: Mermaid, DBML and Markdown default to the default connection, JSON to every connection}
        {--orm : Mermaid and Markdown: also the Eloquent relationships and inferred associations, labelled apart from the foreign keys}
        {--output= : Write to this file instead of the terminal; for html, a .html file or a folder (default: schema-viewer)}';

    protected $description = 'Export the database schema and Eloquent relationships as laranex-schema JSON, Mermaid, DBML, Markdown or a standalone HTML viewer';

    public function handle(SchemaViewer $viewer, Isolation $isolation, ConnectionResolverInterface $connections): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'mermaid', 'dbml', 'markdown', 'html'], true)) {
            $this->components->error("Unknown format [{$format}]. Use json, mermaid, dbml, markdown or html.");

            return self::INVALID;
        }

        $schema = $isolation->document($viewer);
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
        $toFile = $format === 'html' || (is_string($output) && $output !== '');

        if ($format === 'html') {
            $output = $this->htmlFolder(is_string($output) && $output !== '' ? $output : 'schema-viewer');
        } elseif (is_string($output) && $output !== '') {
            $output = $this->absolute($output, (string) getcwd());
        }

        // The application's web root would publish the schema to anyone who guesses the URL.
        $public = is_string($output) && $output !== '' ? $this->publicRoot($output) : null;

        if ($public !== null) {
            $this->components->error("Refusing to write the export inside [{$public}], which the application serves to the web. Choose a path outside public/ and storage/app/public.");

            return self::INVALID;
        }

        if (is_string($output) && $output !== '') {
            $file = $format !== 'html' || str_ends_with(strtolower($output), '.html') ? $output : rtrim($output, '\\/').DIRECTORY_SEPARATOR.'index.html';

            if (! $this->keepOutOfGit([$file])) {
                return self::FAILURE;
            }
        }

        if ($format === 'html' && ($output = $this->htmlFile($output)) === null) {
            return self::FAILURE;
        }

        foreach ($warnings as $warning) {
            $this->note($warning['message'], $toFile);
        }

        $this->note('An export of a private schema is sensitive: storing and sharing it is up to you.', $toFile);

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
     * Where the standalone viewer goes: the given .html file, or the given folder; relative paths start at the
     * application's base path.
     */
    private function htmlFolder(string $output): string
    {
        return $this->absolute($output, $this->laravel->basePath());
    }

    /**
     * The standalone viewer's file: the .html file itself, or index.html inside the folder, which is created when
     * missing.
     */
    private function htmlFile(string $output): ?string
    {
        if (str_ends_with(strtolower($output), '.html')) {
            return $output;
        }

        if (! is_dir($output) && ! @mkdir($output, 0755, true)) {
            $this->components->error("Could not create [{$output}].");

            return null;
        }

        return rtrim($output, '\\/').DIRECTORY_SEPARATOR.'index.html';
    }

    private function absolute(string $path, string $base): string
    {
        return preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) === 1 ? $path : rtrim($base, '\\/').DIRECTORY_SEPARATOR.$path;
    }

    /**
     * The folder the application serves to the web (public/, or storage/app/public behind the public/storage link)
     * that holds the path, if one does: checked on the path as the file system resolves it, symbolic links included.
     */
    private function publicRoot(string $path): ?string
    {
        $resolved = self::resolve($path);

        foreach ([$this->laravel->publicPath(), $this->laravel->storagePath('app'.DIRECTORY_SEPARATOR.'public')] as $root) {
            foreach (array_unique([self::resolve($root), self::normalize($root)]) as $candidate) {
                if ($resolved === $candidate || str_starts_with($resolved, rtrim($candidate, '/').'/')) {
                    return $root;
                }
            }
        }

        return null;
    }

    /**
     * A path with its nearest existing folder resolved through symbolic links, and the rest normalized.
     */
    private static function resolve(string $path): string
    {
        $path = self::normalize($path);
        $rest = [];

        while (($real = realpath($path)) === false && $path !== dirname($path)) {
            array_unshift($rest, basename($path));
            $path = dirname($path);
        }

        return self::normalize(($real !== false ? $real : $path).($rest !== [] ? '/'.implode('/', $rest) : ''));
    }

    /**
     * Forward slashes, no "." or ".." segments.
     */
    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = preg_match('#^([a-zA-Z]:)?/#', $path, $match) === 1 ? $match[0] : '';
        $segments = [];

        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $prefix.implode('/', $segments);
    }
}
