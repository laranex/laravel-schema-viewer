<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Scan;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Git, run with a fixed argument list and never through a shell, for the snapshot scan and for the commands that
 * write the snapshot or an export.
 */
final class Git
{
    /**
     * The exit code and standard output of git -C <root> -c core.quotePath=false <args>, or null when Git could not be
     * run at all.
     *
     * @param  list<string>  $args
     * @return array{int, string}|null
     */
    public static function run(string $root, array $args, ?string $input = null): ?array
    {
        try {
            $process = new Process(['git', '-C', $root, '-c', 'core.quotePath=false', ...$args], null, null, $input, null);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $code = $process->getExitCode();

        return $code === null ? null : [$code, $process->getOutput()];
    }

    /**
     * Standard output of a Git command that must succeed.
     *
     * @param  list<string>  $args
     *
     * @throws RuntimeException
     */
    public static function output(string $root, array $args, ?string $input = null): string
    {
        $result = self::run($root, $args, $input);

        if ($result === null || $result[0] !== 0) {
            throw new RuntimeException("git {$args[0]} failed");
        }

        return $result[1];
    }

    /**
     * The top level of the Git work tree holding a directory (resolved, with forward slashes), or null when it is in
     * none.
     */
    public static function topLevel(string $directory): ?string
    {
        $result = self::run($directory, ['rev-parse', '--show-toplevel']);
        $top = $result !== null && $result[0] === 0 ? realpath(trim($result[1])) : false;

        return $top === false ? null : self::posix($top);
    }

    /**
     * Whether a file the package is about to write could end up in Git: 'tracked' when the index already holds it,
     * 'not_ignored' when Git would offer to add it, with its path relative to the work tree; null outside a work tree,
     * when it is ignored, or when Git cannot tell.
     *
     * @return array{string, string}|null
     */
    public static function exposure(string $file): ?array
    {
        $file = str_replace('\\', '/', $file);
        $directory = dirname($file);
        $rest = [basename($file)];

        while (! is_dir($directory) && dirname($directory) !== $directory) {
            array_unshift($rest, basename($directory));
            $directory = dirname($directory);
        }

        $top = is_dir($directory) ? self::topLevel($directory) : null;
        $real = realpath($directory);

        if ($top === null || $real === false) {
            return null;
        }

        $absolute = rtrim(self::posix($real), '/').'/'.implode('/', $rest);

        if (! str_starts_with($absolute, $top.'/')) {
            return null;
        }

        $path = substr($absolute, strlen($top) + 1);
        $tracked = self::run($top, ['--literal-pathspecs', 'ls-files', '-z', '--', $path]);

        if ($tracked === null || $tracked[0] !== 0) {
            return null;
        }

        if ($tracked[1] !== '') {
            return ['tracked', $path];
        }

        $ignored = self::run($top, ['check-ignore', '-q', '--', $path]);

        return $ignored !== null && $ignored[0] === 1 ? ['not_ignored', $path] : null;
    }

    public static function posix(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
