<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

/**
 * The framework-neutral graph builder (docs/contract.md, "Graph"), a port of laranex-schema-viewer-ui's graph.ts:
 * links from foreign keys within a connection and from relationship keys, two links per pivot relationship, and a
 * derived link per through relationship. The shared conformance cases (contract/conformance/graph) keep the two equal.
 */
final class Graph
{
    /**
     * @param  array<string, mixed>  $document  A document's connections and relationships.
     * @return array{links: list<array<string, mixed>>, derivedLinks: list<array<string, mixed>>}
     */
    public static function build(array $document): array
    {
        $columns = [];
        $connectionOf = [];

        foreach ($document['connections'] as $connection) {
            foreach ($connection['tables'] as $table) {
                $columns[$table['id']] = array_fill_keys(array_map('strval', array_column($table['columns'], 'name')), true);
                $connectionOf[$table['id']] = $connection['id'];
            }
        }

        $resolved = fn (array $endpoint): bool => $endpoint['columns'] !== []
            && array_filter($endpoint['columns'], fn (string $column): bool => ! isset($columns[$endpoint['table']][$column])) === [];

        /** @var array<string, array<string, mixed>> $links */
        $links = [];

        $add = function (array $from, array $to, ?string $foreignKey = null, ?array $relationship = null) use (&$links, $resolved): void {
            if (! $resolved($from) || ! $resolved($to) || count($from['columns']) !== count($to['columns'])) {
                return;
            }

            $id = Ids::link($from['table'], $to['table'], self::pairs($from['columns'], $to['columns']));
            $links[$id] ??= ['id' => $id, 'from' => $from, 'to' => $to, 'foreignKey' => null, 'relationships' => [], 'polymorphic' => false, 'notes' => []];

            // Duplicate constraints on the same columns: the smallest id stands for them; the endpoints follow it.
            if ($foreignKey !== null && ($links[$id]['foreignKey'] === null || strcmp($foreignKey, $links[$id]['foreignKey']) < 0)) {
                $links[$id]['foreignKey'] = $foreignKey;
                $links[$id]['from'] = $from;
                $links[$id]['to'] = $to;
            }

            if ($relationship !== null && ! in_array($relationship['id'], $links[$id]['relationships'], true)) {
                $links[$id]['relationships'][] = $relationship['id'];
                $links[$id]['polymorphic'] = $links[$id]['polymorphic'] || $relationship['polymorphic'] !== null;

                if (is_string($relationship['note']) && $relationship['note'] !== '') {
                    $links[$id]['notes'][] = $relationship['id']."\0".$relationship['note'];
                }
            }
        };

        // Foreign keys first, so a link's endpoints follow the constraint's column order.
        foreach ($document['connections'] as $connection) {
            foreach ($connection['tables'] as $table) {
                $foreignKeys = $table['foreignKeys'];
                usort($foreignKeys, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

                foreach ($foreignKeys as $fk) {
                    if (($connectionOf[$fk['foreignTable']] ?? null) !== $connection['id']) {
                        continue;
                    }

                    $add(['table' => $table['id'], 'columns' => $fk['columns']], ['table' => $fk['foreignTable'], 'columns' => $fk['foreignColumns']], $fk['id']);
                }
            }
        }

        $derived = [];

        foreach ($document['relationships'] as $relationship) {
            if (is_array($relationship['through']) && $relationship['through'] !== []) {
                $tables = [$relationship['table'], $relationship['relatedTable'], ...$relationship['through']];

                if ($relationship['relatedTable'] !== null && array_filter($tables, fn (mixed $table): bool => ! is_string($table) || ! isset($columns[$table])) === []) {
                    $derived[] = ['id' => Ids::derivedLink($relationship['id']), 'from' => $relationship['table'], 'to' => $relationship['relatedTable'], 'via' => $relationship['through'], 'relationship' => $relationship['id']];
                }
            } elseif (is_array($relationship['pivot'])) {
                $pivot = $relationship['pivot'];
                $add(['table' => $pivot['table'], 'columns' => $pivot['parentPivotColumns']], ['table' => $relationship['table'], 'columns' => $pivot['parentColumns']], null, $relationship);

                if ($relationship['relatedTable'] !== null) {
                    $add(['table' => $pivot['table'], 'columns' => $pivot['relatedPivotColumns']], ['table' => $relationship['relatedTable'], 'columns' => $pivot['relatedColumns']], null, $relationship);
                }
            } elseif (is_array($relationship['keys'])) {
                $add($relationship['keys']['from'], $relationship['keys']['to'], null, $relationship);
            }
        }

        $result = [];

        foreach ($links as $link) {
            $relationships = $link['relationships'];
            sort($relationships, SORT_STRING);
            $notes = $link['notes'];
            sort($notes, SORT_STRING);
            unset($link['notes']);

            $result[] = [
                ...$link,
                'relationships' => $relationships,
                'state' => $link['foreignKey'] !== null ? ($relationships !== [] ? 'both' : 'foreign_key') : 'relationship',
                // The note of the first relationship, by id, that has one.
                'note' => $notes !== [] ? explode("\0", $notes[0], 2)[1] : null,
            ];
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($derived, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return ['links' => $result, 'derivedLinks' => $derived];
    }

    /**
     * The [fromColumn, toColumn] pairs, sorted, so (a, b) → (x, y) and (b, a) → (y, x) are one link.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return list<array{string, string}>
     */
    public static function pairs(array $from, array $to): array
    {
        $pairs = array_map(fn (string $column, int $i): array => [$column, $to[$i]], $from, array_keys($from));
        usort($pairs, fn (array $p, array $q): int => strcmp($p[0], $q[0]) ?: strcmp($p[1], $q[1]));

        return $pairs;
    }
}
