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
    /**
     * @param  string|null  $note  The project's note, written under the title.
     */
    public function export(ConnectionSchema $connection, ?string $note = null): string
    {
        $out = ['# Database schema: '.$this->text($connection->name).' ('.$this->text($connection->driver).')', ''];

        if ($note !== null) {
            array_push($out, $this->text($note), '');
        }

        $out[] = count($connection->tables).' tables.';

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
                $foreign[$column] = $this->text($fk->foreignTable.'.'.($fk->foreignColumns[$i] ?? 'id'));
            }
        }

        $out = ['## '.$this->text($table->name)];

        if ($table->comment !== null) {
            array_push($out, '', $this->text($table->comment));
        }

        if ($table->note !== null) {
            array_push($out, '', 'Note: '.$this->text($table->note));
        }
        array_push($out, '', '| Column | Type | Null | Default | Key | Comment |', '| --- | --- | --- | --- | --- | --- |');

        foreach ($table->columns as $column) {
            $out[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s |',
                $this->code($column->name),
                $this->text($column->type),
                $column->nullable ? 'yes' : '',
                $column->default !== null ? '`'.$this->code($column->default).'`' : '',
                in_array($column->name, $primary, true) ? 'PK' : (isset($foreign[$column->name]) ? 'FK → '.$foreign[$column->name] : ''),
                $this->text(implode(' · ', array_filter([$column->comment, $column->note], fn (?string $text): bool => $text !== null))),
            );
        }

        $indexes = array_filter($table->indexes, fn (Index $index): bool => ! $index->primary);

        if ($indexes !== []) {
            array_push($out, '', 'Indexes:', '');
            foreach ($indexes as $index) {
                $out[] = sprintf('- `%s` (%s)%s', $this->code($index->name), $this->text(implode(', ', $index->columns)), $index->unique ? ', unique' : '');
            }
        }

        if ($table->foreignKeys !== []) {
            array_push($out, '', 'Foreign keys:', '');
            foreach ($table->foreignKeys as $fk) {
                $out[] = sprintf(
                    '- `%s`: (%s) → %s (%s), on update %s, on delete %s',
                    $this->code($fk->name),
                    $this->text(implode(', ', $fk->columns)),
                    $this->text($fk->foreignTable),
                    $this->text(implode(', ', $fk->foreignColumns)),
                    $this->text($fk->onUpdate),
                    $this->text($fk->onDelete),
                );
            }
        }

        return $out;
    }

    /**
     * Text from the database as plain Markdown: no HTML, no code spans, no table cells broken by a pipe or a newline.
     */
    private function text(string $text): string
    {
        return str_replace(['&', '<', '>', '|', '`', "\r\n", "\r", "\n"], ['&amp;', '&lt;', '&gt;', '\|', '\`', ' ', ' ', ' '], $text);
    }

    /**
     * Text from the database inside a code span, which shows it literally: only a backtick could close the span.
     */
    private function code(string $text): string
    {
        return str_replace(['`', '|', "\r\n", "\r", "\n"], ["'", '\|', ' ', ' ', ' '], $text);
    }
}
