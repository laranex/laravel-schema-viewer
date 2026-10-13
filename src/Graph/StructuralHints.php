<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Graph;

use Illuminate\Support\Str;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\ForeignKey;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * Hints about the database alone, where it could be better whatever the models say: tables without a primary key,
 * foreign keys whose columns do not match what they reference, and indexes that duplicate or cover each other.
 * Framework tables get none; package tables do.
 */
final class StructuralHints
{
    /**
     * Column types that hold text, after ColumnType::normalize(): what a morph type column is.
     */
    private const TEXT_TYPES = ['varchar', 'char', 'text', 'nvarchar', 'nchar', 'enum'];

    /**
     * @param  array<string, list<string>>  $pivots  The key columns of the tables relations use as a pivot, by table name.
     * @return list<Hint>
     */
    public function hints(ConnectionSchema $connection, array $pivots = []): array
    {
        $hints = [];

        foreach ($connection->tables as $table) {
            if ($table->framework) {
                continue;
            }

            array_push(
                $hints,
                ...$this->primaryKey($connection, $table, $pivots[$table->name] ?? null),
                ...$this->foreignKeys($connection, $table),
                ...($table->indexDetails ? $this->redundantIndexes($connection, $table) : []),
                ...$this->morphs($connection, $table),
            );
        }

        return $hints;
    }

    /**
     * @param  list<string>|null  $pivot
     * @return list<Hint>
     */
    private function primaryKey(ConnectionSchema $connection, Table $table, ?array $pivot): array
    {
        foreach ($table->indexes as $index) {
            if ($index->primary) {
                return [];
            }
        }

        $message = sprintf('%s has no primary key, so one row cannot be updated or deleted reliably and some replication setups refuse the table.', $table->name);

        foreach ($table->indexDetails ? $table->indexes : [] as $index) {
            $notNull = array_filter($index->keyColumns(), fn (string $column): bool => $table->column($column)?->nullable === false);

            if ($index->unique && $index->plain() && count($notNull) === count($index->keyColumns())) {
                $message .= sprintf(' Its unique index %s (%s) could become the primary key.', $index->name, implode(', ', $index->keyColumns()));

                break;
            }
        }

        if ($pivot !== null) {
            $message .= sprintf(' Add a composite primary key on (%s) so a pair cannot be attached twice.', implode(', ', $pivot));
        }

        return [new Hint(HintKind::TableWithoutPrimaryKey, $connection->name, $table->name, $message)];
    }

    /**
     * @return list<Hint>
     */
    private function foreignKeys(ConnectionSchema $connection, Table $table): array
    {
        $hints = [];

        foreach ($table->foreignKeys as $fk) {
            $mismatch = $this->typeMismatch($connection, $table, $fk);

            if ($mismatch !== null) {
                $hints[] = $mismatch;
            }

            $name = $this->nameMismatch($connection, $table, $fk);

            if ($name !== null) {
                $hints[] = $name;
            }
        }

        return $hints;
    }

    private function typeMismatch(ConnectionSchema $connection, Table $table, ForeignKey $fk): ?Hint
    {
        $foreign = $connection->table($fk->foreignTable);

        if ($foreign === null || count($fk->columns) !== count($fk->foreignColumns)) {
            return null;
        }

        $pairs = [];

        foreach ($fk->columns as $i => $name) {
            $column = $table->column($name);
            // SQLite leaves the referenced columns empty when the key names only the table.
            $referenced = $fk->foreignColumns[$i] !== '' ? $foreign->column($fk->foreignColumns[$i]) : null;

            if ($column === null || $referenced === null) {
                return null;
            }

            if (ColumnType::differ($connection->driver, $column, $referenced)) {
                $pairs[] = sprintf('%s.%s is %s but %s.%s, which it references, is %s', $table->name, $column->name, $column->type, $foreign->name, $referenced->name, $referenced->type);
            }
        }

        if ($pairs === []) {
            return null;
        }

        return new Hint(HintKind::ForeignKeyTypeMismatch, $connection->name, $table->name, sprintf(
            '%s; give them the same type so every value fits and lookups use the index.',
            implode('; ', $pairs),
        ), implode(',', $fk->columns));
    }

    private function nameMismatch(ConnectionSchema $connection, Table $table, ForeignKey $fk): ?Hint
    {
        if (count($fk->columns) !== 1 || preg_match('/^(.+)_id$/', $fk->columns[0], $match) !== 1) {
            return null;
        }

        $referenced = $this->unqualified($fk->foreignTable);
        $names = array_map(fn (Table $other): string => $this->unqualified($other->name), $connection->tables);

        foreach (array_unique([Str::plural($match[1]), $match[1]]) as $candidate) {
            if (! in_array($candidate, $names, true)) {
                continue;
            }

            // category_id may point at post_categories: a table named after it with a prefix is fine.
            if ($referenced === $candidate || str_ends_with($referenced, '_'.$candidate)) {
                return null;
            }

            return new Hint(HintKind::ForeignKeyNameMismatch, $connection->name, $table->name, sprintf(
                '%s.%s is named after %s, but its foreign key %s references %s. Rename the column or point the key at %s.',
                $table->name, $fk->columns[0], $candidate, $fk->name, $fk->foreignTable, $candidate,
            ), $fk->columns[0]);
        }

        return null;
    }

    /**
     * Each plain index that only adds work: one on the primary key's columns, several on the same columns, or one whose
     * columns another index starts with. Partial, expression, prefix and INCLUDE indexes, and indexes of another
     * structure (fulltext, gin, …), serve other queries and are never compared.
     *
     * @return list<Hint>
     */
    private function redundantIndexes(ConnectionSchema $connection, Table $table): array
    {
        $plain = array_values(array_filter($table->indexes, fn (Index $index): bool => $index->plain()));
        $primary = [];

        foreach ($plain as $index) {
            if ($index->primary) {
                $primary[implode(',', $index->keyColumns())] ??= $index;
            }
        }

        $primary = array_values($primary);
        $others = array_values(array_filter($plain, fn (Index $index): bool => ! $index->primary));
        usort($others, fn (Index $a, Index $b): int => strcmp($a->name, $b->name));

        $hints = [];
        $done = [];

        foreach ($others as $index) {
            foreach ($primary as $key) {
                if ($key->typeFamily() === $index->typeFamily() && $key->keyColumns() === $index->keyColumns()) {
                    $done[$index->name] = true;
                    $hints[] = new Hint(HintKind::IndexDuplicatesPrimaryKey, $connection->name, $table->name, sprintf(
                        '%s.%s indexes (%s), the same columns as the primary key, so it only adds work to every write. Drop it.',
                        $table->name, $index->name, implode(', ', $index->keyColumns()),
                    ), implode(',', $index->keyColumns()));

                    break;
                }
            }
        }

        $groups = [];

        foreach ($others as $index) {
            if (! isset($done[$index->name])) {
                $groups[implode(',', $index->keyColumns()).'|'.($index->unique ? 'u' : '').'|'.$index->typeFamily()][] = $index;
            }
        }

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $index) {
                $done[$index->name] = true;
            }

            $hints[] = new Hint(HintKind::DuplicateIndex, $connection->name, $table->name, sprintf(
                '%s has %d %sindexes on (%s): %s. Every write updates each one; keep one.',
                $table->name, count($group), $group[0]->unique ? 'unique ' : '', implode(', ', $group[0]->keyColumns()),
                implode(', ', array_map(fn (Index $index): string => $index->name, $group)),
            ), implode(',', $group[0]->keyColumns()));
        }

        $covering = [...$primary, ...$others];

        foreach ($others as $index) {
            if (isset($done[$index->name]) || $index->unique) {
                continue;
            }

            $key = $index->keyColumns();

            foreach ($covering as $other) {
                $otherKey = $other->keyColumns();
                $covers = $other !== $index && $other->typeFamily() === $index->typeFamily() && (
                    (count($otherKey) > count($key) && array_slice($otherKey, 0, count($key)) === $key)
                    || (($other->unique || $other->primary) && $otherKey === $key)
                );

                if ($covers) {
                    $hints[] = new Hint(HintKind::RedundantIndex, $connection->name, $table->name, sprintf(
                        '%s.%s on (%s) is covered by %s on (%s), which starts with the same columns, so it adds work to every write. Drop it unless a query needs the smaller index.',
                        $table->name, $index->name, implode(', ', $key), $other->name, implode(', ', $otherKey),
                    ), implode(',', $key));

                    break;
                }
            }
        }

        return $hints;
    }

    /**
     * @return list<Hint>
     */
    private function morphs(ConnectionSchema $connection, Table $table): array
    {
        $hints = [];

        foreach ($table->columns as $column) {
            if (preg_match('/^(.+)_type$/', $column->name, $match) !== 1) {
                continue;
            }

            $id = $match[1].'_id';

            if (! $table->hasColumn($id) || ! in_array(ColumnType::normalize($connection->driver, $column)['base'], self::TEXT_TYPES, true)) {
                continue;
            }

            foreach ($table->indexes as $index) {
                $key = $index->keyColumns();

                if (in_array($column->name, $key, true) && in_array($id, $key, true)) {
                    continue 2;
                }
            }

            $hints[] = new Hint(HintKind::MorphWithoutIndex, $connection->name, $table->name, sprintf(
                "%s.%s and %s look like a polymorphic pair, but no index covers both, so loading these relations scans the table. Add \$table->index(['%s', '%s']).",
                $table->name, $column->name, $id, $column->name, $id,
            ), $column->name.','.$id);
        }

        return $hints;
    }

    private function unqualified(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }
}
