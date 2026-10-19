<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Contract\Graph;
use Laranex\LaravelSchemaViewer\Contract\Inference;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Migration;
use Laranex\LaravelSchemaViewer\Contract\Validator;

/*
 * The shared laranex-schema 2.0 test suite, read from the verified laranex-schema-viewer-ui tarball: every valid
 * fixture passes strict validation, is in canonical form and stores the links and inferred associations this port
 * builds; every invalid fixture fails with the rule it names; the graph, inference and migration conformance cases
 * give exactly the expected results.
 */

/**
 * The contract files in one directory, by name.
 *
 * @return array<string, string>
 */
function contractCases(string $directory): array
{
    $cases = [];

    foreach (contractFiles() as $path => $content) {
        if (dirname($path) === $directory && str_ends_with($path, '.json')) {
            $cases[basename($path)] = $content;
        }
    }

    ksort($cases);
    expect($cases)->not->toBeEmpty();

    return $cases;
}

it('accepts every valid fixture strictly, in canonical form, with the shared graph and inference', function () {
    $checked = 0;

    foreach (contractCases('fixtures/valid') as $name => $text) {
        $document = Json::decode($text);

        expect(Validator::validate($document, strict: true))->toBe([], $name)
            ->and(Json::canonical($document))->toBe($text, $name);

        $graph = Graph::build($document);

        expect(Json::canonical($graph['links']))->toBe(Json::canonical($document['links']), $name)
            ->and(Json::canonical($graph['derivedLinks']))->toBe(Json::canonical($document['derivedLinks']), $name)
            ->and(Json::canonical(Inference::infer($document)))->toBe(Json::canonical($document['inferredAssociations']), $name);
        $checked++;
    }

    expect($checked)->toBe(14);
});

it('rejects every invalid fixture with the rule it names', function () {
    $checked = 0;

    foreach (contractCases('fixtures/invalid') as $name => $text) {
        $case = Json::decode($text);
        $errors = Validator::validate($case['document'], strict: $case['strict']);

        expect($errors)->not->toBe([], $name);

        foreach ($case['errors'] as $expected) {
            expect(implode("\n", $errors))->toContain($expected);
        }

        $checked++;
    }

    expect($checked)->toBe(53);
});

it('builds the links and derived links of every graph conformance case', function () {
    $checked = 0;

    foreach (contractCases('conformance/graph') as $name => $text) {
        $case = Json::decode($text);

        expect(Json::canonical(Graph::build($case['input'])))->toBe(Json::canonical($case['expected']), $name);
        $checked++;
    }

    expect($checked)->toBe(15);
});

it('infers the associations of every inference conformance case', function () {
    $checked = 0;

    foreach (contractCases('conformance/inference') as $name => $text) {
        $case = Json::decode($text);

        expect(Json::canonical(['inferredAssociations' => Inference::infer($case['input'])]))->toBe(Json::canonical($case['expected']), $name);
        $checked++;
    }

    expect($checked)->toBe(12);
});

it('computes the history and status of every migration conformance case', function () {
    $checked = 0;

    foreach (contractCases('conformance/migrations') as $name => $text) {
        $case = Json::decode($text);
        $history = $case['input']['revisions'] === null ? null : Migration::history($case['input']['revisions']);
        $state = Migration::state($history, $case['input']['current']);

        expect(Json::canonical(['history' => $history, ...$state]))->toBe(Json::canonical($case['expected']), $name);
        $checked++;
    }

    expect($checked)->toBe(14);
});

it('refuses 1.x and other formats with one clear error', function () {
    expect(Validator::validate(['format' => 'laranex-schema', 'version' => '1.0']))->toBe(['Unsupported laranex-schema version "1.0"; this reader supports 2.x'])
        ->and(Validator::validate(['format' => 2, 'document' => ['version' => 1]]))->toBe(['Not a laranex-schema document'])
        ->and(Validator::validate('text'))->toBe(['Not a laranex-schema document']);
});

it('writes canonical JSON as JavaScript does', function () {
    expect(Json::canonical(['b' => [], 'a' => new stdClass, 'é' => 'ü/<', 'c' => ['x' => 1.0, 'y' => [1, 'two']], 'd' => "line\u{2028}\x01"]))
        ->toBe("{\n  \"a\": {},\n  \"b\": [],\n  \"c\": {\n    \"x\": 1,\n    \"y\": [\n      1,\n      \"two\"\n    ]\n  },\n  \"d\": \"line\u{2028}\\u0001\",\n  \"é\": \"ü/<\"\n}\n")
        ->and(Json::compact(['link', 'a/b', [['é', 'x']]]))->toBe('["link","a/b",[["é","x"]]]')
        ->and(Json::canonical(Json::decode('{"0":"a","e":{}}')))->toBe("{\n  \"0\": \"a\",\n  \"e\": {}\n}\n");
});
