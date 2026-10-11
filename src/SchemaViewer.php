<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Laranex\LaravelSchemaViewer\Graph\SchemaGraph;
use Laranex\LaravelSchemaViewer\Inspection\DatabaseInspector;
use Laranex\LaravelSchemaViewer\Inspection\ModelFinder;
use Laranex\LaravelSchemaViewer\Inspection\ModelInspector;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;

/**
 * Builds the schema document for the configured connections and models.
 */
class SchemaViewer
{
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
            fn (string $name) => $this->database->inspect($name, $this->strings('schema-viewer.exclude_tables'), $this->strings('schema-viewer.framework_tables')),
            $this->connectionNames($models),
        );

        return $this->graph->build($connections, $models, $relations, $unresolved, $this->strings('schema-viewer.hints.ignore'));
    }

    /**
     * @return list<string>
     */
    public function modelPaths(): array
    {
        $paths = $this->config->get('schema-viewer.models');

        return is_array($paths) ? array_values(array_map('strval', $paths)) : [$this->app->path('Models')];
    }

    public function layoutPath(): string
    {
        $path = $this->config->get('schema-viewer.layout_path');

        return is_string($path) && $path !== '' ? $path : $this->app->basePath('schema-viewer.json');
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
     * @return list<string>
     */
    private function strings(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
