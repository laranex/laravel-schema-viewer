<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\SchemaViewer;

final class CheckCommand extends Command
{
    protected $signature = 'schema-viewer:check
        {--fail-on=* : Hint kinds that fail the check; defaults to schema-viewer.hints.fail_on}';

    protected $description = 'List the schema hints and fail when a hint of a chosen kind remains';

    public function handle(SchemaViewer $viewer, Config $config): int
    {
        /** @var list<string> $option */
        $option = $this->option('fail-on');
        $configured = $config->get('schema-viewer.hints.fail_on', []);
        $failOn = $option !== [] ? $option : (is_array($configured) ? array_map('strval', $configured) : []);

        $unknown = array_diff($failOn, array_map(fn (HintKind $kind): string => $kind->value, HintKind::cases()));

        if ($unknown !== []) {
            $this->components->error('Unknown hint kind ['.implode(', ', $unknown).'].');

            return self::INVALID;
        }

        try {
            $viewer->ignoreRules();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $hints = $viewer->document()->hints;

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
            $this->line('  <fg=gray>Ignore it:</> '.IgnoreRule::php($hint->ignoreRules()['hint']).',');
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
}
