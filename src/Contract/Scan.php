<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Snapshot scanning, ported from laranex-schema-viewer-ui's src/contract/scan.ts: finds laranex-schema documents (and
 * the legacy formats the shared specification still flags) in file content, judges them against a path and SHA-256
 * allowlist, and writes the laranex-schema-scan 1.0 report. The shared docs/contract.md, "Snapshot scanning", is the
 * normative description; its conformance cases (contract/conformance/scan) check this port. Everything here is pure:
 * Scan\Scanner reads Git and the file system and hands the bytes to files().
 *
 * A file is a list of scope, path, commit (history only) and either its bytes or why they could not be read:
 *
 * @phpstan-type ScanFile array{scope: string, path: string, commit?: string|null, bytes?: string, error?: string}
 * @phpstan-type Entry array{path: string, sha256: string}
 * @phpstan-type Resolved array{entries: list<Entry>, errors: list<string>}
 * @phpstan-type Finding array{scope: string, status: string, kind: string, path: string, commit: string|null, sha256: string|null, reason: string}
 * @phpstan-type Report array{format: string, version: string, exitCode: int, scopes: list<string>, limit: int, counts: array<string, int>, findings: list<Finding>, errors: list<array{kind: string, message: string}>}
 */
final class Scan
{
    public const FORMAT = 'laranex-schema-scan';

    public const VERSION = '1.0';

    public const ALLOWLIST_FORMAT = 'laranex-schema-allowlist';

    public const ALLOWLIST_VERSION = '1.0';

    /**
     * The allowlist read at the scan root when none is named.
     */
    public const DEFAULT_ALLOWLIST = '.laranex-schema-allowlist.json';

    /**
     * Files larger than this are not read; the scan is then incomplete (exit 2).
     */
    public const SIZE_LIMIT = 64 * 1024 * 1024;

    /**
     * The JSON Schema of an allowlist, as the shared contract/allowlist.schema.json has it (a test keeps them equal).
     */
    public const ALLOWLIST_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'format' => ['const' => self::ALLOWLIST_FORMAT],
            'version' => ['const' => self::ALLOWLIST_VERSION],
            'entries' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'pattern' => self::RELATIVE_PATH],
                        'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ],
                    'required' => ['path', 'sha256'],
                    'additionalProperties' => false,
                ],
            ],
            'include' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'pattern' => self::RELATIVE_PATH],
                        'root' => ['type' => 'string', 'pattern' => '^\.$|'.self::RELATIVE_PATH],
                    ],
                    'required' => ['path', 'root'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['format', 'version', 'entries'],
        'additionalProperties' => false,
    ];

    /**
     * A relative POSIX path: no leading slash, no backslash, no empty, "." or ".." segment, no trailing slash. The
     * JavaScript pattern, as the shared schema writes it (Shape reads \uXXXX).
     */
    private const RELATIVE_PATH = '^(?!/)(?!.*//)(?!.*/$)(?!(.*/)?\.\.?(/|$))[^\\\\\\u0000]+$';

    private const DOCUMENT = 'laranex-schema';

    private const EXTENSIONS = [
        'tree' => ['.json', '.html', '.htm'],
        'artifact' => ['.json', '.html', '.htm', '.js', '.mjs', '.cjs', '.map'],
    ];

    /**
     * The deepest a nested document is looked for: the number of keys and indexes from the top-level value to it.
     */
    private const MAX_DEPTH = 6;

    /**
     * JavaScript's \s, which the shared patterns use: its white space and line terminators.
     */
    private const S = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * Up to three backslashes: quotes escaped for JSON inside a JavaScript string inside a source map.
     */
    private const ESC = '\\\\{0,3}';

    private const EXPOSED = ['confirmed', 'tampered', 'moved'];

    /**
     * Tree mode (what a repository holds: tracked, untracked, history) or artifact mode (build output, where
     * JavaScript and source maps are read too).
     */
    public static function mode(string $scope): string
    {
        return $scope === 'artifacts' ? 'artifact' : 'tree';
    }

    /**
     * Whether a file of this name is read at all in this mode.
     */
    public static function isCandidate(string $path, string $mode): bool
    {
        return in_array(self::extension($path), self::EXTENSIONS[$mode], true);
    }

    /**
     * What one file holds, as kind and reason; null when it is clean or not read in this mode. Never returns any of
     * the content.
     *
     * @return array{kind: string, reason: string}|null
     */
    public static function content(string $path, string $bytes, string $mode = 'tree', int $limit = self::SIZE_LIMIT): ?array
    {
        if (! self::isCandidate($path, $mode)) {
            return null;
        }

        if (strlen($bytes) > $limit) {
            return ['kind' => 'too_large', 'reason' => 'size_limit'];
        }

        $text = self::text($bytes);

        if (self::extension($path) === '.json') {
            return self::json($text);
        }

        return str_contains($text, self::DOCUMENT) ? self::markup($text) : null;
    }

    /**
     * The allowlist digest: SHA-256 of the bytes with every CRLF read as LF, so a Windows checkout matches.
     */
    public static function digest(string $bytes): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $bytes));
    }

    /**
     * The problems of an allowlist, read strictly: unknown properties and any other version are errors.
     *
     * @return list<string>
     */
    public static function validateAllowlist(mixed $value): array
    {
        $errors = [];
        Shape::checkAgainst(self::ALLOWLIST_SCHEMA, $value, $errors, true);

        if ($errors === []) {
            $seen = [];

            foreach (Json::members($value)['entries'] as $i => $entry) {
                $entry = Json::members($entry);
                $key = $entry['path']."\0".$entry['sha256'];

                if (isset($seen[$key])) {
                    $errors[] = "\$.entries[{$i}]: {$entry['path']} with this sha256 is listed more than once";
                }

                $seen[$key] = true;
            }
        }

        return $errors;
    }

    /**
     * An allowlist and its includes. $load returns the decoded JSON of the file at a path (POSIX, include paths
     * joined to the including file's directory) and throws when it cannot. Entries of an included file have its root
     * prepended, roots of nested includes compose, and a cycle is an error.
     *
     * @param  callable(string): mixed  $load
     * @return Resolved
     */
    public static function resolveAllowlist(string $path, callable $load): array
    {
        $entries = [];
        $errors = [];

        $visit = function (string $file, string $root, array $chain) use (&$visit, &$entries, &$errors, $load): void {
            if (in_array($file, $chain, true)) {
                $errors[] = "Allowlist {$file} includes itself";

                return;
            }

            try {
                $value = $load($file);
            } catch (Throwable) {
                $errors[] = "Allowlist {$file} could not be read as JSON";

                return;
            }

            $problems = self::validateAllowlist($value);

            if ($problems !== []) {
                array_push($errors, ...array_map(fn (string $e): string => "Allowlist {$file}: {$e}", $problems));

                return;
            }

            $list = Json::members($value);

            foreach ($list['entries'] as $entry) {
                $entry = Json::members($entry);
                $joined = self::join($root, $entry['path']);
                $entries[$joined."\0".$entry['sha256']] = ['path' => $joined, 'sha256' => $entry['sha256']];
            }

            foreach ($list['include'] ?? [] as $include) {
                $include = Json::members($include);
                $visit(self::join(self::dirname($file), $include['path']), self::join($root, $include['root']), [...$chain, $file]);
            }
        };

        $visit($path, '.', []);
        $entries = array_values($entries);
        usort($entries, fn (array $a, array $b): int => self::byList([$a['path'], $a['sha256']], [$b['path'], $b['sha256']]));

        /** @var list<Entry> $entries */
        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * The scan: classifies every file, judges what it found against the allowlist, and reports. $covered says whether
     * the scan covered a path, so an entry naming it that matched nothing is stale even if the file is gone; paths the
     * scan read are always covered, and without it every path is.
     *
     * @param  list<string>  $scopes
     * @param  list<ScanFile>  $files
     * @param  Resolved|null  $allowlist
     * @param  list<array{kind: string, message: string}>  $errors
     * @param  (callable(string): bool)|null  $covered
     * @return Report
     */
    public static function files(array $scopes, array $files, ?array $allowlist = null, array $errors = [], int $limit = self::SIZE_LIMIT, ?callable $covered = null): array
    {
        $entries = $allowlist['entries'] ?? [];

        foreach ($allowlist['errors'] ?? [] as $message) {
            $errors[] = ['kind' => 'allowlist_invalid', 'message' => $message];
        }

        $byPath = [];
        $byHash = [];

        foreach ($entries as $i => $entry) {
            $byPath[$entry['path']][] = $i;
            $byHash[$entry['sha256']] = true;
        }

        $matched = [];
        $examined = [];
        $findings = [];
        $count = 0;

        foreach ($files as $file) {
            // A file that could not be read is always reported; others are read only when their name makes them candidates.
            $error = $file['error'] ?? null;

            if ($error === null && ! self::isCandidate($file['path'], self::mode($file['scope']))) {
                continue;
            }

            $count++;
            $commit = $file['commit'] ?? null;
            $remember = function (string $state) use ($file, &$examined): void {
                if ($file['scope'] !== 'history' && ($examined[$file['path']] ?? null) !== 'judged') {
                    $examined[$file['path']] = $state;
                }
            };

            if ($error !== null || ! isset($file['bytes'])) {
                $reason = $error ?? 'read_error';
                $findings[] = self::finding($file['scope'], 'incomplete', $reason === 'size_limit' ? 'too_large' : 'unreadable', $file['path'], $commit, null, $reason);
                $remember('judged');

                continue;
            }

            try {
                $match = self::content($file['path'], $file['bytes'], self::mode($file['scope']), $limit);
            } catch (RuntimeException) {
                // A pattern the regular expression engine could not finish: the file is not vouched for.
                $findings[] = self::finding($file['scope'], 'incomplete', 'unreadable', $file['path'], $commit, null, 'read_error');
                $remember('judged');

                continue;
            }

            if ($match === null) {
                $remember('clean');

                continue;
            }

            $remember('judged');

            if ($match['kind'] === 'too_large') {
                $findings[] = self::finding($file['scope'], 'incomplete', 'too_large', $file['path'], $commit, null, 'size_limit');

                continue;
            }

            $digest = self::digest($file['bytes']);
            $listed = $byPath[$file['path']] ?? [];
            $allowed = null;

            foreach ($listed as $i) {
                if ($entries[$i]['sha256'] === $digest) {
                    $allowed = $i;

                    break;
                }
            }

            if ($allowed !== null) {
                $matched[$allowed] = true;
            }

            $status = match (true) {
                $allowed !== null => 'allowed',
                $listed !== [] => 'tampered',
                isset($byHash[$digest]) => 'moved',
                $match['kind'] === 'uncertain' => 'uncertain',
                default => 'confirmed',
            };
            $findings[] = self::finding($file['scope'], $status, $match['kind'], $file['path'], $commit, $digest, $match['reason']);
        }

        foreach ($entries as $i => $entry) {
            if (isset($matched[$i]) || array_filter($byPath[$entry['path']], fn (int $j): bool => isset($matched[$j])) !== []) {
                continue;
            }

            $state = $examined[$entry['path']] ?? null;

            if ($state === 'clean' || ($state === null && ($covered === null || $covered($entry['path'])))) {
                $findings[] = self::finding('allowlist', 'stale', 'allowlist_entry', $entry['path'], null, $entry['sha256'], $state === 'clean' ? 'not_detected' : 'not_found');
            }
        }

        usort($findings, fn (array $a, array $b): int => self::byList(self::findingOrder($a), self::findingOrder($b)));

        $unique = [];

        foreach ($errors as $error) {
            $unique[$error['kind']."\0".$error['message']] = ['kind' => $error['kind'], 'message' => $error['message']];
        }

        $errors = array_values($unique);
        usort($errors, fn (array $a, array $b): int => self::byList([$a['kind'], $a['message']], [$b['kind'], $b['message']]));

        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);
        $by = fn (string $status): int => count(array_filter($findings, fn (array $f): bool => $f['status'] === $status));

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exitCode' => self::exitCode($findings, $errors),
            'scopes' => $scopes,
            'limit' => $limit,
            'counts' => [
                'files' => $count, 'confirmed' => $by('confirmed'), 'tampered' => $by('tampered'), 'moved' => $by('moved'),
                'uncertain' => $by('uncertain'), 'allowed' => $by('allowed'), 'incomplete' => $by('incomplete'), 'stale' => $by('stale'),
            ],
            'findings' => $findings,
            'errors' => $errors,
        ];
    }

    /**
     * 0 clean, 1 confirmed exposure, 2 scan failure or incomplete scan, 3 uncertain exposure; 2 > 1 > 3 > 0.
     *
     * @param  list<array{status: string}>  $findings
     * @param  list<mixed>  $errors
     */
    public static function exitCode(array $findings, array $errors): int
    {
        $statuses = array_column($findings, 'status');

        return match (true) {
            $errors !== [] || in_array('incomplete', $statuses, true) => 2,
            array_intersect($statuses, self::EXPOSED) !== [] => 1,
            in_array('uncertain', $statuses, true) => 3,
            default => 0,
        };
    }

    /**
     * The order findings are sorted in: path, commit (null as empty), scope, status and reason.
     *
     * @param  array{path: string, commit: string|null, scope: string, status: string, reason: string}  $finding
     * @return list<string>
     */
    public static function findingOrder(array $finding): array
    {
        return [$finding['path'], $finding['commit'] ?? '', $finding['scope'], $finding['status'], $finding['reason']];
    }

    /**
     * Element-wise code point order of string lists (byte order of UTF-8); a prefix sorts first.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function byList(array $a, array $b): int
    {
        for ($i = 0; $i < min(count($a), count($b)); $i++) {
            $d = strcmp($a[$i], $b[$i]);

            if ($d !== 0) {
                return $d <=> 0;
            }
        }

        return count($a) <=> count($b);
    }

    /**
     * @return Finding
     */
    private static function finding(string $scope, string $status, string $kind, string $path, ?string $commit, ?string $sha256, string $reason): array
    {
        return ['scope' => $scope, 'status' => $status, 'kind' => $kind, 'path' => $path, 'commit' => $commit, 'sha256' => $sha256, 'reason' => $reason];
    }

    private static function extension(string $path): string
    {
        $name = strtolower(substr($path, (int) strrpos('/'.$path, '/')));
        $dot = strrpos($name, '.');

        return $dot === false || $dot === 0 ? '' : substr($name, $dot);
    }

    /**
     * The content as UTF-8, as JavaScript's TextDecoder reads it (invalid sequences replaced, its leading byte order
     * mark dropped), with the one byte order mark the scanner drops after it.
     */
    private static function text(string $bytes): string
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $substitute = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $bytes = (string) mb_scrub($bytes, 'UTF-8');
            mb_substitute_character($substitute);
        }

        for ($i = 0; $i < 2 && str_starts_with($bytes, "\u{FEFF}"); $i++) {
            $bytes = substr($bytes, 3);
        }

        return $bytes;
    }

    /**
     * @return array{kind: string, reason: string}|null
     */
    private static function json(string $text): ?array
    {
        try {
            $value = self::decode($text);
        } catch (JsonException) {
            return self::formatHit($text) !== null || (self::matches(self::connections(), $text) && self::matches('/"(derivedLinks|comparisons|inferredAssociations|relationships|models)"'.self::S.'*:/u', $text))
                ? ['kind' => 'uncertain', 'reason' => 'unparseable_json']
                : null;
        }

        if (self::isDocument($value)) {
            return ['kind' => 'document', 'reason' => 'document'];
        }

        $legacy = self::legacy($value);

        if ($legacy !== null) {
            return ['kind' => 'legacy', 'reason' => $legacy];
        }

        $inner = self::nested($value);

        return $inner !== null ? ['kind' => 'embedded', 'reason' => $inner] : null;
    }

    /**
     * JSON.parse: objects as stdClass, arrays as lists. JavaScript also reads an unpaired surrogate escape, which PHP
     * refuses; no rule looks inside such a string, so it is read as U+FFFD.
     *
     * @throws JsonException
     */
    private static function decode(string $text): mixed
    {
        try {
            return json_decode($text, false, 2147483647, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_UTF16) {
                throw $e;
            }

            $text = (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u[dD][89a-fA-F][0-9a-fA-F]{2}/', '$1\\\\uFFFD', $text);

            return json_decode($text, false, 2147483647, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return array{kind: string, reason: string}|null
     */
    private static function markup(string $text): ?array
    {
        $hit = self::formatHit($text);

        if ($hit === null) {
            return null;
        }

        if (! self::matches(self::connections(), $text)) {
            return ['kind' => 'uncertain', 'reason' => 'missing_connections'];
        }

        $reason = match (true) {
            self::matches('/window\.__SCHEMA_VIEWER__'.self::S.'*='.self::S.'*\{/u', $text) => 'viewer_global',
            $hit === 'quoted' => 'embedded_json',
            default => 'object_literal',
        };

        return ['kind' => 'embedded', 'reason' => $reason];
    }

    private static function isDocument(mixed $value): bool
    {
        return $value instanceof stdClass
            && ($value->format ?? null) === self::DOCUMENT
            && is_string($value->version ?? null) && preg_match('/^[0-9]+\.[0-9]+\z/', $value->version) === 1
            && is_array($value->connections ?? null);
    }

    /**
     * Temporary in the shared specification, until every producer has migrated: Laravel's envelope (format 2 with
     * document.connections) and the Python and Node.js v1 viewer format with its tables.
     */
    private static function legacy(mixed $value): ?string
    {
        if (! $value instanceof stdClass) {
            return null;
        }

        $format = $value->format ?? null;

        if ((is_int($format) || is_float($format)) && $format == 2 && ($value->document ?? null) instanceof stdClass && is_array($value->document->connections ?? null)) {
            return 'laravel_envelope';
        }

        return $format === self::DOCUMENT.'-viewer' && is_array($value->tables ?? null) ? 'viewer_v1' : null;
    }

    /**
     * A nested document or legacy snapshot at depth 1 to MAX_DEPTH; a document wins over a legacy one, and a legacy
     * one is not searched further.
     */
    private static function nested(mixed $value): ?string
    {
        $legacy = false;
        $stack = [[$value, 0]];

        while ($stack !== []) {
            [$v, $depth] = array_pop($stack);

            if ($depth > 0 && self::isDocument($v)) {
                return 'nested_document';
            }

            if ($depth > 0 && self::legacy($v) !== null) {
                $legacy = true;

                continue;
            }

            if ($depth < self::MAX_DEPTH && (is_array($v) || $v instanceof stdClass)) {
                foreach (is_array($v) ? $v : get_object_vars($v) as $child) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }

        return $legacy ? 'nested_legacy' : null;
    }

    /**
     * The first format pair in the text that is not a viewer manifest's contract, as quoted or literal. The value
     * must be exactly the format, so laranex-schema-gate and a JSON Schema's "format": {"const": …} never match.
     */
    private static function formatHit(string $text): ?string
    {
        $q = self::ESC.'["\']';
        $patterns = [
            'quoted' => '/'.self::ESC.'"format'.self::ESC.'"'.self::S.'*:'.self::S.'*'.self::ESC.'"'.self::DOCUMENT.self::ESC.'"/u',
            'literal' => '/[{,]'.self::S.'*format'.self::S.'*:'.self::S.'*'.$q.self::DOCUMENT.$q.self::S.'*[,}]/u',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException(preg_last_error_msg());
            }

            foreach ($matches[0] as [$match, $offset]) {
                $at = $offset + (int) strpos($match, 'format');

                if (! self::matches('/contract'.self::ESC.'["\']?'.self::S.'*:'.self::S.'*\{[^{}]*\z/u', self::before($text, $at))) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * The 200 UTF-16 code units before a byte offset, as JavaScript's text.slice(at - 200, at) has them.
     */
    private static function before(string $text, int $at): string
    {
        $start = max(0, $at - 800);
        $chunk = substr($text, $start, $at - $start);

        if (preg_match('/[\x80-\xFF]/', $chunk) !== 1) {
            return substr($chunk, -200);
        }

        $units = (string) mb_convert_encoding($chunk, 'UTF-16BE', 'UTF-8');

        return (string) mb_convert_encoding(substr($units, -400), 'UTF-8', 'UTF-16BE');
    }

    private static function connections(): string
    {
        return '/'.self::ESC.'["\']?connections'.self::ESC.'["\']?'.self::S.'*:'.self::S.'*\[/u';
    }

    private static function matches(string $pattern, string $text): bool
    {
        $result = preg_match($pattern, $text);

        if ($result === false) {
            throw new RuntimeException(preg_last_error_msg());
        }

        return $result === 1;
    }

    private static function dirname(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    private static function join(string $dir, string $path): string
    {
        return match (true) {
            $dir === '' || $dir === '.' => $path,
            $path === '.' => $dir,
            default => $dir.'/'.$path,
        };
    }
}
