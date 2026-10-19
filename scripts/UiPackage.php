<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Scripts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The shared laranex-schema-viewer-ui npm package that ui.lock.json pins, for maintainers only: composer ui:fetch and
 * composer ui:check use it, and the tests read the contract fixtures from the cached tarball through it. Nothing here
 * runs on composer install or in a Laravel application.
 *
 * The tarball's SHA-512 is checked against the lock before anything is read from it. Its members are read into
 * memory, never extracted: only regular files under package/ with plain relative names are accepted, within size
 * limits. The viewer manifest must describe laranex-schema 2.x and match every file it lists. The verified runtime
 * files are then installed under resources/ (committed, since Composer distributions are git archives) and recorded,
 * with their SHA-256, in ui.stamp.json.
 */
final class UiPackage
{
    public const LOCK = 'ui.lock.json';

    public const STAMP = 'ui.stamp.json';

    /**
     * The installed locations, which hold nothing but the files the stamp lists.
     */
    public const VIEWER = 'resources/viewer';

    public const CONTRACT = 'resources/contract';

    /**
     * Where fetch keeps the verified tarball, git-ignored, for the contract tests.
     */
    public const CACHE = 'build/ui';

    public const CONTRACT_MAJOR = 2;

    private const REGISTRY = 'https://registry.npmjs.org/';

    private const MAX_TARBALL = 32 * 1024 * 1024;

    private const MAX_UNPACKED = 128 * 1024 * 1024;

    private const MAX_MEMBERS = 4096;

    /**
     * Contract directories the tests read; each must hold cases.
     */
    public const CONTRACT_DIRECTORIES = [
        'fixtures/valid', 'fixtures/invalid', 'conformance/graph', 'conformance/inference', 'conformance/migrations', 'conformance/scan',
    ];

    /**
     * Verified tarballs read in this process, by path, so the tests unpack each once.
     *
     * @var array<string, array<string, string>>
     */
    private static array $read = [];

    public function __construct(private readonly string $root) {}

    /**
     * Install the locked package: downloaded from the registry, or a local tarball. A local tarball that does not match
     * the lock is installed only when $allowUnverified, and is then stamped unverified, which ui:check refuses.
     *
     * @return array{name: string, version: string, integrity: string, source: string, verified: bool, files: array<string, string>}
     */
    public function fetch(?string $tarball = null, bool $allowUnverified = false): array
    {
        $lock = $this->lock();
        $data = $tarball !== null ? $this->readLocal($tarball) : $this->download($lock['tarball']);
        $actual = self::integrity($data);
        $verified = hash_equals($lock['integrity'], $actual);

        if (! $verified && ! ($tarball !== null && $allowUnverified)) {
            throw new RuntimeException(sprintf(
                'The tarball\'s integrity %s does not match %s (%s)%s.',
                $actual, self::LOCK, $lock['integrity'], $tarball !== null ? '; pass --allow-unverified to install a local build for development' : '',
            ));
        }

        $files = $this->plan(self::members($data), $lock);

        $stamp = [
            'name' => $lock['name'],
            'version' => $lock['version'],
            'integrity' => $actual,
            'source' => $tarball !== null ? 'file:'.basename($tarball) : $lock['tarball'],
            'verified' => $verified,
            'files' => [],
        ];

        $this->install($files, $stamp);

        if ($verified) {
            $this->write($this->cachePath($lock), $data);
        }

        return $this->stamp();
    }

    /**
     * Check the installed files against the stamp, the stamp against the lock, and the files against the viewer
     * manifest; with $registry, also download the locked tarball and check every installed file against it. Returns
     * the problems, none when everything is verified.
     *
     * @return list<string>
     */
    public function check(bool $registry = false): array
    {
        try {
            $lock = $this->lock();
            $stamp = $this->stamp();
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        $problems = [];

        foreach (['name', 'version', 'integrity'] as $field) {
            if ($stamp[$field] !== $lock[$field]) {
                $problems[] = sprintf('%s %s is %s, %s pins %s.', self::STAMP, $field, $stamp[$field], self::LOCK, $lock[$field]);
            }
        }

        // The integrity is the proof: a verified local copy of the locked tarball is byte for byte the registry's.
        if (! $stamp['verified']) {
            $problems[] = sprintf('%s records unverified assets; run composer ui:fetch.', self::STAMP);
        }

        foreach ($stamp['files'] as $path => $sha256) {
            $file = $this->root.'/'.$path;

            if (! is_file($file)) {
                $problems[] = "Missing asset {$path}.";
            } elseif (! hash_equals($sha256, (string) hash_file('sha256', $file))) {
                $problems[] = "Modified asset {$path}: its SHA-256 differs from ".self::STAMP.'.';
            }
        }

        foreach ([self::VIEWER, self::CONTRACT] as $directory) {
            foreach ($this->filesIn($directory) as $path) {
                if (! isset($stamp['files'][$path])) {
                    $problems[] = "Unverified file {$path}: ".self::STAMP.' does not list it.';
                }
            }
        }

        if ($problems === []) {
            $problems = $this->checkManifest($lock, $stamp['files']);
        }

        if ($problems === [] && $registry) {
            $problems = $this->checkRegistry($lock, $stamp['files']);
        }

        return $problems;
    }

    /**
     * The contract files (paths below contract/, such as fixtures/valid/x.json) of the cached tarball, after checking
     * it against the lock; null when composer ui:fetch has not cached it yet.
     *
     * @return array<string, string>|null
     */
    public function contract(): ?array
    {
        $lock = $this->lock();
        $path = $this->cachePath($lock);

        if (! is_file($path)) {
            return null;
        }

        if (! isset(self::$read[$path])) {
            $data = $this->readLocal($path);

            if (! hash_equals($lock['integrity'], self::integrity($data))) {
                throw new RuntimeException("The cached tarball {$path} does not match ".self::LOCK.'; run composer ui:fetch.');
            }

            $contract = [];

            foreach (self::members($data) as $name => $content) {
                if (str_starts_with($name, 'contract/')) {
                    $contract[substr($name, strlen('contract/'))] = $content;
                }
            }

            self::$read[$path] = $contract;
        }

        return self::$read[$path];
    }

    /**
     * Where composer ui:fetch caches the locked tarball (it may not exist yet).
     */
    public function cached(): string
    {
        return $this->cachePath($this->lock());
    }

    /**
     * The npm integrity string of some bytes.
     */
    public static function integrity(string $data): string
    {
        return 'sha512-'.base64_encode(hash('sha512', $data, true));
    }

    /**
     * The members of a gzipped npm tarball, by their path below package/. Only regular files with plain relative
     * names are accepted; links, directories, extended headers and every other member type are refused.
     *
     * @return array<string, string>
     */
    public static function members(string $data): array
    {
        if (strlen($data) > self::MAX_TARBALL) {
            throw new RuntimeException('The tarball is larger than '.self::MAX_TARBALL.' bytes.');
        }

        $tar = @gzdecode($data, self::MAX_UNPACKED + 1);

        if (! is_string($tar)) {
            throw new RuntimeException('The tarball is not gzip data, or unpacks to more than '.self::MAX_UNPACKED.' bytes.');
        }

        if (strlen($tar) > self::MAX_UNPACKED) {
            throw new RuntimeException('The tarball unpacks to more than '.self::MAX_UNPACKED.' bytes.');
        }

        $members = [];
        $offset = 0;
        $length = strlen($tar);

        while ($offset + 512 <= $length) {
            $header = substr($tar, $offset, 512);

            if ($header === str_repeat("\0", 512)) {
                return $members;
            }

            $sum = 0;

            for ($i = 0; $i < 512; $i++) {
                $sum += $i >= 148 && $i < 156 ? 32 : ord($header[$i]);
            }

            $field = fn (int $start, int $size): string => rtrim(substr($header, $start, $size), "\0");
            $octal = fn (int $start, int $size): ?int => preg_match('/^\s*([0-7]+)[\s\0]*$/', substr($header, $start, $size), $m) === 1 ? (int) octdec($m[1]) : null;

            if ($octal(148, 8) !== $sum || ! str_starts_with(substr($header, 257, 6), 'ustar')) {
                throw new RuntimeException('The tarball has a damaged or non-ustar header at byte '.$offset.'.');
            }

            $prefix = $field(345, 155);
            $name = ($prefix !== '' ? $prefix.'/' : '').$field(0, 100);
            $type = $header[156];
            $size = $octal(124, 12);
            $parts = explode('/', $name);

            if (($type !== '0' && $type !== "\0") || $size === null || str_contains($name, '\\') || str_contains($name, "\0")
                || count($parts) < 2 || $parts[0] !== 'package' || array_intersect($parts, ['', '.', '..']) !== []) {
                throw new RuntimeException('Unsafe tarball member: '.json_encode($name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).'.');
            }

            $path = implode('/', array_slice($parts, 1));

            if (isset($members[$path])) {
                throw new RuntimeException("Duplicate tarball member: {$name}.");
            }

            if ($offset + 512 + $size > $length) {
                throw new RuntimeException("The tarball is truncated in {$name}.");
            }

            if (count($members) >= self::MAX_MEMBERS) {
                throw new RuntimeException('The tarball has more than '.self::MAX_MEMBERS.' members.');
            }

            $members[$path] = substr($tar, $offset + 512, $size);
            $offset += 512 + (int) (ceil($size / 512) * 512);
        }

        throw new RuntimeException('The tarball ends without its end-of-archive marker.');
    }

    /**
     * Installed path (relative to the repository) => content, after checking the package's structure.
     *
     * @param  array<string, string>  $members
     * @param  array{name: string, version: string, tarball: string, integrity: string}  $lock
     * @return array<string, string>
     */
    private function plan(array $members, array $lock): array
    {
        $package = json_decode($members['package.json'] ?? '', true);
        $manifest = json_decode($members['dist/viewer/manifest.json'] ?? '', true);

        if (! is_array($package) || ! is_array($manifest)) {
            throw new RuntimeException('Not a laranex-schema-viewer-ui package: package.json or dist/viewer/manifest.json is missing.');
        }

        if (($package['name'] ?? null) !== $lock['name'] || ($package['version'] ?? null) !== $lock['version']) {
            throw new RuntimeException(sprintf('The tarball is %s %s; %s pins %s %s.', json_encode($package['name'] ?? null), json_encode($package['version'] ?? null), self::LOCK, $lock['name'], $lock['version']));
        }

        $files = [self::VIEWER.'/manifest.json' => $members['dist/viewer/manifest.json']];

        foreach ($this->manifestEntries($manifest, $lock) as $entry) {
            $content = $members['dist/viewer/'.$entry['path']] ?? null;

            if ($content === null) {
                throw new RuntimeException("The viewer manifest lists a file the package does not have: {$entry['path']}.");
            }

            if (strlen($content) !== $entry['size'] || ! hash_equals($entry['sha256'], hash('sha256', $content))) {
                throw new RuntimeException("dist/viewer/{$entry['path']} does not match the viewer manifest.");
            }

            $files[self::VIEWER.'/'.$entry['path']] = $content;
        }

        foreach (['LICENSE', 'NOTICE'] as $name) {
            $files[self::VIEWER.'/'.$name] = $members[$name] ?? throw new RuntimeException("The package has no {$name}.");
        }

        $schema = $members['contract/schema.json'] ?? throw new RuntimeException('The package has no contract/schema.json.');
        $decoded = json_decode($schema, true);

        if (! is_array($decoded) || ($decoded['properties']['format']['const'] ?? null) !== 'laranex-schema') {
            throw new RuntimeException('contract/schema.json is not the laranex-schema JSON Schema.');
        }

        $files[self::CONTRACT.'/schema.json'] = $schema;

        foreach (['contract/check.mjs', 'docs/contract.md'] as $required) {
            if (! isset($members[$required])) {
                throw new RuntimeException("The package has no {$required}.");
            }
        }

        foreach (self::CONTRACT_DIRECTORIES as $directory) {
            if (preg_grep('#^contract/'.preg_quote($directory, '#').'/[^/]+\.json$#', array_keys($members)) === []) {
                throw new RuntimeException("The package has no contract/{$directory} cases.");
            }
        }

        return $files;
    }

    /**
     * The manifest's file entries, after checking it describes the locked package for laranex-schema 2.x and lists
     * only plain relative paths.
     *
     * @param  array<mixed>  $manifest
     * @param  array{name: string, version: string, tarball: string, integrity: string}  $lock
     * @return list<array{path: string, size: int, sha256: string}>
     */
    private function manifestEntries(array $manifest, array $lock): array
    {
        $contract = is_array($manifest['contract'] ?? null) ? $manifest['contract'] : [];

        if (($manifest['name'] ?? null) !== $lock['name'] || ($manifest['version'] ?? null) !== $lock['version']
            || ($contract['format'] ?? null) !== 'laranex-schema' || ($contract['major'] ?? null) !== self::CONTRACT_MAJOR) {
            throw new RuntimeException(sprintf('The viewer manifest does not describe %s %s for laranex-schema %d.x.', $lock['name'], $lock['version'], self::CONTRACT_MAJOR));
        }

        $entry = is_array($manifest['entry'] ?? null) ? $manifest['entry'] : [];
        $entries = [];

        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;

            if (! is_string($path) || preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $path) !== 1 || in_array('..', explode('/', $path), true)
                || ! is_int($file['size'] ?? null) || ! is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1) {
                throw new RuntimeException('The viewer manifest lists an unsafe or malformed file: '.json_encode($file, JSON_UNESCAPED_SLASHES).'.');
            }

            $entries[$path] = ['path' => $path, 'size' => $file['size'], 'sha256' => $file['sha256']];
        }

        foreach (['script', 'style', 'page', 'offline'] as $role) {
            if (! is_string($entry[$role] ?? null) || ! isset($entries[$entry[$role]])) {
                throw new RuntimeException("The viewer manifest's {$role} entry is not one of its files.");
            }
        }

        foreach (['favicon.svg', 'favicon.ico'] as $required) {
            if (! isset($entries[$required])) {
                throw new RuntimeException("The viewer manifest does not list {$required}.");
            }
        }

        return array_values($entries);
    }

    /**
     * @param  array{name: string, version: string, tarball: string, integrity: string}  $lock
     * @param  array<string, string>  $stamped
     * @return list<string>
     */
    private function checkManifest(array $lock, array $stamped): array
    {
        $manifest = json_decode((string) @file_get_contents($this->root.'/'.self::VIEWER.'/manifest.json'), true);

        try {
            $entries = $this->manifestEntries(is_array($manifest) ? $manifest : [], $lock);
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        $expected = [self::VIEWER.'/manifest.json', self::VIEWER.'/LICENSE', self::VIEWER.'/NOTICE', self::CONTRACT.'/schema.json'];

        foreach ($entries as $entry) {
            $path = self::VIEWER.'/'.$entry['path'];
            $expected[] = $path;

            if (! is_file($this->root.'/'.$path) || filesize($this->root.'/'.$path) !== $entry['size'] || ($stamped[$path] ?? null) !== $entry['sha256']) {
                $problems[] = "{$path} does not match the viewer manifest.";
            }
        }

        foreach (array_diff($expected, array_keys($stamped)) as $path) {
            $problems[] = "Missing asset {$path}: ".self::STAMP.' does not list it.';
        }

        foreach (array_diff(array_keys($stamped), $expected) as $path) {
            $problems[] = "Unexpected asset {$path}: the package does not ship it.";
        }

        return $problems;
    }

    /**
     * @param  array{name: string, version: string, tarball: string, integrity: string}  $lock
     * @param  array<string, string>  $stamped
     * @return list<string>
     */
    private function checkRegistry(array $lock, array $stamped): array
    {
        $data = $this->download($lock['tarball']);

        if (! hash_equals($lock['integrity'], self::integrity($data))) {
            return ['The registry tarball\'s integrity '.self::integrity($data).' does not match '.self::LOCK.'.'];
        }

        $problems = [];
        $planned = $this->plan(self::members($data), $lock);

        foreach ($planned as $path => $content) {
            if (($stamped[$path] ?? null) !== hash('sha256', $content)) {
                $problems[] = "{$path} differs from the registry tarball.";
            }
        }

        foreach (array_diff_key($stamped, $planned) as $path => $sha256) {
            $problems[] = "{$path} is not in the registry tarball.";
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array{name: string, version: string, integrity: string, source: string, verified: bool, files: array<string, string>}  $stamp
     */
    private function install(array $files, array $stamp): void
    {
        // A partial install is never stamped.
        @unlink($this->root.'/'.self::STAMP);

        foreach ([self::VIEWER, self::CONTRACT] as $directory) {
            foreach ($this->filesIn($directory) as $path) {
                unlink($this->root.'/'.$path);
            }
        }

        ksort($files, SORT_STRING);

        foreach ($files as $path => $content) {
            $this->write($this->root.'/'.$path, $content);
            $stamp['files'][$path] = hash('sha256', $content);
        }

        $this->write($this->root.'/'.self::STAMP, json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array{name: string, version: string, tarball: string, integrity: string}
     */
    private function lock(): array
    {
        $lock = json_decode((string) @file_get_contents($this->root.'/'.self::LOCK), true);

        if (! is_array($lock) || array_filter(['name', 'version', 'tarball', 'integrity'], fn (string $key): bool => ! is_string($lock[$key] ?? null)) !== []) {
            throw new RuntimeException(self::LOCK.' is missing or lacks name, version, tarball and integrity.');
        }

        if (! str_starts_with($lock['tarball'], self::REGISTRY) || preg_match('/^sha512-[A-Za-z0-9+\/]{86}==$/', $lock['integrity']) !== 1) {
            throw new RuntimeException(self::LOCK.' must pin a tarball on '.self::REGISTRY.' with a sha512 integrity.');
        }

        /** @var array{name: string, version: string, tarball: string, integrity: string} $lock */
        return $lock;
    }

    /**
     * @return array{name: string, version: string, integrity: string, source: string, verified: bool, files: array<string, string>}
     */
    private function stamp(): array
    {
        $stamp = json_decode((string) @file_get_contents($this->root.'/'.self::STAMP), true);

        if (! is_array($stamp) || ! is_array($stamp['files'] ?? null) || ! is_bool($stamp['verified'] ?? null)
            || array_filter(['name', 'version', 'integrity', 'source'], fn (string $key): bool => ! is_string($stamp[$key] ?? null)) !== []
            || array_filter($stamp['files'], fn (mixed $hash, mixed $path): bool => ! is_string($path) || ! is_string($hash), ARRAY_FILTER_USE_BOTH) !== []) {
            throw new RuntimeException(self::STAMP.' is missing or malformed; run composer ui:fetch.');
        }

        /** @var array{name: string, version: string, integrity: string, source: string, verified: bool, files: array<string, string>} $stamp */
        return $stamp;
    }

    /**
     * Every file under a directory of the repository, as repository paths.
     *
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $root = $this->root.'/'.$directory;

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && ! $file->isDir()) {
                $files[] = $directory.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array{name: string, version: string, tarball: string, integrity: string}  $lock
     */
    private function cachePath(array $lock): string
    {
        return $this->root.'/'.self::CACHE.'/'.$lock['name'].'-'.$lock['version'].'.tgz';
    }

    private function download(string $url): string
    {
        if (! str_starts_with($url, self::REGISTRY)) {
            throw new RuntimeException('Only tarballs on '.self::REGISTRY." are downloaded, not {$url}.");
        }

        $context = stream_context_create([
            'http' => ['timeout' => 60, 'follow_location' => 0, 'header' => "Accept: application/octet-stream\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $context, 0, self::MAX_TARBALL + 1);

        if (! is_string($data)) {
            throw new RuntimeException("Could not download {$url}.");
        }

        if (strlen($data) > self::MAX_TARBALL) {
            throw new RuntimeException('The tarball is larger than '.self::MAX_TARBALL.' bytes.');
        }

        return $data;
    }

    private function readLocal(string $path): string
    {
        if (! is_file($path) || (int) filesize($path) > self::MAX_TARBALL) {
            throw new RuntimeException('No tarball of at most '.self::MAX_TARBALL." bytes at {$path}.");
        }

        return (string) file_get_contents($path);
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Could not create '.dirname($path).'.');
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }
    }
}
