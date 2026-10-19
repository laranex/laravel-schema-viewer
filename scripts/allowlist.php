<?php

declare(strict_types=1);

/*
 * Maintainer-only: write this repository's .laranex-schema-allowlist.json from the synthetic documents it tracks in
 * SchemaAllowlist::DIRECTORIES (composer allowlist). Review every entry: allowlisting is a decision, not proof that a
 * file is harmless.
 */

use Laranex\LaravelSchemaViewer\Scripts\SchemaAllowlist;

require dirname(__DIR__).'/vendor/autoload.php';

$allowlist = new SchemaAllowlist(dirname(__DIR__));
$text = $allowlist->generate();
file_put_contents($allowlist->path(), $text);
fwrite(STDOUT, sprintf("Wrote %s with %d entries.\n", basename($allowlist->path()), substr_count($text, '"path": ')));
