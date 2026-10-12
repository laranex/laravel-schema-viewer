<?php

declare(strict_types=1);

it('ships the same agent skill to Laravel Boost and to npx skills', function (): void {
    $root = dirname(__DIR__, 2);

    expect($root.'/skills/laravel-schema-viewer/SKILL.md')->toBeFile()
        ->and(file_get_contents($root.'/skills/laravel-schema-viewer/SKILL.md'))
        ->toBe(file_get_contents($root.'/resources/boost/skills/laravel-schema-viewer/SKILL.md'));
});

it('keeps the usage-only structure', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2).'/skills/laravel-schema-viewer/SKILL.md');
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

    expect($skill)->toStartWith("---\nname: laravel-schema-viewer\n")
        ->and($headings)->toBe([
            '# Laravel Schema Viewer',
            '## When to use',
            '## Install',
            '## Configure',
            '## Use',
            '### Read the schema before changing it',
            '### Share the viewer without the app',
            '### Check the schema and the models agree',
            '### Open the viewer',
            '## Test your app',
            '## Avoid',
        ]);
});
