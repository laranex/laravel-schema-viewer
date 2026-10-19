<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

/**
 * Opaque ids the contract defines by rule: a prefix and the first 16 hex digits of the SHA-256 of compact JSON parts,
 * exactly as laranex-schema-viewer-ui's ids.ts and graph.ts compute them.
 */
final class Ids
{
    /**
     * @param  list<mixed>  $parts
     */
    public static function hash(string $prefix, array $parts): string
    {
        return $prefix.substr(hash('sha256', Json::compact($parts)), 0, 16);
    }

    /**
     * @param  list<array{string, string}>  $pairs  The [fromColumn, toColumn] pairs, sorted.
     */
    public static function link(string $fromTable, string $toTable, array $pairs): string
    {
        return self::hash('l', ['link', $fromTable, $toTable, $pairs]);
    }

    public static function derivedLink(string $relationship): string
    {
        return self::hash('d', ['derived', $relationship]);
    }

    /**
     * @param  list<string>|null  $columns
     */
    public static function diagnostic(string $kind, string $subjectType, ?string $subjectId, ?array $columns, string $message): string
    {
        return self::hash('n', ['diagnostic', $kind, $subjectType, $subjectId, $columns, $message]);
    }

    public static function comparison(string $baselineConnection, ?string $baselineSchema, string $targetConnection, ?string $targetSchema): string
    {
        return self::hash('c', ['comparison', $baselineConnection, $baselineSchema, $targetConnection, $targetSchema]);
    }

    /**
     * @param  list<string>  $key
     */
    public static function difference(string $comparison, string $objectType, array $key): string
    {
        return self::hash('x', ['difference', $comparison, $objectType, $key]);
    }

    public static function migrationTrack(string $provider, string $connection, ?string $schema): string
    {
        return self::hash('m', ['migration', $provider, $connection, $schema]);
    }

    /**
     * @param  list<string>  $foreignKeys
     */
    public static function inferredAssociation(string $kind, array $foreignKeys, ?string $via): string
    {
        return self::hash('i', ['inferred', $kind, self::sorted($foreignKeys), $via]);
    }

    /**
     * Unique strings in Unicode code point order (byte order of UTF-8).
     *
     * @param  iterable<string>  $values
     * @return list<string>
     */
    public static function sorted(iterable $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $unique[$value] = true;
        }

        $sorted = array_map('strval', array_keys($unique));
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * Whether a list of strings is unique and sorted.
     *
     * @param  list<string>  $values
     */
    public static function isSorted(array $values): bool
    {
        return $values === self::sorted($values);
    }

    /**
     * Element-wise code point order of string lists; a prefix sorts first.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function byList(array $a, array $b): int
    {
        for ($i = 0; $i < min(count($a), count($b)); $i++) {
            $order = strcmp($a[$i], $b[$i]);

            if ($order !== 0) {
                return $order;
            }
        }

        return count($a) - count($b);
    }
}
