<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Scan;
use Symfony\Component\Process\Process;

/*
 * php artisan schema-viewer:scan on temporary Git repositories (the test application's project root): the scopes,
 * the allowlist statuses, the exit codes and their precedence, and the same report as the shared contract/scan.mjs.
 */

const SNAPSHOT = '{"format":"laranex-schema","version":"2.0","source":"database","generator":{"name":"synthetic","orm":null},"note":"secret_orders_table","connections":[]}';

/**
 * Run the scan from the project root: the exit code, the report it wrote and what it printed.
 *
 * @param  array<string, mixed>  $options
 * @return array{int, array<string, mixed>|null, string}
 */
function scan(array $options): array
{
    $report = sys_get_temp_dir().'/scan-report-'.bin2hex(random_bytes(6)).'.json';
    $cwd = (string) getcwd();
    chdir(app()->basePath());

    try {
        $code = Artisan::call('schema-viewer:scan', [...$options, '--report' => $report]);
    } finally {
        chdir($cwd);
    }

    $text = @file_get_contents($report);
    @unlink($report);
    $decoded = is_string($text) ? Json::decode($text) : null;

    if ($decoded !== null) {
        expect(scanReportProblems($decoded))->toBe([]);
    }

    return [$code, $decoded, Artisan::output()];
}

/**
 * The findings as "scope status reason path" lines.
 *
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function findings(array $report): array
{
    return array_map(fn (array $f): string => "{$f['scope']} {$f['status']} {$f['reason']} {$f['path']}", $report['findings']);
}

it('finds a snapshot that was tracked before it was ignored', function () {
    $root = repository();
    put($root, 'schema-viewer.json', SNAPSHOT);
    commit($root);
    put($root, '.gitignore', "/schema-viewer.json\n");

    [$code, $report, $output] = scan(['--tracked' => true, '--untracked' => true]);

    expect($code)->toBe(1)
        ->and(findings($report))->toBe(['tracked confirmed document schema-viewer.json'])
        ->and($output)->toContain('laranex-schema-scan: 1 files (tracked, untracked); 1 confirmed, 0 tampered, 0 moved, 0 uncertain, 0 incomplete, 0 allowed, 0 stale; exit 1');
});

it('scans the staged bytes, not the working tree', function () {
    $root = repository();
    put($root, 'data.json', SNAPSHOT);
    git($root, 'add', 'data.json');
    put($root, 'data.json', '{"clean":true}');

    expect(findings(scan(['--tracked' => true])[1]))->toBe(['tracked confirmed document data.json']);

    git($root, 'add', 'data.json');
    put($root, 'data.json', SNAPSHOT);

    expect(scan(['--tracked' => true])[0])->toBe(0);
});

it('scans untracked files and leaves ignored ones alone', function () {
    $root = repository();
    put($root, '.gitignore', "ignored/\n");
    put($root, 'ignored/schema.json', SNAPSHOT);
    put($root, 'new/schema.json', SNAPSHOT);
    put($root, 'bundle.js', SNAPSHOT);

    [$code, $report] = scan(['--untracked' => true]);

    expect($code)->toBe(1)->and(findings($report))->toBe(['untracked confirmed document new/schema.json']);
});

it('scans every file of an artifact directory, ignore rules not applied', function () {
    $root = repository();
    put($root, '.gitignore', "dist/\n");
    put($root, 'dist/assets/app.js', 'const d = JSON.parse(\'{"format":"laranex-schema","version":"2.0","connections":[]}\');');
    put($root, 'dist/index.html', '<p>clean</p>');

    [$code, $report] = scan(['--artifacts' => ['dist']]);

    expect($code)->toBe(1)
        ->and(findings($report))->toBe(['artifacts confirmed embedded_json dist/assets/app.js'])
        ->and($report['counts']['files'])->toBe(2)
        ->and(scan(['--artifacts' => ['missing']])[1]['errors'][0]['kind'])->toBe('artifact_missing');
});

it('does not follow a symbolic link to a directory: the scan is incomplete', function () {
    $root = repository();
    put($root, 'outside/x.json', '{}');
    mkdir($root.'/dist');
    symlink($root.'/outside', $root.'/dist/linked');

    [$code, $report] = scan(['--artifacts' => ['dist']]);

    expect($code)->toBe(2)->and(findings($report))->toBe(['artifacts incomplete symlinked_directory dist/linked']);
})->skip(PHP_OS_FAMILY === 'Windows', 'Symbolic links need extra rights on Windows.');

it('fails on a file over the size limit, whatever else it found', function () {
    $root = repository();
    put($root, 'schema.json', SNAPSHOT);
    commit($root);
    $big = fopen($root.'/dist-big.json', 'w');
    ftruncate($big, Scan::SIZE_LIMIT + 1);
    fclose($big);
    mkdir($root.'/build');
    rename($root.'/dist-big.json', $root.'/build/big.json');

    [$code, $report] = scan(['--tracked' => true, '--artifacts' => ['build']]);

    expect($code)->toBe(2)->and(findings($report))->toBe(['artifacts incomplete size_limit build/big.json', 'tracked confirmed document schema.json']);
});

it('scans the history on request, and fails on a shallow clone', function () {
    $root = repository();
    put($root, 'old/schema.json', SNAPSHOT);
    commit($root, 'add');
    $added = trim(git($root, 'rev-parse', 'HEAD'));
    unlink($root.'/old/schema.json');
    commit($root, 'remove');

    expect(scan(['--tracked' => true])[0])->toBe(0);

    [$code, $report] = scan(['--history' => true]);

    expect($code)->toBe(1)
        ->and($report['findings'][0])->toMatchArray(['scope' => 'history', 'status' => 'confirmed', 'path' => 'old/schema.json', 'commit' => $added]);

    $clone = $root.'/shallow';
    git($root, 'clone', '-q', '--depth=1', 'file://'.(str_starts_with($root, '/') ? '' : '/').$root, $clone);
    app()->setBasePath($clone);

    [$code, $report] = scan(['--history' => true]);

    expect($code)->toBe(2)->and($report['errors'][0]['kind'])->toBe('history_unavailable');
});

it('judges detected files against the allowlist: allowed, tampered, moved and stale', function () {
    $root = repository();
    $digest = Scan::digest(SNAPSHOT);
    put($root, 'tests/fixtures/allowed.json', SNAPSHOT);
    put($root, 'tests/fixtures/changed.json', SNAPSHOT.' ');
    put($root, 'tests/fixtures/elsewhere.json', SNAPSHOT);
    put($root, 'tests/fixtures/cleaned.json', '{}');
    put($root, Scan::DEFAULT_ALLOWLIST, Json::canonical(['format' => 'laranex-schema-allowlist', 'version' => '1.0', 'entries' => [
        ['path' => 'tests/fixtures/allowed.json', 'sha256' => $digest],
        ['path' => 'tests/fixtures/changed.json', 'sha256' => $digest],
        ['path' => 'tests/fixtures/cleaned.json', 'sha256' => str_repeat('a', 64)],
        ['path' => 'tests/fixtures/gone.json', 'sha256' => str_repeat('b', 64)],
    ]]));
    commit($root);

    [$code, $report] = scan(['--tracked' => true, '--untracked' => true]);

    expect($code)->toBe(1)->and(findings($report))->toBe([
        'tracked allowed document tests/fixtures/allowed.json',
        'tracked tampered document tests/fixtures/changed.json',
        'allowlist stale not_detected tests/fixtures/cleaned.json',
        'tracked moved document tests/fixtures/elsewhere.json',
        'allowlist stale not_found tests/fixtures/gone.json',
    ]);
});

it('reads a CRLF checkout of an allowed file as allowed', function () {
    $root = repository();
    put($root, 'fixture.json', str_replace(',', ",\n", SNAPSHOT));
    put($root, 'allow.json', Json::canonical(['format' => 'laranex-schema-allowlist', 'version' => '1.0', 'entries' => [
        ['path' => 'fixture.json', 'sha256' => Scan::digest(str_replace(',', ",\r\n", SNAPSHOT))],
    ]]));

    expect(scan(['--untracked' => true, '--allowlist' => 'allow.json'])[0])->toBe(0);
});

it('applies an included allowlist under its root', function () {
    $root = repository();
    put($root, 'vendor-copy/pkg/contract/fixtures/valid/schema.json', SNAPSHOT);
    put($root, 'vendor-copy/pkg/contract/synthetic.json', Json::canonical(['format' => 'laranex-schema-allowlist', 'version' => '1.0', 'entries' => [
        ['path' => 'contract/fixtures/valid/schema.json', 'sha256' => Scan::digest(SNAPSHOT)],
    ]]));
    put($root, Scan::DEFAULT_ALLOWLIST, Json::canonical(['format' => 'laranex-schema-allowlist', 'version' => '1.0', 'entries' => [], 'include' => [
        ['path' => 'vendor-copy/pkg/contract/synthetic.json', 'root' => 'vendor-copy/pkg'],
    ]]));

    [$code, $report] = scan(['--untracked' => true]);

    expect($code)->toBe(0)->and(findings($report))->toBe(['untracked allowed document vendor-copy/pkg/contract/fixtures/valid/schema.json']);
});

it('fails on an invalid or unreadable allowlist', function (string $content, string $message) {
    $root = repository();
    put($root, Scan::DEFAULT_ALLOWLIST, $content);

    [$code, $report] = scan(['--tracked' => true]);

    expect($code)->toBe(2)->and($report['errors'][0])->toBe(['kind' => 'allowlist_invalid', 'message' => $message]);
})->with([
    'an absolute path' => ['{"format":"laranex-schema-allowlist","version":"1.0","entries":[{"path":"/abs.json","sha256":"'.str_repeat('a', 64).'"}]}', 'Allowlist .laranex-schema-allowlist.json: $.entries[0].path: "/abs.json" does not match ^(?!/)(?!.*//)(?!.*/$)(?!(.*/)?\.\.?(/|$))[^\\\\\\u0000]+$'],
    'another version' => ['{"format":"laranex-schema-allowlist","version":"1.1","entries":[]}', 'Allowlist .laranex-schema-allowlist.json: $.version: expected "1.0", got "1.1"'],
    'an unknown property' => ['{"format":"laranex-schema-allowlist","version":"1.0","entries":[],"note":"x"}', 'Allowlist .laranex-schema-allowlist.json: $: unknown property note'],
    'not JSON' => ['{', 'Allowlist .laranex-schema-allowlist.json could not be read as JSON'],
]);

it('fails outside a Git work tree', function () {
    [$code, $report] = scan(['--tracked' => true]);

    expect($code)->toBe(2)->and($report['errors'][0]['kind'])->toBe('not_a_repository')->and($report['scopes'])->toBe([]);
});

it('exits by precedence: incomplete, then confirmed, then uncertain', function () {
    $root = repository();
    put($root, 'fragment.html', '<script>{"format":"laranex-schema"}</script>');

    expect(scan(['--untracked' => true])[0])->toBe(3);

    put($root, 'schema.json', SNAPSHOT);

    expect(scan(['--untracked' => true])[0])->toBe(1);

    put($root, Scan::DEFAULT_ALLOWLIST, '[]');

    expect(scan(['--untracked' => true])[0])->toBe(2);
});

it('needs a scope, and exits 2 on usage errors', function () {
    expect(Artisan::call('schema-viewer:scan'))->toBe(2)
        ->and(Artisan::output())->toContain('name at least one scope')
        ->and(Artisan::call('schema-viewer:scan', ['--tracked' => true, '--report' => '/missing-folder-'.bin2hex(random_bytes(4)).'/r.json']))->toBe(2);
});

it('never prints or reports any of the content', function () {
    $root = repository();
    put($root, 'schema.json', SNAPSHOT);

    [, $report, $output] = scan(['--untracked' => true]);
    $cwd = (string) getcwd();
    chdir($root);
    Artisan::call('schema-viewer:scan', ['--untracked' => true]);
    $summary = Artisan::output();
    Artisan::call('schema-viewer:scan', ['--untracked' => true, '--json' => true]);
    chdir($cwd);

    expect($output.$summary.Json::canonical($report).Artisan::output())->not->toContain('secret_orders_table')
        ->and($summary)->toContain('Paths and reasons are in the report: --report FILE or --json.')->not->toContain('schema.json');
});

it('writes the same report as the shared scan.mjs', function () {
    $node = nodePackage();
    $root = repository();
    put($root, '.gitignore', "/dist/\n/ignored.json\n");
    put($root, 'tracked/schema.json', SNAPSHOT);
    put($root, 'tracked/legacy.json', '{"format":2,"document":{"connections":[]}}');
    put($root, 'tracked/fragment.html', '<script>{"format":"laranex-schema"}</script>');
    put($root, 'tracked/allowed.json', SNAPSHOT."\n");
    put($root, 'tracked/clean.json', '{"format":"laranex-schema-gate"}');
    put($root, 'ignored.json', SNAPSHOT);
    commit($root, 'one');
    unlink($root.'/tracked/schema.json');
    commit($root, 'two');
    put($root, 'untracked/page.html', '<script>window.__SCHEMA_VIEWER__ = {"document":{"format":"laranex-schema","version":"2.0","connections":[]}}</script>');
    put($root, 'dist/app.js', 'x={format:"laranex-schema",connections:[]}');
    put($root, 'dist/app.js.map', '{"sourcesContent":["{\\"format\\":\\"laranex-schema\\",\\"connections\\":[]}"]}');
    put($root, Scan::DEFAULT_ALLOWLIST, Json::canonical(['format' => 'laranex-schema-allowlist', 'version' => '1.0', 'entries' => [
        ['path' => 'tracked/allowed.json', 'sha256' => Scan::digest(SNAPSHOT."\n")],
        ['path' => 'tracked/gone.json', 'sha256' => str_repeat('c', 64)],
        ['path' => 'dist/gone.js', 'sha256' => str_repeat('d', 64)],
    ]]));
    $arguments = ['--tracked', '--untracked', '--history', '--artifacts', 'dist', '--json'];

    $process = new Process(['node', $node.'/contract/scan.mjs', ...$arguments], $root);
    $process->run();
    [$code, $report] = scan(['--tracked' => true, '--untracked' => true, '--history' => true, '--artifacts' => ['dist']]);

    expect($process->getExitCode())->toBe(1)
        ->and($code)->toBe(1)
        ->and(Json::canonical($report))->toBe($process->getOutput())
        ->and(count($report['findings']))->toBeGreaterThan(8);
});
