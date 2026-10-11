<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;

/**
 * A Mermaid erDiagram of one connection's database: tables, columns and foreign keys.
 */
final class MermaidExporter
{
    public function export(ConnectionSchema $connection): string
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

        foreach ($connection->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                $lines[] = sprintf(
                    '    %s ||--%s %s : "%s"',
                    $this->ident($fk->foreignTable),
                    $table->isUnique($fk->columns) ? 'o|' : 'o{',
                    $this->ident($table->name),
                    implode(', ', $fk->columns),
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function ident(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $value);
    }
}
