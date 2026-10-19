<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Export\Exporters;

/*
 * The PHP exporters give the same bytes and warnings as the shared viewer's own (laranex-schema-viewer-ui/export,
 * which its Download dialog runs), for every valid shared fixture: every format, each connection and all of them,
 * with and without the ORM declarations.
 */

/**
 * @param  array<string, mixed>  $document
 * @return list<array{string, list<string>|null, bool}>
 */
function exportRequests(array $document): array
{
    $requests = [];

    foreach (array_keys(Exporters::all()) as $format) {
        foreach ([null, ...array_map(fn (array $c): array => [$c['id']], $document['connections'])] as $connections) {
            foreach ([false, true] as $orm) {
                $requests[] = [$format, $connections, $orm];
            }
        }
    }

    return $requests;
}

/**
 * @param  array<string, mixed>  $document
 */
function expectSameExports(array $document, string $name): int
{
    $requests = exportRequests($document);
    $expected = nodeExports(Json::canonical($document), $requests);

    foreach ($requests as $n => [$format, $connections, $orm]) {
        $actual = Exporters::all()[$format]->export($document, $connections, $orm);
        $what = "{$name} {$format} ".json_encode($connections).($orm ? ' orm' : '');

        expect($actual['content'])->toBe($expected[$n]['content'], $what)
            ->and(Json::compact($actual['warnings']))->toBe(Json::compact($expected[$n]['warnings']), $what);
    }

    return count($requests);
}

it('exports every shared fixture exactly as the shared viewer does', function () {
    $checked = 0;

    foreach (contractFiles() as $path => $text) {
        if (str_starts_with($path, 'fixtures/valid/')) {
            $checked += expectSameExports(Json::decode($text), basename($path));
        }
    }

    expect($checked)->toBeGreaterThan(200);
});
