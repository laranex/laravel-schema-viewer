<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * DBML for dbdiagram.io: one connection's tables with their keys and indexes, then one Ref per foreign key.
 */
final class DbmlExporter
{
    /**
     * @param  string|null  $note  The project's note, written as the Project block's.
     */
    public function export(ConnectionSchema $connection, ?string $note = null): string
    {
        $blocks = [];

        if ($note !== null) {
            $blocks[] = 'Project '.$this->name($connection->name)." {\n  Note: '".$this->escape($note)."'\n}";
        }

        foreach ($connection->tables as $table) {
            $primary = [];
            $extraIndexes = [];
            foreach ($table->indexes as $index) {
                if ($index->primary) {
                    $primary = $index->columns;
                } elseif ($index->columns !== [] && (count($index->columns) > 1 || ! $index->unique)) {
                    // An index on expressions only, such as lower(email), reports no columns; DBML cannot name it.
                    $extraIndexes[] = $index;
                }
            }

            $lines = ['Table '.$this->name($table->name).' {'];
            foreach ($table->columns as $column) {
                $settings = array_filter([
                    count($primary) === 1 && $primary[0] === $column->name ? 'pk' : '',
                    $column->autoIncrement ? 'increment' : '',
                    $column->nullable ? '' : 'not null',
                    $this->isUniqueColumn($table, $column->name) ? 'unique' : '',
                    // An auto-increment column's default (PostgreSQL's nextval(...)) is the increment itself.
                    $column->default !== null && ! $column->autoIncrement ? 'default: `'.str_replace('`', "'", $column->default).'`' : '',
                    $column->comment !== null || $column->note !== null ? "note: '".$this->escape($this->joined($column->comment, $column->note))."'" : '',
                ]);
                $lines[] = '  '.$this->name($column->name).' '.$this->type($column->type).($settings !== [] ? ' ['.implode(', ', $settings).']' : '');
            }

            if (count($primary) > 1 || $extraIndexes !== []) {
                $lines[] = '';
                $lines[] = '  indexes {';

                if (count($primary) > 1) {
                    $lines[] = '    ('.implode(', ', array_map($this->name(...), $primary)).') [pk]';
                }
                foreach ($extraIndexes as $index) {
                    $columns = count($index->columns) > 1 ? '('.implode(', ', array_map($this->name(...), $index->columns)).')' : $this->name($index->columns[0]);
                    $lines[] = '    '.$columns.' ['.($index->unique ? 'unique, ' : '')."name: '".$this->escape($index->name)."']";
                }
                $lines[] = '  }';
            }

            if ($table->comment !== null || $table->note !== null) {
                $lines[] = "  Note: '".$this->escape($this->joined($table->comment, $table->note))."'";
            }

            $lines[] = '}';
            $blocks[] = implode("\n", $lines);
        }

        $refs = [];
        foreach ($connection->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                $settings = array_filter([$fk->onDelete !== 'no action' ? 'delete: '.$fk->onDelete : '', $fk->onUpdate !== 'no action' ? 'update: '.$fk->onUpdate : '']);
                $refs[] = sprintf(
                    'Ref %s: %s.%s %s %s.%s%s',
                    $this->name($fk->name),
                    $this->name($table->name),
                    $this->columns($fk->columns),
                    $table->isUnique($fk->columns) ? '-' : '>',
                    $this->name($fk->foreignTable),
                    $this->columns($fk->foreignColumns),
                    $settings !== [] ? ' ['.implode(', ', $settings).']' : '',
                );
            }
        }

        return implode("\n\n", $blocks).($refs !== [] ? "\n\n".implode("\n", $refs) : '')."\n";
    }

    /**
     * The database's comment and the config's note in DBML's one note: "comment · note".
     */
    private function joined(?string $comment, ?string $note): string
    {
        return implode(' · ', array_filter([$comment, $note], fn (?string $text): bool => $text !== null));
    }

    private function isUniqueColumn(Table $table, string $column): bool
    {
        foreach ($table->indexes as $index) {
            if ($index->unique && ! $index->primary && $index->columns === [$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function columns(array $columns): string
    {
        return count($columns) === 1 ? $this->name($columns[0]) : '('.implode(', ', array_map($this->name(...), $columns)).')';
    }

    private function name(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : '"'.str_replace('"', '', $name).'"';
    }

    private function type(string $type): string
    {
        return preg_match('/^[A-Za-z0-9_(),]+$/', $type) === 1 ? $type : '"'.str_replace('"', '', $type).'"';
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', "'", "\n"], ['\\\\', "\\'", ' '], $text);
    }
}
