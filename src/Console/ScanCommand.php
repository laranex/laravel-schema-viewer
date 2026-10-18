<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Scan\Scanner;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * php artisan schema-viewer:scan: the shared snapshot scan (laranex-schema-viewer-ui's contract/scan.mjs, with the same
 * options, summary, report and exit codes) from the project root. Exit codes: 0 clean, 1 confirmed exposure, 2 scan
 * failure or incomplete scan (usage errors included), 3 uncertain exposure.
 */
final class ScanCommand extends Command
{
    private const USAGE = 'usage: schema-viewer:scan [--tracked] [--untracked] [--artifacts DIR]... [--history] [--allowlist FILE] [--report FILE] [--json]';

    protected $name = 'schema-viewer:scan';

    protected $description = 'Scan the repository and build output for schema snapshots, so a private schema is never committed or published';

    /**
     * @return list<array{string, null, int, string}>
     */
    protected function getOptions(): array
    {
        return [
            ['tracked', null, InputOption::VALUE_NONE, 'Every file in the Git index, read from the index (staged bytes, and files tracked before they were ignored)'],
            ['untracked', null, InputOption::VALUE_NONE, 'Files Git would add: untracked and not ignored'],
            ['artifacts', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A build output directory: every file, ignore rules not applied (repeatable)'],
            ['history', null, InputOption::VALUE_NONE, 'Every version of a .json, .html or .htm file reachable from any ref (fails on a shallow clone)'],
            ['allowlist', null, InputOption::VALUE_REQUIRED, 'A laranex-schema-allowlist file (default: .laranex-schema-allowlist.json at the scan root, if any)'],
            ['report', null, InputOption::VALUE_REQUIRED, 'Write the laranex-schema-scan report (JSON) to FILE'],
            ['json', null, InputOption::VALUE_NONE, 'Print the report instead of the summary'],
        ];
    }

    /**
     * Usage errors (an unknown option, an option without its value) exit 2, as every other scan failure does.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            $this->stderr($output, 'schema-viewer:scan: '.$e->getMessage()."\n".self::USAGE);

            return 2;
        }
    }

    public function handle(): int
    {
        $artifacts = array_values(array_filter((array) $this->option('artifacts'), 'is_string'));
        $options = [
            'tracked' => (bool) $this->option('tracked'),
            'untracked' => (bool) $this->option('untracked'),
            'history' => (bool) $this->option('history'),
            'artifacts' => $artifacts,
            'allowlist' => is_string($this->option('allowlist')) && $this->option('allowlist') !== '' ? $this->option('allowlist') : null,
        ];
        $report = is_string($this->option('report')) && $this->option('report') !== '' ? $this->option('report') : null;
        $json = (bool) $this->option('json');

        if (! $options['tracked'] && ! $options['untracked'] && ! $options['history'] && $artifacts === []) {
            $this->stderr($this->output, "schema-viewer:scan: name at least one scope\n".self::USAGE);

            return 2;
        }

        try {
            $cwd = (string) getcwd();
            ['report' => $result, 'scopes' => $scopes] = (new Scanner)->scan($this->laravel->basePath(), $cwd, $options);

            if ($report !== null) {
                $file = preg_match('#^([a-zA-Z]:)?[\\\\/]#', $report) === 1 ? $report : $cwd.DIRECTORY_SEPARATOR.$report;

                if (@file_put_contents($file, Json::canonical($result)) === false) {
                    $this->stderr($this->output, "schema-viewer:scan: could not write the report to {$report}");

                    return 2;
                }
            }
        } catch (Throwable $e) {
            $this->stderr($this->output, 'schema-viewer:scan: the scan failed: '.$e->getMessage());

            return 2;
        }

        if ($json) {
            $this->output->write(Json::canonical($result), false, OutputInterface::OUTPUT_RAW);
        } else {
            $c = $result['counts'];
            $this->output->writeln(sprintf(
                'laranex-schema-scan: %d files (%s); %d confirmed, %d tampered, %d moved, %d uncertain, %d incomplete, %d allowed, %d stale; exit %d',
                $c['files'], implode(', ', $scopes), $c['confirmed'], $c['tampered'], $c['moved'], $c['uncertain'], $c['incomplete'], $c['allowed'], $c['stale'], $result['exitCode'],
            ), OutputInterface::OUTPUT_RAW);

            if ($result['exitCode'] !== 0 && $report === null) {
                $this->output->writeln('Paths and reasons are in the report: --report FILE or --json.', OutputInterface::OUTPUT_RAW);
            }
        }

        foreach ($result['errors'] as $error) {
            $this->stderr($this->output, "schema-viewer:scan: {$error['kind']}: {$error['message']}", $json);
        }

        return $result['exitCode'];
    }

    /**
     * A line on standard error. Without one (Artisan::call), on the output, unless the report is printed there.
     */
    private function stderr(OutputInterface $output, string $message, bool $quiet = false): void
    {
        $inner = method_exists($output, 'getOutput') ? $output->getOutput() : $output;

        if ($inner instanceof ConsoleOutputInterface) {
            $inner->getErrorOutput()->writeln($message, OutputInterface::OUTPUT_RAW);
        } elseif (! $quiet) {
            $output->writeln($message, OutputInterface::OUTPUT_RAW);
        }
    }
}
