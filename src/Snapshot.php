<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Foundation\Application;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RuntimeException;
use stdClass;

/**
 * The schema snapshot: schema-viewer.json in the project root, which schema-viewer:build writes and the viewer's page
 * only reads. It holds the schema document and each connection's Mermaid, DBML and Markdown downloads as readable
 * JSON, so it can be committed and diffed. Building it runs the application's models; reading it runs nothing.
 */
final class Snapshot
{
    /**
     * Raised when the file's shape changes, so files written by another version are never read.
     */
    public const FORMAT = 2;

    /**
     * The file read last, kept for the requests that follow until it changes on disk.
     *
     * @var array{stamp: string, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string}>}|null
     */
    private ?array $loaded = null;

    public function __construct(
        private readonly Application $app,
        private readonly SchemaViewer $viewer,
        private readonly Isolation $isolation,
    ) {}

    /**
     * Where the snapshot lives: always schema-viewer.json in the project root, so it never lands where the web server
     * serves files.
     */
    public function path(): string
    {
        return $this->app->basePath('schema-viewer.json');
    }

    /**
     * The path as it is shown: relative to the project root when inside it.
     */
    public function displayPath(): string
    {
        $path = str_replace('\\', '/', $this->path());
        $root = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Read the models and the database and write the snapshot. The application's jobs, mail, notifications, HTTP calls,
     * cache and events are kept from the outside world while it runs (see Isolation).
     */
    public function build(): SchemaDocument
    {
        $rendered = $this->render();

        $this->write($this->path(), $rendered['contents']);
        $this->loaded = null;

        return $rendered['document'];
    }

    /**
     * Read the models and the database, as build() does, and return the document and the file it would write, without
     * writing it.
     *
     * @return array{document: SchemaDocument, contents: string}
     */
    public function render(): array
    {
        $document = $this->isolation->run(fn (): SchemaDocument => $this->viewer->document($this->isolation->events(...)));

        // Without when it was built, the file changes only when the schema does, so a committed snapshot diffs cleanly.
        $written = $document->jsonSerialize();
        unset($written['generatedAt']);

        $contents = json_encode([
            'format' => self::FORMAT,
            'document' => $written,
            'downloads' => HtmlExporter::downloads($document),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        return ['document' => $document, 'contents' => $contents];
    }

    /**
     * The snapshot on disk as it would be compared: without a UTF-8 BOM and with \n line endings, so a checkout that
     * converted them still matches. Null when there is no file.
     */
    public function current(): ?string
    {
        $path = $this->path();
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        return str_replace("\r\n", "\n", str_starts_with($contents, "\u{FEFF}") ? substr($contents, 3) : $contents);
    }

    /**
     * How the snapshot on disk differs from freshly rendered contents: null when they are the same, otherwise a few
     * lines that say what changed.
     *
     * @return list<string>|null
     */
    public function compare(string $existing, string $fresh): ?array
    {
        if ($existing === $fresh) {
            return null;
        }

        $old = json_decode($existing, true);
        $new = json_decode($fresh, true);

        if (! is_array($old) || ($old['format'] ?? null) !== self::FORMAT || ! is_array($old['document'] ?? null) || ! is_array($new)) {
            return ['It was written by another version of laravel-schema-viewer.'];
        }

        if ($old === $new) {
            return ['Only its formatting differs.'];
        }

        $lines = $this->summary($old, $new);

        return $lines !== [] ? $lines : ['Its contents differ in order only.'];
    }

    /**
     * What changed, section by section: tables per connection, models, relations, hints and downloads.
     *
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @return list<string>
     */
    private function summary(array $old, array $new): array
    {
        $lines = [];
        $tables = function (array $data): array {
            $tables = [];

            foreach ((array) ($data['document']['connections'] ?? []) as $connection) {
                foreach ((array) ($connection['tables'] ?? []) as $table) {
                    $tables[($connection['name'] ?? '').'.'.($table['name'] ?? '')] = $table;
                }
            }

            return $tables;
        };
        $before = $tables($old);
        $after = $tables($new);

        foreach (['added' => array_diff_key($after, $before), 'removed' => array_diff_key($before, $after)] as $change => $list) {
            if ($list !== []) {
                $lines[] = sprintf('Tables %s: %s.', $change, implode(', ', array_keys($list)));
            }
        }

        $changed = array_keys(array_filter(array_intersect_key($after, $before), fn (mixed $table, string $key): bool => $table !== $before[$key], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $lines[] = sprintf('Tables changed: %s.', implode(', ', $changed));
        }

        foreach (['models' => 'class', 'relations' => 'id', 'hints' => 'message'] as $section => $key) {
            $count = fn (array $data): array => array_count_values(array_map(fn (mixed $item): string => is_array($item) ? (string) json_encode($item[$key] ?? $item) : '', (array) ($data['document'][$section] ?? [])));
            $was = $count($old);
            $is = $count($new);
            $added = array_sum(array_map(fn (string $item): int => max(0, $is[$item] - ($was[$item] ?? 0)), array_keys($is)));
            $removed = array_sum(array_map(fn (string $item): int => max(0, $was[$item] - ($is[$item] ?? 0)), array_keys($was)));
            $same = $added === 0 && $removed === 0;

            if (! $same || ($old['document'][$section] ?? null) !== ($new['document'][$section] ?? null)) {
                $lines[] = $same ? ucfirst($section).' changed.' : sprintf('%s: %d added, %d removed.', ucfirst($section), $added, $removed);
            }
        }

        if (($old['downloads'] ?? null) !== ($new['downloads'] ?? null) && $lines === []) {
            $lines[] = 'The downloads differ.';
        }

        return $lines;
    }

    /**
     * The schema document as JSON, exactly as it was built (empty objects stay {}), for the viewer's page.
     *
     * @throws SnapshotUnavailable
     */
    public function json(): string
    {
        return $this->load()['json'];
    }

    /**
     * Each connection's downloads, by connection name: Mermaid, DBML and Markdown.
     *
     * @return array<string, array{mermaid: string, dbml: string, markdown: string}>
     *
     * @throws SnapshotUnavailable
     */
    public function files(): array
    {
        return $this->load()['files'];
    }

    /**
     * @return array{stamp: string, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string}>}
     *
     * @throws SnapshotUnavailable
     */
    private function load(): array
    {
        $path = $this->path();
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw SnapshotUnavailable::missing($this->displayPath());
        }

        $stamp = $path.'|'.@filemtime($path).'|'.@filesize($path);

        if ($this->loaded !== null && $this->loaded['stamp'] === $stamp) {
            return $this->loaded;
        }

        // Objects, not arrays, so empty objects stay {} when the document is encoded again.
        $data = json_decode((string) @file_get_contents($path), false);

        if (! $data instanceof stdClass || ($data->format ?? null) !== self::FORMAT || ! ($data->document ?? null) instanceof stdClass
            || ! is_array($data->document->connections ?? null) || ! ($data->downloads ?? null) instanceof stdClass) {
            throw SnapshotUnavailable::unreadable($this->displayPath());
        }

        $files = [];

        foreach ($data->document->connections as $connection) {
            $name = $connection instanceof stdClass ? ($connection->name ?? null) : null;
            $downloads = is_string($name) ? ($data->downloads->{$name} ?? null) : null;

            if (! is_string($name) || ! $downloads instanceof stdClass || ! is_string($downloads->mermaid ?? null)
                || ! is_string($downloads->dbml ?? null) || ! is_string($downloads->markdown ?? null)) {
                throw SnapshotUnavailable::unreadable($this->displayPath());
            }

            $files[$name] = [
                'mermaid' => $downloads->mermaid,
                'dbml' => $downloads->dbml,
                'markdown' => $downloads->markdown,
            ];
        }

        return $this->loaded = [
            'stamp' => $stamp,
            'json' => (string) json_encode($data->document, JSON_UNESCAPED_SLASHES),
            'files' => $files,
        ];
    }

    /**
     * Write through a temporary file in the same folder and rename it into place, so a request never reads half a file.
     * Windows refuses to replace a file another process has open, so the rename is tried again for a moment.
     */
    private function write(string $path, string $contents): void
    {
        $folder = dirname($path);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException("Could not create [{$folder}].");
        }

        $temporary = $folder.DIRECTORY_SEPARATOR.'.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException("Could not write [{$temporary}].");
        }

        for ($attempt = 1; ! @rename($temporary, $path); $attempt++) {
            if ($attempt === 20) {
                @unlink($temporary);

                throw new RuntimeException("Could not replace [{$path}].");
            }

            usleep(50_000);
        }
    }
}
