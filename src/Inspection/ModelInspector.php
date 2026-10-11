<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laranex\LaravelSchemaViewer\Schema\ModelInfo;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reads what a model declares: its table, keys, casts, mass assignment rules, scopes and observers.
 */
final class ModelInspector
{
    private const SCOPE_ATTRIBUTE = 'Illuminate\Database\Eloquent\Attributes\Scope';

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
    ) {}

    public function inspect(Model $model): ModelInfo
    {
        return new ModelInfo(
            class: $model::class,
            connection: $this->connectionOf($model),
            table: $model->getTable(),
            primaryKey: (string) $model->getKeyName(),
            keyType: $model->getKeyType(),
            incrementing: $model->getIncrementing(),
            timestamps: $model->usesTimestamps(),
            softDeletes: in_array(SoftDeletes::class, class_uses_recursive($model), true),
            casts: $model->getCasts(),
            fillable: array_values($model->getFillable()),
            guarded: array_values($model->getGuarded()),
            hidden: array_values($model->getHidden()),
            appends: array_map(fn (mixed $name): string => (string) $name, $model->getAppends()),
            scopes: $this->scopes($model),
            globalScopes: array_map(fn (int|string $name): string => (string) $name, array_keys($model->getGlobalScopes())),
            observers: $this->observers($model),
        );
    }

    public function connectionOf(Model $model): string
    {
        return $model->getConnectionName() ?? $this->connections->getDefaultConnection();
    }

    /**
     * Local scopes: scopeName() methods, and #[Scope] methods on Laravel 12+.
     *
     * @return list<string>
     */
    private function scopes(Model $model): array
    {
        $scopes = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                continue;
            }

            if (preg_match('/^scope(.+)$/', $method->getName(), $match) === 1) {
                $scopes[] = lcfirst($match[1]);
            } elseif (class_exists(self::SCOPE_ATTRIBUTE) && $method->getAttributes(self::SCOPE_ATTRIBUTE) !== []) {
                $scopes[] = $method->getName();
            }
        }

        sort($scopes);

        return array_values(array_unique($scopes));
    }

    /**
     * Observer classes listening to this model's events.
     *
     * @return list<string>
     */
    private function observers(Model $model): array
    {
        if (! method_exists($this->events, 'getRawListeners')) {
            return [];
        }

        $observers = [];

        /** @var array<string, array<int, mixed>> $listeners */
        $listeners = $this->events->getRawListeners();

        foreach ($listeners as $event => $handlers) {
            if (! str_starts_with($event, 'eloquent.') || ! str_ends_with($event, ': '.$model::class)) {
                continue;
            }

            foreach ($handlers as $handler) {
                if (is_string($handler) && str_contains($handler, '@')) {
                    $observers[] = explode('@', $handler)[0];
                }
            }
        }

        $observers = array_values(array_unique($observers));
        sort($observers);

        return $observers;
    }
}
