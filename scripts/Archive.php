<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Scripts;

use RuntimeException;

/**
 * The git archive Composer installs (dist zips are git archives, shaped by .gitattributes export-ignore), checked for
 * release: it carries the verified viewer assets exactly as ui.stamp.json records them, and none of the development
 * files.
 */
final class Archive
{
    /**
     * Paths every distribution needs.
     */
    private const REQUIRED = ['composer.json', 'LICENSE.md', 'README.md', 'config/schema-viewer.php', 'routes/schema-viewer.php', UiPackage::LOCK, UiPackage::STAMP];

    /**
     * Development paths no distribution carries (directories end with a slash).
     */
    private const EXCLUDED = [
        '.github/', '.agents/', '.claude/', '.claude', 'build/', 'scripts/', 'skills/', 'tests/', 'workbench/',
        '.editorconfig', '.gitattributes', '.gitignore', '.laranex-schema-allowlist.json', 'AGENTS.md', 'CLAUDE.md', 'phpstan.neon.dist', 'phpunit.xml.dist',
        'pint.json', 'testbench.yaml',
    ];

    public function __construct(private readonly string $root, private readonly string $ref = 'HEAD') {}

    /**
     * @return list<string>
     */
    public function check(): array
    {
        $files = $this->files();
        $problems = [];

        foreach (self::REQUIRED as $path) {
            if (! isset($files[$path])) {
                $problems[] = "The archive of {$this->ref} lacks {$path}.";
            }
        }

        foreach (array_keys($files) as $path) {
            foreach (self::EXCLUDED as $excluded) {
                if ($path === rtrim($excluded, '/') || (str_ends_with($excluded, '/') && str_starts_with($path, $excluded))) {
                    $problems[] = "The archive of {$this->ref} carries the development file {$path}; export-ignore it in .gitattributes.";
                }
            }
        }

        $stamp = json_decode($files[UiPackage::STAMP] ?? '', true);
        $stamped = is_array($stamp) && is_array($stamp['files'] ?? null) ? $stamp['files'] : [];

        if ($stamped === [] || ($stamp['verified'] ?? false) !== true) {
            $problems[] = "The archive of {$this->ref} has no verified ".UiPackage::STAMP.'.';
        }

        foreach ($stamped as $path => $sha256) {
            if (! isset($files[$path])) {
                $problems[] = "The archive of {$this->ref} lacks the viewer asset {$path}.";
            } elseif (! is_string($sha256) || ! hash_equals($sha256, hash('sha256', $files[$path]))) {
                $problems[] = "The archive of {$this->ref} carries a modified {$path}.";
            }
        }

        foreach (array_keys($files) as $path) {
            if ((str_starts_with($path, UiPackage::VIEWER.'/') || str_starts_with($path, UiPackage::CONTRACT.'/')) && ! isset($stamped[$path])) {
                $problems[] = "The archive of {$this->ref} carries {$path}, which ".UiPackage::STAMP.' does not list.';
            }
        }

        return $problems;
    }

    /**
     * The archive's regular files by path.
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $process = proc_open(['git', '-C', $this->root, 'archive', '--format=tar', $this->ref], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not run git archive.');
        }

        $tar = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('git archive failed: '.trim($error));
        }

        $files = [];
        $offset = 0;
        $long = null;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);

            if ($header === str_repeat("\0", 512)) {
                break;
            }

            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];
            $content = substr($tar, $offset + 512, $size);
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $name = $long ?? ($prefix !== '' ? $prefix.'/' : '').rtrim(substr($header, 0, 100), "\0");
            $long = null;

            if ($type === 'x' && preg_match('/\d+ path=([^\n]*)\n/', $content, $match) === 1) {
                $long = $match[1];
            } elseif ($type === '0' || $type === "\0") {
                $files[$name] = $content;
            }

            $offset += 512 + (int) (ceil($size / 512) * 512);
        }

        return $files;
    }
}
