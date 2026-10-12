<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Graph\SchemaGraph;
use Laranex\LaravelSchemaViewer\Inspection\DatabaseInspector;
use Laranex\LaravelSchemaViewer\Inspection\ModelFinder;
use Laranex\LaravelSchemaViewer\Inspection\ModelInspector;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;

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
     * Tables that well-known packages create, by package name: the class that tells the package is installed, and the
     * table patterns. They are tagged with the package, so the viewer can show or hide them with one toggle.
     */
    private const PACKAGE_TABLES = [
        'laravel/passport' => ['Laravel\\Passport\\Passport', ['oauth_*']],
        'spatie/laravel-permission' => ['Spatie\\Permission\\PermissionRegistrar', ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions']],
        'jeffgreco13/filament-breezy' => ['Jeffgreco13\\FilamentBreezy\\FilamentBreezyServiceProvider', ['breezy_*']],
    ];

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

    public function document(): SchemaDocument
    {
        $models = [];
        $relations = [];
        $unresolved = [];

        foreach ($this->finder->find($this->modelPaths()) as $class) {
            /** @var Model $model */
            $model = new $class;
            $models[] = $this->models->inspect($model);
            $found = $this->relations->resolve($model);
            array_push($relations, ...$found['relations']);
            array_push($unresolved, ...$found['unresolved']);
        }

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
     * left in; null when no editor is configured.
     */
    public function editorHref(): ?string
    {
        $editor = $this->config->get('schema-viewer.editor') ?? $this->config->get('app.editor');
        $name = is_array($editor) ? ($editor['name'] ?? null) : $editor;

        if (is_array($editor) && is_string($editor['href'] ?? null)) {
            $href = $editor['href'];
        } elseif (is_string($name) && $name !== '') {
            $href = self::EDITORS[$name] ?? $name.'://open?file={file}&line={line}';
        } else {
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
