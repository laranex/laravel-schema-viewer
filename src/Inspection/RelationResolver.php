<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
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
use SplFileObject;
use Throwable;

/**
 * Finds a model's relation methods and reads each one from the relation object it returns. No query runs.
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

    public function __construct(
        private readonly ModelInspector $models,
        private readonly Application $app,
    ) {}

    /**
     * @return array{relations: list<RelationInfo>, unresolved: list<UnresolvedRelation>}
     */
    public function resolve(Model $model): array
    {
        $relations = [];
        $unresolved = [];

        foreach ($this->candidates($model) as $name => $call) {
            try {
                $relation = Relation::noConstraints($call);
            } catch (Throwable $e) {
                $unresolved[] = new UnresolvedRelation($model::class, $name, $e->getMessage(), $this->package($model, $name));

                continue;
            }

            if (! $relation instanceof Relation) {
                $unresolved[] = new UnresolvedRelation($model::class, $name, 'It returned '.get_debug_type($relation).' instead of a relation.', $this->package($model, $name));

                continue;
            }

            $relations[] = $this->describe($model, $name, $relation);
        }

        return ['relations' => $relations, 'unresolved' => $unresolved];
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

        $source = new SplFileObject($file);
        $source->seek((int) $method->getStartLine() - 1);
        $code = '';

        while ($source->key() < $method->getEndLine() && ! $source->eof()) {
            $line = $source->current();
            $code .= is_string($line) ? $line : '';
            $source->next();
        }

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

        foreach ($relation->getQuery()->getQuery()->joins ?? [] as $join) {
            $constraints[] = 'join '.(is_string($join->table) ? $join->table : 'expression');
        }

        return $constraints;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function where(array $where): string
    {
        $column = isset($where['column']) && is_string($where['column']) ? $this->column($where['column']) : '';
        $not = str_starts_with((string) ($where['type'] ?? ''), 'Not');

        return match ($where['type'] ?? null) {
            'Basic' => trim($column.' '.(is_string($where['operator'] ?? null) ? $where['operator'] : '=').' '.$this->value($where['value'] ?? null)),
            'Null' => $column.' is null',
            'NotNull' => $column.' is not null',
            'In', 'NotIn' => $column.($not ? ' not in (' : ' in (').implode(', ', array_map($this->value(...), is_array($where['values'] ?? null) ? $where['values'] : [])).')',
            'Column' => $column.' '.(is_string($where['operator'] ?? null) ? $where['operator'] : '=').' '.(is_string($where['second'] ?? null) ? $this->column($where['second']) : ''),
            'raw' => is_string($where['sql'] ?? null) ? $where['sql'] : 'raw condition',
            default => strtolower((string) ($where['type'] ?? 'condition')).($column !== '' ? ' on '.$column : ''),
        };
    }

    private function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_string($value) => "'".$value."'",
            is_scalar($value) => (string) $value,
            default => '?',
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
