<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Scripts;

use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Scan;
use Laranex\LaravelSchemaViewer\Scan\Git;

/**
 * This repository's .laranex-schema-allowlist.json, for maintainers: the synthetic documents it tracks in the
 * directories listed here, by exact path and digest, so php artisan schema-viewer:scan (and CI) allows exactly these
 * and nothing else. Only these directories are listed, never "whatever is detected"; a test keeps the file current.
 * Composer installs none of them (they are export-ignored).
 */
final class SchemaAllowlist
{
    /**
     * Where this repository keeps synthetic documents on purpose.
     */
    public const DIRECTORIES = ['tests/Fixtures', 'workbench'];

    public function __construct(private readonly string $root) {}

    /**
     * The allowlist as it should be, in canonical JSON.
     */
    public function generate(): string
    {
        $entries = [];

        foreach (explode("\0", Git::output($this->root, ['ls-files', '-z', '--', ...self::DIRECTORIES])) as $path) {
            $bytes = $path !== '' && Scan::isCandidate($path, 'tree') ? @file_get_contents($this->root.'/'.$path) : false;
            $match = is_string($bytes) ? Scan::content($path, $bytes) : null;

            if ($match !== null && in_array($match['kind'], ['document', 'legacy', 'embedded'], true)) {
                $entries[] = ['path' => $path, 'sha256' => Scan::digest((string) $bytes)];
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return Json::canonical(['format' => Scan::ALLOWLIST_FORMAT, 'version' => Scan::ALLOWLIST_VERSION, 'entries' => $entries]);
    }

    public function path(): string
    {
        return $this->root.'/'.Scan::DEFAULT_ALLOWLIST;
    }
}
