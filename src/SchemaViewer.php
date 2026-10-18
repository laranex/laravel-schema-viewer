<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Graph\SchemaGraph;
use Laranex\LaravelSchemaViewer\Inspection\DatabaseInspector;
use Laranex\LaravelSchemaViewer\Inspection\ModelFinder;
use Laranex\LaravelSchemaViewer\Inspection\ModelInspector;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Throwable;

/**
 * Builds the schema document for the configured connections and models.
 */
class SchemaViewer
{
    /**
     * Link formats of common editors, as Laravel's app.editor knows them.
     */
    private const EDITORS = [
        'antigravity' => 'antigravity://file/{file}:{line}',
        'atom' => 'atom://core/open/file?filename={file}&line={line}',
        'cursor' => 'cursor://file/{file}:{line}',
        'emacs' => 'emacs://open?url=file://{file}&line={line}',
        'fleet' => 'fleet://open?file={file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
        'kiro' => 'kiro://file/{file}:{line}',
        'macvim' => 'mvim://open/?url=file://{file}&line={line}',
        'neovim' => 'nvim://open?url=file://{file}&line={line}',
        'netbeans' => 'netbeans://open/?f={file}:{line}',
        'nova' => 'nova://core/open/file?filename={file}&line={line}',
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'trae' => 'trae://file/{file}:{line}',
        'vscode' => 'vscode://file/{file}:{line}',
        'vscode-insiders' => 'vscode-insiders://file/{file}:{line}',
        'vscode-insiders-remote' => 'vscode-insiders://vscode-remote/{file}:{line}',
        'vscode-remote' => 'vscode://vscode-remote/{file}:{line}',
        'vscodium' => 'vscodium://file/{file}:{line}',
        'windsurf' => 'windsurf://file/{file}:{line}',
        'xdebug' => 'xdebug://{file}@{line}',
        'zed' => 'zed://file/{file}:{line}',
    ];

    /**
     * Link schemes that run code or embed content instead of opening an editor.
     */
    private const UNSAFE_SCHEMES = ['javascript', 'data', 'vbscript', 'blob', 'file', 'about'];

    /**
     * The callback set with auth(), which decides who may open the viewer; null keeps the default.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * Whether the models are being read right now.
     */
    private static bool $building = false;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly ConnectionResolverInterface $connections,
        private readonly DatabaseInspector $database,
        private readonly ModelFinder $finder,
        private readonly ModelInspector $models,
        private readonly RelationResolver $relations,
        private readonly SchemaGraph $graph,
    ) {}

    /**
     * Decide who may open the viewer, in every environment, as Telescope::auth() does. The callback replaces the default
     * check: everyone in the local environment, elsewhere the users the viewSchema gate allows. Call it in a service
     * provider's boot().
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Whether the package is reading the application's models right now: true while schema-viewer:build, export or check
     * constructs each model and calls its relation methods, false everywhere else. Check it in a constructor, boot(),
     * booted() or relation method to skip work that would reach the outside world.
     */
    public static function building(): bool
    {
        return self::$building;
    }

    /**
     * Whether the request may open the viewer: the auth() callback when one is set. Otherwise, in the local environment,
     * a request from this machine to this machine: its connection comes from a loopback address (127.0.0.0/8 or ::1)
     * and its Host header names one (localhost, *.localhost, 127.0.0.0/8 or ::1). Any other request, in any
     * environment, needs a user the viewSchema gate allows. Both are read from the request itself, never from proxy
     * headers, so a tunnel or a reverse proxy on the machine does not count as local.
     */
    public static function check(Request $request, ?Application $app = null): bool
    {
        if (static::$authUsing !== null) {
            return (bool) (static::$authUsing)($request);
        }

        $app ??= Container::getInstance();

        if (! $app instanceof Application) {
            return false;
        }

        if ($app->environment('local') && self::fromLoopback($request)) {
            return true;
        }

        if (! $app->bound(Gate::class)) {
            return false;
        }

        $gate = $app->make(Gate::class);

        return $gate->has('viewSchema') && $gate->forUser($request->user())->allows('viewSchema');
    }

    /**
     * Whether the request reached this machine from this machine: a loopback peer address and a loopback Host.
     */
    private static function fromLoopback(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $host = (string) $request->headers->get('host', '');
        // [::1]:8000, 127.0.0.1:8000 or localhost:8000: the host without its port.
        $host = strtolower(preg_match('/^\[([^\]]+)\](?::\d+)?$/', $host, $match) === 1 ? $match[1] : (string) preg_replace('/:\d+$/', '', $host));

        return is_string($peer) && self::loopbackAddress($peer)
            && ($host === 'localhost' || str_ends_with($host, '.localhost') || self::loopbackAddress($host));
    }

    private static function loopbackAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        // ::ffff:127.0.0.1, IPv4 as IPv6, counts as its IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4 ? $packed[0] === "\x7f" : $packed === str_repeat("\0", 15)."\x01";
    }

    /**
     * Build the document. Every model is constructed and its relation methods called with every connection pretending
     * (see RelationResolver); the tables are read afterwards. A model that cannot be constructed or inspected is noted
     * as unresolved instead of failing the document. The relation methods run inside $whileReadingRelations when given,
     * which schema-viewer:build uses to fake events while they run.
     *
     * @param  (Closure(Closure(): void): void)|null  $whileReadingRelations
     */
    public function document(?Closure $whileReadingRelations = null): SchemaDocument
    {
        $models = [];
        $relations = [];
        $unresolved = [];

        $building = self::$building;
        self::$building = true;

        try {
            $this->relations->pretend(function () use (&$models, &$relations, &$unresolved, $whileReadingRelations): void {
                $instances = [];

                foreach ($this->finder->find($this->modelPaths()) as $class) {
                    try {
                        /** @var Model $model */
                        $model = new $class;
                        $models[] = $this->models->inspect($model);
                        $instances[] = $model;
                    } catch (Throwable $e) {
                        $unresolved[] = $this->relations->unreadable($class, $e);
                    }
                }

                $read = function () use ($instances, &$relations, &$unresolved): void {
                    foreach ($instances as $model) {
                        $found = $this->relations->resolve($model);
                        array_push($relations, ...$found['relations']);
                        array_push($unresolved, ...$found['unresolved']);
                    }
                };

                $whileReadingRelations !== null ? $whileReadingRelations($read) : $read();
            });
        } finally {
            self::$building = $building;
        }

        $notes = $this->notes();
        $connections = array_map(
            fn (string $name) => $this->database->inspect($name, $this->strings('schema-viewer.exclude_tables'), $this->strings('schema-viewer.framework_tables'), $this->packageTables(), $notes),
            $this->connectionNames($models),
        );

        return $this->graph->build($connections, $models, $relations, $unresolved, $this->ignoreRules(), note: $notes['project'], relationshipNotes: $notes['relationships']);
    }

    /**
     * What the viewer opens on: 'all' or one connection's name.
     */
    public function defaultConnection(): string
    {
        $value = $this->config->get('schema-viewer.default_connection');

        return is_string($value) && $value !== '' ? $value : 'all';
    }

    /**
     * The link that opens a file in the developer's editor, with {file} (relative to the application's root) and {line}
     * left in; null when no editor is configured. Laravel's app.editor counts only in the local environment, since the
     * link carries the application's path; schema-viewer.editor counts everywhere. A link with a scheme that runs code
     * or embeds content (javascript:, data:, vbscript:, blob:, file:) is dropped.
     */
    public function editorHref(): ?string
    {
        $editor = $this->config->get('schema-viewer.editor');

        if (($editor === null || $editor === '') && $this->app->environment('local')) {
            $editor = $this->config->get('app.editor');
        }

        $name = is_array($editor) ? ($editor['name'] ?? null) : $editor;

        if (is_array($editor) && is_string($editor['href'] ?? null)) {
            $href = $editor['href'];
        } elseif (is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', $name) === 1) {
            $href = self::EDITORS[$name] ?? $name.'://open?file={file}&line={line}';
        } else {
            return null;
        }

        // Browsers ignore control characters and spaces around and inside a scheme.
        $scheme = preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', (string) preg_replace('/[\x00-\x20]+/', '', $href), $match) === 1 ? strtolower($match[1]) : null;

        if ($scheme === null || in_array($scheme, self::UNSAFE_SCHEMES, true)) {
            return null;
        }

        $root = is_array($editor) && is_string($editor['base_path'] ?? null) ? $editor['base_path'] : $this->app->basePath();

        return str_replace('{file}', rtrim(str_replace('\\', '/', $root), '/').'/{file}', $href);
    }

    /**
     * @return list<string>
     */
    public function modelPaths(): array
    {
        $paths = $this->config->get('schema-viewer.models');

        return is_array($paths) ? array_values(array_map('strval', $paths)) : [$this->app->path('Models')];
    }

    /**
     * The configured connections, or the default connection plus every connection a model uses.
     *
     * @param  list<Schema\ModelInfo>  $models
     * @return list<string>
     */
    private function connectionNames(array $models): array
    {
        $configured = $this->config->get('schema-viewer.connections');

        if (is_array($configured)) {
            return array_values(array_map('strval', $configured));
        }

        $names = [$this->connections->getDefaultConnection()];

        foreach ($models as $model) {
            $names[] = $model->connection;
        }

        return array_values(array_unique($names));
    }

    /**
     * The hints.ignore rules.
     *
     * @return list<IgnoreRule>
     *
     * @throws InvalidArgumentException When a rule names an unknown field or kind.
     */
    public function ignoreRules(): array
    {
        $rules = $this->config->get('schema-viewer.hints.ignore', []);

        if (! is_array($rules)) {
            throw new InvalidArgumentException('schema-viewer.hints.ignore must be a list of rules.');
        }

        return array_values(array_map(IgnoreRule::fromConfig(...), $rules));
    }

    /**
     * The notes config: a note on the project, notes on tables by table name, and notes on columns and on relationships
     * by table name, then column (for a relationship, the columns that hold its key, comma separated). A table name may
     * start with a connection name and a colon (analytics:events), which wins over the plain name on that connection.
     *
     * @return array{project: string|null, tables: array<string, string>, columns: array<string, array<string, string>>, relationships: array<string, array<string, string>>}
     *
     * @throws InvalidArgumentException When a note is not a string.
     */
    public function notes(): array
    {
        $notes = $this->config->get('schema-viewer.notes', []);
        $notes = is_array($notes) ? $notes : throw new InvalidArgumentException('schema-viewer.notes must be an array with project, tables and columns.');
        $project = $notes['project'] ?? null;
        $tables = $notes['tables'] ?? [];
        $columns = $notes['columns'] ?? [];
        $relationships = $notes['relationships'] ?? [];

        if ($project !== null && ! is_string($project)) {
            throw new InvalidArgumentException('schema-viewer.notes.project must be a string or null.');
        }

        if (! is_array($tables) || array_filter($tables, fn (mixed $note): bool => ! is_string($note)) !== []) {
            throw new InvalidArgumentException('schema-viewer.notes.tables must map table names to strings, such as [\'orders\' => \'One row per checkout.\'].');
        }

        if (! is_array($columns) || array_filter($columns, fn (mixed $notes): bool => ! is_array($notes) || array_filter($notes, fn (mixed $note): bool => ! is_string($note)) !== []) !== []) {
            throw new InvalidArgumentException('schema-viewer.notes.columns must map table names to column notes, such as [\'orders\' => [\'status\' => \'…\']].');
        }

        if (! is_array($relationships) || array_filter($relationships, fn (mixed $notes): bool => ! is_array($notes) || array_filter($notes, fn (mixed $note): bool => ! is_string($note)) !== []) !== []) {
            throw new InvalidArgumentException('schema-viewer.notes.relationships must map table names to notes by the columns that hold the key, such as [\'orders\' => [\'user_id\' => \'…\']].');
        }

        /** @var array<string, string> $tables */
        /** @var array<string, array<string, string>> $columns */
        /** @var array<string, array<string, string>> $relationships */
        $byColumn = fn (array $notes): array => array_combine(array_map('strval', array_keys($notes)), array_map(fn (array $notes): array => array_combine(array_map('strval', array_keys($notes)), $notes), $notes));

        return [
            'project' => $project !== '' ? $project : null,
            'tables' => array_combine(array_map('strval', array_keys($tables)), $tables),
            'columns' => $byColumn($columns),
            'relationships' => $byColumn($relationships),
        ];
    }

    /**
     * The table, column and relationship notes that name nothing in the document, such as orders.stauts, so the build
     * can say so. A relationship note is listed with "relationship" before it.
     *
     * @return list<string>
     */
    public function unmatchedNotes(SchemaDocument $document): array
    {
        $notes = $this->notes();
        $unmatched = [];
        $find = function (string $key) use ($document): array {
            [$connection, $table] = str_contains($key, ':') ? explode(':', $key, 2) : [null, $key];
            $found = [];

            foreach ($document->connections as $schema) {
                if (($connection === null || $schema->name === $connection) && ($match = $schema->table($table)) !== null) {
                    $found[] = $match;
                }
            }

            return $found;
        };

        foreach (array_keys($notes['tables']) as $key) {
            if ($find($key) === []) {
                $unmatched[] = $key;
            }
        }

        foreach ($notes['columns'] as $key => $columns) {
            $tables = $find($key);

            foreach (array_keys($columns) as $column) {
                if (array_filter($tables, fn (Schema\Table $table): bool => $table->hasColumn($column)) === []) {
                    $unmatched[] = $key.'.'.$column;
                }
            }
        }

        foreach ($notes['relationships'] as $key => $relationships) {
            [$connection, $table] = str_contains($key, ':') ? explode(':', $key, 2) : [null, $key];

            foreach (array_keys($relationships) as $columns) {
                $found = array_filter($document->links, fn (Schema\Link $link): bool => ($connection === null || $link->from->connection === $connection)
                    && $link->from->table === $table
                    && implode(',', $link->from->columns) === $columns);

                if ($found === []) {
                    $unmatched[] = 'relationship '.$key.'.'.$columns;
                }
            }
        }

        return $unmatched;
    }

    /**
     * The table patterns of the installed packages, by package name, from package_tables; none when it is false.
     *
     * @return array<string, list<string>>
     */
    public function packageTables(): array
    {
        $configured = $this->config->get('schema-viewer.package_tables', true);

        if ($configured === false) {
            return [];
        }

        // true, from a config published before the list moved into it, means the list the package ships.
        if ($configured === true) {
            /** @var array{package_tables: mixed} $defaults */
            $defaults = require __DIR__.'/../config/schema-viewer.php';
            $configured = $defaults['package_tables'];
        }

        if (! is_array($configured)) {
            throw new InvalidArgumentException('schema-viewer.package_tables must be false or a list of table patterns by package name.');
        }

        $tables = [];

        foreach ($configured as $package => $names) {
            if (! is_string($package) || ! is_array($names) || array_filter($names, fn (mixed $name): bool => ! is_string($name)) !== []) {
                throw new InvalidArgumentException('schema-viewer.package_tables must be false or a list of table patterns by package name.');
            }

            if (! $this->installed($package)) {
                continue;
            }

            /** @var list<string> $names */
            $names = array_values($names);
            $tables[$package] = $names;
        }

        return $tables;
    }

    /**
     * Whether Composer installed a package.
     */
    private function installed(string $package): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
