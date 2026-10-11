<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\Link;
use Laranex\LaravelSchemaViewer\Schema\ModelInfo;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * A Markdown reference of one connection: each table with its columns, model, relations and keys, then the hints.
 * Written for people and coding agents alike.
 */
final class MarkdownExporter
{
    public function export(SchemaDocument $document, ConnectionSchema $connection): string
    {
        $out = ['# Database schema: '.$connection->name.' ('.$connection->driver.')', ''];
        $out[] = sprintf('%d tables, %d models, generated %s by laranex/laravel-schema-viewer.', count($connection->tables), count(array_filter($document->models, fn (ModelInfo $m): bool => $m->connection === $connection->name)), $document->generatedAt);

        foreach ($connection->tables as $table) {
            array_push($out, '', ...$this->table($document, $connection, $table));
        }

        $hints = array_filter($document->hints, fn (Hint $hint): bool => $hint->connection === $connection->name);

        if ($hints !== []) {
            array_push($out, '', '## Hints', '');
            foreach ($hints as $hint) {
                $out[] = sprintf('- **%s** (`%s`): %s', $hint->kind->value, $hint->table, $hint->message);
            }
        }

        return implode("\n", $out)."\n";
    }

    /**
     * @return list<string>
     */
    private function table(SchemaDocument $document, ConnectionSchema $connection, Table $table): array
    {
        $models = array_values(array_filter($document->models, fn (ModelInfo $m): bool => $m->connection === $connection->name && $m->table === $table->name));
        $casts = [];
        foreach ($models as $model) {
            $casts += $model->casts;
        }
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
        $meta = [];

        if ($models !== []) {
            $meta[] = 'Model: '.implode(', ', array_map(fn (ModelInfo $m): string => '`'.$m->class.'`', $models));
        } else {
            $meta[] = $table->framework ? 'Framework table, no model.' : 'No model.';
        }

        if ($table->comment !== null) {
            $meta[] = $table->comment;
        }
        array_push($out, '', implode(' ', $meta), '', '| Column | Type | Null | Default | Key | Cast |', '| --- | --- | --- | --- | --- | --- |');

        foreach ($table->columns as $column) {
            $key = in_array($column->name, $primary, true) ? 'PK' : (isset($foreign[$column->name]) ? 'FK → '.$foreign[$column->name] : '');
            $out[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s |',
                $column->name,
                $column->type,
                $column->nullable ? 'yes' : '',
                $column->default !== null ? '`'.str_replace('|', '\|', $column->default).'`' : '',
                $key,
                isset($casts[$column->name]) ? '`'.$casts[$column->name].'`' : '',
            );
        }

        $relations = array_values(array_filter($document->relations, fn (RelationInfo $r): bool => in_array($r->model, array_map(fn (ModelInfo $m): string => $m->class, $models), true)));

        if ($relations !== []) {
            array_push($out, '', 'Relations:', '');
            foreach ($relations as $relation) {
                $out[] = '- '.$this->relation($document, $relation);
            }
        }

        return $out;
    }

    private function relation(SchemaDocument $document, RelationInfo $relation): string
    {
        $text = sprintf('`%s()` %s `%s`', $relation->name, lcfirst($relation->type), Names::class($relation->related));

        if ($relation->pivot !== null) {
            $text .= sprintf(' through pivot `%s` (%s, %s)', $relation->pivot->table, $relation->pivot->foreignPivotKey, $relation->pivot->relatedPivotKey);
        } elseif ($relation->through !== null) {
            $text .= ' via '.implode(' → ', $relation->through);
        } elseif ($relation->from !== null && $relation->to !== null) {
            $text .= sprintf(' on %s.%s → %s.%s', $relation->from->table, implode(', ', $relation->from->columns), $relation->to->table, implode(', ', $relation->to->columns));
        }

        if ($relation->morph !== null) {
            $text .= sprintf(', morph columns %s and %s', $relation->morph->type, $relation->morph->id);
        }

        if ($relation->constraints !== []) {
            $text .= ', where '.implode(' and ', $relation->constraints);
        }

        $link = $this->linkOf($document, $relation);

        if ($link !== null && $relation->pivot === null) {
            $text .= $link->foreignKey !== null ? ' (foreign key '.$link->foreignKey.')' : ' (no foreign key)';
        }

        return $text;
    }

    private function linkOf(SchemaDocument $document, RelationInfo $relation): ?Link
    {
        foreach ($document->links as $link) {
            if (in_array($relation->id(), $link->relations, true)) {
                return $link;
            }
        }

        return null;
    }
}
