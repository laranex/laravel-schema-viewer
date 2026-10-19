<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Contract\Scan;
use Laranex\LaravelSchemaViewer\Scripts\SchemaAllowlist;

it('keeps the repository allowlist current: exactly the synthetic documents of its fixture directories', function () {
    $allowlist = new SchemaAllowlist(dirname(__DIR__, 2));
    $text = (string) file_get_contents($allowlist->path());

    expect($text)->toBe($allowlist->generate(), 'Run composer allowlist and review the entries.')
        ->and(Scan::validateAllowlist(json_decode($text, true)))->toBe([]);
});
