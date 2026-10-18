<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Build\Isolation;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CheckCommand extends Command
{
    protected $signature = 'schema-viewer:check
        {--fail-on=* : Hint kinds that fail the check; defaults to schema-viewer.hints.fail_on}
        {--format=text : text or json}';

    protected $description = 'List the schema hints and fail when a hint of a chosen kind remains';

    public function handle(SchemaViewer $viewer, Isolation $isolation, Config $config): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['text', 'json'], true)) {
            $this->components->error("Unknown format [{$format}]. Use text or json.");

            return self::INVALID;
        }

        $json = $format === 'json';

        /** @var list<string> $option */
        $option = $this->option('fail-on');
        $configured = $config->get('schema-viewer.hints.fail_on', []);
        $failOn = array_values($option !== [] ? $option : (is_array($configured) ? array_map('strval', $configured) : []));

        $unknown = array_diff($failOn, array_map(fn (HintKind $kind): string => $kind->value, HintKind::cases()));

        if ($unknown !== []) {
            return $this->refuse('Unknown hint kind ['.implode(', ', $unknown).'].', self::INVALID, $json);
        }

        try {
            $viewer->ignoreRules();
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage(), self::INVALID, $json);
        }

        if ($json) {
            try {
                $hints = $isolation->document($viewer)->hints;
            } catch (Throwable $e) {
                return $this->refuse('The schema could not be read: '.$e->getMessage(), self::FAILURE, true);
            }

            return $this->json($hints, $failOn);
        }

        $hints = $isolation->document($viewer)->hints;

        if ($hints === []) {
            $this->components->info('No hints. The schema and the models agree.');

            return self::SUCCESS;
        }

        $failing = 0;

        foreach ($hints as $hint) {
            $fails = in_array($hint->kind->value, $failOn, true);
            $failing += $fails ? 1 : 0;
            $this->components->twoColumnDetail(
                sprintf('<fg=%s>%s</> %s.%s', $fails ? 'red' : 'yellow', $fails ? 'FAIL' : 'NOTE', $hint->connection, $hint->table),
                $hint->kind->value.($hint->kind->certainty() === 'likely' ? ' <fg=gray>· likely</>' : ''),
            );
            $this->line('  <fg=gray>'.$hint->message.'</>');
            $this->line('  <fg=gray>Ignore it:</> '.$this->ignorePhp($hint));
        }

        $this->newLine();
        $this->line('  <fg=gray>To accept a hint, add its rule to hints.ignore in config/schema-viewer.php.</>');
        $this->newLine();

        if ($failing > 0) {
            $this->components->error(sprintf('%d of %d hints fail the check.', $failing, count($hints)));

            return self::FAILURE;
        }

        $this->components->info(sprintf('%d hints, none of a failing kind.', count($hints)));

        return self::SUCCESS;
    }

    /**
     * The hints as one JSON document for scripts and agents, in the order they were built.
     *
     * @param  list<Hint>  $hints
     * @param  list<string>  $failOn
     */
    private function json(array $hints, array $failOn): int
    {
        $items = [];
        $failing = 0;
        $bySeverity = $byCertainty = $byKind = [];

        foreach ($hints as $hint) {
            $fails = in_array($hint->kind->value, $failOn, true);
            $failing += $fails ? 1 : 0;
            $bySeverity[$hint->kind->severity()] = ($bySeverity[$hint->kind->severity()] ?? 0) + 1;
            $byCertainty[$hint->kind->certainty()] = ($byCertainty[$hint->kind->certainty()] ?? 0) + 1;
            $byKind[$hint->kind->value] = ($byKind[$hint->kind->value] ?? 0) + 1;

            $serialized = $hint->jsonSerialize();
            $ignore = $serialized['ignore'];
            unset($serialized['ignore']);

            $items[] = [...$serialized, 'fails' => $fails, 'ignore' => $ignore, 'ignorePhp' => $this->ignorePhp($hint)];
        }

        $this->write([
            'version' => 1,
            'passed' => $failing === 0,
            'failOn' => $failOn,
            'summary' => [
                'hints' => count($hints),
                'failing' => $failing,
                'bySeverity' => (object) $bySeverity,
                'byCertainty' => (object) $byCertainty,
                'byKind' => (object) $byKind,
            ],
            'hints' => $items,
        ]);

        return $failing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report an error as text, or as a JSON document that says the check did not pass.
     */
    private function refuse(string $message, int $code, bool $json): int
    {
        if ($json) {
            $this->write(['version' => 1, 'passed' => false, 'error' => $message]);
        } else {
            $this->components->error($message);
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        $this->output->write(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            false,
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * The narrowest hints.ignore rule that leaves the hint out, ready to paste into the config.
     */
    private function ignorePhp(Hint $hint): string
    {
        return IgnoreRule::php($hint->ignoreRules()['hint']).',';
    }
}
