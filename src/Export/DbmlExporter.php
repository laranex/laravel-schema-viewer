<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * DBML as dbdiagram.io reads it, a port of laranex-schema-viewer-ui's export/dbml.ts: an optional Project note, named
 * enums, every table with its columns, keys, indexes, checks and notes, then one Ref per foreign key. Only physical
 * foreign keys become Refs; ORM relationships never do.
 */
final class DbmlExporter implements Exporter
{
    public function extension(): string
    {
        return 'dbml';
    }

    public function export(array $document, ?array $connections = null, bool $orm = false): array
    {
        $ctx = new ExportContext(Exporters::normalized($document), $connections);
        $ctx->sourceWarnings();

        if ($ctx->several) {
            $ctx->warn('lossy_conversion', 'document', null, null, 'DBML has one namespace per file: with several connections, each table is named by its connection and table, such as "app.public.users".');
        }

        $views = array_map($ctx->view(...), $ctx->scopedTables());
        $blocks = [];

        if ($ctx->doc['note'] !== null) {
            $blocks[] = 'Project '.self::name(count($ctx->connections) === 1 ? $ctx->connections[0]['name'] : 'schema')." {\n  Note: ".self::quoted($ctx->doc['note'])."\n}";
        }

        array_push($blocks, ...$this->enums($ctx, $views));

        foreach ($views as $view) {
            $blocks[] = $this->table($ctx, $view);
        }

        $refs = $this->references($ctx, $views);

        return ['content' => implode("\n\n", $blocks).($refs !== [] ? "\n\n".implode("\n", $refs) : '')."\n", 'warnings' => $ctx->warnings];
    }

    /**
     * A name as written: bare when it is an identifier, otherwise quoted with " and \ escaped.
     */
    public static function name(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $name).'"';
    }

    private static function type(string $type): string
    {
        return preg_match('/^[A-Za-z0-9_(),]+$/', $type) === 1 ? $type : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $type).'"';
    }

    /**
     * A single-quoted string: \ and ' escaped, line breaks as spaces.
     */
    private static function quoted(string $text): string
    {
        return "'".(string) preg_replace('/\r\n|\r|\n/', ' ', str_replace(['\\', "'"], ['\\\\', "\\'"], $text))."'";
    }

    private static function joined(?string ...$texts): string
    {
        return implode(' · ', array_filter($texts, fn (?string $t): bool => $t !== null));
    }

    /**
     * schema.table, or the connection-qualified name as one quoted name when the file holds several connections.
     *
     * @param  array<string, mixed>  $table
     */
    private static function tableRef(ExportContext $ctx, array $table): string
    {
        if ($ctx->several) {
            return self::name($ctx->labelOf($table));
        }

        return $table['schema'] !== null && $table['schema'] !== '' ? self::name($table['schema']).'.'.self::name($table['name']) : self::name($table['name']);
    }

    /**
     * @param  list<string>  $columns
     */
    private static function columnsRef(array $columns): string
    {
        return count($columns) === 1 ? self::name($columns[0]) : '('.implode(', ', array_map(self::name(...), $columns)).')';
    }

    /**
     * Named enum types, once each, in the order their columns appear.
     *
     * @param  list<TableView>  $views
     * @return list<string>
     */
    private function enums(ExportContext $ctx, array $views): array
    {
        $seen = [];
        $out = [];

        foreach ($views as $view) {
            foreach ($view->columns as $column) {
                $enum = $column['enum'];

                if ($enum === null || $enum['name'] === null) {
                    continue;
                }

                if (isset($seen[$enum['name']])) {
                    if ($seen[$enum['name']] !== $enum['values']) {
                        $ctx->warn('lossy_conversion', 'column', $view->table['id'], "{$view->label}.{$column['name']}", "Enum {$enum['name']} has different values on {$view->label}.{$column['name']}; DBML keeps the first list.");
                    }

                    continue;
                }

                $seen[$enum['name']] = $enum['values'];
                $out[] = 'Enum '.self::name($enum['name'])." {\n".implode("\n", array_map(fn (string $v): string => '  "'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $enum['values']))."\n}";
            }
        }

        return $out;
    }

    private function table(ExportContext $ctx, TableView $view): string
    {
        $table = $view->table;
        $label = $view->label;
        $lines = ['Table '.self::tableRef($ctx, $table).' {'];
        $primaryKey = $view->primaryKey();
        $single = count($primaryKey) === 1 ? $primaryKey[0] : null;
        // Single-column uniqueness written on the column, and so not repeated under indexes.
        $onColumn = [];

        foreach ($view->columns as $column) {
            $found = $view->uniqueColumn($column['name']);
            // Only an index the column setting says everything about; any other stays under indexes with its details.
            $unique = $found !== null && (! isset($found['parts']) || self::plain($found)) ? $found : null;

            if ($unique !== null) {
                $onColumn[$unique['id']] = true;

                if ($unique['name'] !== null) {
                    $kind = isset($unique['parts']) ? 'index' : 'constraint';
                    $ctx->warn('lossy_conversion', $kind, $unique['id'], $label, "The unique {$kind} {$unique['name']} on {$label}.{$column['name']} is written as the column's unique setting; its name is not kept.");
                }
            }

            $settings = array_values(array_filter([
                $single === $column['name'] ? 'pk' : '',
                $column['autoIncrement'] === true ? 'increment' : '',
                $column['nullable'] ? '' : 'not null',
                $unique !== null ? 'unique' : '',
                $this->defaultSetting($ctx, $view, $column),
                $column['comment'] !== null || $column['note'] !== null ? 'note: '.self::quoted(self::joined($column['comment'], $column['note'])) : '',
            ]));
            $type = ($column['enum']['name'] ?? null) !== null ? self::name($column['enum']['name']) : self::type($column['type']);
            $lines[] = '  '.self::name($column['name']).' '.$type.($settings !== [] ? ' ['.implode(', ', $settings).']' : '');
            $this->columnWarnings($ctx, $view, $column);
        }

        $indexLines = [];

        if (count($primaryKey) > 1) {
            $indexLines[] = '    ('.implode(', ', array_map(self::name(...), $primaryKey)).') [pk]';
        }

        foreach ($view->indexes as $index) {
            if (($index['backs']['kind'] ?? null) === 'primary_key' || isset($onColumn[$index['id']])) {
                continue;
            }

            $line = $this->indexLine($ctx, $view, $index);

            if ($line !== null) {
                $indexLines[] = $line;
            }
        }

        foreach ($view->constraints as $c) {
            $backed = array_filter($view->indexes, fn (array $i): bool => ($i['backs']['kind'] ?? null) === 'unique_constraint' && $i['backs']['constraint'] === $c['id']) !== [];

            if ($c['kind'] !== 'unique' || isset($onColumn[$c['id']]) || $backed) {
                continue;
            }

            $keys = [...array_map(self::name(...), $c['columns']), ...($c['expression'] !== null ? [$this->code($ctx, $view, $c['expression'])] : [])];
            $indexLines[] = '    '.(count($keys) === 1 ? $keys[0] : '('.implode(', ', $keys).')').' [unique'.($c['name'] !== null ? ', name: '.self::quoted($c['name']) : '').']';
            $ctx->warn('lossy_conversion', 'constraint', $c['id'], $label, 'The unique constraint '.($c['name'] ?? 'unnamed')." on {$label} is written as a unique index; DBML has no unique constraints apart from indexes.");
        }

        if ($indexLines !== []) {
            array_push($lines, '', '  indexes {', ...$indexLines);
            $lines[] = '  }';
        }

        $checkLines = [];

        foreach ($view->constraints as $c) {
            if ($c['kind'] !== 'check') {
                continue;
            }

            if ($c['expression'] === null) {
                $ctx->warn('skipped_entity', 'constraint', $c['id'], $label, 'The check constraint '.($c['name'] ?? 'unnamed')." on {$label} has no reported expression; it is left out.");

                continue;
            }

            $checkLines[] = '    '.$this->code($ctx, $view, $c['expression']).($c['name'] !== null ? ' [name: '.self::quoted($c['name']).']' : '');
        }

        if ($checkLines !== []) {
            array_push($lines, '', '  checks {', ...$checkLines);
            $lines[] = '  }';
        }

        if ($table['comment'] !== null || $table['note'] !== null) {
            $lines[] = '  Note: '.self::quoted(self::joined($table['comment'], $table['note']));
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * An index that only makes its columns unique: nothing a column's unique setting would lose but its name.
     *
     * @param  array<string, mixed>  $index
     */
    private static function plain(array $index): bool
    {
        if ($index['included'] !== [] || ExportContext::members($index['dialectOptions'] ?? null) !== [] || ($index['method'] !== null && strtolower($index['method']) !== 'btree')) {
            return false;
        }

        foreach ($index['parts'] as $p) {
            if ($p['order'] !== null || $p['nulls'] !== null || $p['collation'] !== null || $p['opclass'] !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * SQL text in backticks; a backtick inside it becomes a quote, as DBML has no escape for it.
     */
    private function code(ExportContext $ctx, TableView $view, string $sql): string
    {
        if (str_contains($sql, '`')) {
            $ctx->warn('lossy_conversion', 'table', $view->table['id'], $view->label, "SQL text on {$view->label} contains a backtick, which DBML cannot hold; it is written as a quote: {$sql}");
        }

        return '`'.str_replace('`', "'", $sql).'`';
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function defaultSetting(ExportContext $ctx, TableView $view, array $column): string
    {
        // An auto-increment column's default (an identity, a sequence's nextval) is the increment itself.
        if ($column['default'] === null || $column['autoIncrement'] === true) {
            return '';
        }

        return 'default: '.$this->code($ctx, $view, $column['default']['value']);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function columnWarnings(ExportContext $ctx, TableView $view, array $column): void
    {
        $at = "{$view->label}.{$column['name']}";

        if ($column['generated'] !== null) {
            $kind = $column['generated']['kind'];
            $ctx->warn('unsupported_feature', 'column', $view->table['id'], $at, "{$at} is a generated".($kind !== null && $kind !== '' ? " ({$kind})" : '').' column; DBML cannot state its expression: '.($column['generated']['expression'] ?? 'null'));
        }

        if ($column['collation'] !== null) {
            $ctx->warn('unsupported_feature', 'column', $view->table['id'], $at, "DBML has no column collation; {$at} uses {$column['collation']}.");
        }
    }

    /**
     * @param  array<string, mixed>  $index
     */
    private function indexLine(ExportContext $ctx, TableView $view, array $index): ?string
    {
        $label = $view->label;
        $name = $index['name'] ?? 'unnamed';
        $expressionParts = array_filter($index['parts'], fn (array $p): bool => $p['expression'] !== null) !== [];

        if ($index['expression'] && ! $expressionParts) {
            $ctx->warn('skipped_entity', 'index', $index['id'], $label, "Index {$name} on {$label} is on an expression whose text was not reported; it is left out.");

            return null;
        }

        $keys = array_map(fn (array $p): string => $p['column'] !== null ? self::name($p['column']) : $this->code($ctx, $view, (string) $p['expression']), $index['parts']);
        $lost = [];

        if ($index['partial']) {
            $lost[] = $index['predicate'] === null ? 'that it is partial (its predicate was not reported)' : "the predicate WHERE {$index['predicate']}";
        }

        if ($index['included'] !== []) {
            $lost[] = 'the included columns ('.implode(', ', $index['included']).')';
        }

        foreach ($index['parts'] as $p) {
            $key = $p['column'] ?? $p['expression'] ?? '';
            $details = array_values(array_filter([
                $p['order'] !== null && $p['order'] !== '' ? "order {$p['order']}" : '',
                $p['nulls'] !== null && $p['nulls'] !== '' ? "nulls {$p['nulls']}" : '',
                $p['length'] !== null ? "prefix length {$p['length']}" : '',
                $p['collation'] !== null && $p['collation'] !== '' ? "collation {$p['collation']}" : '',
                $p['opclass'] !== null && $p['opclass'] !== '' ? "operator class {$p['opclass']}" : '',
            ]));

            if ($details !== []) {
                $lost[] = "{$key}'s ".implode(', ', $details);
            }
        }

        $method = $index['method'] !== null ? strtolower($index['method']) : null;

        if ($method !== null && $method !== 'btree' && $method !== 'hash') {
            $lost[] = "the method {$index['method']}";
        }

        $options = array_keys(ExportContext::members($index['dialectOptions'] ?? null));

        if ($options !== []) {
            $lost[] = 'the dialect options ('.implode(', ', $options).')';
        }

        if ($lost !== []) {
            $ctx->warn('lossy_conversion', 'index', $index['id'], $label, "Index {$name} on {$label} loses ".implode('; ', $lost).': DBML cannot state them.');
        }

        $settings = array_values(array_filter([$index['unique'] ? 'unique' : '', $method === 'hash' ? 'type: hash' : '', $index['name'] !== null ? 'name: '.self::quoted($index['name']) : '']));

        return '    '.(count($keys) === 1 ? $keys[0] : '('.implode(', ', $keys).')').($settings !== [] ? ' ['.implode(', ', $settings).']' : '');
    }

    /**
     * @param  list<TableView>  $views
     * @return list<string>
     */
    private function references(ExportContext $ctx, array $views): array
    {
        $out = [];
        $written = [];

        foreach ($views as $view) {
            foreach ($view->foreignKeys as ['fk' => $fk, 'target' => $target]) {
                $from = self::tableRef($ctx, $view->table).'.'.self::columnsRef($fk['columns']);
                $to = self::tableRef($ctx, $target).'.'.self::columnsRef($fk['foreignColumns']);
                $pair = [$from, $to];
                sort($pair, SORT_STRING);
                $key = implode("\0", $pair);
                $name = $fk['name'] ?? 'unnamed';

                if (isset($written[$key])) {
                    $ctx->warn('skipped_entity', 'foreignKey', $fk['id'], $view->label, "Foreign key {$name} on {$view->label} has the same columns as {$written[$key]}; DBML allows one Ref per pair of endpoints, so it is left out.");

                    continue;
                }

                $written[$key] = $name;
                $actions = array_values(array_filter([
                    $fk['onDelete'] !== null && $fk['onDelete'] !== 'NO ACTION' ? 'delete: '.ExportContext::actionWords($fk['onDelete']) : '',
                    $fk['onUpdate'] !== null && $fk['onUpdate'] !== 'NO ACTION' ? 'update: '.ExportContext::actionWords($fk['onUpdate']) : '',
                ]));
                $out[] = 'Ref'.($fk['name'] !== null ? ' '.self::name($fk['name']) : '').": {$from} ".($view->unique($fk['columns']) ? '-' : '>')." {$to}".($actions !== [] ? ' ['.implode(', ', $actions).']' : '');

                if ($fk['deferrable'] === true || $fk['initially'] === 'DEFERRED') {
                    $ctx->warn('unsupported_feature', 'foreignKey', $fk['id'], $view->label, "DBML cannot state that foreign key {$name} on {$view->label} is deferrable".($fk['initially'] !== null && $fk['initially'] !== '' ? ', initially '.strtolower($fk['initially']) : '').'.');
                }
            }
        }

        return $out;
    }
}
