<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * A Markdown reference of the database, a port of laranex-schema-viewer-ui's export/markdown.ts: per connection a
 * title, the table count, and each table with its columns, indexes, constraints and foreign keys. With $orm the
 * models, relationships and inferred associations follow in their own section, apart from the foreign keys.
 */
final class MarkdownExporter implements Exporter
{
    public function extension(): string
    {
        return 'md';
    }

    public function export(array $document, ?array $connections = null, bool $orm = false): array
    {
        $ctx = new ExportContext(Exporters::normalized($document), $connections);
        $ctx->sourceWarnings();
        $doc = $ctx->doc;
        $out = [];

        foreach ($ctx->connections as $n => $connection) {
            if ($n > 0) {
                $out[] = '';
            }

            array_push($out, '# Database schema: '.self::text($connection['name']).' ('.self::text($connection['driver'] ?? 'unknown driver').')', '');

            if ($doc['note'] !== null && $n === 0) {
                array_push($out, self::text($doc['note']), '');
            }

            if ($doc['source'] === 'declared') {
                array_push($out, 'From ORM declarations only; no database was inspected.', '');
            }

            $coverage = $doc['source'] === 'declared' ? $connection['coverage']['declared'] : $connection['coverage']['database'];

            if (is_array($coverage) && $coverage['complete'] !== true) {
                $gaps = [];

                foreach (ExportContext::members($coverage['features']) as $feature => $state) {
                    if ($state === 'partial' || $state === 'unavailable') {
                        $gaps[] = "{$feature}: {$state}";
                    }
                }

                array_push($out, 'Coverage: incomplete'.($gaps !== [] ? ' ('.implode(', ', $gaps).')' : '').'. Only what was read is listed.', '');
            }

            $tables = $ctx->tablesOf($connection);
            $out[] = self::plural(count($tables), 'table').'.';

            foreach ($tables as $table) {
                array_push($out, '', ...$this->table($ctx->view($table)));
            }
        }

        if ($orm) {
            array_push($out, ...$this->ormSection($ctx));
        }

        return ['content' => implode("\n", $out)."\n", 'warnings' => $ctx->warnings];
    }

    /**
     * Text from the schema as plain Markdown: no HTML, no code spans, no table cell broken by a pipe or a line break.
     */
    public static function text(string $text): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', ' ', str_replace(['&', '<', '>', '|', '`'], ['&amp;', '&lt;', '&gt;', '\|', '\`'], $text));
    }

    /**
     * Text inside a code span, which shows it literally: only a backtick could close it, and a pipe would end a cell.
     */
    public static function code(string $text): string
    {
        return '`'.(string) preg_replace('/\r\n|\r|\n/', ' ', str_replace(['`', '|'], ["'", '\|'], $text)).'`';
    }

    private static function joined(?string ...$texts): string
    {
        return implode(' · ', array_filter($texts, fn (?string $t): bool => $t !== null));
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function part(array $p): string
    {
        $key = $p['column'] !== null ? self::text($p['column']) : self::code($p['expression'] ?? '');

        return implode(' ', array_filter([
            $key.($p['length'] !== null ? "({$p['length']})" : ''),
            $p['collation'] !== null && $p['collation'] !== '' ? 'collate '.self::text($p['collation']) : '',
            $p['opclass'] !== null && $p['opclass'] !== '' ? self::text($p['opclass']) : '',
            $p['order'] ?? '',
            $p['nulls'] !== null && $p['nulls'] !== '' ? "nulls {$p['nulls']}" : '',
        ], fn (string $s): bool => $s !== ''));
    }

    /**
     * @param  array<string, mixed>  $index
     */
    private static function index(array $index): string
    {
        $parts = array_map(self::part(...), $index['parts']);

        if ($index['expression'] && array_filter($index['parts'], fn (array $p): bool => $p['expression'] !== null) === []) {
            $parts[] = 'an expression not reported';
        }

        $extra = array_filter([
            $index['unique'] ? 'unique' : '',
            $index['method'] !== null && $index['method'] !== '' && strtolower($index['method']) !== 'btree' ? self::text($index['method']) : '',
            $index['partial'] ? 'partial'.($index['predicate'] !== null ? ' where '.self::code($index['predicate']) : '') : '',
            $index['included'] !== [] ? 'include ('.implode(', ', array_map(self::text(...), $index['included'])).')' : '',
            ($index['backs']['kind'] ?? null) === 'unique_constraint' ? 'backs a unique constraint' : '',
        ]);

        return '- '.($index['name'] !== null ? self::code($index['name']) : 'unnamed').' ('.implode(', ', $parts).')'.implode('', array_map(fn (string $e): string => ", {$e}", $extra));
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function type(array $column): string
    {
        $enum = $column['enum'];

        return implode(' ', array_filter([
            self::text($column['type']),
            ($enum['name'] ?? null) !== null && $enum['name'] !== '' ? '(enum '.self::text($enum['name']).': '.implode(', ', array_map(self::text(...), $enum['values'])).')' : '',
            $column['collation'] !== null && $column['collation'] !== '' ? 'collate '.self::text($column['collation']) : '',
            $column['autoIncrement'] === true ? 'auto-increment' : '',
        ]));
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function default(array $column): string
    {
        if ($column['generated'] !== null) {
            $kind = $column['generated']['kind'];

            return 'generated'.($kind !== null && $kind !== '' ? " {$kind}" : '').': '.self::code((string) $column['generated']['expression']);
        }

        return $column['default'] !== null ? self::code($column['default']['value']) : '';
    }

    /**
     * @return list<string>
     */
    private function table(TableView $view): array
    {
        $table = $view->table;
        $out = ['## '.self::text(ExportContext::tableLabel($table))];

        if ($table['owner']['kind'] !== 'application') {
            array_push($out, '', $table['owner']['kind'] === 'framework' ? 'Framework table.' : 'Package table'.($table['owner']['package'] !== null && $table['owner']['package'] !== '' ? ': '.self::text($table['owner']['package']) : '').'.');
        }

        if ($table['comment'] !== null) {
            array_push($out, '', self::text($table['comment']));
        }

        if ($table['note'] !== null) {
            array_push($out, '', 'Note: '.self::text($table['note']));
        }

        $foreign = [];

        foreach ($view->foreignKeys as ['fk' => $fk, 'target' => $target]) {
            foreach ($fk['columns'] as $i => $c) {
                $foreign[$c] ??= ExportContext::tableLabel($target).'.'.$fk['foreignColumns'][$i];
            }
        }

        array_push($out, '', '| Column | Type | Null | Default | Key | Comment |', '| --- | --- | --- | --- | --- | --- |');

        foreach ($view->columns as $column) {
            $key = match (true) {
                in_array($column['name'], $view->primaryKey(), true) => 'PK',
                isset($foreign[$column['name']]) => 'FK → '.self::text($foreign[$column['name']]),
                $view->uniqueColumn($column['name']) !== null => 'UK',
                default => '',
            };
            $out[] = sprintf('| %s | %s | %s | %s | %s | %s |', self::code($column['name']), self::type($column), $column['nullable'] ? 'yes' : '', self::default($column), $key, self::text(self::joined($column['comment'], $column['note'])));
        }

        $indexes = array_values(array_filter($view->indexes, fn (array $i): bool => ($i['backs']['kind'] ?? null) !== 'primary_key'));

        if ($indexes !== []) {
            array_push($out, '', 'Indexes:', '', ...array_map(self::index(...), $indexes));
        }

        if ($view->constraints !== []) {
            array_push($out, '', 'Constraints:', '', ...array_map(function (array $c): string {
                $name = $c['name'] !== null ? self::code($c['name']) : 'unnamed';
                $what = $c['kind'] === 'unique'
                    ? 'unique ('.implode(', ', [...array_map(self::text(...), $c['columns']), ...($c['expression'] !== null ? [self::code($c['expression'])] : [])]).')'
                    : 'check '.($c['expression'] !== null ? self::code($c['expression']) : '(expression not reported)');

                return "- {$name}: {$what}";
            }, $view->constraints));
        }

        if ($view->foreignKeys !== []) {
            array_push($out, '', 'Foreign keys:', '', ...array_map(function (array $f): string {
                ['fk' => $fk, 'target' => $target] = $f;
                $deferrable = $fk['deferrable'] === true ? ', deferrable'.($fk['initially'] !== null && $fk['initially'] !== '' ? ', initially '.strtolower($fk['initially']) : '') : '';

                return sprintf(
                    '- %s: (%s) → %s (%s), on update %s, on delete %s%s',
                    $fk['name'] !== null ? self::code($fk['name']) : 'unnamed',
                    implode(', ', array_map(self::text(...), $fk['columns'])),
                    self::text(ExportContext::tableLabel($target)),
                    implode(', ', array_map(self::text(...), $fk['foreignColumns'])),
                    ExportContext::actionWords($fk['onUpdate']) ?? 'not reported',
                    ExportContext::actionWords($fk['onDelete']) ?? 'not reported',
                    $deferrable,
                );
            }, $view->foreignKeys));
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function ormSection(ExportContext $ctx): array
    {
        $doc = $ctx->doc;
        $tableName = function (string $id) use ($ctx): string {
            $table = $ctx->tables[$id] ?? null;

            if ($table === null) {
                return self::text($id);
            }

            return self::text($ctx->several || ! isset($ctx->inScope[$id]) ? ($ctx->connectionOf[$id]['name'] ?? '').' · '.ExportContext::tableLabel($table) : ExportContext::tableLabel($table));
        };
        $classes = array_column($doc['models'], 'className', 'id');
        $modelName = fn (string $id): string => ExportContext::shortClass($classes[$id] ?? $id);
        $inScope = fn (string $t): bool => isset($ctx->inScope[$t]);
        $models = array_values(array_filter($doc['models'], fn (array $m): bool => array_filter($m['tables'], $inScope) !== []));
        $relationships = array_values(array_filter($doc['relationships'], fn (array $r): bool => $inScope($r['table'])));
        $inferred = array_values(array_filter($doc['inferredAssociations'], fn (array $a): bool => $inScope($a['table'])));

        $out = ['', '# ORM declarations', '', "From the ORM's metadata. These are not database constraints: the foreign keys are listed with their tables above."];
        array_push($out, '', '## Models', '');
        array_push($out, ...($models !== [] ? array_map(fn (array $m): string => '- '.self::code($m['className']).': '.(implode(', ', array_map($tableName, $m['tables'])) ?: 'no table').($m['source'] !== null ? ' ('.self::text($m['source']['file']).($m['source']['line'] !== null ? ':'.$m['source']['line'] : '').')' : ''), $models) : ['None.']));
        array_push($out, '', '## Relationships', '');
        array_push($out, ...($relationships !== [] ? array_map(function (array $r) use ($tableName, $modelName): string {
            $target = $r['relatedTable'] !== null ? $tableName($r['relatedTable']) : 'no single table';
            $how = match (true) {
                $r['pivot'] !== null => 'via '.$tableName($r['pivot']['table']),
                is_array($r['through']) && $r['through'] !== [] => 'through '.implode(', ', array_map($tableName, $r['through'])),
                $r['keys'] !== null => 'on '.$tableName($r['keys']['from']['table']).' ('.implode(', ', array_map(self::text(...), $r['keys']['from']['columns'])).') → '.$tableName($r['keys']['to']['table']).' ('.implode(', ', array_map(self::text(...), $r['keys']['to']['columns'])).')',
                $r['polymorphic'] !== null => 'polymorphic on '.self::text($r['polymorphic']['typeColumn']).', '.self::text($r['polymorphic']['idColumn']),
                default => 'keys not resolved',
            };

            return '- '.self::code($modelName($r['model']).'::'.$r['name']).': '.self::text($r['type']).", {$r['cardinality']}, ".$tableName($r['table'])." → {$target}, {$how}";
        }, $relationships) : ['None.']));

        if ($inferred !== []) {
            array_push($out, '', '## Inferred associations', '', 'Inferred from foreign keys; no ORM attribute declares them.', '');
            array_push($out, ...array_map(function (array $a) use ($ctx, $tableName, $modelName): string {
                $name = function (string $id) use ($ctx, $a): string {
                    foreach ([$a['table'], $a['via'] ?? ''] as $table) {
                        foreach ($ctx->tables[$table]['foreignKeys'] ?? [] as $fk) {
                            if ($fk['id'] === $id) {
                                return $fk['name'] ?? $id;
                            }
                        }
                    }

                    return $id;
                };

                return '- '.self::text($modelName($a['model']).' → '.$modelName($a['relatedModel'])).": {$a['cardinality']}, ".$tableName($a['table']).' → '.$tableName($a['relatedTable'])
                    .($a['via'] !== null ? ' via '.$tableName($a['via']) : '')
                    .', from '.implode(', ', array_map(fn (string $id): string => self::code($name($id)), $a['foreignKeys']))
                    .", {$a['confidence']} confidence"
                    .($a['ambiguities'] !== [] ? ', ambiguous: '.implode(', ', array_map(self::text(...), $a['ambiguities'])) : '');
            }, $inferred));
        }

        return $out;
    }
}
