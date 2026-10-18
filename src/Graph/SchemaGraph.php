<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Graph;

use Illuminate\Support\Str;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\DerivedLink;
use Laranex\LaravelSchemaViewer\Schema\Endpoint;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\Schema\Link;
use Laranex\LaravelSchemaViewer\Schema\LinkState;
use Laranex\LaravelSchemaViewer\Schema\ModelInfo;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\Schema\UnresolvedRelation;

/**
 * Joins the database and the models: one link per pair of columns, inverse relations paired, and the hints.
 */
final class SchemaGraph
{
    /**
     * The models of the document being built, by class.
     *
     * @var array<string, ModelInfo>
     */
    private array $models = [];

    /**
     * The morphOne / morphMany relations of the document being built, by their columns and morph type: the targets of
     * a morphTo on the same columns.
     *
     * @var array<string, list<RelationInfo>>
     */
    private array $morphTargets = [];

    public function __construct(private readonly StructuralHints $structural = new StructuralHints) {}

    /**
     * @param  list<ConnectionSchema>  $connections
     * @param  list<ModelInfo>  $models
     * @param  list<RelationInfo>  $relations
     * @param  list<UnresolvedRelation>  $unresolved
     * @param  list<IgnoreRule>  $ignore  Hints to leave out.
     * @param  string|null  $note  The project's note, from the notes config.
     * @param  array<string, array<string, string>>  $relationshipNotes  Notes on relationships by the table that holds the key (plain, or
     *                                                                   prefixed with a connection and a colon, which wins), then by its columns, comma separated.
     */
    public function build(array $connections, array $models, array $relations, array $unresolved, array $ignore = [], ?string $generatedAt = null, ?string $note = null, array $relationshipNotes = []): SchemaDocument
    {
        $this->models = [];

        foreach ($models as $model) {
            $this->models[$model->class] ??= $model;
        }

        $this->morphTargets = [];

        foreach ($relations as $other) {
            if ($other->from !== null && $other->to !== null && $other->morph !== null && $other->type !== 'MorphTo') {
                $this->morphTargets[$other->from->key().'|'.$other->morph->type][] = $other;
            }
        }

        try {
            return $this->document($connections, $models, $relations, $unresolved, $ignore, $generatedAt, $note, $relationshipNotes);
        } finally {
            $this->models = $this->morphTargets = [];
        }
    }

    /**
     * @param  list<ConnectionSchema>  $connections
     * @param  list<ModelInfo>  $models
     * @param  list<RelationInfo>  $relations
     * @param  list<UnresolvedRelation>  $unresolved
     * @param  list<IgnoreRule>  $ignore  Hints to leave out.
     * @param  array<string, array<string, string>>  $relationshipNotes
     */
    private function document(array $connections, array $models, array $relations, array $unresolved, array $ignore, ?string $generatedAt, ?string $note, array $relationshipNotes): SchemaDocument
    {
        $this->pairInverses($relations);

        /** @var array<string, array{from: Endpoint, to: Endpoint, foreignKey: string|null, relations: list<string>, morph: bool}> $links */
        $links = [];
        $add = function (Endpoint $from, Endpoint $to, ?string $foreignKey, ?string $relation, bool $morph) use (&$links): void {
            $key = $from->key().'>'.$to->key();
            $links[$key] ??= ['from' => $from, 'to' => $to, 'foreignKey' => null, 'relations' => [], 'morph' => false];
            $links[$key]['foreignKey'] ??= $foreignKey;
            $links[$key]['morph'] = $links[$key]['morph'] || $morph;

            if ($relation !== null && ! in_array($relation, $links[$key]['relations'], true)) {
                $links[$key]['relations'][] = $relation;
            }
        };

        foreach ($connections as $connection) {
            foreach ($connection->tables as $table) {
                foreach ($table->foreignKeys as $fk) {
                    $add(
                        new Endpoint($connection->name, $table->name, $fk->columns),
                        new Endpoint($connection->name, $fk->foreignTable, $fk->foreignColumns),
                        $fk->name,
                        null,
                        false,
                    );
                }
            }
        }

        $derived = [];

        foreach ($relations as $relation) {
            foreach ($this->endpointsOf($relation) as [$from, $to, $morph]) {
                $add($from, $to, null, $relation->id(), $morph);
            }

            if ($relation->through !== null && count($relation->through) >= 2) {
                $model = $this->model($relation->model);
                $derived[] = new DerivedLink(
                    id: 'd'.substr(hash('sha256', $relation->id()), 0, 10),
                    connection: $model->connection ?? '',
                    from: $relation->through[0],
                    to: $relation->through[count($relation->through) - 1],
                    via: array_slice($relation->through, 1, -1),
                    relation: $relation->id(),
                );
            }
        }

        ksort($links);

        $built = array_map(fn (string $key, array $link): Link => new Link(
            id: 'l'.substr(hash('sha256', $key), 0, 10),
            from: $link['from'],
            to: $link['to'],
            foreignKey: $link['foreignKey'],
            relations: $link['relations'],
            morph: $link['morph'],
            note: $relationshipNotes[$link['from']->connection.':'.$link['from']->table][implode(',', $link['from']->columns)]
                ?? $relationshipNotes[$link['from']->table][implode(',', $link['from']->columns)]
                ?? null,
        ), array_keys($links), $links);

        $hints = array_values(array_filter(
            $this->hints($connections, $models, $relations, $unresolved, $built),
            function (Hint $hint) use ($ignore): bool {
                foreach ($ignore as $rule) {
                    if ($rule->matches($hint)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return new SchemaDocument(
            generatedAt: $generatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            connections: $connections,
            models: $models,
            relations: $relations,
            unresolved: $unresolved,
            links: $built,
            derivedLinks: $derived,
            hints: $hints,
            note: $note,
        );
    }

    /**
     * The column pairs a relation uses, as [from, to, morph] triples.
     *
     * @return list<array{Endpoint, Endpoint, bool}>
     */
    private function endpointsOf(RelationInfo $relation): array
    {
        $morph = $relation->morph !== null;

        if ($relation->pivot !== null) {
            $parent = $this->model($relation->model);
            $related = $this->model($relation->related);

            if ($parent === null || $related === null) {
                return [];
            }

            $pivot = $relation->pivot;
            $morphColumn = $relation->morph?->id;

            return [
                [new Endpoint($parent->connection, $pivot->table, [$pivot->foreignPivotKey]), new Endpoint($parent->connection, $parent->table, [$pivot->parentKey]), $pivot->foreignPivotKey === $morphColumn],
                [new Endpoint($parent->connection, $pivot->table, [$pivot->relatedPivotKey]), new Endpoint($related->connection, $related->table, [$pivot->relatedKey]), $pivot->relatedPivotKey === $morphColumn],
            ];
        }

        if ($relation->type === 'MorphTo' && $relation->from !== null && $relation->morph !== null) {
            // A morphTo names no target: every morphOne / morphMany pointing back at its columns is one.
            $targets = [];

            foreach ($this->morphTargets[$relation->from->key().'|'.$relation->morph->type] ?? [] as $other) {
                if ($other->to !== null) {
                    $targets[$other->to->key()] = [$relation->from, $other->to, true];
                }
            }

            return array_values($targets);
        }

        return $relation->from !== null && $relation->to !== null ? [[$relation->from, $relation->to, $morph]] : [];
    }

    /**
     * Mark relations that describe the same link from opposite models as each other's inverse.
     * When several fit, the plain one wins: no extra conditions, and hasMany over hasOne for a belongsTo.
     *
     * @param  list<RelationInfo>  $relations
     */
    private function pairInverses(array $relations): void
    {
        // Only relations on the same pivot columns, or from the same columns, can pair: bucket them by those.
        $byPivot = [];
        $byFrom = [];

        foreach ($relations as $i => $relation) {
            if ($relation->pivot !== null) {
                $byPivot[$relation->pivot->table.'|'.$relation->pivot->relatedPivotKey][] = $i;
            }

            if ($relation->from !== null) {
                $byFrom[$relation->from->key()][] = $i;
            }
        }

        foreach ($relations as $a) {
            $best = null;
            $bestScore = -1;
            $candidates = [
                ...($a->pivot !== null ? $byPivot[$a->pivot->table.'|'.$a->pivot->foreignPivotKey] ?? [] : []),
                ...($a->from !== null ? $byFrom[$a->from->key()] ?? [] : []),
            ];
            $candidates = array_unique($candidates);
            sort($candidates);

            foreach ($candidates as $i) {
                $b = $relations[$i];

                // A morphTo names no related model (it reports its own class), so only the other side is checked.
                $modelsMatch = ($a->type === 'MorphTo' || $a->related === $b->model) && ($b->type === 'MorphTo' || $b->related === $a->model);

                if ($a === $b || ! $modelsMatch || ! $this->samePair($a, $b)) {
                    continue;
                }

                $score = ($b->constraints === [] ? 2 : 0) + (in_array($b->type, ['HasMany', 'MorphMany', 'BelongsTo', 'MorphTo', 'BelongsToMany', 'MorphToMany'], true) ? 1 : 0);

                if ($score > $bestScore) {
                    $best = $b;
                    $bestScore = $score;
                }
            }

            $a->inverse = $best?->id();
        }
    }

    private function samePair(RelationInfo $a, RelationInfo $b): bool
    {
        if ($a->pivot !== null && $b->pivot !== null) {
            return $a->pivot->table === $b->pivot->table && $a->pivot->foreignPivotKey === $b->pivot->relatedPivotKey;
        }

        if ($a->from === null || $b->from === null || $a->from->key() !== $b->from->key()) {
            return false;
        }

        if ($a->type === 'MorphTo' || $b->type === 'MorphTo') {
            return $a->morph !== null && $b->morph !== null && $a->morph->type === $b->morph->type;
        }

        return $a->to !== null && $b->to !== null && $a->to->key() === $b->to->key();
    }

    /**
     * @param  list<ConnectionSchema>  $connections
     * @param  list<ModelInfo>  $models
     * @param  list<RelationInfo>  $relations
     * @param  list<UnresolvedRelation>  $unresolved
     * @param  list<Link>  $links
     * @return list<Hint>
     */
    private function hints(array $connections, array $models, array $relations, array $unresolved, array $links): array
    {
        $hints = [];
        $inspected = [];
        $byId = [];

        foreach ($relations as $i => $relation) {
            $byId[$relation->id()] ??= [$i, $relation];
        }

        $pivots = [];

        foreach ($relations as $relation) {
            $model = $relation->pivot !== null ? $this->model($relation->model) : null;

            if ($relation->pivot !== null && $model !== null) {
                $keys = [$relation->pivot->foreignPivotKey, $relation->pivot->relatedPivotKey];

                // A polymorphic pivot row is told apart by its type column too, next to the id it goes with.
                if ($relation->morph !== null && ! in_array($relation->morph->type, $keys, true)) {
                    $at = array_search($relation->morph->id, $keys, true);
                    array_splice($keys, $at === false ? count($keys) : (int) $at, 0, [$relation->morph->type]);
                }

                $pivots[$model->connection][$relation->pivot->table] ??= $keys;
            }
        }

        foreach ($connections as $connection) {
            $inspected[$connection->name] = $connection;

            foreach ($connection->tables as $table) {
                foreach ($table->foreignKeys as $fk) {
                    if (! $table->framework && ! $table->isIndexed($fk->columns)) {
                        $hints[] = new Hint(HintKind::ForeignKeyWithoutIndex, $connection->name, $table->name, sprintf(
                            '%s.%s has a foreign key but no index that starts with it, so deleting or updating %s row scans the whole table.',
                            $table->name, implode(', ', $fk->columns), $this->article(Str::singular($fk->foreignTable)),
                        ), implode(',', $fk->columns));
                    }
                }
            }

            array_push($hints, ...$this->structural->hints($connection, $pivots[$connection->name] ?? []));
        }

        foreach ($links as $link) {
            $crossesConnections = $link->from->connection !== $link->to->connection;

            $fromTable = ($inspected[$link->from->connection] ?? null)?->table($link->from->table);

            if ($link->state() === LinkState::RelationOnly && ! $link->morph && ! $crossesConnections && isset($inspected[$link->from->connection]) && ! $fromTable?->framework) {
                $hints[] = $this->relationWithoutForeignKey($link, $byId, $inspected[$link->to->connection] ?? null);
            }

            if ($link->state() === LinkState::ForeignKeyOnly && ! ($inspected[$link->from->connection] ?? null)?->table($link->from->table)?->framework) {
                $hints[] = new Hint(HintKind::ForeignKeyWithoutRelation, $link->from->connection, $link->from->table, sprintf(
                    '%s links %s to %s, but no model declares a relation for it.',
                    $link->foreignKey, $link->from->table, $link->to->table,
                ), implode(',', $link->from->columns));
            }
        }

        foreach ($models as $model) {
            $connection = $inspected[$model->connection] ?? null;

            if ($connection !== null && $connection->table($model->table) === null) {
                $hints[] = new Hint(HintKind::ModelWithoutTable, $model->connection, $model->table, sprintf(
                    '%s uses the %s table, which does not exist on the %s connection.',
                    $model->class, $model->table, $model->connection,
                ), model: $model->class);
            }
        }

        $morphTables = [];

        foreach ($relations as $relation) {
            if ($relation->morph?->class !== null && str_contains($relation->morph->class, '\\')) {
                $table = $relation->pivot->table ?? $relation->from->table ?? null;
                $model = $this->model($relation->model);

                // Like the other hints, none for a framework table or a table that is not there.
                $inspectedTable = $table !== null && $model !== null ? ($inspected[$model->connection] ?? null)?->table($table) : null;

                if ($inspectedTable !== null && ! $inspectedTable->framework) {
                    $morphTables[$model->connection.'|'.$table] ??= [$model->connection, $table, $relation->morph->type, $relation->morph->class];
                }
            }
        }

        foreach ($morphTables as [$connection, $table, $column, $class]) {
            $hints[] = new Hint(HintKind::MorphWithoutMap, $connection, $table, sprintf(
                '%s.%s stores class names such as %s, so renaming a model breaks existing rows. Map them with Relation::enforceMorphMap().',
                $table, $column, $class,
            ), $column);
        }

        foreach ($unresolved as $failure) {
            $model = $this->model($failure->model);

            if ($failure->method === '__construct') {
                $hints[] = new Hint(HintKind::UnresolvedRelation, '', '', sprintf(
                    '%s could not be read: constructing it threw %s',
                    $failure->model, $failure->reason,
                ), relation: $failure->model.'::'.$failure->method);

                continue;
            }

            $hints[] = $failure->package === null
                ? new Hint(HintKind::UnresolvedRelation, $model->connection ?? '', $model->table ?? '', sprintf(
                    '%s::%s() could not be read (the viewer calls it on a blank model, with no row loaded): %s',
                    $failure->model, $failure->method, $failure->reason,
                ), relation: $failure->model.'::'.$failure->method)
                : new Hint(HintKind::UnresolvedPackageRelation, $model->connection ?? '', $model->table ?? '', sprintf(
                    '%s::%s() comes from %s and could not be read (the viewer calls it on a blank model, with no row loaded), which is common for package relations that need one: %s',
                    $failure->model, $failure->method, $failure->package, $failure->reason,
                ), relation: $failure->model.'::'.$failure->method);
        }

        return $hints;
    }

    /**
     * A relation-only link. Relations that join other tables, or that match a column that is not a unique key, are not
     * the whole link, so their foreign key is noted rather than flagged.
     *
     * @param  array<string, array{int, RelationInfo}>  $relations  The relations by id, with their position.
     */
    private function relationWithoutForeignKey(Link $link, array $relations, ?ConnectionSchema $connection): Hint
    {
        $used = [];

        foreach ($link->relations as $id) {
            if (isset($relations[$id])) {
                $used[$relations[$id][0]] = $relations[$id][1];
            }
        }

        ksort($used);
        $labels = implode(' and ', array_map($this->label(...), $link->relations));
        $target = $connection?->table($link->to->table);
        $columns = sprintf('%s.%s to %s.%s', $link->from->table, implode(', ', $link->from->columns), $link->to->table, implode(', ', $link->to->columns));

        $joins = [];

        foreach ($used as $relation) {
            $joined = array_values(array_filter($relation->constraints, fn (string $constraint): bool => str_starts_with($constraint, 'join ')));

            if ($joined === []) {
                $joins = [];

                break;
            }

            array_push($joins, ...array_map(fn (string $constraint): string => substr($constraint, 5), $joined));
        }

        if ($joins !== []) {
            return new Hint(HintKind::CustomRelation, $link->from->connection, $link->from->table, sprintf(
                '%s %s %s, so matching %s is only part of the link. Custom query, foreign key not checked.',
                $labels, count($link->relations) > 1 ? 'join' : 'joins', implode(', ', array_unique($joins)), $columns,
            ), implode(',', $link->from->columns));
        }

        if ($target !== null && ! $target->isUnique($link->to->columns)) {
            return new Hint(HintKind::CustomRelation, $link->from->connection, $link->from->table, sprintf(
                '%s %s %s, which is not a unique key, so no foreign key can back it. Custom keys, foreign key not checked.',
                $labels, count($link->relations) > 1 ? 'match' : 'matches', $columns,
            ), implode(',', $link->from->columns));
        }

        return new Hint(HintKind::RelationWithoutForeignKey, $link->from->connection, $link->from->table, sprintf(
            '%s %s %s.%s, which has no foreign key to %s, so rows can point to %s that no longer exists.',
            $labels, count($link->relations) > 1 ? 'use' : 'uses',
            $link->from->table, implode(', ', $link->from->columns), $link->to->table, $this->article(Str::singular($link->to->table)),
        ), implode(',', $link->from->columns));
    }

    /**
     * A word with "a" or "an" before it, by its sound for the common cases: an asn, an oauth_client, an upload, a user.
     */
    private function article(string $word): string
    {
        $vowelSound = preg_match('/^([aeio]|u[^aeiou][^aeiou]|u$)/i', $word) === 1;

        return ($vowelSound ? 'an ' : 'a ').$word;
    }

    private function model(string $class): ?ModelInfo
    {
        return $this->models[$class] ?? null;
    }

    private function label(string $relationId): string
    {
        [$class, $method] = explode('::', $relationId);

        return class_basename($class).'::'.$method.'()';
    }
}
