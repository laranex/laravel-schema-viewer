<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Inspection;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laranex\LaravelSchemaViewer\Schema\ModelInfo;
use Laranex\LaravelSchemaViewer\Schema\Source;
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
        private readonly Application $app,
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
            fillable: $this->strings($model->getFillable()),
            guarded: array_values($model->getGuarded()),
            hidden: $this->strings($model->getHidden()),
            appends: $this->strings($model->getAppends()),
            scopes: $this->scopes($model),
            globalScopes: $this->strings(array_keys($model->getGlobalScopes())),
            observers: $this->observers($model),
            source: Source::of(new ReflectionClass($model), $this->app->basePath()),
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
     * The registered listeners. The parameter is an object because only some Laravel versions put this method on the contract.
     *
     * @return array<array-key, mixed>
     */
    private function rawListeners(object $events): array
    {
        $listeners = method_exists($events, 'getRawListeners') ? $events->getRawListeners() : [];

        return is_array($listeners) ? $listeners : [];
    }

    /**
     * Values as a list of strings, whatever the array's keys.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            $strings[] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }

    /**
     * Observer classes listening to this model's events.
     *
     * @return list<string>
     */
    private function observers(Model $model): array
    {
        $observers = [];

        /** @var array<string, array<int, mixed>> $listeners */
        $listeners = $this->rawListeners($this->events);

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
