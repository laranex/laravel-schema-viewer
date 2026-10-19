<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use JsonException;
use stdClass;

/**
 * JSON as the laranex-schema contract writes and reads it. In PHP values a JSON array is a list, a JSON object an
 * associative array, and an object that has no keys (or only keys PHP would turn into a list) a stdClass, so an empty
 * object stays {} and never becomes [].
 */
final class Json
{
    /**
     * The canonical form every producer writes: object keys sorted by Unicode code point, recursively; two-space
     * indentation and ": " after keys; slashes and non-ASCII characters unescaped; a trailing newline. It is
     * JavaScript's JSON.stringify(sorted, null, 2) byte for byte.
     */
    public static function canonical(mixed $value): string
    {
        return self::encode($value, '', true)."\n";
    }

    /**
     * JavaScript's JSON.stringify(value, null, 2) and a newline: as canonical(), with object keys in their given order.
     */
    public static function pretty(mixed $value): string
    {
        return self::encode($value, '', false)."\n";
    }

    /**
     * Compact JSON, as JavaScript's JSON.stringify writes it, for ids and messages: no whitespace, slashes and
     * non-ASCII characters unescaped, object keys in their given order.
     */
    public static function compact(mixed $value): string
    {
        return json_encode(self::forEncoding($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode JSON text into the values above.
     *
     * @throws JsonException
     */
    public static function decode(string $text): mixed
    {
        return self::fromDecoded(json_decode($text, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    /**
     * Whether a value is a JSON object.
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ! array_is_list($value));
    }

    /**
     * An object's members by key; empty for anything that is not an object.
     *
     * @return array<string, mixed>
     */
    public static function members(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && ! array_is_list($value) ? array_combine(array_map('strval', array_keys($value)), $value) : [];
    }

    /**
     * An object with these members, kept an object even when it is empty.
     *
     * @param  array<array-key, mixed>  $members
     * @return array<string, mixed>|stdClass
     */
    public static function object(array $members): array|stdClass
    {
        return $members === [] || array_is_list($members) ? (object) $members : $members;
    }

    private static function fromDecoded(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::fromDecoded(...), $value);
        }

        if ($value instanceof stdClass) {
            // The array cast keeps PHP's own keys: numeric property names become integers, so {"0": …} reads as a list.
            $members = array_map(self::fromDecoded(...), (array) $value);

            return $members === [] || array_is_list($members) ? (object) $members : $members;
        }

        return $value;
    }

    private static function encode(mixed $value, string $indent, bool $sort): string
    {
        $inner = $indent.'  ';

        if (self::isObject($value)) {
            $members = self::members($value);

            if ($members === []) {
                return '{}';
            }

            if ($sort) {
                ksort($members, SORT_STRING);
            }
            $lines = [];

            foreach ($members as $key => $member) {
                $lines[] = $inner.self::scalar((string) $key).': '.self::encode($member, $inner, $sort);
            }

            return "{\n".implode(",\n", $lines)."\n".$indent.'}';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            return "[\n".implode(",\n", array_map(fn (mixed $item): string => $inner.self::encode($item, $inner, $sort), $value))."\n".$indent.']';
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        // JavaScript writes integral numbers without a fraction.
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (string) (int) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /**
     * Objects with sorted or unsorted keys as json_encode needs them: stdClass for every object.
     */
    private static function forEncoding(mixed $value): mixed
    {
        if (self::isObject($value)) {
            return (object) array_map(self::forEncoding(...), self::members($value));
        }

        if (is_array($value)) {
            return array_map(self::forEncoding(...), $value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (int) $value;
        }

        return $value;
    }
}
