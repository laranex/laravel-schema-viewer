<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

/**
 * Migration history and status rules (docs/contract.md, "Migrations"), a port of laranex-schema-viewer-ui's
 * migration.ts, checked against contract/conformance/migrations. This package writes no migration tracks; the
 * validator uses these rules to check the tracks of any document it reads.
 */
final class Migration
{
    /**
     * A history from its revisions: revisions sorted by id with sorted parents and branch labels, heads (revisions no
     * revision names as a parent) and missing (parents named but not present).
     *
     * @param  list<array<string, mixed>>  $revisions
     * @return array{revisions: list<array{id: string, parents: list<string>, branchLabels: list<string>}>, heads: list<string>, missing: list<string>}
     */
    public static function history(array $revisions): array
    {
        $sorted = array_map(fn (array $r): array => ['id' => (string) $r['id'], 'parents' => Ids::sorted($r['parents']), 'branchLabels' => Ids::sorted($r['branchLabels'])], $revisions);
        usort($sorted, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        $ids = array_fill_keys(array_column($sorted, 'id'), true);
        $parents = array_fill_keys(array_merge([], ...array_column($sorted, 'parents')), true);

        return [
            'revisions' => $sorted,
            'heads' => Ids::sorted(array_values(array_filter(array_column($sorted, 'id'), fn (string $id): bool => ! isset($parents[$id])))),
            'missing' => Ids::sorted(array_values(array_filter(array_map('strval', array_keys($parents)), fn (string $p): bool => ! isset($ids[$p])))),
        ];
    }

    /**
     * The status of a database at revisions $current against a history.
     *
     * @param  array<string, mixed>|null  $history
     * @param  list<string>|null  $current
     * @return array{status: string, pending: list<string>|null, unknownRevisions: list<string>}
     */
    public static function state(?array $history, ?array $current): array
    {
        if ($current === null) {
            return self::unknown();
        }

        $parentsOf = [];

        foreach ($history['revisions'] ?? [] as $revision) {
            $parentsOf[$revision['id']] = $revision['parents'];
        }

        $ancestry = function (array $from) use ($parentsOf): array {
            $seen = [];
            $stack = $from;

            while ($stack !== []) {
                $id = (string) array_pop($stack);

                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    array_push($stack, ...($parentsOf[$id] ?? []));
                }
            }

            return $seen;
        };

        $missing = array_fill_keys($history['missing'] ?? [], true);
        $pendingFrom = function (array $applied) use ($ancestry, $history, $missing): ?array {
            $pending = array_values(array_filter(array_map('strval', array_keys($ancestry($history['heads'] ?? []))), fn (string $r): bool => ! isset($applied[$r])));

            return array_filter($pending, fn (string $r): bool => isset($missing[$r])) !== [] ? null : Ids::sorted($pending);
        };

        if ($current === []) {
            return ['status' => 'uninitialized', 'pending' => $history === null ? null : $pendingFrom([]), 'unknownRevisions' => []];
        }

        if ($history === null) {
            return self::unknown();
        }

        $unknownRevisions = Ids::sorted(array_filter($current, fn (string $r): bool => ! array_key_exists($r, $parentsOf)));

        if ($unknownRevisions !== []) {
            return self::unknown($unknownRevisions);
        }

        foreach ($current as $c) {
            foreach ($current as $other) {
                if ($other !== $c && isset($ancestry($parentsOf[$other] ?? [])[$c])) {
                    return ['status' => 'diverged', 'pending' => null, 'unknownRevisions' => []];
                }
            }
        }

        $pending = $pendingFrom($ancestry($current));

        if ($pending === null) {
            return self::unknown();
        }

        return ['status' => $pending !== [] ? 'behind' : 'up_to_date', 'pending' => $pending, 'unknownRevisions' => []];
    }

    /**
     * @param  list<string>  $revisions
     * @return array{status: string, pending: null, unknownRevisions: list<string>}
     */
    private static function unknown(array $revisions = []): array
    {
        return ['status' => 'unknown', 'pending' => null, 'unknownRevisions' => $revisions];
    }
}
