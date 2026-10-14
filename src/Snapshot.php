<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Foundation\Application;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Diff\SchemaDiff;
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
     * @var array{stamp: string, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string}>, changes: string}|null
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
     * writing it. The file keeps what changed since the snapshot it replaces: the changes that snapshot listed when the
     * schema is the same, so building twice changes nothing, or the changes from it to this one. Without a readable
     * snapshot to compare with, changes is null.
     *
     * @return array{document: SchemaDocument, contents: string}
     */
    public function render(): array
    {
        $document = $this->isolation->run(fn (): SchemaDocument => $this->viewer->document($this->isolation->events(...)));

        // Without when it was built, the file changes only when the schema does, so a committed snapshot diffs cleanly.
        $written = $document->jsonSerialize();
        unset($written['generatedAt']);

        // Encoded and decoded once, so it compares with the file as JSON reads it back.
        $fresh = json_decode((string) json_encode($written, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
        $previous = $this->decoded($this->current());

        $changes = null;

        if ($previous !== null && is_array($fresh)) {
            $diff = $previous['document'] === $fresh ? [] : SchemaDiff::diff($previous['document'], $fresh);
            // Nothing the changes cover differs (at most the hints did): keep what the last different build listed.
            $changes = $diff === [] ? $previous['changes'] : $diff;
        }

        $contents = json_encode([
            'format' => self::FORMAT,
            'document' => $written,
            'downloads' => HtmlExporter::downloads($document),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        return ['document' => $document, 'contents' => $contents];
    }

    /**
     * A snapshot's contents decoded, or null when it is missing, damaged or written by another version.
     *
     * @return array{document: array<mixed>, changes: list<array<string, mixed>>|null}|null
     */
    public function decoded(?string $contents): ?array
    {
        $data = $contents !== null ? json_decode($contents, true) : null;

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['document'] ?? null)) {
            return null;
        }

        /** @var list<array<string, mixed>>|null $changes */
        $changes = is_array($data['changes'] ?? null) ? array_values(array_filter($data['changes'], 'is_array')) : null;

        return ['document' => $data['document'], 'changes' => $changes];
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

        $old = $this->decoded($existing);
        $new = $this->decoded($fresh);

        if ($old === null || $new === null) {
            return ['It was written by another version of laravel-schema-viewer.'];
        }

        if (json_decode($existing, true) === json_decode($fresh, true)) {
            return ['Only its formatting differs.'];
        }

        $lines = array_map(SchemaDiff::describe(...), SchemaDiff::diff($old['document'], $new['document']));

        return $lines !== [] ? $lines : ['Its contents differ in details the summary does not cover, such as the hints, the order of items or the downloads.'];
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
     * What changed since the snapshot before this one, as JSON: a list, or null when there was none to compare with.
     *
     * @throws SnapshotUnavailable
     */
    public function changes(): string
    {
        return $this->load()['changes'];
    }

    /**
     * @return array{stamp: string, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string}>, changes: string}
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
            'changes' => is_array($data->changes ?? null) ? (string) json_encode($data->changes, JSON_UNESCAPED_SLASHES) : 'null',
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
