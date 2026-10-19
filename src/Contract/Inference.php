<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

/**
 * The association inference every producer implements (docs/contract.md, "Inferred associations"), a port of
 * laranex-schema-viewer-ui's inference.ts, checked against contract/conformance/inference. It reads the stored links: a
 * foreign-key link with no relationship is a foreign key no declared relationship covers. Inferences never become
 * relationships or links.
 */
final class Inference
{
    private const LEVELS = ['high', 'medium', 'low'];

    /**
     * @param  array<string, mixed>  $document  A document's source, connections, models and links.
     * @return list<array<string, mixed>>
     */
    public static function infer(array $document): array
    {
        $tables = [];
        $fks = [];

        foreach ($document['connections'] as $connection) {
            foreach ($connection['tables'] as $table) {
                $tables[$table['id']] = $table;

                foreach ($table['foreignKeys'] as $fk) {
                    $fks[$fk['id']] = $fk;
                }
            }
        }

        $modelsOf = function (string $table) use ($document): array {
            $models = array_values(array_filter($document['models'], fn (array $model): bool => in_array($table, $model['tables'], true)));
            usort($models, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

            return $models;
        };
        $confirmed = fn (array $provenance): bool => $document['source'] === 'declared' || in_array('database', $provenance, true);
        $mapped = fn (string $table): bool => $modelsOf($table) !== [];

        $candidates = [];

        foreach ($document['links'] as $link) {
            if ($link['foreignKey'] !== null && $link['relationships'] === [] && isset($fks[$link['foreignKey']])) {
                $candidates[] = ['link' => $link, 'fk' => $fks[$link['foreignKey']], 'from' => $link['from']['table'], 'to' => $link['to']['table']];
            }
        }

        usort($candidates, fn (array $a, array $b): int => strcmp($a['fk']['id'], $b['fk']['id']));

        $fkLinksBetween = fn (string $from, string $to): int => count(array_filter($document['links'], fn (array $l): bool => $l['foreignKey'] !== null && $l['from']['table'] === $from && $l['to']['table'] === $to));
        $keyEvidence = fn (array $key): array => ['kind' => $key['kind'], 'id' => $key['id'], 'confirmed' => $confirmed($key['provenance'])];
        $fkEvidence = fn (array $candidate): array => ['kind' => 'foreign_key', 'id' => $candidate['fk']['id'], 'confirmed' => $confirmed($candidate['fk']['provenance'])];

        $result = [];

        $build = function (array $parts, array $ambiguities, array $evidence, array $used) use (&$result, $modelsOf, $confirmed): void {
            $models = $modelsOf($parts['table']);
            $related = $modelsOf($parts['relatedTable']);

            if (count($models) > 1 || count($related) > 1) {
                $ambiguities[] = 'multiple_models';
            }

            if ($parts['table'] === $parts['relatedTable']) {
                $ambiguities[] = 'self_reference';
            }

            foreach ([...$models, ...$related] as $model) {
                $evidence[] = ['kind' => 'model_mapping', 'id' => $model['id'], 'confirmed' => true];
            }

            $unconfirmed = count(array_filter($used, fn (array $candidate): bool => ! $confirmed($candidate['fk']['provenance'])));
            $unique = [];

            foreach ($evidence as $item) {
                $unique[$item['kind']."\0".$item['id']] = $item;
            }

            $unique = array_values($unique);
            usort($unique, fn (array $a, array $b): int => strcmp($a['kind'], $b['kind']) ?: strcmp($a['id'], $b['id']));
            $ambiguities = Ids::sorted($ambiguities);

            $result[] = [
                'id' => Ids::inferredAssociation($parts['kind'], $parts['foreignKeys'], $parts['via']),
                ...$parts,
                'model' => $models[0]['id'],
                'relatedModel' => $related[0]['id'],
                'confidence' => self::LEVELS[min(2, count($ambiguities) + $unconfirmed)],
                'evidence' => $unique,
                'ambiguities' => $ambiguities,
            ];
        };

        // Association tables: an unmapped table with a key made of exactly the columns of two of its uncovered foreign
        // keys is a many-to-many association between the tables they reference.
        $consumed = [];

        foreach ($tables as $table) {
            if ($mapped($table['id'])) {
                continue;
            }

            $own = array_values(array_filter($candidates, fn (array $c): bool => $c['from'] === $table['id']));
            $keys = self::candidateKeys($table);

            for ($i = 0; $i < count($own); $i++) {
                for ($j = $i + 1; $j < count($own); $j++) {
                    [$a, $b] = [$own[$i], $own[$j]];
                    $columns = [...$a['fk']['columns'], ...$b['fk']['columns']];
                    $matching = array_values(array_filter($keys, fn (array $key): bool => self::sameSet($key['columns'], $columns)));

                    if (count(array_unique($columns)) !== count($columns) || $matching === [] || ! $mapped($a['to']) || ! $mapped($b['to'])) {
                        continue;
                    }

                    $consumed[$a['fk']['id']] = $consumed[$b['fk']['id']] = true;
                    $build(
                        ['kind' => 'association_table', 'table' => $a['to'], 'relatedTable' => $b['to'], 'via' => $table['id'], 'cardinality' => 'many-to-many', 'foreignKeys' => Ids::sorted([$a['fk']['id'], $b['fk']['id']]), 'links' => Ids::sorted([$a['link']['id'], $b['link']['id']])],
                        [],
                        [$fkEvidence($a), $fkEvidence($b), ...array_map($keyEvidence, $matching)],
                        [$a, $b],
                    );
                }
            }
        }

        // Every other uncovered foreign key between two mapped tables: many-to-one from the referencing model, or
        // one-to-one when its columns are exactly a key of the referencing table.
        foreach ($candidates as $c) {
            if (isset($consumed[$c['fk']['id']]) || ! $mapped($c['from']) || ! $mapped($c['to'])) {
                continue;
            }

            $unique = array_values(array_filter(self::candidateKeys($tables[$c['from']]), fn (array $key): bool => self::sameSet($key['columns'], $c['fk']['columns'])));
            $build(
                ['kind' => 'foreign_key', 'table' => $c['from'], 'relatedTable' => $c['to'], 'via' => null, 'cardinality' => $unique !== [] ? 'one-to-one' : 'many-to-one', 'foreignKeys' => [$c['fk']['id']], 'links' => [$c['link']['id']]],
                $fkLinksBetween($c['from'], $c['to']) > 1 ? ['multiple_foreign_keys'] : [],
                [$fkEvidence($c), ...array_map($keyEvidence, $unique)],
                [$c],
            );
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $result;
    }

    /**
     * The column sets a table's rows are unique on: its primary key, its unique constraints, and unique indexes that
     * back no constraint entity, are not partial and have no expression parts.
     *
     * @param  array<string, mixed>  $table
     * @return list<array{kind: string, id: string, columns: list<string>, provenance: list<string>}>
     */
    public static function candidateKeys(array $table): array
    {
        $keys = [];

        if ($table['primaryKey'] !== []) {
            $keys[] = ['kind' => 'primary_key', 'id' => $table['id'], 'columns' => $table['primaryKey'], 'provenance' => $table['provenance']];
        }

        foreach ($table['constraints'] as $constraint) {
            if ($constraint['kind'] === 'unique' && $constraint['columns'] !== [] && $constraint['expression'] === null) {
                $keys[] = ['kind' => 'unique_constraint', 'id' => $constraint['id'], 'columns' => $constraint['columns'], 'provenance' => $constraint['provenance']];
            }
        }

        foreach ($table['indexes'] as $index) {
            $backs = $index['backs'];

            if ($index['unique'] && ! $index['partial'] && ! $index['expression'] && ($backs['kind'] ?? null) !== 'primary_key'
                && ! (($backs['kind'] ?? null) === 'unique_constraint' && $backs['constraint'] !== null)) {
                $keys[] = ['kind' => 'unique_index', 'id' => $index['id'], 'columns' => array_map(fn (array $part): string => (string) $part['column'], $index['parts']), 'provenance' => $index['provenance']];
            }
        }

        return $keys;
    }

    /**
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        return count($a) === count($b) && count(array_unique($a)) === count(array_unique([...$a, ...$b]));
    }
}
