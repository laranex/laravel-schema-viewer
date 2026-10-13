<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\SchemaViewerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SchemaViewerServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        // Dynamic relations live in a static registry; clear the ones tests add.
        $resolvers = new ReflectionProperty(Model::class, 'relationResolvers');
        $resolvers->setValue(null, []);
        SchemaViewer::$authUsing = null;

        parent::tearDown();
    }

    private string $environment = 'local';

    /**
     * @var array<string, mixed>
     */
    private array $viewerConfig = [];

    /**
     * Boot a fresh application in another environment or with other viewer settings; routes load at boot.
     *
     * @param  array<string, mixed>  $config
     */
    public function rebootWith(string $environment = 'local', array $config = []): void
    {
        $this->environment = $environment;
        $this->viewerConfig = $config;
        $this->refreshApplication();
        $this->defineDatabaseMigrations();
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = $this->environment;
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        foreach ($this->viewerConfig as $key => $value) {
            $app['config']->set('schema-viewer.'.$key, $value);
        }
        $app['config']->set('database.default', 'testing');

        // CI also runs the suite on real databases: SCHEMA_VIEWER_DB=mysql, mariadb, pgsql or sqlsrv.
        $database = getenv('SCHEMA_VIEWER_DB');

        if (is_string($database) && $database !== '' && $database !== 'sqlite') {
            $app['config']->set('database.connections.testing', [
                'driver' => $database === 'mariadb' ? 'mariadb' : $database,
                'host' => '127.0.0.1',
                'port' => (int) getenv('SCHEMA_VIEWER_DB_PORT'),
                'database' => $database === 'sqlsrv' ? 'master' : 'testing',
                'username' => match ($database) {
                    'pgsql' => 'postgres',
                    'sqlsrv' => 'sa',
                    default => 'root',
                },
                'password' => 'Schema-Viewer-1',
                'charset' => $database === 'pgsql' || $database === 'sqlsrv' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'search_path' => 'public',
                'trust_server_certificate' => true,
            ]);
        }
        $app['config']->set('schema-viewer.models', [__DIR__.'/Fixtures/Models']);
    }

    /**
     * The blog schema shared with the workbench. The in-memory database is dropped after each test, so nothing rolls back.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Real databases keep their tables between tests; SQLite in memory starts empty.
        Schema::dropAllTables();
        $migration = require dirname(__DIR__).'/workbench/database/migrations/2026_10_12_000000_create_blog_tables.php';
        $migration->up();
    }
}
