<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Viewer;

use RuntimeException;

/**
 * The shared viewer's files (laranex-schema-viewer-ui's dist/viewer), shipped in resources/viewer and verified against
 * the npm tarball ui.lock.json pins when the package is released. Only the files its manifest lists are ever read or
 * served, and only when their size is the manifest's.
 */
final class ViewerAssets
{
    /**
     * @var array{entry: array<string, string>, files: array<string, array{size: int, sha256: string}>, version: string}|null
     */
    private ?array $manifest = null;

    public function __construct(private readonly string $root = __DIR__.'/../../resources/viewer') {}

    /**
     * The file a manifest entry names: script, style, page or offline.
     */
    public function entry(string $role): string
    {
        return $this->manifest()['entry'][$role] ?? throw new RuntimeException("The viewer manifest has no {$role} entry.");
    }

    /**
     * Whether the manifest lists the file.
     */
    public function has(string $file): bool
    {
        return isset($this->manifest()['files'][$file]);
    }

    /**
     * A short fingerprint of the bundle, which asset URLs carry so every file can be cached for good.
     */
    public function version(): string
    {
        return $this->manifest()['version'];
    }

    /**
     * The SHA-256 the manifest gives a file.
     */
    public function sha256(string $file): string
    {
        return $this->manifest()['files'][$file]['sha256'] ?? throw new RuntimeException("The viewer manifest does not list [{$file}].");
    }

    /**
     * The absolute path of a listed file whose size is the manifest's, or null.
     */
    public function path(string $file): ?string
    {
        $entry = $this->manifest()['files'][$file] ?? null;
        $path = $this->root.'/'.$file;

        return $entry !== null && is_file($path) && filesize($path) === $entry['size'] ? $path : null;
    }

    public function contents(string $file): string
    {
        $path = $this->path($file);
        $contents = $path !== null ? @file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException("The viewer asset [{$file}] is missing from [{$this->root}] or does not match its manifest; reinstall laranex/laravel-schema-viewer.");
        }

        return $contents;
    }

    /**
     * @return array{entry: array<string, string>, files: array<string, array{size: int, sha256: string}>, version: string}
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $text = @file_get_contents($this->root.'/manifest.json');
        $manifest = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($manifest) || ($manifest['contract']['major'] ?? null) !== 2 || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['entry'] ?? null)) {
            throw new RuntimeException("The viewer manifest at [{$this->root}/manifest.json] is missing or not for laranex-schema 2.x; reinstall laranex/laravel-schema-viewer.");
        }

        $files = [];

        foreach ($manifest['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && is_int($file['size'] ?? null) && is_string($file['sha256'] ?? null)
                && preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $file['path']) === 1 && ! in_array('..', explode('/', $file['path']), true)) {
                $files[$file['path']] = ['size' => $file['size'], 'sha256' => $file['sha256']];
            }
        }

        return $this->manifest = [
            'entry' => array_filter($manifest['entry'], 'is_string'),
            'files' => $files,
            'version' => substr(hash('sha256', $text), 0, 12),
        ];
    }
}
