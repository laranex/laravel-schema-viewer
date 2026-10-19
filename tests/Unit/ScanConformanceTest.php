<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Scan;

/*
 * The shared snapshot-scanning cases (contract/conformance/scan), read from the verified laranex-schema-viewer-ui
 * tarball: resolving each case's allowlist and scanning its files gives exactly the expected laranex-schema-scan
 * report, and every report this port writes is valid against the shared contract/scan-report.schema.json.
 */

it('turns every shared scan case into exactly the expected report', function () {
    $checked = 0;

    foreach (contractFiles() as $path => $text) {
        if (! str_starts_with($path, 'conformance/scan/')) {
            continue;
        }

        $name = basename($path);
        $case = Json::decode($text);
        $input = $case['input'];
        $files = array_map(fn (array $file): array => [
            'scope' => $file['scope'],
            'path' => $file['path'],
            'commit' => $file['commit'] ?? null,
            'bytes' => array_key_exists('contentBase64', $file) ? (string) base64_decode($file['contentBase64'], true) : $file['content'],
        ], $input['files']);
        $allowlists = Json::members($input['allowlists']);
        $allowlist = $input['allowlist'] === null ? null : Scan::resolveAllowlist(
            $input['allowlist'],
            fn (string $file): mixed => array_key_exists($file, $allowlists) ? $allowlists[$file] : throw new RuntimeException("No allowlist {$file}"),
        );

        $report = Scan::files($input['scopes'], $files, $allowlist, [], $input['limit'] ?? Scan::SIZE_LIMIT);

        expect(Json::canonical($report))->toBe(Json::canonical($case['expected']), $name)
            ->and(scanReportProblems(Json::decode(Json::canonical($report))))->toBe([], $name);
        $checked++;
    }

    expect($checked)->toBe(45);
});

it('reads allowlists with the shared allowlist schema', function () {
    $shared = Json::decode(contractFiles()['allowlist.schema.json']);
    unset($shared['$schema'], $shared['$id'], $shared['title']);

    expect(Json::canonical(Scan::ALLOWLIST_SCHEMA))->toBe(Json::canonical($shared));
});

it('reads content as JavaScript does', function (string $path, string $bytes, ?string $reason) {
    expect(Scan::content($path, $bytes)['reason'] ?? null)->toBe($reason);
})->with([
    'a document with an unpaired surrogate escape' => ['a.json', '{"format":"laranex-schema","version":"2.0","note":"\ud800","connections":[]}', 'document'],
    'format 2.0 is the number 2' => ['a.json', '{"format":2.0,"document":{"connections":[]}}', 'laravel_envelope'],
    'an empty object is not an array' => ['a.json', '{"format":"laranex-schema","version":"2.0","connections":{}}', null],
    'a version with a trailing newline' => ['a.json', '{"format":"laranex-schema","version":"2.0\n","connections":[]}', null],
    'invalid UTF-8 in a string' => ['a.json', "{\"format\":\"laranex-schema\",\"version\":\"2.0\",\"note\":\"\xff\",\"connections\":[]}", 'document'],
    'two byte order marks' => ['a.json', "\u{FEFF}\u{FEFF}{\"format\":\"laranex-schema\",\"version\":\"2.0\",\"connections\":[]}", 'document'],
    'a no-break space before the colon' => ['a.html', "<script>{\"format\"\u{A0}: \"laranex-schema\", \"connections\": []}</script>", 'embedded_json'],
    'a manifest contract with non-ASCII text before it' => ['a.html', '{"contract": {"note": "'.str_repeat('é', 150).'", "format": "laranex-schema"}}', null],
    'JavaScript in the tree' => ['a.js', '{"format":"laranex-schema","connections":[]}', null],
    'a dot file has no extension' => ['.json', '{"format":"laranex-schema","version":"2.0","connections":[]}', null],
    'an upper-case extension' => ['A.JSON', '{"format":"laranex-schema","version":"2.0","connections":[]}', 'document'],
]);
