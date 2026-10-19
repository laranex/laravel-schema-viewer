<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * A Mermaid erDiagram of the database, a port of laranex-schema-viewer-ui's export/mermaid.ts: every table with its
 * columns (base type, name, PK/FK/UK), then one relationship per foreign key. ORM relationships and inferred
 * associations are added only with $orm, as dotted lines labelled as what they are.
 */
final class MermaidExporter implements Exporter
{
    private const ORM_ENDS = [
        'one-to-one' => ['|o', 'o|'],
        'one-to-many' => ['|o', 'o{'],
        'many-to-one' => ['}o', 'o|'],
        'many-to-many' => ['}o', 'o{'],
    ];

    public function extension(): string
    {
        return 'mmd';
    }

    public function export(array $document, ?array $connections = null, bool $orm = false): array
    {
        $ctx = new ExportContext(Exporters::normalized($document), $connections);
        $ctx->sourceWarnings();
        $views = array_map($ctx->view(...), $ctx->scopedTables());
        $lines = ['erDiagram'];
        $seen = [];
        $renamedTables = [];

        $entity = function (string $label, string $id) use (&$seen, &$renamedTables, $ctx): string {
            $name = self::ident($label);
            $other = $seen[$name] ?? null;

            if ($other === null) {
                $seen[$name] = $label;

                if ($name !== $label) {
                    $renamedTables[] = "{$label} → {$name}";
                }
            } elseif ($other !== $label) {
                $ctx->warn('lossy_conversion', 'table', $id, $label, "{$other} and {$label} are both written as {$name} in Mermaid.");
            }

            return $name;
        };

        foreach ($views as $view) {
            $lines[] = '    '.$entity($view->label, $view->table['id']).' {';
            $foreign = array_fill_keys(array_merge([], ...array_map(fn (array $f): array => $f['fk']['columns'], $view->foreignKeys)), true);
            $primary = array_fill_keys($view->primaryKey(), true);
            $renamed = [];
            $names = [];

            foreach ($view->columns as $column) {
                $name = self::ident($column['name']);

                if ($name !== $column['name'] || isset($names[$name])) {
                    $renamed[] = $column['name'].' → '.$name.(isset($names[$name]) ? ' (twice)' : '');
                }

                $names[$name] = true;
                $keys = array_values(array_filter([
                    isset($primary[$column['name']]) ? 'PK' : '',
                    isset($foreign[$column['name']]) ? 'FK' : '',
                    ! isset($primary[$column['name']]) && $view->uniqueColumn($column['name']) !== null ? 'UK' : '',
                ]));
                $lines[] = '        '.self::ident($column['typeName'] ?? $column['type']).' '.$name.($keys !== [] ? ' '.implode(', ', $keys) : '');
            }

            if ($renamed !== []) {
                $ctx->warn('lossy_conversion', 'table', $view->table['id'], $view->label, sprintf('Mermaid names allow letters, digits and underscores only; columns of %s renamed: %s.', $view->label, implode(', ', $renamed)));
            }

            $lines[] = '    }';
        }

        foreach ($views as $view) {
            foreach ($view->foreignKeys as ['fk' => $fk, 'target' => $target]) {
                $optional = false;

                foreach ($fk['columns'] as $c) {
                    foreach ($view->columns as $col) {
                        if ($col['name'] === $c) {
                            $optional = $optional || $col['nullable'];

                            break;
                        }
                    }
                }

                $lines[] = sprintf('    %s %s--%s %s : "%s"', self::ident($ctx->labelOf($target)), $optional ? '|o' : '||', $view->unique($fk['columns']) ? 'o|' : 'o{', self::ident($view->label), self::label(implode(', ', $fk['columns'])));

                if (count($fk['columns']) > 1) {
                    $ctx->warn('lossy_conversion', 'foreignKey', $fk['id'], $view->label, sprintf('Composite foreign key %s on %s is one relationship labelled with its columns; Mermaid cannot pair them with %s.', $fk['name'] ?? 'unnamed', $view->label, implode(', ', $fk['foreignColumns'])));
                }
            }
        }

        if ($renamedTables !== []) {
            $ctx->warn('lossy_conversion', 'document', null, null, 'Mermaid names allow letters, digits and underscores only; tables renamed: '.implode(', ', $renamedTables).'.');
        }

        if ($orm) {
            array_push($lines, ...$this->ormLines($ctx));
        }

        $this->omitted($ctx, $views);

        return ['content' => implode("\n", $lines)."\n", 'warnings' => $ctx->warnings];
    }

    /**
     * Mermaid names allow letters, digits and underscores; anything else becomes an underscore, and a leading digit gets
     * one.
     */
    public static function ident(string $value): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $value);

        return preg_match('/^[0-9]/', $name) === 1 ? '_'.$name : $name;
    }

    /**
     * A relationship label: anything but a double quote or a control character fits between its quotes.
     */
    public static function label(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1f]+/', ' ', str_replace('"', "'", $value));
    }

    /**
     * @return list<string>
     */
    private function ormLines(ExportContext $ctx): array
    {
        $doc = $ctx->doc;
        $out = [];
        $label = fn (string $id): string => $ctx->labelOf($ctx->tables[$id]);
        $classes = array_column($doc['models'], 'className', 'id');
        $model = fn (string $id): string => ExportContext::shortClass($classes[$id] ?? $id);

        foreach ($doc['relationships'] as $r) {
            if (! isset($ctx->inScope[$r['table']])) {
                continue;
            }

            if ($r['relatedTable'] === null || ! isset($ctx->inScope[$r['relatedTable']])) {
                $name = $model($r['model']).'::'.$r['name'];
                $ctx->warn('skipped_entity', 'relationship', $r['id'], $name, sprintf('ORM relationship %s %s; it is not drawn.', $name, $r['relatedTable'] === null ? 'has no single related table' : 'relates to a table outside this export'));

                continue;
            }

            [$left, $right] = self::ORM_ENDS[$r['cardinality']];
            $via = $r['pivot'] !== null ? ' via '.$label($r['pivot']['table']) : (is_array($r['through']) && $r['through'] !== [] ? ' through '.implode(', ', array_map($label, $r['through'])) : '');
            $out[] = sprintf('    %s %s..%s %s : "%s"', self::ident($label($r['table'])), $left, $right, self::ident($label($r['relatedTable'])), self::label('ORM: '.$model($r['model']).'::'.$r['name'].' ('.$r['type'].$via.')'));
        }

        foreach ($doc['inferredAssociations'] as $a) {
            if (! isset($ctx->inScope[$a['table']], $ctx->inScope[$a['relatedTable']])) {
                continue;
            }

            [$left, $right] = self::ORM_ENDS[$a['cardinality']];
            $out[] = sprintf('    %s %s..%s %s : "%s"', self::ident($label($a['table'])), $left, $right, self::ident($label($a['relatedTable'])), self::label('inferred: '.$model($a['model']).' → '.$model($a['relatedModel']).' (no ORM attribute declared)'));
        }

        return $out;
    }

    /**
     * One warning per connection for what an ER diagram cannot hold.
     *
     * @param  list<TableView>  $views
     */
    private function omitted(ExportContext $ctx, array $views): void
    {
        foreach ($ctx->connections as $c) {
            $own = array_filter($views, fn (TableView $v): bool => $v->connection['id'] === $c['id']);
            $sum = fn (callable $count): int => array_sum(array_map($count, $own));
            $count = fn (int $n, string $one, ?string $many = null): array => $n > 0 ? [$n.' '.($n === 1 ? $one : ($many ?? $one.'s'))] : [];

            $parts = [
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->indexes, fn (array $i): bool => ($i['backs']['kind'] ?? null) !== 'primary_key'))), 'index', 'indexes'),
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->constraints, fn (array $k): bool => $k['kind'] === 'check'))), 'check constraint'),
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->columns, fn (array $col): bool => $col['default'] !== null))), 'column default'),
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->columns, fn (array $col): bool => $col['generated'] !== null))), 'generated column'),
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->columns, fn (array $col): bool => $col['enum'] !== null))), 'enum value list'),
                ...$count($sum(fn (TableView $v): int => count(array_filter([$v->table['comment'], $v->table['note'], ...array_merge([], ...array_map(fn (array $col): array => [$col['comment'], $col['note']], $v->columns))], fn (mixed $t): bool => $t !== null))), 'comment or note', 'comments and notes'),
                ...$count($sum(fn (TableView $v): int => count(array_filter($v->foreignKeys, fn (array $f): bool => ($f['fk']['onDelete'] !== null && $f['fk']['onDelete'] !== 'NO ACTION') || ($f['fk']['onUpdate'] !== null && $f['fk']['onUpdate'] !== 'NO ACTION')))), 'referential action set', 'referential action sets'),
            ];

            if ($parts !== []) {
                $ctx->warn('unsupported_feature', 'connection', $c['id'], $c['name'], sprintf('A Mermaid ER diagram holds tables, columns, keys and foreign keys only; left out of %s: %s.', $c['name'], implode(', ', $parts)));
            }
        }
    }
}
