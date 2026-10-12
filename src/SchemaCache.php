<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use FilesystemIterator;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laranex\LaravelSchemaViewer\Export\HtmlExporter;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;
use Throwable;

/**
 * The viewer's schema document, built once and kept in the application's cache until something it reads changes: a
 * model file, the viewer's config, the morph map, dynamic relations, observers, installed packages, or a connection's
 * migrations (and an SQLite database file). Anything else, such as a table altered by hand, shows after the TTL or a
 * refresh. The document and each connection's downloads are kept encoded, so a cached request never encodes them again.
 */
class SchemaCache
{
    /**
     * Raised when the cached entry's shape changes, so older entries are never read.
     */
    private const FORMAT = 1;

    public function __construct(
        private readonly SchemaViewer $viewer,
        private readonly Application $app,
        private readonly Config $config,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {}

    /**
     * The schema document as JSON, as the viewer's API serves it; read again when $refresh is true.
     */
    public function json(bool $refresh = false): string
    {
        return $this->entry($refresh)['json'];
    }

    /**
     * Each connection's downloads, by connection name: Mermaid, DBML, Markdown and the connection's own JSON.
     *
     * @return array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>
     */
    public function files(): array
    {
        $entry = $this->entry(false);

        if ($entry['files'] !== null) {
            return $entry['files'];
        }

        $files = $entry['packed'] !== null ? json_decode((string) @gzuncompress($entry['packed']), true) : null;

        if (! is_array($files)) {
            $files = $this->build($entry['key'])['files'];
        }

        /** @var array<string, array{mermaid: string, dbml: string, markdown: string, json: string}> $files */
        return $files;
    }

    /**
     * The entry for the current fingerprint: from the cache, or built now.
     *
     * @return array{key: string, stamps: array<string, string>, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>|null, packed: string|null}
     */
    private function entry(bool $refresh): array
    {
        $key = 'schema-viewer:'.hash('sha256', $this->fingerprint());

        $store = $this->store();

        if (! $refresh && $store !== null) {
            try {
                $cached = $store->get($key);
            } catch (Throwable) {
                $cached = null;
            }

            if (is_array($cached) && ($cached['format'] ?? null) === self::FORMAT && is_array($cached['stamps'] ?? null) && is_string($cached['json'] ?? null)
                && is_string($cached['files'] ?? null) && $this->stamps(array_map('strval', array_keys($cached['stamps']))) === $cached['stamps']) {
                /** @var array<string, string> $stamps */
                $stamps = $cached['stamps'];
                $json = @gzuncompress($cached['json']);

                if (is_string($json)) {
                    return ['key' => $key, 'stamps' => $stamps, 'json' => $json, 'files' => null, 'packed' => $cached['files']];
                }
            }
        }

        return $this->build($key);
    }

    /**
     * Build the document and its files, encoded once, then keep them in the cache.
     *
     * @return array{key: string, stamps: array<string, string>, json: string, files: array<string, array{mermaid: string, dbml: string, markdown: string, json: string}>, packed: null}
     */
    private function build(string $key): array
    {
        $document = $this->viewer->document();
        $json = (string) json_encode($document, JSON_UNESCAPED_SLASHES);
        $files = [];

        foreach (HtmlExporter::downloads($document) as $name => $downloads) {
            $files[$name] = [...$downloads, 'json' => (string) json_encode($document->connection($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"];
        }

        $stamps = $this->stamps(array_map(fn (Schema\ConnectionSchema $connection): string => $connection->name, $document->connections));
        $entry = ['key' => $key, 'stamps' => $stamps, 'json' => $json, 'files' => $files, 'packed' => null];

        try {
            $this->store()?->put($key, [
                'format' => self::FORMAT,
                'stamps' => $stamps,
                'json' => gzcompress($json),
                'files' => gzcompress((string) json_encode($files, JSON_UNESCAPED_SLASHES)),
            ], $this->ttl());
        } catch (Throwable) {
            // A cache that cannot take the entry only costs the next request a rebuild.
        }

        return $entry;
    }

    /**
     * The configured cache store, or null when caching is off or the application has no cache.
     */
    private function store(): ?CacheStore
    {
        $enabled = $this->config->get('schema-viewer.cache.enabled', true);

        if ($enabled === false || ! $this->app->bound(CacheFactory::class)) {
            return null;
        }

        $name = $this->config->get('schema-viewer.cache.store');

        try {
            return $this->app->make(CacheFactory::class)->store(is_string($name) && $name !== '' ? $name : null);
        } catch (Throwable) {
            return null;
        }
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('schema-viewer.cache.ttl', 3600);

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 3600;
    }

    /**
     * What the document is built from, apart from the databases: cheap to read on every request.
     */
    private function fingerprint(): string
    {
        $files = [];

        foreach ($this->viewer->modelPaths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[$file->getPathname()] = $file->getMTime().':'.$file->getSize();
                }
            }
        }

        ksort($files);

        $resolvers = new ReflectionProperty(Model::class, 'relationResolvers');
        $resolvers->setAccessible(true);
        $dynamic = array_map(fn (mixed $relations): array => is_array($relations) ? array_keys($relations) : [], (array) $resolvers->getValue());

        $listeners = method_exists($this->events, 'getRawListeners') ? (array) $this->events->getRawListeners() : [];
        $observers = [];

        foreach ($listeners as $event => $handlers) {
            if (str_starts_with($event, 'eloquent.')) {
                $observers[$event] = array_map(fn (mixed $handler): string => is_string($handler) ? $handler : get_debug_type($handler), (array) $handlers);
            }
        }

        $installed = $this->app->basePath('vendor/composer/installed.json');

        return (string) json_encode([
            self::FORMAT,
            SchemaDocument::VERSION,
            $this->config->get('schema-viewer'),
            $this->config->get('database.default'),
            $this->config->get('permission.table_names'),
            Relation::morphMap(),
            $dynamic,
            $observers,
            $files,
            is_file($installed) ? filemtime($installed) : null,
        ]);
    }

    /**
     * Each connection's migrations (their count and last id) and, for SQLite, its database file: what changes when
     * the schema does.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function stamps(array $names): array
    {
        $stamps = [];

        foreach ($names as $name) {
            try {
                $connection = $this->connections->connection($name);
                $parts = [];

                $database = method_exists($connection, 'getConfig') ? $connection->getConfig('database') : null;

                if (method_exists($connection, 'getDriverName') && $connection->getDriverName() === 'sqlite' && is_string($database) && is_file($database)) {
                    clearstatcache(true, $database);
                    $parts[] = filemtime($database).':'.filesize($database);
                }

                $table = $this->config->get('database.migrations');
                $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;
                $table = is_string($table) && $table !== '' ? $table : 'migrations';

                // Checked first: a failed query would abort an open PostgreSQL transaction.
                if (method_exists($connection, 'getSchemaBuilder') && $connection->getSchemaBuilder()->hasTable($table)) {
                    $row = (array) $connection->table($table)->selectRaw('count(*) as migrations, max(id) as last')->first();
                    $parts[] = implode(':', array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row));
                }

                $stamps[$name] = implode('|', $parts);
            } catch (Throwable) {
                $stamps[$name] = 'unreadable';
            }
        }

        return $stamps;
    }
}
