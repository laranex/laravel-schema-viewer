<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use JsonException;
use LogicException;
use RuntimeException;

/**
 * Checks a value against the laranex-schema JSON Schema (resources/contract/schema.json, the verified copy of the
 * shared contract/schema.json) with the messages of laranex-schema-viewer-ui's shape.ts. The schema is generated from
 * those shapes, so it uses only the forms they write: strings, booleans, integers, constants, enums, nullable values,
 * arrays, records, objects with required and optional properties, any JSON, any object, and references.
 *
 * Strict checks (producers and tests) report properties the schema does not define; lenient ones (readers) ignore them.
 */
final class Shape
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $schema = null;

    /**
     * Check a document; the errors are appended.
     *
     * @param  list<string>  $errors
     */
    public static function check(mixed $value, array &$errors, bool $strict): void
    {
        $schema = self::schema();
        self::node($schema, $value, '$', $errors, $strict);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        if (self::$schema === null) {
            $path = __DIR__.'/../../resources/contract/schema.json';
            $schema = json_decode((string) @file_get_contents($path), true);

            if (! is_array($schema) || ! is_array($schema['$defs'] ?? null)) {
                throw new RuntimeException("The laranex-schema JSON Schema is missing from [{$path}].");
            }

            self::$schema = $schema;
        }

        return self::$schema;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function node(array $node, mixed $value, string $path, array &$errors, bool $strict): void
    {
        if ($node === []) {
            return;
        }

        if (isset($node['$ref'])) {
            $name = substr((string) $node['$ref'], strlen('#/$defs/'));
            $definition = self::schema()['$defs'][$name] ?? throw new LogicException("Unknown schema reference {$node['$ref']}.");
            self::node($definition, $value, $path, $errors, $strict);

            return;
        }

        if (array_key_exists('const', $node)) {
            if ($value !== $node['const']) {
                $errors[] = sprintf('%s: expected %s, got %s', $path, Json::compact($node['const']), self::stringify($value));
            }

            return;
        }

        if (isset($node['enum'])) {
            if (! is_string($value) || ! in_array($value, $node['enum'], true)) {
                $errors[] = sprintf('%s: expected one of %s, got %s', $path, implode(', ', $node['enum']), self::stringify($value));
            }

            return;
        }

        if (isset($node['anyOf'])) {
            if (count($node['anyOf']) !== 2 || $node['anyOf'][1] !== ['type' => 'null']) {
                throw new LogicException('Unsupported anyOf in the laranex-schema JSON Schema at '.$path.'.');
            }

            if ($value !== null) {
                self::node($node['anyOf'][0], $value, $path, $errors, $strict);
            }

            return;
        }

        $kind = self::kindOf($value);

        switch ($node['type'] ?? null) {
            case 'string':
                if (! is_string($value)) {
                    $errors[] = "{$path}: expected a string, got {$kind}";
                } elseif (isset($node['minLength']) && mb_strlen($value) < $node['minLength']) {
                    $errors[] = "{$path}: expected at least {$node['minLength']} characters";
                } elseif (isset($node['pattern']) && preg_match('/'.str_replace('/', '\/', $node['pattern']).'/uD', $value) !== 1) {
                    $errors[] = sprintf('%s: %s does not match %s', $path, Json::compact($value), $node['pattern']);
                }

                return;

            case 'boolean':
                if (! is_bool($value)) {
                    $errors[] = "{$path}: expected a boolean, got {$kind}";
                }

                return;

            case 'integer':
                if (! is_int($value) && ! (is_float($value) && floor($value) === $value)) {
                    $errors[] = "{$path}: expected an integer, got {$kind}";
                } elseif (isset($node['minimum']) && $value < $node['minimum']) {
                    $errors[] = "{$path}: expected at least {$node['minimum']}";
                }

                return;

            case 'array':
                if (! is_array($value) || ! array_is_list($value)) {
                    $errors[] = "{$path}: expected an array, got {$kind}";

                    return;
                }

                if (isset($node['minItems']) && count($value) < $node['minItems']) {
                    $errors[] = "{$path}: expected at least {$node['minItems']} items";
                }

                if (($node['uniqueItems'] ?? false) === true && count(array_unique(array_map(Json::compact(...), $value))) !== count($value)) {
                    $errors[] = "{$path}: expected unique items";
                }

                foreach ($value as $i => $item) {
                    self::node($node['items'], $item, "{$path}[{$i}]", $errors, $strict);
                }

                return;

            case 'object':
                if ($kind !== 'object') {
                    $errors[] = "{$path}: expected an object, got {$kind}";

                    return;
                }

                $members = Json::members($value);

                if (isset($node['properties'])) {
                    foreach ($node['properties'] as $name => $property) {
                        if (! array_key_exists($name, $members)) {
                            if (in_array($name, $node['required'] ?? [], true)) {
                                $errors[] = "{$path}: missing {$name}";
                            }

                            continue;
                        }

                        self::node($property, $members[$name], "{$path}.{$name}", $errors, $strict);
                    }

                    if ($strict) {
                        foreach (array_keys($members) as $key) {
                            if (! array_key_exists($key, $node['properties'])) {
                                $errors[] = "{$path}: unknown property {$key}";
                            }
                        }
                    }

                    return;
                }

                if (is_array($node['additionalProperties'] ?? null)) {
                    $keys = $node['propertyNames']['enum'] ?? null;

                    foreach ($members as $key => $member) {
                        if (is_array($keys) && ! in_array($key, $keys, true)) {
                            if ($strict) {
                                $errors[] = "{$path}: unknown key {$key}";
                            }

                            continue;
                        }

                        self::node($node['additionalProperties'], $member, "{$path}.{$key}", $errors, $strict);
                    }
                }

                return;
        }

        throw new LogicException('Unsupported form in the laranex-schema JSON Schema at '.$path.': '.Json::compact($node));
    }

    private static function kindOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            Json::isObject($value) => 'object',
            is_array($value) => 'array',
            default => get_debug_type($value),
        };
    }

    /**
     * JSON.stringify for the values messages quote.
     */
    private static function stringify(mixed $value): string
    {
        try {
            return Json::compact($value);
        } catch (JsonException) {
            return get_debug_type($value);
        }
    }
}
