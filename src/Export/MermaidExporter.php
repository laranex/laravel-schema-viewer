<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;

/**
 * A Mermaid erDiagram for one connection: solid lines are foreign keys, dotted lines relations without one.
 */
final class MermaidExporter
{
    public function export(SchemaDocument $document, ConnectionSchema $connection): string
    {
        $lines = ['erDiagram'];

        foreach ($connection->tables as $table) {
            $primary = [];
            foreach ($table->indexes as $index) {
                if ($index->primary) {
                    $primary = $index->columns;
                }
            }
            $foreign = [];
            foreach ($table->foreignKeys as $fk) {
                array_push($foreign, ...$fk->columns);
            }

            $lines[] = '    '.$this->ident($table->name).' {';
            foreach ($table->columns as $column) {
                $keys = implode(', ', array_filter([in_array($column->name, $primary, true) ? 'PK' : '', in_array($column->name, $foreign, true) ? 'FK' : '']));
                $lines[] = '        '.$this->ident($column->typeName).' '.$this->ident($column->name).($keys !== '' ? ' '.$keys : '');
            }
            $lines[] = '    }';
        }

        foreach ($document->links as $link) {
            if ($link->from->connection !== $connection->name || $link->to->connection !== $connection->name) {
                continue;
            }

            $many = ! ($connection->table($link->from->table)?->isUnique($link->from->columns) ?? false);
            $label = $link->relations !== [] ? implode(', ', array_map(fn (string $id): string => Names::relation($id), $link->relations)) : (string) $link->foreignKey;
            $lines[] = sprintf(
                '    %s ||%s%s %s : "%s"',
                $this->ident($link->to->table),
                $link->foreignKey !== null ? '--' : '..',
                $many ? 'o{' : 'o|',
                $this->ident($link->from->table),
                str_replace('"', "'", $label),
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function ident(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $value);
    }
}
