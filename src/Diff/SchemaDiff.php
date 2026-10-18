<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Diff;

use Laranex\LaravelSchemaViewer\Contract\Json;

/**
 * What changed between two laranex-schema 2.x documents: connections, tables, columns, indexes, foreign keys, models,
 * relationships and links that were added, removed or changed. Diagnostics are left out: they have their own list, and
 * a new rule or setting can change them while the schema stays the same. A renamed table or column shows as one removed
 * and one added.
 *
 * Each change is what the shared viewer's Changes tab shows (its ViewerChange): type, change (added, removed or
 * changed), subject (the id of what it is about, which the viewer opens), label, message, and fields ([before, after]
 * by field) for a change; connection and table (names) say where it is.
 */
final class SchemaDiff
{
    private const ORDER = ['connection', 'table', 'column', 'index', 'foreignKey', 'model', 'relationship', 'link'];

    private const TABLE_FIELDS = ['comment', 'note', 'owner'];

    private const COLUMN_FIELDS = ['type', 'nullable', 'default', 'autoIncrement', 'collation', 'enum', 'generated', 'comment', 'note'];

    private const INDEX_FIELDS = ['parts', 'unique', 'backs', 'method', 'partial', 'expression', 'included'];

    private const FOREIGN_KEY_FIELDS = ['name', 'onUpdate', 'onDelete'];

    private const MODEL_FIELDS = ['tables', 'primaryKey', 'extensions'];

    private const RELATIONSHIP_FIELDS = ['type', 'relatedModel', 'relatedTable', 'keys', 'pivot', 'polymorphic', 'through'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        $old = self::keyed($before['connections'] ?? [], 'id');
        $new = self::keyed($after['connections'] ?? [], 'id');

        foreach (array_diff_key($new, $old) as $id => $connection) {
            $changes[] = self::change('connection', 'added', $id, $connection['name'], $connection['name'], null, self::count(count($connection['tables']), 'table'));
        }

        foreach (array_diff_key($old, $new) as $id => $connection) {
            $changes[] = self::change('connection', 'removed', $id, $connection['name'], $connection['name'], null, self::count(count($connection['tables']), 'table'));
        }

        foreach (array_intersect_key($new, $old) as $id => $connection) {
            array_push($changes, ...self::tables($connection['name'], $old[$id]['tables'], $connection['tables']));
        }

        $models = fn (array $document): array => self::keyed($document['models'] ?? [], 'id');
        $oldModels = $models($before);
        $newModels = $models($after);

        foreach (array_diff_key($newModels, $oldModels) as $id => $model) {
            $changes[] = self::change('model', 'added', $id, $model['className'], null, null, self::tablesOf($model));
        }

        foreach (array_diff_key($oldModels, $newModels) as $id => $model) {
            $changes[] = self::change('model', 'removed', $id, $model['className'], null, null, self::tablesOf($model));
        }

        foreach (array_intersect_key($newModels, $oldModels) as $id => $model) {
            $fields = self::fields($oldModels[$id], $model, self::MODEL_FIELDS);

            if ($fields !== []) {
                $changes[] = self::change('model', 'changed', $id, $model['className'], null, null, null, $fields);
            }
        }

        $relationships = fn (array $document): array => self::keyed($document['relationships'] ?? [], 'id');
        $oldRelationships = $relationships($before);
        $newRelationships = $relationships($after);
        $relationshipLabel = fn (array $r): string => self::shortRelation($r['id']).'()';

        foreach (array_diff_key($newRelationships, $oldRelationships) as $id => $r) {
            $changes[] = self::change('relationship', 'added', $id, $relationshipLabel($r), null, null, $r['type'].' '.$r['cardinality']);
        }

        foreach (array_diff_key($oldRelationships, $newRelationships) as $id => $r) {
            $changes[] = self::change('relationship', 'removed', $id, $relationshipLabel($r), null, null, $r['type'].' '.$r['cardinality']);
        }

        foreach (array_intersect_key($newRelationships, $oldRelationships) as $id => $r) {
            $fields = self::fields($oldRelationships[$id], $r, self::RELATIONSHIP_FIELDS);

            if ($fields !== []) {
                $changes[] = self::change('relationship', 'changed', $id, $relationshipLabel($r), null, null, null, $fields);
            }
        }

        array_push($changes, ...self::links($before, $after));

        $key = fn (array $c): array => [(string) ($c['connection'] ?? ''), (string) ($c['table'] ?? ''), (int) array_search($c['type'], self::ORDER, true), (string) $c['label'], (string) $c['change'], (string) ($c['message'] ?? '')];
        usort($changes, fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $changes;
    }

    /**
     * One change as a line of text: "Column orders.status changed: type varchar(20) → varchar(50)".
     *
     * @param  array<string, mixed>  $change
     */
    public static function describe(array $change): string
    {
        $type = (string) ($change['type'] ?? '');
        $label = (string) ($change['label'] ?? '');

        if ($type === 'link') {
            return (string) ($change['message'] ?? $label);
        }

        $noun = match ($type) {
            'foreignKey' => 'Foreign key',
            default => ucfirst($type),
        };
        $details = [];

        foreach (is_array($change['fields'] ?? null) ? $change['fields'] : [] as $field => $values) {
            $values = is_array($values) ? array_values($values) : [null, null];
            $details[] = $field.' '.self::show($values[0] ?? null).' → '.self::show($values[1] ?? null);
        }

        if (is_string($change['message'] ?? null) && $change['message'] !== '') {
            $details[] = $change['message'];
        }

        return $noun.' '.$label.' '.((string) ($change['change'] ?? '')).($details !== [] ? ': '.implode(', ', $details) : '');
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private static function tables(string $connection, array $before, array $after): array
    {
        $changes = [];
        $old = self::keyed($before, 'id');
        $new = self::keyed($after, 'id');
        $label = fn (array $t): string => ($t['schema'] !== null ? $t['schema'].'.' : '').$t['name'];

        foreach (array_diff_key($new, $old) as $id => $table) {
            $changes[] = self::change('table', 'added', $id, $label($table), $connection, $label($table), self::count(count($table['columns']), 'column'));
        }

        foreach (array_diff_key($old, $new) as $id => $table) {
            $changes[] = self::change('table', 'removed', $id, $label($table), $connection, $label($table), self::count(count($table['columns']), 'column'));
        }

        foreach (array_intersect_key($new, $old) as $id => $table) {
            $name = $label($table);
            $fields = self::fields($old[$id], $table, self::TABLE_FIELDS);

            if ($fields !== []) {
                $changes[] = self::change('table', 'changed', $id, $name, $connection, $name, null, $fields);
            }

            array_push(
                $changes,
                ...self::entities('column', $old[$id]['columns'], $table['columns'], fn (array $c): string => $c['name'], self::COLUMN_FIELDS,
                    fn (array $c): array => [$id, $name.'.'.$c['name'], $connection, $name], fn (array $c): string => (string) $c['type']),
                ...self::entities('index', $old[$id]['indexes'], $table['indexes'], fn (array $i): string => $i['id'], self::INDEX_FIELDS,
                    fn (array $i): array => [$i['id'], ($i['name'] ?? 'unnamed').' on '.$name, $connection, $name], fn (array $i): string => '('.implode(', ', array_map(fn (array $p): string => (string) ($p['column'] ?? $p['expression']), $i['parts'])).')'),
                ...self::entities('foreignKey', $old[$id]['foreignKeys'], $table['foreignKeys'], fn (array $f): string => (string) json_encode([$f['columns'], $f['foreignTable'], $f['foreignColumns']]), self::FOREIGN_KEY_FIELDS,
                    fn (array $f): array => [$f['id'], ($f['name'] ?? 'unnamed').' on '.$name, $connection, $name], fn (array $f): string => 'references '.$f['foreignTable']),
            );
        }

        return $changes;
    }

    /**
     * Added, removed and changed items of one kind, matched by key.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @param  callable(array<string, mixed>): string  $key
     * @param  list<string>  $compared
     * @param  callable(array<string, mixed>): array{string, string, string, string}  $where  Subject id, label, connection and table.
     * @param  callable(array<string, mixed>): string  $summary
     * @return list<array<string, mixed>>
     */
    private static function entities(string $type, array $before, array $after, callable $key, array $compared, callable $where, callable $summary): array
    {
        $changes = [];
        $old = [];
        $new = [];

        foreach ($before as $item) {
            $old[$key($item)] ??= $item;
        }

        foreach ($after as $item) {
            $new[$key($item)] ??= $item;
        }

        foreach (array_diff_key($new, $old) as $item) {
            [$subject, $label, $connection, $table] = $where($item);
            $changes[] = self::change($type, 'added', $subject, $label, $connection, $table, $summary($item));
        }

        foreach (array_diff_key($old, $new) as $item) {
            [$subject, $label, $connection, $table] = $where($item);
            // A removed item is no longer in the document: its table is what the viewer can open.
            $changes[] = self::change($type, 'removed', $type === 'column' ? $subject : null, $label, $connection, $table, $summary($item));
        }

        foreach (array_intersect_key($new, $old) as $id => $item) {
            $fields = self::fields($old[$id], $item, $compared);

            if ($fields !== []) {
                [$subject, $label, $connection, $table] = $where($item);
                $changes[] = self::change($type, 'changed', $subject, $label, $connection, $table, null, $fields);
            }
        }

        return $changes;
    }

    /**
     * Links whose state moved (a relationship that gained or lost its foreign key, a foreign key that gained or lost a
     * relationship), and links whose note changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private static function links(array $before, array $after): array
    {
        $names = [];
        $tables = [];

        foreach ([$before, $after] as $document) {
            foreach ($document['connections'] ?? [] as $connection) {
                foreach ($connection['tables'] as $table) {
                    $names[$table['id']] ??= $connection['name'];
                    $tables[$table['id']] ??= ($table['schema'] !== null ? $table['schema'].'.' : '').$table['name'];
                }
            }
        }

        $old = self::keyed($before['links'] ?? [], 'id');
        $changes = [];

        foreach (self::keyed($after['links'] ?? [], 'id') as $id => $link) {
            if (! isset($old[$id])) {
                continue;
            }

            $from = $tables[$link['from']['table']] ?? $link['from']['table'];
            $name = $from.'.'.implode(', ', $link['from']['columns']).' → '.($tables[$link['to']['table']] ?? $link['to']['table']).'.'.implode(', ', $link['to']['columns']);
            $connection = $names[$link['from']['table']] ?? null;
            $was = $old[$id]['state'];
            $is = $link['state'];

            if ($old[$id]['note'] !== $link['note']) {
                $changes[] = self::change('link', 'changed', $id, $name, $connection, $from, sprintf('Relationship %s note: %s → %s', $name, self::show($old[$id]['note']), self::show($link['note'])), ['note' => [$old[$id]['note'], $link['note']]]);
            }

            if ($was === $is) {
                continue;
            }

            $relations = implode(' and ', array_map(fn (string $r): string => self::shortRelation($r).'()', $link['relationships'] !== [] ? $link['relationships'] : $old[$id]['relationships']));
            $several = str_contains($relations, ' and ');
            $foreignKey = self::foreignKeyName($link['foreignKey'] ?? $old[$id]['foreignKey']);

            $message = match ($was.'>'.$is) {
                'relationship>both' => sprintf('%s %s now backed by a foreign key', $relations, $several ? 'are' : 'is'),
                'both>relationship' => sprintf('%s lost %s foreign key %s', $relations, $several ? 'their' : 'its', self::foreignKeyName($old[$id]['foreignKey'])),
                'foreign_key>both' => sprintf('Foreign key %s now has a relationship: %s', $foreignKey, $relations),
                'both>foreign_key' => sprintf('Foreign key %s no longer has a relationship', $foreignKey),
                default => sprintf('%s is now %s instead of %s', $name, $is, $was),
            };

            $changes[] = self::change('link', 'changed', $id, $name, $connection, $from, $message, ['state' => [$was, $is]]);
        }

        return $changes;
    }

    /**
     * @param  array<string, array{mixed, mixed}>|null  $fields
     * @return array<string, mixed>
     */
    private static function change(string $type, string $change, ?string $subject, string $label, ?string $connection, ?string $table, ?string $message, ?array $fields = null): array
    {
        return array_filter([
            'type' => $type,
            'change' => $change,
            'subject' => $subject,
            'label' => $label,
            'message' => $message,
            'fields' => $fields,
            'connection' => $connection,
            'table' => $table,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The compared fields that differ, as [before, after].
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $compared
     * @return array<string, array{mixed, mixed}>
     */
    private static function fields(array $before, array $after, array $compared): array
    {
        $fields = [];

        foreach ($compared as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if (Json::canonical($old) !== Json::canonical($new)) {
                $fields[$field] = [$old, $new];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(mixed $items, string $key): array
    {
        $keyed = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item[$key] ?? null)) {
                $keyed[$item[$key]] ??= $item;
            }
        }

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private static function tablesOf(array $model): ?string
    {
        return $model['tables'] !== [] ? 'tables '.implode(', ', $model['tables']) : null;
    }

    private static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }

    private static function foreignKeyName(mixed $id): string
    {
        $id = is_string($id) ? $id : '';
        $at = strrpos($id, ':fk:');

        return $at === false ? $id : substr($id, $at + 4);
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
