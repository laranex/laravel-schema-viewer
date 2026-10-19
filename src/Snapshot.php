<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Foundation\Application;
use JsonException;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Contract\InvalidDocument;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\Contract\Validator;
use Laranex\LaravelSchemaViewer\Diff\SchemaDiff;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RuntimeException;

/**
 * The schema snapshot: schema-viewer.json in the project root, a laranex-schema 2.0 document in canonical form, which
 * schema-viewer:build writes and the viewer's page only reads. Beside it, schema-viewer.changes.json keeps what changed
 * since the build before (the viewer's Changes tab), for the document whose revision it names; the contract keeps such
 * host settings out of the document. Building runs the application's models; reading runs nothing.
 *
 * Only laranex-schema 2.x is read. A snapshot from laravel-schema-viewer 1.x, or any other file, is refused with one
 * message that says to build it again.
 */
final class Snapshot
{
    /**
     * Larger snapshots are refused rather than read into memory.
     */
    public const MAX_BYTES = 64 * 1024 * 1024;

    private const CHANGES_FORMAT = 'laravel-schema-viewer-changes';

    /**
     * The snapshot read last, kept for the requests that follow until it changes on disk.
     *
     * @var array{stamp: string, text: string, document: array<string, mixed>, changes: list<array<string, mixed>>|null}|null
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
     * Where the changes since the previous build live, beside the snapshot.
     */
    public function changesPath(): string
    {
        return $this->app->basePath('schema-viewer.changes.json');
    }

    /**
     * The path as it is shown: relative to the project root when inside it.
     */
    public function displayPath(?string $path = null): string
    {
        $path = str_replace('\\', '/', $path ?? $this->path());
        $root = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Read the models and the database and write the snapshot and its changes. The application's jobs, mail,
     * notifications, HTTP calls, cache and events are kept from the outside world while it runs (see Isolation).
     *
     * @return array{schema: SchemaDocument, document: array<string, mixed>}
     */
    public function build(): array
    {
        $rendered = $this->render();

        $this->write($this->path(), $rendered['contents']);
        $this->write($this->changesPath(), Json::canonical([
            'format' => self::CHANGES_FORMAT,
            'version' => 1,
            'document' => hash('sha256', $rendered['contents']),
            'changes' => $rendered['changes'],
        ]));
        $this->loaded = null;

        return ['schema' => $rendered['schema'], 'document' => $rendered['document']];
    }

    /**
     * Read the models and the database, as build() does, and return the document, the file it would write and the
     * changes it would keep, without writing anything. The changes are what changed since the snapshot it replaces: the
     * changes that snapshot listed when the schema is the same, so building twice changes nothing, or the changes from
     * it to this one. Without a readable snapshot to compare with, changes is null.
     *
     * @return array{schema: SchemaDocument, document: array<string, mixed>, contents: string, changes: list<array<string, mixed>>|null}
     */
    public function render(): array
    {
        $schema = $this->isolation->document($this->viewer);
        $document = (new Producer)->document($schema);
        $contents = Json::canonical($document);
        $previous = $this->current();
        $changes = null;

        if ($previous !== null) {
            try {
                $old = $this->decode($previous);
                $diff = $previous === $contents ? [] : SchemaDiff::diff($old, Json::decode($contents));
                // Nothing the changes cover differs (at most the diagnostics did): keep what the last different build listed.
                $changes = $diff === [] ? $this->changesFor($previous) : $diff;
            } catch (SnapshotUnavailable) {
                $changes = null;
            }
        }

        /** @var array<string, mixed> $written */
        $written = Json::decode($contents);

        // The document as JSON reads it back, so it compares with any snapshot read from disk.
        return ['schema' => $schema, 'document' => $written, 'contents' => $contents, 'changes' => $changes];
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

        return self::normalized((string) @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1));
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

        try {
            $old = $this->decode($existing);
        } catch (SnapshotUnavailable $e) {
            return [$e->getMessage()];
        }

        $new = $this->decode($fresh);

        if (Json::canonical($old) === $fresh) {
            return ['Only its formatting differs.'];
        }

        $lines = array_map(SchemaDiff::describe(...), SchemaDiff::diff($old, $new));

        return $lines !== [] ? $lines : ['Its contents differ in details the summary does not cover, such as the diagnostics or the order of items.'];
    }

    /**
     * The snapshot's document as JSON text, as the viewer's page embeds it.
     *
     * @throws SnapshotUnavailable
     */
    public function json(): string
    {
        return rtrim($this->load()['text']);
    }

    /**
     * The snapshot's document, validated as readers do: any 2.x minor, unknown properties ignored.
     *
     * @return array<string, mixed>
     *
     * @throws SnapshotUnavailable
     */
    public function document(): array
    {
        return $this->load()['document'];
    }

    /**
     * What changed since the build before this snapshot, or null when there was none to compare with.
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws SnapshotUnavailable
     */
    public function changes(): ?array
    {
        return $this->load()['changes'];
    }

    /**
     * A laranex-schema 2.x document from a snapshot's text, validated as readers do.
     *
     * @return array<string, mixed>
     *
     * @throws SnapshotUnavailable When it is not JSON, is a laravel-schema-viewer 1.x snapshot, is another format or
     *                             version, or fails validation.
     */
    public function decode(string $contents, ?string $path = null): array
    {
        $shown = $this->displayPath($path ?? $this->path());

        if (strlen($contents) > self::MAX_BYTES) {
            throw SnapshotUnavailable::unreadable($shown, 'it is larger than '.(self::MAX_BYTES / 1024 / 1024).' MiB');
        }

        try {
            $data = Json::decode(self::normalized($contents));
        } catch (JsonException $e) {
            throw SnapshotUnavailable::unreadable($shown, 'it is not JSON ('.$e->getMessage().')');
        }

        $members = Json::members($data);

        // laravel-schema-viewer 1.x wrote {"format": 2, "document": {"version": 1, …}, "downloads": …, "changes": …}.
        if (($members['format'] ?? null) === 2 && Json::isObject($members['document'] ?? null)) {
            throw SnapshotUnavailable::legacy($shown);
        }

        $unsupported = Validator::unsupported($data);

        if ($unsupported !== null) {
            throw SnapshotUnavailable::unreadable($shown, $unsupported);
        }

        try {
            return Validator::assert($data);
        } catch (InvalidDocument $e) {
            throw SnapshotUnavailable::unreadable($shown, $e->getMessage());
        }
    }

    /**
     * @return array{stamp: string, text: string, document: array<string, mixed>, changes: list<array<string, mixed>>|null}
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

        $changes = $this->changesPath();
        clearstatcache(true, $changes);
        $stamp = implode('|', [$path, @filemtime($path), @filesize($path), @filemtime($changes), @filesize($changes)]);

        if ($this->loaded !== null && $this->loaded['stamp'] === $stamp) {
            return $this->loaded;
        }

        $text = (string) $this->current();
        $document = $this->decode($text);

        return $this->loaded = [
            'stamp' => $stamp,
            'text' => $text,
            'document' => $document,
            'changes' => $this->changesFor($text),
        ];
    }

    /**
     * The changes kept beside a snapshot, when they belong to exactly these contents.
     *
     * @return list<array<string, mixed>>|null
     */
    private function changesFor(string $contents): ?array
    {
        $path = $this->changesPath();
        $text = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;

        try {
            $data = is_string($text) && strlen($text) <= self::MAX_BYTES ? Json::decode($text) : null;
        } catch (JsonException) {
            return null;
        }

        $data = Json::members($data);

        if (($data['format'] ?? null) !== self::CHANGES_FORMAT || ($data['version'] ?? null) !== 1 || ($data['document'] ?? null) !== hash('sha256', $contents) || ! is_array($data['changes'] ?? null)) {
            return null;
        }

        /** @var list<array<string, mixed>> $changes */
        $changes = array_values(array_filter($data['changes'], fn (mixed $change): bool => is_array($change) && is_string($change['type'] ?? null) && is_string($change['change'] ?? null)));

        return $changes;
    }

    private static function normalized(string $contents): string
    {
        return str_replace("\r\n", "\n", str_starts_with($contents, "\u{FEFF}") ? substr($contents, 3) : $contents);
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
