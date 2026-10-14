<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Diff;

/**
 * What changed between two schema documents, as decoded from JSON: connections, tables, columns, indexes, foreign keys,
 * models, relations and links that were added, removed or changed. Hints are left out: they have their own list, and a
 * new rule or setting can change them while the schema stays the same. A field one side does not have, because a
 * version of the package wrote it without that field, is never a change. A renamed table or column shows as one removed
 * and one added.
 *
 * Each change is an array: type, change (added, removed or changed), connection and table where they apply, name, and
 * fields ([before, after] by field) for a change, a small before for a removal, a small after for an addition, and a
 * message for links.
 */
final class SchemaDiff
{
    private const ORDER = ['connection', 'table', 'column', 'index', 'foreign_key', 'model', 'relation', 'link'];

    private const TABLE_FIELDS = ['comment', 'note'];

    private const COLUMN_FIELDS = ['type', 'nullable', 'default', 'autoIncrement', 'comment', 'note'];

    private const INDEX_FIELDS = ['columns', 'unique', 'primary', 'partial', 'expression'];

    private const FOREIGN_KEY_FIELDS = ['name', 'onUpdate', 'onDelete'];

    private const MODEL_FIELDS = ['table', 'connection', 'primaryKey', 'casts', 'fillable', 'hidden', 'softDeletes', 'timestamps'];

    private const RELATION_FIELDS = ['type', 'related', 'keys', 'pivot', 'morph', 'through'];

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     * @return list<array<string, mixed>>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        $old = self::keyed(self::list($before['connections'] ?? []), fn (array $c): string => self::string($c['name'] ?? ''));
        $new = self::keyed(self::list($after['connections'] ?? []), fn (array $c): string => self::string($c['name'] ?? ''));

        foreach (array_diff_key($new, $old) as $name => $connection) {
            $changes[] = ['type' => 'connection', 'change' => 'added', 'connection' => $name, 'after' => ['tables' => count(self::list($connection['tables'] ?? []))]];
        }

        foreach (array_diff_key($old, $new) as $name => $connection) {
            $changes[] = ['type' => 'connection', 'change' => 'removed', 'connection' => $name, 'before' => ['tables' => count(self::list($connection['tables'] ?? []))]];
        }

        foreach (array_intersect_key($new, $old) as $name => $connection) {
            array_push($changes, ...self::tables($name, self::list($old[$name]['tables'] ?? []), self::list($connection['tables'] ?? [])));
        }

        array_push($changes, ...self::entities(
            'model',
            self::list($before['models'] ?? []),
            self::list($after['models'] ?? []),
            fn (array $m): string => self::string($m['class'] ?? ''),
            self::MODEL_FIELDS,
            fn (array $m): array => ['name' => self::string($m['class'] ?? ''), 'connection' => self::string($m['connection'] ?? ''), 'table' => self::string($m['table'] ?? '')],
            fn (array $m): array => ['table' => $m['table'] ?? null],
        ));

        array_push($changes, ...self::entities(
            'relation',
            self::list($before['relations'] ?? []),
            self::list($after['relations'] ?? []),
            fn (array $r): string => self::string($r['id'] ?? ''),
            self::RELATION_FIELDS,
            fn (array $r): array => ['name' => self::string($r['id'] ?? '')],
            fn (array $r): array => ['type' => $r['type'] ?? null, 'related' => $r['related'] ?? null],
        ));

        array_push($changes, ...self::links(self::list($before['links'] ?? []), self::list($after['links'] ?? [])));

        usort($changes, fn (array $a, array $b): int => [
            self::string($a['connection'] ?? ''), self::string($a['table'] ?? ''), array_search($a['type'], self::ORDER, true), self::string($a['name'] ?? ''), self::string($a['change']), self::string($a['message'] ?? ''),
        ] <=> [
            self::string($b['connection'] ?? ''), self::string($b['table'] ?? ''), array_search($b['type'], self::ORDER, true), self::string($b['name'] ?? ''), self::string($b['change']), self::string($b['message'] ?? ''),
        ]);

        return $changes;
    }

    /**
     * One change as a line of text: "Column orders.status changed: type varchar(20) → varchar(50)".
     *
     * @param  array<string, mixed>  $change
     */
    public static function describe(array $change): string
    {
        $type = self::string($change['type'] ?? '');
        $label = match ($type) {
            'connection' => 'Connection '.self::string($change['connection'] ?? ''),
            'table' => 'Table '.self::string($change['table'] ?? ''),
            'column' => 'Column '.self::string($change['table'] ?? '').'.'.self::string($change['name'] ?? ''),
            'index' => 'Index '.self::string($change['name'] ?? '').' on '.self::string($change['table'] ?? ''),
            'foreign_key' => 'Foreign key '.self::string($change['name'] ?? '').' on '.self::string($change['table'] ?? ''),
            'model' => 'Model '.self::string($change['name'] ?? ''),
            'relation' => 'Relation '.self::string($change['name'] ?? ''),
            default => self::string($change['message'] ?? ''),
        };

        if ($type === 'link') {
            return $label;
        }

        $details = [];

        foreach (is_array($change['fields'] ?? null) ? $change['fields'] : [] as $field => $values) {
            $values = is_array($values) ? array_values($values) : [null, null];
            $details[] = $field.' '.self::show($values[0] ?? null).' → '.self::show($values[1] ?? null);
        }

        foreach (['after', 'before'] as $side) {
            foreach (is_array($change[$side] ?? null) ? $change[$side] : [] as $field => $value) {
                $details[] = $field.' '.self::show($value);
            }
        }

        return $label.' '.self::string($change['change'] ?? '').($details !== [] ? ': '.implode(', ', $details) : '');
    }

    /**
     * @param  list<array<mixed>>  $before
     * @param  list<array<mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private static function tables(string $connection, array $before, array $after): array
    {
        $changes = [];
        $old = self::keyed($before, fn (array $t): string => self::string($t['name'] ?? ''));
        $new = self::keyed($after, fn (array $t): string => self::string($t['name'] ?? ''));

        foreach (array_diff_key($new, $old) as $name => $table) {
            $changes[] = ['type' => 'table', 'change' => 'added', 'connection' => $connection, 'table' => $name, 'after' => ['columns' => count(self::list($table['columns'] ?? []))]];
        }

        foreach (array_diff_key($old, $new) as $name => $table) {
            $changes[] = ['type' => 'table', 'change' => 'removed', 'connection' => $connection, 'table' => $name, 'before' => ['columns' => count(self::list($table['columns'] ?? []))]];
        }

        foreach (array_intersect_key($new, $old) as $name => $table) {
            $fields = self::fields($old[$name], $table, self::TABLE_FIELDS);

            if ($fields !== []) {
                $changes[] = ['type' => 'table', 'change' => 'changed', 'connection' => $connection, 'table' => $name, 'fields' => $fields];
            }

            $at = ['connection' => $connection, 'table' => $name];
            $byName = fn (array $item): string => self::string($item['name'] ?? '');

            array_push(
                $changes,
                ...self::entities('column', self::list($old[$name]['columns'] ?? []), self::list($table['columns'] ?? []), $byName, self::COLUMN_FIELDS,
                    fn (array $c): array => $at + ['name' => self::string($c['name'] ?? '')], fn (array $c): array => ['type' => $c['type'] ?? null]),
                ...self::entities('index', self::list($old[$name]['indexes'] ?? []), self::list($table['indexes'] ?? []), $byName, self::INDEX_FIELDS,
                    fn (array $i): array => $at + ['name' => self::string($i['name'] ?? '')], fn (array $i): array => ['columns' => $i['columns'] ?? null]),
                ...self::entities('foreign_key', self::list($old[$name]['foreignKeys'] ?? []), self::list($table['foreignKeys'] ?? []),
                    fn (array $f): string => (string) json_encode([$f['columns'] ?? null, $f['foreignTable'] ?? null, $f['foreignColumns'] ?? null]), self::FOREIGN_KEY_FIELDS,
                    fn (array $f): array => $at + ['name' => self::string($f['name'] ?? '')], fn (array $f): array => ['references' => self::string($f['foreignTable'] ?? '')]),
            );
        }

        return $changes;
    }

    /**
     * Added, removed and changed items of one kind, matched by key.
     *
     * @param  list<array<mixed>>  $before
     * @param  list<array<mixed>>  $after
     * @param  callable(array<mixed>): string  $key
     * @param  list<string>  $compared
     * @param  callable(array<mixed>): array<string, mixed>  $where
     * @param  callable(array<mixed>): array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    private static function entities(string $type, array $before, array $after, callable $key, array $compared, callable $where, callable $summary): array
    {
        $changes = [];
        $old = self::keyed($before, $key);
        $new = self::keyed($after, $key);

        foreach (array_diff_key($new, $old) as $item) {
            $changes[] = ['type' => $type, 'change' => 'added', ...$where($item), 'after' => $summary($item)];
        }

        foreach (array_diff_key($old, $new) as $item) {
            $changes[] = ['type' => $type, 'change' => 'removed', ...$where($item), 'before' => $summary($item)];
        }

        foreach (array_intersect_key($new, $old) as $id => $item) {
            $fields = self::fields($old[$id], $item, $compared);

            if ($fields !== []) {
                $changes[] = ['type' => $type, 'change' => 'changed', ...$where($item), 'fields' => $fields];
            }
        }

        return $changes;
    }

    /**
     * Links whose state moved: a relation that gained or lost its foreign key, a foreign key that gained or lost a
     * relation.
     *
     * @param  list<array<mixed>>  $before
     * @param  list<array<mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private static function links(array $before, array $after): array
    {
        $key = fn (array $l): string => (string) json_encode([$l['from'] ?? null, $l['to'] ?? null]);
        $old = self::keyed($before, $key);
        $changes = [];

        foreach (self::keyed($after, $key) as $id => $link) {
            $was = $old[$id]['state'] ?? null;
            $is = $link['state'] ?? null;

            if (! is_string($was) || ! is_string($is) || $was === $is) {
                continue;
            }

            $from = is_array($link['from'] ?? null) ? $link['from'] : [];
            $to = is_array($link['to'] ?? null) ? $link['to'] : [];
            $name = self::string($from['table'] ?? '').'.'.implode(', ', self::strings($from['columns'] ?? [])).' → '.self::string($to['table'] ?? '').'.'.implode(', ', self::strings($to['columns'] ?? []));
            $relations = implode(' and ', array_map(fn (string $id): string => self::shortRelation($id).'()', self::strings($link['relations'] ?? $old[$id]['relations'] ?? [])));
            $foreignKey = self::string($link['foreignKey'] ?? $old[$id]['foreignKey'] ?? '');

            $message = match ($was.'>'.$is) {
                'relation>both' => sprintf('%s %s now backed by a foreign key', $relations, str_contains($relations, ' and ') ? 'are' : 'is'),
                'both>relation' => sprintf('%s lost %s foreign key %s', $relations, str_contains($relations, ' and ') ? 'their' : 'its', self::string($old[$id]['foreignKey'] ?? '')),
                'foreign_key>both' => sprintf('Foreign key %s now has a relation: %s', $foreignKey, $relations),
                'both>foreign_key' => sprintf('Foreign key %s no longer has a relation', $foreignKey),
                default => sprintf('%s is now %s instead of %s', $name, $is, $was),
            };

            $changes[] = [
                'type' => 'link',
                'change' => 'changed',
                'connection' => self::string($from['connection'] ?? ''),
                'table' => self::string($from['table'] ?? ''),
                'name' => $name,
                'id' => self::string($link['id'] ?? ''),
                'fields' => ['state' => [$was, $is]],
                'message' => trim($message),
            ];
        }

        return $changes;
    }

    /**
     * The compared fields that differ, as [before, after]; a field one side lacks is left out.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     * @param  list<string>  $compared
     * @return array<string, array{mixed, mixed}>
     */
    private static function fields(array $before, array $after, array $compared): array
    {
        $fields = [];

        foreach ($compared as $field) {
            if (array_key_exists($field, $before) && array_key_exists($field, $after) && $before[$field] !== $after[$field]) {
                $fields[$field] = [$before[$field], $after[$field]];
            }
        }

        return $fields;
    }

    /**
     * @param  list<array<mixed>>  $items
     * @param  callable(array<mixed>): string  $key
     * @return array<string, array<mixed>>
     */
    private static function keyed(array $items, callable $key): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$key($item)] ??= $item;
        }

        return $keyed;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(fn (mixed $item): string => self::string($item), $value)) : [];
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function shortRelation(string $id): string
    {
        $parts = explode('::', $id, 2);
        $class = explode('\\', $parts[0]);

        return end($class).(isset($parts[1]) ? '::'.$parts[1] : '');
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
