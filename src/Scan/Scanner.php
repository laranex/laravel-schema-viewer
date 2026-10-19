<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Scan;

use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Scan;
use RuntimeException;

/**
 * The snapshot scan's Git and file-system reads, as the shared contract/scan.mjs does them: the index (staged bytes,
 * read from Git's objects), the files Git would add, artifact directories (every file, ignore rules not applied) and,
 * on request, every version of a candidate in the history. Contract\Scan classifies what it reads. Nothing here runs
 * the application, touches a database or the network, or keeps any of the content.
 *
 * @phpstan-import-type ScanFile from Scan
 * @phpstan-import-type Report from Scan
 */
final class Scanner
{
    /**
     * Blob reads are batched so that one batch holds at most this many bytes.
     */
    private const BATCH_BYTES = 256 * 1024 * 1024;

    public function __construct(private readonly int $limit = Scan::SIZE_LIMIT) {}

    /**
     * Scan from a directory: its Git work tree's top level is the scan root (the directory itself outside one).
     * Relative artifact and allowlist paths start at $cwd. Returns the report and the scopes in the order scanned, as
     * the summary names them.
     *
     * @param  array{tracked: bool, untracked: bool, history: bool, artifacts: list<string>, allowlist: string|null}  $options
     * @return array{report: Report, scopes: list<string>}
     */
    public function scan(string $start, string $cwd, array $options): array
    {
        $start = Git::posix((string) (realpath($start) ?: $start));
        $cwd = Git::posix((string) (realpath($cwd) ?: $cwd));
        $usesGit = $options['tracked'] || $options['untracked'] || $options['history'];
        $top = Git::topLevel($start);
        $root = $top ?? $start;
        $errors = [];
        $files = [];
        $scopes = [];
        $artifactDirs = [];

        if ($usesGit && $top === null) {
            $errors[] = ['kind' => 'not_a_repository', 'message' => 'The Git scopes need a Git work tree; this is not one.'];
        } elseif ($usesGit) {
            try {
                if ($options['tracked']) {
                    $scopes[] = 'tracked';
                    array_push($files, ...$this->tracked($root));
                }

                if ($options['untracked']) {
                    $scopes[] = 'untracked';
                    array_push($files, ...$this->untracked($root));
                }

                if ($options['history']) {
                    $scopes[] = 'history';
                    $history = $this->history($root);

                    if (is_string($history)) {
                        $errors[] = ['kind' => 'history_unavailable', 'message' => $history];
                    } else {
                        array_push($files, ...$history);
                    }
                }
            } catch (RuntimeException $e) {
                $errors[] = ['kind' => 'git_failed', 'message' => $e->getMessage()];
            }
        }

        foreach ($options['artifacts'] as $dir) {
            $scopes[] = 'artifacts';
            $absolute = self::absolute($dir, $cwd);
            $absolute = Git::posix((string) (realpath($absolute) ?: $absolute));

            if (! is_dir($absolute)) {
                $errors[] = ['kind' => 'artifact_missing', 'message' => "The artifact directory {$dir} does not exist."];

                continue;
            }

            $display = match (true) {
                $absolute === $root => '',
                str_starts_with($absolute, rtrim($root, '/').'/') => substr($absolute, strlen(rtrim($root, '/')) + 1),
                default => $absolute,
            };
            $artifactDirs[] = $display;
            array_push($files, ...$this->artifacts($absolute, $display));
        }

        $allowlist = null;
        $allowlistFile = $options['allowlist'] !== null ? self::absolute($options['allowlist'], $cwd) : $root.'/'.Scan::DEFAULT_ALLOWLIST;

        if ($options['allowlist'] !== null || file_exists($allowlistFile)) {
            $base = dirname($allowlistFile);
            $allowlist = Scan::resolveAllowlist(basename($allowlistFile), function (string $path) use ($base): mixed {
                $text = @file_get_contents($base.'/'.$path);

                return is_string($text) ? Json::decode($text) : throw new RuntimeException("Cannot read {$path}");
            });
        }

        // A stale entry names a path the scan covered: under a scanned artifact directory, or anywhere Git does not
        // ignore when both the tracked and the untracked files were scanned.
        $underArtifacts = fn (string $path): bool => array_filter($artifactDirs, fn (string $d): bool => $d === '' || $path === $d || str_starts_with($path, $d.'/')) !== [];
        $both = $options['tracked'] && $options['untracked'] && $top !== null;
        $ignored = [];

        if ($both && $allowlist !== null) {
            $paths = array_values(array_filter(array_column($allowlist['entries'], 'path'), fn (string $p): bool => ! $underArtifacts($p)));

            try {
                $ignored = array_flip($this->ignored($root, $paths));
            } catch (RuntimeException $e) {
                $errors[] = ['kind' => 'git_failed', 'message' => $e->getMessage()];
            }
        }

        $covered = fn (string $path): bool => $underArtifacts($path) || ($both && ! isset($ignored[$path]));

        return ['report' => Scan::files($scopes, $files, $allowlist, $errors, $this->limit, $covered), 'scopes' => $scopes];
    }

    /**
     * The index, read from the index: staged content, whatever the working tree holds. Symbolic links and submodules
     * are not files.
     *
     * @return list<ScanFile>
     */
    private function tracked(string $root): array
    {
        $entries = [];

        foreach (self::split(Git::output($root, ['ls-files', '-s', '-z'])) as $line) {
            $tab = strpos($line, "\t");

            if ($tab === false) {
                continue;
            }

            [$mode, $oid] = explode(' ', substr($line, 0, $tab)) + ['', ''];
            $path = substr($line, $tab + 1);

            if (($mode === '100644' || $mode === '100755') && Scan::isCandidate($path, 'tree')) {
                $entries[] = ['path' => $path, 'oid' => $oid, 'commit' => null];
            }
        }

        return $this->blobFiles($root, $entries, 'tracked');
    }

    /**
     * Files Git would add: untracked and not ignored, read from the working tree.
     *
     * @return list<ScanFile>
     */
    private function untracked(string $root): array
    {
        $files = [];

        foreach (self::split(Git::output($root, ['ls-files', '-z', '--others', '--exclude-standard'])) as $path) {
            if (str_ends_with($path, '/') || ! Scan::isCandidate($path, 'tree')) {
                continue;
            }

            $stat = @lstat($root.'/'.$path);

            if ($stat === false) {
                $files[] = ['scope' => 'untracked', 'path' => $path, 'error' => 'read_error'];
            } elseif (($stat['mode'] & 0170000) === 0100000) {
                $files[] = $this->read($root.'/'.$path, $path, 'untracked');
            }
        }

        return $files;
    }

    /**
     * Every file under an artifact directory: symbolic links to files are read, symbolic links to directories are not
     * followed (incomplete).
     *
     * @return list<ScanFile>
     */
    private function artifacts(string $directory, string $display, string $relative = ''): array
    {
        $names = @scandir($directory);

        if ($names === false) {
            return [['scope' => 'artifacts', 'path' => $display !== '' ? $display : '.', 'error' => 'read_error']];
        }

        $files = [];

        foreach (array_diff($names, ['.', '..']) as $name) {
            $path = $directory.'/'.$name;
            $shown = $relative !== '' ? $relative.'/'.$name : $name;
            $label = self::text($display !== '' ? $display.'/'.$shown : $shown);

            if (is_link($path)) {
                if (! file_exists($path)) {
                    $files[] = ['scope' => 'artifacts', 'path' => $label, 'error' => 'read_error'];

                    continue;
                }

                if (is_dir($path)) {
                    $files[] = ['scope' => 'artifacts', 'path' => $label, 'error' => 'symlinked_directory'];

                    continue;
                }
            }

            if (is_dir($path)) {
                array_push($files, ...$this->artifacts($path, $display, $shown));
            } elseif (Scan::isCandidate($label, 'artifact')) {
                $files[] = $this->read($path, $label, 'artifacts');
            }
        }

        return $files;
    }

    /**
     * Every version of a candidate file that a commit reachable from any ref introduced, merges included, once per
     * path and blob, with the first commit git log lists for it; a message when the history is not complete.
     *
     * @return list<ScanFile>|string
     */
    private function history(string $root): array|string
    {
        if (trim(Git::output($root, ['rev-parse', '--is-shallow-repository'])) === 'true') {
            return 'The repository is a shallow clone; its history is not available.';
        }

        if (trim(Git::output($root, ['for-each-ref', '--count=1'])) === '') {
            return [];
        }

        $tokens = explode("\0", Git::output($root, ['log', '--all', '--no-renames', '--raw', '--no-abbrev', '-m', '--root', '-z', '--format=%x01%H']));
        $seen = [];
        $commit = null;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = self::text(str_starts_with($tokens[$i], "\n") ? substr($tokens[$i], 1) : $tokens[$i]);

            if (str_starts_with($token, "\x01")) {
                $commit = substr($token, 1);
            } elseif (str_starts_with($token, ':')) {
                $parts = explode(' ', substr($token, 1));
                $mode = $parts[1] ?? '';
                $oid = $parts[3] ?? '';
                $path = self::text($tokens[++$i] ?? '');
                $key = $path."\0".$oid;

                if (($mode === '100644' || $mode === '100755') && preg_match('/^0+$/', $oid) !== 1 && Scan::isCandidate($path, 'tree') && ! isset($seen[$key])) {
                    $seen[$key] = ['path' => $path, 'oid' => $oid, 'commit' => $commit];
                }
            }
        }

        return $this->blobFiles($root, array_values($seen), 'history');
    }

    /**
     * The paths Git ignores, of those given.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function ignored(string $root, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $result = Git::run($root, ['check-ignore', '-z', '--stdin'], implode("\0", $paths)."\0");

        if ($result === null || ($result[0] !== 0 && $result[0] !== 1)) {
            throw new RuntimeException('git check-ignore failed');
        }

        return self::split($result[1]);
    }

    /**
     * @param  list<array{path: string, oid: string, commit: string|null}>  $entries
     * @return list<ScanFile>
     */
    private function blobFiles(string $root, array $entries, string $scope): array
    {
        $blobs = $this->blobs($root, array_column($entries, 'oid'));

        $files = [];

        foreach ($entries as $e) {
            $blob = $blobs[$e['oid']] ?? ['error' => 'read_error'];
            $files[] = array_key_exists('bytes', $blob)
                ? ['scope' => $scope, 'path' => $e['path'], 'commit' => $e['commit'], 'bytes' => $blob['bytes']]
                : ['scope' => $scope, 'path' => $e['path'], 'commit' => $e['commit'], 'error' => $blob['error']];
        }

        return $files;
    }

    /**
     * Blobs by id, each its bytes or why it was not read; never more than the limit per blob.
     *
     * @param  list<string>  $oids
     * @return array<string, array{bytes: string}|array{error: string}>
     */
    private function blobs(string $root, array $oids): array
    {
        $unique = array_values(array_unique($oids));
        $result = [];

        if ($unique === []) {
            return $result;
        }

        $sizes = [];

        foreach (explode("\n", trim(Git::output($root, ['cat-file', '--batch-check'], implode("\n", $unique)."\n"))) as $line) {
            [$oid, $type, $size] = explode(' ', $line) + ['', '', '0'];

            if ($type !== 'blob') {
                $result[$oid] = ['error' => 'read_error'];
            } elseif ((int) $size > $this->limit) {
                $result[$oid] = ['error' => 'size_limit'];
            } else {
                $sizes[$oid] = (int) $size;
            }
        }

        $batches = [[]];
        $total = 0;

        foreach ($sizes as $oid => $size) {
            if ($total + $size > self::BATCH_BYTES && end($batches) !== []) {
                $batches[] = [];
                $total = 0;
            }

            $batches[count($batches) - 1][] = (string) $oid;
            $total += $size;
        }

        foreach (array_filter($batches) as $batch) {
            $out = Git::output($root, ['cat-file', '--batch'], implode("\n", $batch)."\n");
            $at = 0;

            while ($at < strlen($out)) {
                $end = strpos($out, "\n", $at);

                if ($end === false) {
                    break;
                }

                [$oid, , $size] = explode(' ', substr($out, $at, $end - $at)) + ['', '', '0'];
                $result[$oid] = ['bytes' => substr($out, $end + 1, (int) $size)];
                $at = $end + 1 + (int) $size + 1;
            }
        }

        return $result;
    }

    /**
     * @return ScanFile
     */
    private function read(string $file, string $display, string $scope): array
    {
        $size = @filesize($file);

        if ($size === false) {
            return ['scope' => $scope, 'path' => $display, 'error' => 'read_error'];
        }

        if ($size > $this->limit) {
            return ['scope' => $scope, 'path' => $display, 'error' => 'size_limit'];
        }

        $bytes = @file_get_contents($file);

        return is_string($bytes) ? ['scope' => $scope, 'path' => $display, 'bytes' => $bytes] : ['scope' => $scope, 'path' => $display, 'error' => 'read_error'];
    }

    /**
     * NUL-separated Git output as text, as Node.js reads it (invalid UTF-8 replaced), without empty items.
     *
     * @return list<string>
     */
    private static function split(string $output): array
    {
        return array_values(array_filter(explode("\0", self::text($output)), fn (string $item): bool => $item !== ''));
    }

    private static function text(string $bytes): string
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = (string) mb_scrub($bytes, 'UTF-8');
        mb_substitute_character($substitute);

        return $text;
    }

    private static function absolute(string $path, string $base): string
    {
        $path = Git::posix($path);

        return preg_match('#^([a-zA-Z]:)?/#', $path) === 1 ? $path : rtrim($base, '/').'/'.$path;
    }
}
