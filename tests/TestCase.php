<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\SchemaViewerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SchemaViewerServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->useOwnBasePath();
    }

    protected function tearDown(): void
    {
        // Dynamic relations live in a static registry; clear the ones tests add.
        $resolvers = new ReflectionProperty(Model::class, 'relationResolvers');
        $resolvers->setValue(null, []);
        SchemaViewer::$authUsing = null;

        $this->removeOwnBasePath();

        parent::tearDown();
    }

    private string $environment = 'local';

    /**
     * Where this test's snapshot is written: schema-viewer.json in a project root of its own, so parallel tests never
     * share one.
     */
    protected string $snapshot = '';

    /**
     * Give the application a project root of its own, once it has booted from Testbench's skeleton.
     */
    private function useOwnBasePath(): void
    {
        $this->removeOwnBasePath();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'schema-viewer-tests'.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8));
        mkdir($root, 0755, true);
        $this->app?->setBasePath($root);
        $this->snapshot = $root.DIRECTORY_SEPARATOR.'schema-viewer.json';
    }

    private function removeOwnBasePath(): void
    {
        if ($this->snapshot !== '' && is_dir($root = dirname($this->snapshot))) {
            /** @var SplFileInfo $item */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($root);
        }
    }

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
        $this->useOwnBasePath();
        $this->defineDatabaseMigrations();
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = $this->environment;
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // Set first, so rebootWith() can point the viewer at other models.
        $app['config']->set('schema-viewer.models', [__DIR__.'/Fixtures/Models']);

        foreach ($this->viewerConfig as $key => $value) {
            $app['config']->set('schema-viewer.'.$key, $value);
        }
        $app['config']->set('database.default', 'testing');

        // CI also runs the suite on real databases: SCHEMA_VIEWER_DB=mysql, mariadb, pgsql or sqlsrv (and, locally,
        // SCHEMA_VIEWER_DB_DATABASE and SCHEMA_VIEWER_DB_PASSWORD for a server set up otherwise).
        $database = getenv('SCHEMA_VIEWER_DB');

        if (is_string($database) && $database !== '' && $database !== 'sqlite') {
            $app['config']->set('database.connections.testing', [
                'driver' => $database === 'mariadb' ? 'mariadb' : $database,
                'host' => '127.0.0.1',
                'port' => (int) getenv('SCHEMA_VIEWER_DB_PORT'),
                'database' => getenv('SCHEMA_VIEWER_DB_DATABASE') ?: ($database === 'sqlsrv' ? 'master' : 'testing'),
                'username' => match ($database) {
                    'pgsql' => 'postgres',
                    'sqlsrv' => 'sa',
                    default => 'root',
                },
                'password' => getenv('SCHEMA_VIEWER_DB_PASSWORD') ?: 'Schema-Viewer-1',
                'charset' => $database === 'pgsql' || $database === 'sqlsrv' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'search_path' => 'public',
                'trust_server_certificate' => true,
            ]);
        }
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
