<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RuntimeException;
use stdClass;

/**
 * The schema snapshot: the file schema-viewer:build writes (by default schema-viewer.json in the project root) and the
 * viewer's routes only read. It holds the schema document and each connection's Mermaid, DBML and Markdown downloads
 * as readable JSON, so it can be committed and diffed. Building it runs the application's models; reading it runs
 * nothing.
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
     * @var array{stamp: string, json: string, etag: string, gzip: string|null, files: array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>}|null
     */
    private ?array $loaded = null;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly SchemaViewer $viewer,
        private readonly Isolation $isolation,
    ) {}

    /**
     * Where the snapshot lives: the configured path (relative paths start at the project root), or schema-viewer.json in
     * the project root.
     */
    public function path(): string
    {
        $path = $this->config->get('schema-viewer.snapshot');

        if (! is_string($path) || $path === '') {
            return $this->app->basePath('schema-viewer.json');
        }

        return preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) === 1 ? $path : $this->app->basePath($path);
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
        $document = $this->isolation->run(fn (): SchemaDocument => $this->viewer->document($this->isolation->events(...)));

        $contents = json_encode([
            'format' => self::FORMAT,
            'document' => $document,
            'downloads' => HtmlExporter::downloads($document),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        $this->write($this->path(), $contents);
        $this->loaded = null;

        return $document;
    }

    /**
     * The schema document as JSON, as the viewer's API serves it.
     *
     * @throws SnapshotUnavailable
     */
    public function json(): string
    {
        return $this->load()['json'];
    }

    /**
     * An entity tag for the document: it changes whenever the snapshot does.
     *
     * @throws SnapshotUnavailable
     */
    public function etag(): string
    {
        return $this->load()['etag'];
    }

    /**
     * The document's JSON compressed with gzip, compressed once per snapshot.
     *
     * @throws SnapshotUnavailable
     */
    public function gzip(): string
    {
        $loaded = $this->load();

        if ($loaded['gzip'] === null) {
            $loaded['gzip'] = (string) gzencode($loaded['json'], 6);
            $this->loaded = $loaded;
        }

        return $loaded['gzip'];
    }

    /**
     * Each connection's downloads, by connection name: Mermaid, DBML, Markdown and the connection's own JSON.
     *
     * @return array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>
     *
     * @throws SnapshotUnavailable
     */
    public function files(): array
    {
        return $this->load()['files'];
    }

    /**
     * @return array{stamp: string, json: string, etag: string, gzip: string|null, files: array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>}
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
                'json' => json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            ];
        }

        $json = (string) json_encode($data->document, JSON_UNESCAPED_SLASHES);

        return $this->loaded = [
            'stamp' => $stamp,
            'json' => $json,
            'etag' => substr(hash('sha256', $json), 0, 32),
            'gzip' => null,
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
