<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Schema\Endpoint;
use Laranex\LaravelSchemaViewer\Schema\Morph;
use Laranex\LaravelSchemaViewer\Schema\Pivot;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\Source;
use Laranex\LaravelSchemaViewer\Schema\UnresolvedRelation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Finds a model's relation methods and reads each one from the relation object it returns. Reading a relation runs the
 * application's own code: the method, and whatever it calls. While it runs, every configured connection pretends, so
 * queries are logged and never sent, and Eloquent model events are not dispatched. Anything else the code does (the
 * cache, jobs, events, mail, HTTP calls, files) is not prevented here; schema-viewer:build fakes those in its own
 * process.
 */
final class RelationResolver
{
    /**
     * Relation builders looked for in methods without a Relation return type.
     */
    private const BUILDERS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
        'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany', 'through',
        'hasOneDeep', 'hasManyDeep', 'belongsToThrough',
    ];

    /**
     * The lines of the files read for one model, by path.
     *
     * @var array<string, array<int, string>>
     */
    private array $lines = [];

    public function __construct(
        private readonly ModelInspector $models,
        private readonly Application $app,
        private readonly ConnectionResolverInterface $connections,
        private readonly Config $config,
    ) {}

    /**
     * @return array{relations: list<RelationInfo>, unresolved: list<UnresolvedRelation>}
     */
    public function resolve(Model $model): array
    {
        $relations = [];
        $unresolved = [];
        $this->lines = [];

        $this->pretend(function () use ($model, &$relations, &$unresolved): void {
            foreach ($this->candidates($model) as $name => $call) {
                try {
                    $relation = Model::withoutEvents(fn (): mixed => Relation::noConstraints($call));

                    if (! $relation instanceof Relation) {
                        $unresolved[] = new UnresolvedRelation($model::class, $name, 'It returned '.get_debug_type($relation).' instead of a relation.', $this->package($model, $name));

                        continue;
                    }

                    $relations[] = $this->describe($model, $name, $relation);
                } catch (Throwable $e) {
                    $unresolved[] = new UnresolvedRelation($model::class, $name, $this->reason($e), $this->package($model, $name));
                }
            }
        });

        $this->lines = [];

        return ['relations' => $relations, 'unresolved' => $unresolved];
    }

    /**
     * A model that could not be constructed or inspected, noted like a relation that could not be read.
     *
     * @param  class-string  $class
     */
    public function unreadable(string $class, Throwable $e): UnresolvedRelation
    {
        return new UnresolvedRelation($class, '__construct', $this->reason($e));
    }

    /**
     * Run the callback with every configured connection, and every connection already open, pretending: queries are
     * logged, never sent to the database. Connections opened only for this are closed again afterwards. What the
     * callback throws is rethrown only after every pretend() has returned, since Laravel 10, 11 and 12 before 12.41
     * leave a connection pretending (and before 13.33 logging queries) when its callback throws. Connections that
     * already pretend, as in a nested call, are left as they are.
     *
     * @param  Closure(): void  $callback
     */
    public function pretend(Closure $callback): void
    {
        $open = $this->openConnections();
        $configured = $this->config->get('database.connections');
        $names = array_values(array_unique([
            $this->connections->getDefaultConnection(),
            ...$open,
            ...(is_array($configured) ? array_map('strval', array_keys($configured)) : []),
        ]));
        $failure = null;

        try {
            $this->pretendOn($names, function () use ($callback, &$failure): void {
                try {
                    $callback();
                } catch (Throwable $e) {
                    $failure = $e;
                }
            });
        } finally {
            if (method_exists($this->connections, 'purge')) {
                foreach (array_diff($this->openConnections(), $open) as $name) {
                    $this->connections->purge($name);
                }
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * Why a method could not be read, safe to show: the exception's short class and the first line of its message, cut
     * to 200 characters, with paths made relative to the application and connection details or credentials left out.
     */
    private function reason(Throwable $e): string
    {
        $message = explode("\n", str_replace("\r", "\n", trim($e->getMessage())))[0];
        $root = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';
        $message = str_replace([$root, str_replace('/', '\\', $root)], '', $message);

        $message = (string) preg_replace([
            '#\b[A-Za-z][A-Za-z0-9+.-]*://[^\s\'"]*#',                                                   // URLs, which may carry credentials
            '#\b(?:mysql|mariadb|pgsql|sqlsrv|sqlite|odbc|dblib|oci):[^\s\'"]*#i',                       // PDO DSNs
            '#\b(password|passwd|pwd|secret|token|api[_-]?key)\b(\s*[=:]\s*)[^\s,;)]+#i',              // key=value secrets
            "#'[^'\s]*'@'[^'\s]*'#",                                                                    // 'user'@'host'
            '#(?<![\w.])(?:[A-Za-z]:[\\\\/]|/)(?:[^\s\'"():,\\\\/]+[\\\\/])+[^\s\'"():,]*#',              // absolute paths
        ], ['[url]', '[dsn]', '$1$2[hidden]', '[user]', '[path]'], $message);

        $reason = (new ReflectionClass($e))->getShortName().($message !== '' ? ': '.$message : '');

        return Str::limit($reason, 200);
    }

    /**
     * The names of the connections open now.
     *
     * @return list<string>
     */
    private function openConnections(): array
    {
        return method_exists($this->connections, 'getConnections')
            ? array_map('strval', array_keys((array) $this->connections->getConnections()))
            : [];
    }

    /**
     * @param  list<string>  $names
     * @param  Closure(): void  $callback
     */
    private function pretendOn(array $names, Closure $callback): void
    {
        $name = array_shift($names);

        if ($name === null) {
            $callback();

            return;
        }

        try {
            $connection = $this->connections->connection($name);
        } catch (Throwable) {
            // A connection that cannot be opened cannot be written to either.
            $this->pretendOn($names, $callback);

            return;
        }

        if (method_exists($connection, 'pretending') && $connection->pretending()) {
            $this->pretendOn($names, $callback);

            return;
        }

        $connection->pretend(fn () => $this->pretendOn($names, $callback));
    }

    /**
     * Relation methods and dynamic relations, keyed by name.
     *
     * @return array<string, Closure(): mixed>
     */
    private function candidates(Model $model): array
    {
        $candidates = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract() || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Trait methods count as the model's own, so Notifiable's notifications() is kept; Model's and other framework classes' are not.
            if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                continue;
            }

            if ($this->returnsRelation($method) || $this->buildsRelation($method)) {
                $candidates[$method->getName()] = fn (): mixed => $method->invoke($model);
            }
        }

        foreach ($this->dynamicRelations($model) as $name => $resolver) {
            $candidates[$name] ??= fn (): mixed => $resolver($model);
        }

        ksort($candidates);

        return $candidates;
    }

    /**
     * The package that declares an inherited relation method: its Composer name for a file in vendor/, the declaring
     * class for any other file outside the application, or null for the model's own methods and the application's code.
     */
    private function package(Model $model, string $name): ?string
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        $method = new ReflectionMethod($model, $name);

        if ($method->getDeclaringClass()->getName() === $model::class) {
            return null;
        }

        $source = Source::of($method, $this->app->basePath());

        if ($source === null) {
            return $method->getDeclaringClass()->getName();
        }

        return preg_match('#^vendor/([^/]+/[^/]+)/#', $source->file, $match) === 1 ? $match[1] : null;
    }

    private function returnsRelation(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_a($type->getName(), Relation::class, true);
    }

    private function buildsRelation(ReflectionMethod $method): bool
    {
        if ($method->hasReturnType() || ! is_string($file = $method->getFileName())) {
            return false;
        }

        $this->lines[$file] ??= ($lines = @file($file)) !== false ? $lines : [];
        $code = implode('', array_slice($this->lines[$file], (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        foreach (self::BUILDERS as $builder) {
            if (str_contains($code, '$this->'.$builder.'(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relations registered with Model::resolveRelationUsing().
     *
     * @return array<string, Closure>
     */
    private function dynamicRelations(Model $model): array
    {
        $property = new ReflectionProperty(Model::class, 'relationResolvers');
        $property->setAccessible(true);

        /** @var array<string, array<string, Closure>> $resolvers */
        $resolvers = $property->getValue();

        return $resolvers[$model::class] ?? [];
    }

    /**
     * Read the keys, pivot, morph columns and constraints from a relation object. The parameter is an object because
     * Relation's generic parameters differ between the Laravel versions this package supports.
     */
    private function describe(Model $model, string $name, object $relation): RelationInfo
    {
        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException('Expected a relation, got '.get_debug_type($relation).'.');
        }

        $related = $relation->getRelated();
        $own = fn (string $column): Endpoint => $this->endpoint($model, $column);
        $theirs = fn (string $column): Endpoint => $this->endpoint($related, $column);
        $type = (new ReflectionClass($relation))->getShortName();
        $constraints = $this->constraints($relation);

        $from = $to = $pivot = $morph = $through = null;

        if ($relation instanceof BelongsToMany) {
            $pivot = new Pivot(
                table: $relation->getTable(),
                foreignPivotKey: $relation->getForeignPivotKeyName(),
                relatedPivotKey: $relation->getRelatedPivotKeyName(),
                parentKey: $relation->getParentKeyName(),
                relatedKey: $relation->getRelatedKeyName(),
                columns: array_values(array_map(fn (mixed $column): string => is_string($column) ? $column : 'expression', $relation->getPivotColumns())),
                using: $this->pivotModel($relation->getPivotClass()),
                accessor: $relation->getPivotAccessor(),
            );

            if ($relation instanceof MorphToMany) {
                $morph = new Morph(
                    type: $relation->getMorphType(),
                    id: $relation->getInverse() ? $relation->getRelatedPivotKeyName() : $relation->getForeignPivotKeyName(),
                    class: $relation->getMorphClass(),
                );
            }
        } elseif ($relation instanceof MorphTo) {
            $from = $own($relation->getForeignKeyName());
            $morph = new Morph($relation->getMorphType(), $relation->getForeignKeyName(), null);
        } elseif ($relation instanceof BelongsTo) {
            $from = $own($relation->getForeignKeyName());
            $to = $theirs($relation->getOwnerKeyName());
        } elseif ($relation instanceof HasOneOrMany) {
            $from = $theirs($relation->getForeignKeyName());
            $to = $own((string) $relation->getLocalKeyName());

            if ($relation instanceof MorphOneOrMany) {
                $morph = new Morph($this->column($relation->getMorphType()), $relation->getForeignKeyName(), (string) $relation->getMorphClass());
            }
        } elseif (method_exists($relation, 'getFirstKeyName') && method_exists($relation, 'getSecondLocalKeyName')) {
            $through = [$model->getTable(), $relation->getParent()->getTable(), $related->getTable()];
        }

        return new RelationInfo(
            model: $model::class,
            name: $name,
            type: $type,
            related: $related::class,
            from: $from,
            to: $to,
            pivot: $pivot,
            morph: $morph,
            through: $through,
            constraints: $constraints,
            source: method_exists($model, $name) ? Source::of(new ReflectionMethod($model, $name), $this->app->basePath()) : null,
        );
    }

    /**
     * Conditions and joins the method adds, read from the relation's query with Laravel's own key constraints left out.
     *
     * @return list<string>
     */
    private function constraints(object $relation): array
    {
        if (! $relation instanceof Relation) {
            return [];
        }

        $constraints = [];

        if (method_exists($relation, 'isOneOfMany') && $relation->isOneOfMany()) {
            $constraints[] = 'one of many';
        }

        /** @var list<array<string, mixed>> $wheres */
        $wheres = $relation->getQuery()->getQuery()->wheres;

        foreach ($wheres as $where) {
            $constraints[] = $this->where($where);
        }

        // Laravel 10 types joins as an array, later versions as nullable.
        foreach ((array) $relation->getQuery()->getQuery()->joins as $join) {
            $constraints[] = 'join '.(is_string($join->table) ? $join->table : 'expression');
        }

        return $constraints;
    }

    /**
     * One condition, with its bound values as ? so no literal from the code reaches the page.
     *
     * @param  array<string, mixed>  $where
     */
    private function where(array $where): string
    {
        $column = isset($where['column']) && is_string($where['column']) ? $this->column($where['column']) : '';
        $not = str_starts_with((string) ($where['type'] ?? ''), 'Not');

        return match ($where['type'] ?? null) {
            'Basic' => trim($column.' '.(is_string($where['operator'] ?? null) ? $where['operator'] : '=').' ?'),
            'Null' => $column.' is null',
            'NotNull' => $column.' is not null',
            'In', 'NotIn' => $column.($not ? ' not in (' : ' in (').implode(', ', array_fill(0, max(1, is_array($where['values'] ?? null) ? count($where['values']) : 0), '?')).')',
            'Column' => (is_string($where['first'] ?? null) ? $this->column($where['first']) : $column).' '.(is_string($where['operator'] ?? null) ? $where['operator'] : '=').' '.(is_string($where['second'] ?? null) ? $this->column($where['second']) : ''),
            'raw' => is_string($where['sql'] ?? null) ? $where['sql'] : 'raw condition',
            default => strtolower((string) ($where['type'] ?? 'condition')).($column !== '' ? ' on '.$column : ''),
        };
    }

    /**
     * The custom pivot model set with using(), or null for Laravel's own Pivot.
     *
     * @return class-string|null
     */
    private function pivotModel(string $class): ?string
    {
        return $class !== \Illuminate\Database\Eloquent\Relations\Pivot::class && class_exists($class) ? $class : null;
    }

    private function endpoint(Model $model, string $column): Endpoint
    {
        return new Endpoint($this->models->connectionOf($model), $model->getTable(), [$column]);
    }

    /**
     * A column name without its table.
     */
    private function column(string $qualified): string
    {
        $position = strrpos($qualified, '.');

        return $position === false ? $qualified : substr($qualified, $position + 1);
    }
}
