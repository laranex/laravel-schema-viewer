<?php

declare(strict_types=1);

dataset('skills', [
    'setup' => ['laravel-schema-viewer', [
        '# Laravel Schema Viewer',
        '## When to use',
        '## Install',
        '## Configure',
        '## Use',
        '### Enforce the hints in CI',
        '### Keep the snapshot current in CI',
        '### Share the viewer without the app',
        '### Open it on a server',
        '## Test your app',
        '## Avoid',
    ]],
    'schema' => ['laravel-schema-viewer-schema', [
        '# Laravel Schema Viewer: read the schema',
        '## When to use',
        '## Install',
        '## Use',
        '### Read the snapshot',
        '### Read the database',
        '### Answer questions from the JSON',
        '### Find where the database and the models disagree',
        '### See what changed',
        '### Point a person at a table, relationship or column',
        '## Avoid',
    ]],
]);

it('ships the same agent skill to Laravel Boost and to npx skills', function (string $name): void {
    $root = dirname(__DIR__, 2);

    expect($root."/skills/{$name}/SKILL.md")->toBeFile()
        ->and(file_get_contents($root."/skills/{$name}/SKILL.md"))
        ->toBe(file_get_contents($root."/resources/boost/skills/{$name}/SKILL.md"));
})->with('skills');

it('keeps the usage-only structure', function (string $name, array $expected): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2)."/skills/{$name}/SKILL.md");
    $headings = [];
    $inCode = false;

    foreach (explode("\n", str_replace("\r\n", "\n", $skill)) as $line) {
        if (str_starts_with($line, '```')) {
            $inCode = ! $inCode;
        }

        if (! $inCode && str_starts_with($line, '#')) {
            $headings[] = $line;
        }
    }

    expect($skill)->toStartWith("---\nname: {$name}\n")
        ->and($headings)->toBe($expected);
})->with('skills');
