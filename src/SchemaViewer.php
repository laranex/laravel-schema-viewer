<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Closure;
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
     * Tables that well-known packages create, by package name: the class that tells the package is installed, and the
     * table patterns. They are tagged with the package, so the viewer can show or hide them with one toggle.
     */
    private const PACKAGE_TABLES = [
        'laravel/passport' => ['Laravel\\Passport\\Passport', ['oauth_*']],
        'spatie/laravel-permission' => ['Spatie\\Permission\\PermissionRegistrar', ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions']],
        'jeffgreco13/filament-breezy' => ['Jeffgreco13\\FilamentBreezy\\FilamentBreezyServiceProvider', ['breezy_*']],
    ];

    /**
     * The callback set with auth(), which decides who may open the viewer; null keeps the default.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

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
     * Decide who may open the viewer, in every environment. The callback replaces the default check entirely, host rule
     * included: in the local environment every request on a host of this machine (see localHost()), elsewhere the users
     * the viewSchema gate allows. Call it in a service provider's boot().
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Whether the request may open the viewer: the auth() callback when one is set, otherwise, in the local environment,
     * every request on a host of this machine and, elsewhere, the users the viewSchema gate allows.
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

        if ($app->environment('local')) {
            return self::localHost($request, $app);
        }

        if (! $app->bound(Gate::class)) {
            return false;
        }

        $gate = $app->make(Gate::class);

        return $gate->has('viewSchema') && $gate->forUser($request->user())->allows('viewSchema');
    }

    /**
     * Whether a local request came in on a host of this machine: APP_URL's host, localhost, 127.0.0.1 or ::1. Any other
     * host, such as a tunnel's (Herd Share, Expose, ngrok) or one a web page rebinds to this machine, is refused.
     */
    public static function localHost(Request $request, Application $app): bool
    {
        $url = $app->bound('config') ? $app->make('config')->get('app.url') : null;
        $hosts = ['localhost', '127.0.0.1', '::1', '[::1]'];

        if (is_string($url) && is_string($host = parse_url($url, PHP_URL_HOST))) {
            $hosts[] = strtolower($host);
        }

        return in_array(strtolower($request->getHost()), $hosts, true);
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

        $connections = array_map(
            fn (string $name) => $this->database->inspect($name, $this->strings('schema-viewer.exclude_tables'), $this->strings('schema-viewer.framework_tables'), $this->packageTables()),
            $this->connectionNames($models),
        );

        return $this->graph->build($connections, $models, $relations, $unresolved, $this->ignoreRules());
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
     * The table patterns of the installed packages, by package name; none when package_tables is false.
     *
     * @return array<string, list<string>>
     */
    public function packageTables(): array
    {
        if ($this->config->get('schema-viewer.package_tables', true) === false) {
            return [];
        }

        $tables = [];

        foreach (self::PACKAGE_TABLES as $package => [$class, $names]) {
            if (! $this->installed($class)) {
                continue;
            }

            // spatie/laravel-permission lets the application rename its tables.
            $renamed = $package === 'spatie/laravel-permission' ? $this->config->get('permission.table_names') : null;
            $tables[$package] = is_array($renamed) && $renamed !== [] ? array_values(array_map('strval', $renamed)) : $names;
        }

        return $tables;
    }

    /**
     * Whether a package is installed, by a class it ships.
     */
    private function installed(string $class): bool
    {
        return class_exists($class);
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
