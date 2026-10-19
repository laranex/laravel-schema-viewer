<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Laranex\LaravelSchemaViewer\Scan\Git;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Before a command writes the schema to a file: a file Git already tracks is refused, since the next commit would
 * carry the private schema, and a file Git would offer to add gets a warning on standard error. Outside a Git work
 * tree, or for an ignored file, nothing is said.
 *
 * @mixin Command
 */
trait KeepsSchemaOutOfGit
{
    /**
     * Whether the files may be written: false, after saying how to untrack it, when Git tracks one of them.
     *
     * @param  list<string>  $files
     */
    private function keepOutOfGit(array $files): bool
    {
        $writable = true;

        foreach ($files as $file) {
            [$state, $path] = Git::exposure($file) ?? [null, ''];

            if ($state === 'tracked') {
                $this->components->error("Git tracks [{$path}], so the next commit would carry the private schema. Nothing was written. Remove it from the index (git rm --cached -- {$path}) and add /{$path} to .gitignore.");
                $writable = false;
            } elseif ($state === 'not_ignored') {
                $message = "[{$path}] is not ignored by Git: add /{$path} to .gitignore so the private schema is never committed.";
                $output = $this->output->getOutput();

                if ($output instanceof ConsoleOutputInterface) {
                    $output->getErrorOutput()->writeln('<fg=yellow>Warning:</> '.$message);
                } else {
                    $this->components->warn($message);
                }
            }
        }

        return $writable;
    }
}
