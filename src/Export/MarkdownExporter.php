<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * A Markdown reference of one connection's database: each table with its columns, indexes and foreign keys.
 */
final class MarkdownExporter
{
    public function export(ConnectionSchema $connection): string
    {
        $out = ['# Database schema: '.$connection->name.' ('.$connection->driver.')', '', count($connection->tables).' tables.'];

        foreach ($connection->tables as $table) {
            array_push($out, '', ...$this->table($table));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @return list<string>
     */
    private function table(Table $table): array
    {
        $primary = [];
        foreach ($table->indexes as $index) {
            if ($index->primary) {
                $primary = $index->columns;
            }
        }
        $foreign = [];
        foreach ($table->foreignKeys as $fk) {
            foreach ($fk->columns as $i => $column) {
                $foreign[$column] = $fk->foreignTable.'.'.($fk->foreignColumns[$i] ?? 'id');
            }
        }

        $out = ['## '.$table->name];

        if ($table->comment !== null) {
            array_push($out, '', $table->comment);
        }
        array_push($out, '', '| Column | Type | Null | Default | Key | Comment |', '| --- | --- | --- | --- | --- | --- |');

        foreach ($table->columns as $column) {
            $out[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s |',
                $column->name,
                $column->type,
                $column->nullable ? 'yes' : '',
                $column->default !== null ? '`'.$this->cell($column->default).'`' : '',
                in_array($column->name, $primary, true) ? 'PK' : (isset($foreign[$column->name]) ? 'FK → '.$foreign[$column->name] : ''),
                $column->comment !== null ? $this->cell($column->comment) : '',
            );
        }

        $indexes = array_filter($table->indexes, fn (Index $index): bool => ! $index->primary);

        if ($indexes !== []) {
            array_push($out, '', 'Indexes:', '');
            foreach ($indexes as $index) {
                $out[] = sprintf('- `%s` (%s)%s', $index->name, implode(', ', $index->columns), $index->unique ? ', unique' : '');
            }
        }

        if ($table->foreignKeys !== []) {
            array_push($out, '', 'Foreign keys:', '');
            foreach ($table->foreignKeys as $fk) {
                $out[] = sprintf(
                    '- `%s`: (%s) → %s (%s), on update %s, on delete %s',
                    $fk->name,
                    implode(', ', $fk->columns),
                    $fk->foreignTable,
                    implode(', ', $fk->foreignColumns),
                    $fk->onUpdate,
                    $fk->onDelete,
                );
            }
        }

        return $out;
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }
}
