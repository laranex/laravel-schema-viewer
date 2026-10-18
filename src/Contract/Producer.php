<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use Laranex\LaravelSchemaViewer\Schema\Column;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\Endpoint;
use Laranex\LaravelSchemaViewer\Schema\ForeignKey;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\IgnoreRule;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\ModelInfo;
use Laranex\LaravelSchemaViewer\Schema\RelationInfo;
use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use Laranex\LaravelSchemaViewer\Schema\Source;
use Laranex\LaravelSchemaViewer\Schema\Table;

/**
 * The serialization boundary: the schema the package read (src/Schema) as a laranex-schema 2.0 document, the only code
 * that knows the document's field names.
 *
 * Laravel documents are combined: tables, columns, indexes and foreign keys come from the database (provenance
 * database), models and relationships from Eloquent. Ids: connection:<name> for connections, <connection>:<table> for
 * tables (schema-qualified outside the default schema), <table id>:index:<name> and <table id>:fk:<name> for indexes
 * and foreign keys, the class for models and Class::method for relationships. Links, derived links and inferred
 * associations follow the shared rules (Graph, Inference). Eloquent's own facts are in extensions.eloquent, a hint's
 * Laravel kind and ignore rule in extensions.laravel. The package computes no comparisons and no migration tracks, so
 * both are always empty. The result is strictly validated.
 */
final class Producer
{
    public const GENERATOR = 'laravel-schema-viewer';

    private const DRIVERS = ['pgsql' => 'postgresql'];

    /**
     * How each hint kind reads as a diagnostic: the shared kind where one means the same, and its category.
     */
    private const KINDS = [
        'relation_without_foreign_key' => ['relationship_without_foreign_key', 'lint'],
        'foreign_key_without_relation' => ['foreign_key_without_relationship', 'lint'],
        'foreign_key_without_index' => ['missing_index', 'lint'],
        'model_without_table' => ['model_without_table', 'reconciliation'],
        'morph_without_map' => ['morph_without_map', 'lint'],
        'unresolved_relation' => ['unresolved_relationship', 'extraction'],
        'unresolved_package_relation' => ['unresolved_relationship', 'extraction'],
        'custom_relation' => ['custom_relation', 'lint'],
        'table_without_primary_key' => ['table_without_primary_key', 'lint'],
        'foreign_key_type_mismatch' => ['foreign_key_type_mismatch', 'lint'],
        'foreign_key_name_mismatch' => ['foreign_key_name_mismatch', 'lint'],
        'index_duplicates_primary_key' => ['duplicate_index', 'lint'],
        'duplicate_index' => ['duplicate_index', 'lint'],
        'redundant_index' => ['redundant_index', 'lint'],
        'morph_without_index' => ['missing_index', 'lint'],
    ];

    /**
     * The document being written: tables by id, with their columns; foreign key ids by connection, table and name.
     *
     * @var array<string, Table>
     */
    private array $tables = [];

    /**
     * Notes on links that no relation draws, which stay on their foreign key: by connection, table and constraint name.
     *
     * @var array<string, string>
     */
    private array $foreignKeyNotes = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $diagnostics = [];

    /**
     * The document, strictly validated.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidDocument When the result fails strict validation, which is a bug in this package.
     */
    public function document(SchemaDocument $schema): array
    {
        $this->tables = $this->foreignKeyNotes = [];
        $this->diagnostics = [];

        foreach ($schema->connections as $connection) {
            foreach ($connection->tables as $table) {
                $this->tables[self::tableId($connection->name, $table->name)] ??= $table;
            }
        }

        foreach ($schema->links as $link) {
            if ($link->note !== null && $link->relations === [] && $link->foreignKey !== null) {
                $this->foreignKeyNotes[$link->from->connection."\0".$link->from->table."\0".$link->foreignKey] ??= $link->note;
            }
        }

        $models = [];
        $tableOfModel = [];

        foreach ($schema->models as $model) {
            $id = self::tableId($model->connection, $model->table);
            $tableOfModel[$model->class] = isset($this->tables[$id]) ? $id : null;
            $models[$model->class] ??= $this->model($model, $tableOfModel[$model->class], $schema);
        }

        $connections = array_map(fn (ConnectionSchema $connection): array => $this->connection($connection, $schema), $schema->connections);
        $relationships = $this->relationships($schema, $tableOfModel, array_column(array_map(fn (ModelInfo $m): array => ['class' => $m->class, 'connection' => $m->connection], $schema->models), 'connection', 'class'));

        foreach ($schema->hints as $hint) {
            $this->hint($hint, $relationships);
        }

        foreach ($schema->connections as $connection) {
            $this->inspectionNotes($connection);
        }

        $models = array_values($models);
        usort($models, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        $relationships = array_values($relationships);
        usort($relationships, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $document = [
            'format' => Validator::FORMAT,
            'version' => Validator::VERSION,
            'source' => 'combined',
            'generator' => ['name' => self::GENERATOR, 'orm' => 'eloquent'],
            'note' => $schema->note,
            'connections' => $connections,
            'models' => $models,
            'relationships' => $relationships,
            'links' => [],
            'derivedLinks' => [],
            'diagnostics' => self::sortDiagnostics($this->diagnostics),
            'comparisons' => [],
            'migrations' => [],
            'inferredAssociations' => [],
        ];

        ['links' => $document['links'], 'derivedLinks' => $document['derivedLinks']] = Graph::build($document);
        $document['inferredAssociations'] = Inference::infer($document);

        $this->tables = $this->foreignKeyNotes = [];
        $this->diagnostics = [];

        return Validator::assert($document, strict: true);
    }

    public static function connectionId(string $name): string
    {
        return 'connection:'.$name;
    }

    public static function tableId(string $connection, string $table): string
    {
        return $connection.':'.$table;
    }

    /**
     * Diagnostics in the contract's order, (category, kind, subject type, subject id, message), each id once.
     *
     * @param  list<array<string, mixed>>  $diagnostics
     * @return list<array<string, mixed>>
     */
    public static function sortDiagnostics(array $diagnostics): array
    {
        $key = fn (array $d): array => [$d['category'], $d['kind'], $d['subject']['type'], $d['subject']['id'] ?? '', $d['message']];
        usort($diagnostics, fn (array $a, array $b): int => Ids::byList($key($a), $key($b)));
        $unique = [];

        foreach ($diagnostics as $diagnostic) {
            $unique[$diagnostic['id']] ??= $diagnostic;
        }

        return array_values($unique);
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(ConnectionSchema $connection, SchemaDocument $schema): array
    {
        $tables = array_map(fn (Table $table): array => $this->table($connection, $table), $connection->tables);
        usort($tables, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'id' => self::connectionId($connection->name),
            'name' => $connection->name,
            'driver' => self::DRIVERS[$connection->driver] ?? $connection->driver,
            'defaultSchema' => $connection->defaultSchema,
            'coverage' => [
                'database' => $this->databaseCoverage($connection),
                'declared' => $this->declaredCoverage($connection, $schema),
            ],
            'tables' => $tables,
        ];
    }

    /**
     * What Laravel's schema builder reads, per feature (docs/contract.md, "Coverage"). Check constraints are never
     * read, unique constraints only as the unique indexes behind them, and index expressions and predicates only as
     * flags, so the inspection is never complete; each gap has an inspection diagnostic.
     *
     * @return array<string, mixed>
     */
    private function databaseCoverage(ConnectionSchema $connection): array
    {
        $driver = $connection->driver;
        $details = array_filter($connection->tables, fn (Table $table): bool => ! $table->indexDetails) === [];
        $known = in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], true);
        $partialIndexes = in_array($driver, ['sqlite', 'pgsql', 'sqlsrv'], true);
        $included = in_array($driver, ['pgsql', 'sqlsrv'], true);

        $features = [
            'tables' => 'complete',
            'columns' => 'complete',
            'columnTypes' => 'complete',
            'defaults' => 'complete',
            'comments' => $driver === 'sqlite' ? 'not_applicable' : 'complete',
            'primaryKeys' => 'complete',
            'foreignKeys' => 'complete',
            'referentialActions' => 'complete',
            'indexes' => 'complete',
            'indexExpressions' => $known && $details ? 'partial' : 'unavailable',
            'indexPredicates' => ! $known ? 'unavailable' : (! $partialIndexes ? 'not_applicable' : ($details ? 'partial' : 'unavailable')),
            'includedColumns' => ! $known ? 'unavailable' : (! $included ? 'not_applicable' : ($details ? 'complete' : 'unavailable')),
            'uniqueConstraints' => 'partial',
            'checkConstraints' => 'unavailable',
            'models' => 'not_applicable',
            'relationships' => 'not_applicable',
        ];

        return [
            'complete' => false,
            'schemas' => $connection->schemas,
            'features' => $features,
        ];
    }

    /**
     * What the Eloquent models on a connection declare: models, partial when one could not be constructed, and
     * relationships, partial when a relation method could not be read. Eloquent declares no table structure.
     *
     * @return array<string, mixed>
     */
    private function declaredCoverage(ConnectionSchema $connection, SchemaDocument $schema): array
    {
        $connections = [];

        foreach ($schema->models as $model) {
            $connections[$model->class] = $model->connection;
        }

        $models = $relationships = 'complete';

        foreach ($schema->unresolved as $failure) {
            if ($failure->method === '__construct') {
                $models = 'partial';
            } elseif (($connections[$failure->model] ?? null) === $connection->name) {
                $relationships = 'partial';
            }
        }

        return [
            'complete' => $models === 'complete' && $relationships === 'complete',
            'schemas' => null,
            'features' => ['tables' => 'not_applicable', 'models' => $models, 'relationships' => $relationships],
        ];
    }

    /**
     * Why the inspection of a connection is not complete.
     */
    private function inspectionNotes(ConnectionSchema $connection): void
    {
        $subject = ['type' => 'connection', 'id' => self::connectionId($connection->name), 'columns' => null, 'label' => $connection->name];
        $coverage = $this->databaseCoverage($connection)['features'];
        $notes = [
            'Laravel\'s schema builder does not report check constraints.',
            'Laravel\'s schema builder reports unique constraints as the unique indexes that enforce them.',
            $coverage['indexExpressions'] === 'partial' ? 'Index key expressions are read only as a flag: their text and position are not reported.' : 'Index key expressions could not be read for every table.',
        ];

        if ($coverage['indexPredicates'] === 'partial') {
            $notes[] = 'Partial indexes are read only as a flag: their predicates are not reported.';
        } elseif ($coverage['indexPredicates'] === 'unavailable') {
            $notes[] = 'Partial indexes could not be read for every table.';
        }

        if ($coverage['includedColumns'] === 'unavailable') {
            $notes[] = 'Included index columns could not be read for every table.';
        }

        foreach ($notes as $message) {
            $this->diagnose('unsupported_metadata', 'inspection', 'info', 'certain', $subject, $message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function table(ConnectionSchema $connection, Table $table): array
    {
        $id = self::tableId($connection->name, $table->name);
        $primary = null;

        foreach ($table->indexes as $index) {
            if ($index->primary && ! $index->expression) {
                $primary ??= $index;
            }
        }

        $fkIds = [];
        $foreignKeys = [];

        foreach ($table->foreignKeys as $fk) {
            $fkId = $id.':fk:'.$fk->name;

            // Constraint names repeat only where the database keeps none (SQLite): number the repeats.
            for ($n = 2; isset($fkIds[$fkId]); $n++) {
                $fkId = $id.':fk:'.$fk->name.'#'.$n;
            }

            $fkIds[$fkId] = true;
            $foreignKeys[] = $this->foreignKey($connection, $table, $fk, $fkId);
        }

        usort($foreignKeys, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $indexIds = [];
        $indexes = [];

        foreach ($table->indexes as $index) {
            $indexId = $id.':index:'.$index->name;

            for ($n = 2; isset($indexIds[$indexId]); $n++) {
                $indexId = $id.':index:'.$index->name.'#'.$n;
            }

            $indexIds[$indexId] = true;
            $indexes[] = $this->index($index, $indexId, $index === $primary);
        }

        return [
            'id' => $id,
            'name' => $table->baseName(),
            'schema' => $table->schema,
            'comment' => $table->comment,
            'note' => $table->note,
            'owner' => [
                'kind' => $table->package !== null ? 'package' : ($table->framework ? 'framework' : 'application'),
                'package' => $table->package,
            ],
            'primaryKey' => $primary?->keyColumns() ?? [],
            'columns' => array_map(fn (Column $column): array => $this->column($connection, $column), $table->columns),
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
            'constraints' => [],
            'provenance' => ['database'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(ConnectionSchema $connection, Column $column): array
    {
        return [
            'name' => $column->name,
            'type' => $column->type,
            'typeName' => $column->typeName !== '' ? $column->typeName : null,
            'nullable' => $column->nullable,
            'autoIncrement' => $column->autoIncrement,
            'timezone' => null,
            'collation' => $column->collation,
            'enum' => $this->enum($connection, $column),
            'default' => $column->default !== null ? ['kind' => $this->defaultKind($column), 'value' => $column->default] : null,
            'ormDefault' => null,
            'generated' => $column->generated,
            'comment' => $column->comment,
            'note' => $column->note,
            'provenance' => ['database'],
        ];
    }

    private function defaultKind(Column $column): string
    {
        return preg_match('/^nextval\(/i', (string) $column->default) === 1 ? 'sequence' : 'raw';
    }

    /**
     * A MySQL or MariaDB enum column's values, in declaration order, from its type.
     *
     * @return array{name: null, values: list<string>}|null
     */
    private function enum(ConnectionSchema $connection, Column $column): ?array
    {
        if (! in_array($connection->driver, ['mysql', 'mariadb'], true) || strtolower($column->typeName) !== 'enum'
            || preg_match('/^enum\((.*)\)$/is', $column->type, $match) !== 1
            || preg_match_all("/'((?:[^']|'')*)'/s", $match[1], $values) === 0) {
            return null;
        }

        return ['name' => null, 'values' => array_map(fn (string $value): string => str_replace("''", "'", $value), $values[1])];
    }

    /**
     * @return array<string, mixed>
     */
    private function index(Index $index, string $id, bool $primary): array
    {
        return [
            'id' => $id,
            'name' => $index->name,
            'parts' => array_map(fn (string $column): array => [
                'column' => $column,
                'expression' => null,
                'order' => null,
                'nulls' => null,
                'length' => null,
                'collation' => null,
                'opclass' => null,
            ], $index->keyColumns()),
            'expression' => $index->expression,
            'unique' => $index->unique || $index->primary,
            'backs' => $primary ? ['kind' => 'primary_key', 'constraint' => null] : null,
            'method' => $index->type,
            'partial' => $index->partial,
            'predicate' => null,
            'included' => array_values(array_intersect($index->included, $index->columns)),
            'provenance' => ['database'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignKey(ConnectionSchema $connection, Table $table, ForeignKey $fk, string $id): array
    {
        $target = $this->tables[self::tableId($connection->name, $fk->foreignTable)] ?? null;
        $action = fn (string $action): ?string => $action !== '' ? strtoupper($action) : null;

        if ($target === null || array_filter($fk->foreignColumns, fn (string $column): bool => ! $target->hasColumn($column)) !== []) {
            $this->diagnose('unresolved_foreign_key', 'inspection', 'info', 'certain', ['type' => 'foreignKey', 'id' => $id, 'columns' => $fk->columns, 'label' => $table->name.'.'.$fk->name], $target === null
                ? sprintf('%s references %s, which is not in the schema (excluded, or outside the schemas read).', $fk->name, $fk->foreignTable)
                : sprintf('%s references columns %s does not have: %s.', $fk->name, $fk->foreignTable, implode(', ', $fk->foreignColumns)));
        }

        $foreignKey = [
            'id' => $id,
            'name' => $fk->name,
            'columns' => $fk->columns,
            'foreignTable' => self::tableId($connection->name, $fk->foreignTable),
            'foreignColumns' => $fk->foreignColumns,
            'onUpdate' => $action($fk->onUpdate),
            'onDelete' => $action($fk->onDelete),
            'deferrable' => null,
            'initially' => null,
            'provenance' => ['database'],
        ];

        $note = $this->foreignKeyNotes[$connection->name."\0".$table->name."\0".$fk->name] ?? null;

        if ($note !== null) {
            $foreignKey['extensions'] = ['laravel' => ['note' => $note]];
        }

        return $foreignKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function model(ModelInfo $model, ?string $table, SchemaDocument $schema): array
    {
        $eloquent = [
            'connection' => $model->connection,
            'table' => $model->table,
            'keyType' => $model->keyType,
            'incrementing' => $model->incrementing,
            'timestamps' => $model->timestamps,
            'softDeletes' => $model->softDeletes,
        ];

        foreach (['casts' => $model->casts, 'fillable' => $model->fillable, 'guarded' => $model->guarded, 'hidden' => $model->hidden, 'appends' => $model->appends, 'scopes' => $model->scopes, 'globalScopes' => $model->globalScopes, 'observers' => $model->observers] as $key => $values) {
            if ($values !== []) {
                $eloquent[$key] = $key === 'casts' ? Json::object($values) : array_values($values);
            }
        }

        if ($table === null && $schema->connection($model->connection) === null) {
            $this->diagnose('model_without_table', 'reconciliation', 'info', 'certain', ['type' => 'model', 'id' => $model->class, 'columns' => null, 'label' => class_basename($model->class)],
                sprintf('%s uses the %s connection, which is not read (schema-viewer.connections).', $model->class, $model->connection));
        }

        return [
            'id' => $model->class,
            'className' => $model->class,
            'tables' => $table !== null ? [$table] : [],
            'primaryKey' => [$model->primaryKey],
            'source' => self::source($model->source),
            'extensions' => ['eloquent' => $eloquent],
        ];
    }

    /**
     * @param  array<string, string|null>  $tableOfModel
     * @param  array<string, string>  $connectionOfModel
     * @return array<string, array<string, mixed>>
     */
    private function relationships(SchemaDocument $schema, array $tableOfModel, array $connectionOfModel): array
    {
        // A relationship's note is the note of a link it draws; a foreign key's link without a relation keeps its note on
        // the foreign key.
        $notes = [];

        foreach ($schema->links as $link) {
            foreach ($link->note !== null ? $link->relations : [] as $relation) {
                $notes[$relation] ??= $link->note;
            }
        }

        $relationships = [];

        foreach ($schema->relations as $relation) {
            $table = $tableOfModel[$relation->model] ?? null;
            $label = class_basename($relation->model).'::'.$relation->name;

            if ($table === null) {
                $this->unresolved($relation, $label, sprintf('%s() is left out: its model\'s table is not in the schema.', $label), 'info');

                continue;
            }

            $relationships[$relation->id()] ??= $this->relationship($relation, $table, $connectionOfModel[$relation->model], $tableOfModel, $notes[$relation->id()] ?? null, $label);
        }

        foreach ($relationships as $id => $relationship) {
            if ($relationship['inverse'] !== null && ! isset($relationships[$relationship['inverse']])) {
                $relationships[$id]['inverse'] = null;
            }
        }

        return $relationships;
    }

    /**
     * @param  array<string, string|null>  $tableOfModel
     * @return array<string, mixed>
     */
    private function relationship(RelationInfo $relation, string $table, string $connection, array $tableOfModel, ?string $note, string $label): array
    {
        $morphTo = $relation->type === 'MorphTo';
        $relatedModel = ! $morphTo && array_key_exists($relation->related, $tableOfModel) ? $relation->related : null;
        $relatedTable = null;

        if (! $morphTo && $relation->relatedConnection !== null && $relation->relatedTable !== null && isset($this->tables[self::tableId($relation->relatedConnection, $relation->relatedTable)])) {
            $relatedTable = self::tableId($relation->relatedConnection, $relation->relatedTable);
        }

        if (! $morphTo && $relatedModel === null) {
            $this->unresolved($relation, $label, sprintf('%s() relates to %s, which is not one of the scanned models (schema-viewer.models).', $label, $relation->related), 'info');
        }

        $keys = null;

        if (! $morphTo && $relation->from !== null && $relation->to !== null) {
            $keys = ['from' => $this->endpoint($relation->from), 'to' => $this->endpoint($relation->to)];

            if ($keys['from'] === null || $keys['to'] === null) {
                $missing = $keys['from'] === null ? $relation->from : $relation->to;
                $this->unresolved($relation, $label, sprintf('%s() uses %s.%s, which the %s connection does not have.', $label, $missing->table, implode(', ', $missing->columns), $missing->connection), 'warning');
                $keys = null;
            }
        }

        $pivot = null;

        if ($relation->pivot !== null) {
            $pivotTable = $this->tables[self::tableId($connection, $relation->pivot->table)] ?? null;
            $pivotColumns = [$relation->pivot->foreignPivotKey, $relation->pivot->relatedPivotKey];

            if ($pivotTable === null || array_filter($pivotColumns, fn (string $column): bool => ! $pivotTable->hasColumn($column)) !== []) {
                $this->unresolved($relation, $label, sprintf('%s() uses the pivot table %s, which %s.', $label, $relation->pivot->table, $pivotTable === null ? 'the '.$connection.' connection does not have' : 'lacks '.implode(', ', array_filter($pivotColumns, fn (string $column): bool => ! $pivotTable->hasColumn($column)))), 'warning');
            } else {
                $pivot = [
                    'table' => self::tableId($connection, $relation->pivot->table),
                    'parentColumns' => [$relation->pivot->parentKey],
                    'parentPivotColumns' => [$relation->pivot->foreignPivotKey],
                    'relatedColumns' => [$relation->pivot->relatedKey],
                    'relatedPivotColumns' => [$relation->pivot->relatedPivotKey],
                    'columns' => array_values(array_unique(array_filter($relation->pivot->columns, fn (string $column): bool => $pivotTable->hasColumn($column)))),
                ];
            }
        }

        $through = null;

        if ($relation->through !== null && count($relation->through) >= 2) {
            $through = array_map(fn (string $name): string => self::tableId($connection, $name), array_slice($relation->through, 1, -1));

            if (array_filter($through, fn (string $id): bool => ! isset($this->tables[$id])) !== []) {
                $this->unresolved($relation, $label, sprintf('%s() goes through %s, which the %s connection does not have.', $label, implode(', ', array_slice($relation->through, 1, -1)), $connection), 'warning');
                $through = null;
            }
        }

        $eloquent = ['related' => $relation->related];

        if ($relation->pivot !== null) {
            $eloquent['accessor'] = $relation->pivot->accessor;

            if ($relation->pivot->using !== null) {
                $eloquent['using'] = $relation->pivot->using;
            }
        }

        return [
            'id' => $relation->id(),
            'name' => $relation->name,
            'model' => $relation->model,
            'table' => $table,
            'relatedModel' => $relatedModel,
            'relatedTable' => $relatedTable,
            'type' => $relation->type,
            'cardinality' => $relation->cardinality,
            'collection' => in_array($relation->cardinality, ['one-to-many', 'many-to-many'], true),
            'readOnly' => $relation->through !== null,
            'keys' => $keys,
            'pivot' => $pivot,
            'polymorphic' => $relation->morph !== null ? ['typeColumn' => $relation->morph->type, 'idColumn' => $relation->morph->id, 'typeValue' => $relation->morph->class] : null,
            'through' => $through,
            'join' => null,
            'constraints' => $relation->constraints,
            'inverse' => $relation->inverse,
            'note' => $note,
            'source' => self::source($relation->source),
            'extensions' => ['eloquent' => $eloquent],
        ];
    }

    /**
     * @return array{table: string, columns: list<string>}|null
     */
    private function endpoint(Endpoint $endpoint): ?array
    {
        $id = self::tableId($endpoint->connection, $endpoint->table);
        $table = $this->tables[$id] ?? null;

        if ($table === null || $endpoint->columns === [] || array_filter($endpoint->columns, fn (string $column): bool => ! $table->hasColumn($column)) !== []) {
            return null;
        }

        return ['table' => $id, 'columns' => $endpoint->columns];
    }

    private function unresolved(RelationInfo $relation, string $label, string $message, string $severity): void
    {
        $this->diagnose('unresolved_relationship', 'extraction', $severity, 'certain', ['type' => 'relationship', 'id' => $relation->id(), 'columns' => null, 'label' => $label], $message);
    }

    /**
     * A hint as a diagnostic, about the narrowest subject the document has: the relation that could not be read, the
     * model without a table, the columns, or the table.
     *
     * @param  array<string, array<string, mixed>>  $relationships
     */
    private function hint(Hint $hint, array $relationships): void
    {
        [$kind, $category] = self::KINDS[$hint->kind->value];
        $tableId = self::tableId($hint->connection, $hint->table);
        $table = $this->tables[$tableId] ?? null;
        $columns = $hint->columns !== null ? explode(',', $hint->columns) : null;

        $subject = match (true) {
            $hint->kind === HintKind::UnresolvedRelation && str_ends_with((string) $hint->relation, '::__construct') => ['type' => 'document', 'id' => null, 'columns' => null, 'label' => substr((string) $hint->relation, 0, -strlen('::__construct'))],
            $hint->relation !== null => ['type' => 'relationship', 'id' => $hint->relation, 'columns' => null, 'label' => class_basename(explode('::', $hint->relation)[0]).'::'.(explode('::', $hint->relation)[1] ?? '')],
            $hint->model !== null => ['type' => 'model', 'id' => $hint->model, 'columns' => null, 'label' => class_basename($hint->model)],
            $table !== null && $columns !== null && array_filter($columns, fn (string $column): bool => ! $table->hasColumn($column)) === [] => ['type' => 'column', 'id' => $tableId, 'columns' => $columns, 'label' => $hint->table.'.'.implode(', ', $columns)],
            $table !== null => ['type' => 'table', 'id' => $tableId, 'columns' => $columns, 'label' => $hint->table],
            default => ['type' => 'document', 'id' => null, 'columns' => null, 'label' => $hint->table !== '' ? $hint->table : null],
        };

        if ($subject['type'] === 'document' && $hint->kind === HintKind::UnresolvedRelation) {
            $kind = 'model_unreadable';
        }

        // A relationship the document left out stays a subject only for the unresolved kinds, which may name it.
        if ($subject['type'] === 'relationship' && $kind !== 'unresolved_relationship' && ! isset($relationships[$subject['id']])) {
            $subject = ['type' => 'document', 'id' => null, 'columns' => null, 'label' => $subject['label']];
        }

        $rules = $hint->ignoreRules();
        $this->diagnose($kind, $category, $hint->kind->severity(), $hint->kind->certainty(), $subject, $hint->message, [
            'laravel' => ['hint' => $hint->kind->value, 'ignore' => $rules['hint'], 'ignorePhp' => IgnoreRule::php($rules['hint']).','],
        ]);
    }

    /**
     * @param  array{type: string, id: string|null, columns: list<string>|null, label: string|null}  $subject
     * @param  array<string, mixed>|null  $extensions
     */
    private function diagnose(string $kind, string $category, string $severity, string $certainty, array $subject, string $message, ?array $extensions = null): void
    {
        $diagnostic = [
            'id' => Ids::diagnostic($kind, $subject['type'], $subject['id'], $subject['columns'], $message),
            'kind' => $kind,
            'category' => $category,
            'severity' => $severity,
            'certainty' => $certainty,
            'subject' => $subject,
            'message' => $message,
        ];

        if ($extensions !== null) {
            $diagnostic['extensions'] = $extensions;
        }

        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private static function source(?Source $source): ?array
    {
        return $source !== null ? ['file' => $source->file, 'line' => $source->line] : null;
    }
}
