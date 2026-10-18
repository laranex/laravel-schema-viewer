<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

/**
 * Validation of laranex-schema 2.x documents, a port of laranex-schema-viewer-ui's validate.ts with its messages.
 *
 * First the format and major version (one clear error for anything else, 1.x included), then the shape (Shape), then
 * the reference rules of docs/contract.md. Strict validation (producers and tests) accepts exactly version 2.0 and
 * rejects unknown properties; lenient validation (readers) accepts any 2.x and ignores what it does not know.
 */
final class Validator
{
    public const FORMAT = 'laranex-schema';

    public const VERSION = '2.0';

    public const MAJOR = 2;

    private const MAX_ERRORS = 100;

    private const DRIVER_ALIASES = ['pgsql' => 'postgresql', 'postgres' => 'postgresql', 'mssql' => 'sqlsrv', 'sqlserver' => 'sqlsrv'];

    private const OBJECT_TYPES = ['table', 'column', 'primaryKey', 'index', 'foreignKey', 'constraint'];

    private const CHANGES = ['missing', 'extra', 'changed'];

    /**
     * Why a value is not a laranex-schema 2.x document at all, or null when it is one (valid or not).
     */
    public static function unsupported(mixed $value): ?string
    {
        if (! Json::isObject($value) || (Json::members($value)['format'] ?? null) !== self::FORMAT) {
            return 'Not a '.self::FORMAT.' document';
        }

        $version = Json::members($value)['version'] ?? null;
        $major = is_string($version) ? explode('.', $version)[0] : null;

        if ($major === null || ! is_numeric($major) || (float) $major !== (float) self::MAJOR) {
            return sprintf('Unsupported %s version %s; this reader supports %d.x', self::FORMAT, array_key_exists('version', Json::members($value)) ? Json::compact($version) : 'undefined', self::MAJOR);
        }

        return null;
    }

    /**
     * The errors, at most 100; none when the document is valid.
     *
     * @return list<string>
     */
    public static function validate(mixed $value, bool $strict = false): array
    {
        $unsupported = self::unsupported($value);

        if ($unsupported !== null) {
            return [$unsupported];
        }

        $errors = [];

        /** @var array<string, mixed> $value */
        if ($strict && $value['version'] !== self::VERSION) {
            $errors[] = '$.version: strict validation is for version '.self::VERSION.', got '.Json::compact($value['version']);
        }

        Shape::check($value, $errors, $strict);

        if ($errors === []) {
            self::rules($value, $errors);
        }

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * The document when it is valid.
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedDocument When it is not a laranex-schema 2.x document.
     * @throws InvalidDocument When it fails validation.
     */
    public static function assert(mixed $value, bool $strict = false): array
    {
        $unsupported = self::unsupported($value);

        if ($unsupported !== null) {
            throw new UnsupportedDocument($unsupported);
        }

        $errors = self::validate($value, $strict);

        if ($errors !== []) {
            throw new InvalidDocument($errors);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Whether a table is in a scope: its schema, or unqualified while the connection's default is that schema.
     *
     * @param  array<string, mixed>  $table
     * @param  array<string, mixed>  $connection
     */
    public static function inScope(array $table, array $connection, ?string $schema): bool
    {
        return $schema === null
            ? $table['schema'] === null || $table['schema'] === $connection['defaultSchema']
            : $table['schema'] === $schema || ($table['schema'] === null && $connection['defaultSchema'] === $schema);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function duplicates(array $values): array
    {
        $seen = [];
        $found = [];

        foreach ($values as $value) {
            if (isset($seen[$value]) && ! in_array($value, $found, true)) {
                $found[] = $value;
            }

            $seen[$value] = true;
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function sortedById(array $items): bool
    {
        return Ids::isSorted(array_column($items, 'id')) && count(array_unique(array_column($items, 'id'))) === count($items);
    }

    /**
     * Whether every value is a key of the set.
     *
     * @param  array<mixed>  $values  Strings from a validated document.
     * @param  array<array-key, mixed>  $set
     */
    private static function subset(array $values, array $set): bool
    {
        foreach ($values as $value) {
            if (! is_string($value) || ! array_key_exists($value, $set)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $errors
     */
    private static function rules(array $doc, array &$errors): void
    {
        $tables = [];
        $tableById = [];
        $connectionOf = [];

        foreach ($doc['connections'] as $connection) {
            foreach ($connection['tables'] as $table) {
                $tables[] = $table;
                $tableById[$table['id']] ??= $table;
                $connectionOf[$table['id']] ??= $connection['id'];
            }
        }

        $columnsOf = fn (string $id): array => isset($tableById[$id]) ? array_fill_keys(array_map('strval', array_column($tableById[$id]['columns'], 'name')), true) : [];
        $foreignKeys = array_merge([], ...array_column($tables, 'foreignKeys'));
        $indexes = array_merge([], ...array_column($tables, 'indexes'));
        $constraints = array_merge([], ...array_column($tables, 'constraints'));
        $fkById = array_column($foreignKeys, null, 'id');
        $models = array_fill_keys(array_column($doc['models'], 'id'), true);
        $relationshipById = array_column($doc['relationships'], null, 'id');
        $links = array_fill_keys(array_column($doc['links'], 'id'), true);
        $derivedIds = array_fill_keys(array_column($doc['derivedLinks'], 'id'), true);
        $unresolved = fn (string $kind, string $subject): bool => array_filter($doc['diagnostics'], fn (array $d): bool => $d['kind'] === $kind && $d['subject']['id'] === $subject) !== [];

        foreach ([
            'connection' => array_column($doc['connections'], 'id'),
            'table' => array_column($tables, 'id'),
            'index' => array_column($indexes, 'id'),
            'foreign-key' => array_column($foreignKeys, 'id'),
            'constraint' => array_column($constraints, 'id'),
            'model' => array_column($doc['models'], 'id'),
            'relationship' => array_column($doc['relationships'], 'id'),
            'link' => [...array_column($doc['links'], 'id'), ...array_column($doc['derivedLinks'], 'id')],
            'diagnostic' => array_column($doc['diagnostics'], 'id'),
            'comparison' => array_column($doc['comparisons'], 'id'),
            'difference' => array_merge([], ...array_map(fn (array $c): array => array_column($c['differences'], 'id'), $doc['comparisons'])),
            'migration' => array_column($doc['migrations'], 'id'),
            'inferred-association' => array_column($doc['inferredAssociations'], 'id'),
        ] as $label => $values) {
            foreach (self::duplicates(array_map('strval', $values)) as $value) {
                $errors[] = "Duplicate {$label} id: {$value}";
            }
        }

        foreach (self::duplicates(array_column($doc['connections'], 'name')) as $value) {
            $errors[] = "Duplicate connection name: {$value}";
        }

        // Provenance and coverage must agree with the document's source mode.
        $allowed = match ($doc['source']) {
            'declared' => ['declared'],
            'database' => ['database'],
            default => ['database', 'declared'],
        };

        foreach ($tables as $t) {
            $physical = [["table {$t['id']}", $t]];

            foreach ($t['columns'] as $c) {
                $physical[] = ["column {$t['id']}.{$c['name']}", $c];
            }

            foreach ($t['indexes'] as $i) {
                $physical[] = ["index {$i['id']}", $i];
            }

            foreach ($t['foreignKeys'] as $f) {
                $physical[] = ["foreign key {$f['id']}", $f];
            }

            foreach ($t['constraints'] as $c) {
                $physical[] = ["constraint {$c['id']}", $c];
            }

            foreach ($physical as [$what, $entity]) {
                if (array_diff($entity['provenance'], $allowed) !== []) {
                    $errors[] = 'Provenance '.implode('+', $entity['provenance'])." is not possible in a {$doc['source']} document: {$what}";
                }

                if (($entity['drift'] ?? []) !== [] && ! (in_array('database', $entity['provenance'], true) && in_array('declared', $entity['provenance'], true))) {
                    $errors[] = "Drift needs both database and declared provenance: {$what}";
                }
            }
        }

        $needs = ['declared' => [false, true], 'database' => [true, false], 'combined' => [true, true]][$doc['source']];

        foreach ($doc['connections'] as $connection) {
            $canonical = $connection['driver'] !== null ? (self::DRIVER_ALIASES[$connection['driver']] ?? null) : null;

            if ($canonical !== null) {
                $errors[] = "Driver {$connection['driver']} is spelled {$canonical}: connection {$connection['id']}";
            }

            $database = $connection['coverage']['database'];
            $declared = $connection['coverage']['declared'];

            if (($database !== null) !== $needs[0] || ($declared !== null) !== $needs[1]) {
                $errors[] = "Coverage does not match source {$doc['source']}: connection {$connection['id']}";
            }

            foreach (['database' => $database, 'declared' => $declared] as $name => $coverage) {
                if (is_array($coverage) && $coverage['complete'] === true && array_intersect(array_values(Json::members($coverage['features'])), ['partial', 'unavailable']) !== []) {
                    $errors[] = "Coverage is marked complete with partial or unavailable features: connection {$connection['id']} ({$name})";
                }
            }
        }

        foreach ($tables as $table) {
            self::tableRules($table, $errors);
            $columns = $columnsOf($table['id']);

            foreach ($table['foreignKeys'] as $fk) {
                if (count($fk['columns']) !== count($fk['foreignColumns'])) {
                    $errors[] = "Foreign-key column counts differ: {$fk['id']}";
                }

                if (! self::subset($fk['columns'], $columns)) {
                    $errors[] = "Unknown foreign-key column: {$fk['id']}";
                }

                $target = $tableById[$fk['foreignTable']] ?? null;
                $excused = $unresolved('unresolved_foreign_key', $fk['id']);

                if ($target === null && ! $excused) {
                    $errors[] = "Unknown foreign-key target table: {$fk['id']}";
                } elseif ($target !== null && $connectionOf[$target['id']] !== $connectionOf[$table['id']]) {
                    // A constraint lives in one database; only ORM relationships may cross connections.
                    $errors[] = "Foreign key crosses connections: {$fk['id']}";
                } elseif ($target !== null && ! self::subset($fk['foreignColumns'], $columnsOf($target['id'])) && ! $excused) {
                    $errors[] = "Unknown foreign-key target column: {$fk['id']}";
                }
            }
        }

        foreach ($doc['models'] as $model) {
            if (! self::subset($model['tables'], $tableById)) {
                $errors[] = "Unknown model table: {$model['id']}";
            }
        }

        $endpointOk = fn (array $e): bool => isset($tableById[$e['table']]) && self::subset($e['columns'], $columnsOf($e['table']));

        foreach ($doc['relationships'] as $r) {
            if (! isset($models[$r['model']])) {
                $errors[] = "Unknown relationship model: {$r['id']}";
            }

            if ($r['relatedModel'] === null ? $r['polymorphic'] === null && ! $unresolved('unresolved_relationship', $r['id']) : ! isset($models[$r['relatedModel']])) {
                $errors[] = "Unknown related model: {$r['id']}";
            }

            if (! isset($tableById[$r['table']]) || ($r['relatedTable'] !== null && ! isset($tableById[$r['relatedTable']]))) {
                $errors[] = "Unknown relationship table: {$r['id']}";
            }

            if ($r['keys'] !== null && (! $endpointOk($r['keys']['from']) || ! $endpointOk($r['keys']['to']) || count($r['keys']['from']['columns']) !== count($r['keys']['to']['columns']))) {
                $errors[] = "Invalid relationship keys: {$r['id']}";
            }

            if ($r['pivot'] !== null) {
                $p = $r['pivot'];
                $pairs = count($p['parentColumns']) === count($p['parentPivotColumns']) && count($p['relatedColumns']) === count($p['relatedPivotColumns']);

                if (! isset($tableById[$p['table']]) || ! $pairs || ! self::subset([...$p['parentPivotColumns'], ...$p['relatedPivotColumns'], ...$p['columns']], $columnsOf($p['table']))) {
                    $errors[] = "Invalid relationship pivot: {$r['id']}";
                }
            }

            if ($r['through'] !== null && ! self::subset($r['through'], $tableById)) {
                $errors[] = "Unknown relationship through table: {$r['id']}";
            }

            if ($r['inverse'] !== null && ! isset($relationshipById[$r['inverse']])) {
                $errors[] = "Unknown inverse relationship: {$r['id']}";
            }
        }

        foreach ($doc['links'] as $link) {
            if (! $endpointOk($link['from']) || ! $endpointOk($link['to'])) {
                $errors[] = "Unknown link endpoint: {$link['id']}";
            }

            if ($link['foreignKey'] !== null && ! isset($fkById[$link['foreignKey']])) {
                $errors[] = "Unknown link foreign key: {$link['id']}";
            }

            if (! self::subset($link['relationships'], $relationshipById)) {
                $errors[] = "Unknown link relationship: {$link['id']}";
            }

            $state = $link['foreignKey'] !== null ? ($link['relationships'] !== [] ? 'both' : 'foreign_key') : ($link['relationships'] !== [] ? 'relationship' : null);

            if ($state !== $link['state']) {
                $errors[] = "Link state {$link['state']} does not match its foreign key and relationships: {$link['id']}";
            }
        }

        foreach ($doc['derivedLinks'] as $link) {
            if (! isset($tableById[$link['from']]) || ! isset($tableById[$link['to']]) || ! self::subset($link['via'], $tableById) || ! isset($relationshipById[$link['relationship']])) {
                $errors[] = "Unknown derived link reference: {$link['id']}";
            }
        }

        $ids = fn (array $items): array => array_fill_keys(array_column($items, 'id'), true);
        $exists = [
            'connection' => $ids($doc['connections']),
            'table' => $tableById,
            'column' => $tableById,
            'index' => $ids($indexes),
            'foreignKey' => $fkById,
            'constraint' => $ids($constraints),
            'model' => $models,
            'relationship' => $relationshipById,
            'link' => $links + $derivedIds,
            'comparison' => $ids($doc['comparisons']),
            'migration' => $ids($doc['migrations']),
            'inferredAssociation' => $ids($doc['inferredAssociations']),
        ];

        foreach ($doc['diagnostics'] as $n => $d) {
            ['type' => $type, 'id' => $id, 'columns' => $columns] = $d['subject'];
            $expected = Ids::diagnostic($d['kind'], $type, $id, $columns, $d['message']);

            if ($d['id'] !== $expected) {
                $errors[] = "Diagnostic id is not {$expected}: diagnostics[{$n}]";
            }

            if ($type === 'column' && ($columns ?? []) === []) {
                $errors[] = "A column subject names its columns: diagnostics[{$n}]";
            }

            if ($type === 'document' && $id !== null) {
                $errors[] = "A document diagnostic has no subject id: diagnostics[{$n}]";
            }

            // An unresolved subject is named by id but, by definition, not in the document.
            $unresolvedKind = in_array($d['kind'], ['unresolved_foreign_key', 'unresolved_relationship'], true);

            if ($id !== null && $type !== 'document' && ! isset($exists[$type][$id]) && ! $unresolvedKind) {
                $errors[] = "Unknown diagnostic subject {$type} {$id}: diagnostics[{$n}]";
            }

            if ($type === 'column' && $id !== null && isset($tableById[$id]) && ! self::subset($columns ?? [], $columnsOf($id))) {
                $errors[] = "Unknown diagnostic subject column: diagnostics[{$n}]";
            }
        }

        $connectionById = array_column($doc['connections'], null, 'id');
        self::comparisonRules($doc, $connectionById, $tableById, $connectionOf, $errors);
        self::migrationRules($doc, $connectionById, $tableById, $connectionOf, $errors);
        self::inferenceRules($doc, $tableById, $fkById, $errors);
    }

    /**
     * Whether a table id is a table of the connection and in the scope.
     *
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, string>  $connectionOf
     * @param  array<string, mixed>  $connection
     */
    private static function owned(array $tables, array $connectionOf, array $connection, ?string $schema, string $id): bool
    {
        return isset($tables[$id]) && $connectionOf[$id] === $connection['id'] && self::inScope($tables[$id], $connection, $schema);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, array<string, mixed>>  $connections
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, string>  $connectionOf
     * @param  list<string>  $errors
     */
    private static function comparisonRules(array $doc, array $connections, array $tables, array $connectionOf, array &$errors): void
    {
        if (! self::sortedById($doc['comparisons'])) {
            $errors[] = 'Comparisons are sorted by id';
        }

        foreach ($doc['comparisons'] as $c) {
            $at = "comparison {$c['id']}";

            if (! isset($connections[$c['baseline']['connection']]) || ! isset($connections[$c['target']['connection']])) {
                $errors[] = "Unknown comparison connection: {$at}";

                continue;
            }

            if ($c['baseline']['connection'] === $c['target']['connection'] && $c['baseline']['schema'] === $c['target']['schema']) {
                $errors[] = "A comparison compares two different scopes: {$at}";
            }

            $expected = Ids::comparison($c['baseline']['connection'], $c['baseline']['schema'], $c['target']['connection'], $c['target']['schema']);

            if ($c['id'] !== $expected) {
                $errors[] = "Comparison id is not {$expected}: {$at}";
            }

            $order = fn (array $d): array => [str_pad((string) array_search($d['objectType'], self::OBJECT_TYPES, true), 2, '0', STR_PAD_LEFT), ...$d['key']];

            foreach ($c['differences'] as $i => $d) {
                if ($i === 0) {
                    continue;
                }

                $previous = $c['differences'][$i - 1];
                $byKey = Ids::byList(array_values(array_map('strval', $order($previous))), array_values(array_map('strval', $order($d))));

                if (! ($byKey < 0 || ($byKey === 0 && array_search($previous['change'], self::CHANGES, true) < array_search($d['change'], self::CHANGES, true)))) {
                    $errors[] = "Differences are sorted by object type, key and change: {$at}";

                    break;
                }
            }

            $counts = ['missing' => 0, 'extra' => 0, 'changed' => 0];

            foreach ($c['differences'] as $d) {
                $where = "difference {$d['id']}";
                $counts[$d['change']]++;
                $expected = Ids::difference($c['id'], $d['objectType'], $d['key']);

                if ($d['id'] !== $expected) {
                    $errors[] = "Difference id is not {$expected}: {$where}";
                }

                $sides = ['missing' => [true, false], 'extra' => [false, true], 'changed' => [true, true]][$d['change']];

                if (($d['baseline'] !== null) !== $sides[0] || ($d['target'] !== null) !== $sides[1]) {
                    $has = match ($d['change']) {
                        'changed' => 'both sides',
                        'missing' => 'only a baseline',
                        default => 'only a target',
                    };
                    $errors[] = "A {$d['change']} difference has {$has}: {$where}";
                }

                if (($d['change'] === 'changed') !== ($d['fields'] !== [])) {
                    $errors[] = "Only a changed difference lists fields, and it lists at least one: {$where}";
                }

                if (! Ids::isSorted(array_column($d['fields'], 'field'))) {
                    $errors[] = "Difference fields are unique and sorted by field: {$where}";
                }

                foreach ([[$c['baseline'], $d['baseline']], [$c['target'], $d['target']]] as [$scope, $ref]) {
                    if ($ref === null) {
                        continue;
                    }

                    $connection = $connections[$scope['connection']];

                    if (! self::owned($tables, $connectionOf, $connection, $scope['schema'], $ref['table'])) {
                        $errors[] = "A difference side names a table of its own scope: {$where}";

                        continue;
                    }

                    $table = $tables[$ref['table']];

                    if (($d['key'][0] ?? null) !== $table['name']) {
                        $errors[] = "A difference key starts with its table's name: {$where}";
                    }

                    if ($d['objectType'] === 'column' && ! in_array($d['key'][1] ?? null, array_column($table['columns'], 'name'), true)) {
                        $errors[] = "A column difference key names a column of its table: {$where}";
                    }

                    $entities = ['index' => $table['indexes'], 'foreignKey' => $table['foreignKeys'], 'constraint' => $table['constraints']][$d['objectType']] ?? null;

                    if ($entities !== null ? ! in_array($ref['id'], array_column($entities, 'id'), true) : $ref['id'] !== null) {
                        $errors[] = "A difference side's id is its {$d['objectType']}'s, of its table: {$where}";
                    }
                }
            }

            $total = $c['summary']['missing'] + $c['summary']['extra'] + $c['summary']['changed'];

            if ($c['omitted'] > 0 && $c['complete'] === true) {
                $errors[] = "A comparison with omitted differences is incomplete: {$at}";
            }

            if ($total !== count($c['differences']) + $c['omitted'] || array_filter(self::CHANGES, fn (string $k): bool => $c['summary'][$k] < $counts[$k]) !== []) {
                $errors[] = "Comparison summary does not count its differences and omitted ones: {$at}";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, array<string, mixed>>  $connections
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, string>  $connectionOf
     * @param  list<string>  $errors
     */
    private static function migrationRules(array $doc, array $connections, array $tables, array $connectionOf, array &$errors): void
    {
        if (! self::sortedById($doc['migrations'])) {
            $errors[] = 'Migration tracks are sorted by id';
        }

        foreach ($doc['migrations'] as $m) {
            $at = "migration {$m['id']}";
            $connection = $connections[$m['connection']] ?? null;

            if ($connection === null) {
                $errors[] = "Unknown migration connection: {$at}";

                continue;
            }

            $expected = Ids::migrationTrack($m['provider'], $m['connection'], $m['schema']);

            if ($m['id'] !== $expected) {
                $errors[] = "Migration track id is not {$expected}: {$at}";
            }

            $own = fn (string $id): bool => self::owned($tables, $connectionOf, $connection, $m['schema'], $id);

            if ($m['versionTable'] !== null && ! $own($m['versionTable'])) {
                $errors[] = "The version table is a table of the track's scope: {$at}";
            }

            if ($m['versionTable'] === null && ($m['current'] === null || $m['current'] !== [])) {
                $errors[] = "Without a version table no revision is recorded (current is []): {$at}";
            }

            if ($m['history'] !== null) {
                $history = Migration::history($m['history']['revisions']);
                $mismatch = false;

                foreach ($m['history']['revisions'] as $i => $revision) {
                    $mismatch = $mismatch || $revision['parents'] !== $history['revisions'][$i]['parents'] || $revision['branchLabels'] !== $history['revisions'][$i]['branchLabels'];
                }

                if (! self::sortedById($m['history']['revisions']) || $mismatch) {
                    $errors[] = "Revisions are unique and sorted by id, with sorted parents and branch labels: {$at}";
                }

                if ($m['history']['heads'] !== $history['heads']) {
                    $errors[] = "History heads are the revisions no revision names as a parent: {$at}";
                }

                if ($m['history']['missing'] !== $history['missing']) {
                    $errors[] = "History missing lists the parents not in revisions: {$at}";
                }
            }

            if ($m['current'] !== null && ! Ids::isSorted($m['current'])) {
                $errors[] = "Current revisions are unique and sorted: {$at}";
            }

            $state = Migration::state($m['history'], $m['current']);

            if ($m['status'] !== $state['status'] || $m['pending'] !== $state['pending'] || $m['unknownRevisions'] !== $state['unknownRevisions']) {
                $errors[] = "Migration status, pending and unknownRevisions do not follow the status rules (expected {$state['status']}): {$at}";
            }

            foreach ($m['coverage'] as $i => $row) {
                if ($i > 0 && Ids::byList([$m['coverage'][$i - 1]['name'], $m['coverage'][$i - 1]['table'] ?? ''], [$row['name'], $row['table'] ?? '']) >= 0) {
                    $errors[] = "Coverage rows are unique and sorted by name and table: {$at}";

                    break;
                }
            }

            foreach ($m['coverage'] as $row) {
                if ($row['table'] !== null && (! $own($row['table']) || $tables[$row['table']]['name'] !== $row['name'])) {
                    $errors[] = "A coverage row's table is a table of the track's scope with its name: {$at} ({$row['name']})";
                }

                if (($row['autogenerate'] === 'unknown' || $row['migrationManaged'] === 'unknown') && $row['certainty'] !== 'likely') {
                    $errors[] = "A coverage row with an unknown answer is likely, not certain: {$at} ({$row['name']})";
                }

                if (! Ids::isSorted(array_values(array_map(fn (array $e): string => $e['kind']."\0".$e['detail'], $row['evidence'])))) {
                    $errors[] = "Coverage evidence is unique and sorted by kind and detail: {$at} ({$row['name']})";
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, array<string, mixed>>  $fks
     * @param  list<string>  $errors
     */
    private static function inferenceRules(array $doc, array $tables, array $fks, array &$errors): void
    {
        if (! self::sortedById($doc['inferredAssociations'])) {
            $errors[] = 'Inferred associations are sorted by id';
        }

        $linkById = array_column($doc['links'], null, 'id');
        $tableOf = [];

        foreach ($tables as $table) {
            foreach ($table['foreignKeys'] as $fk) {
                $tableOf[$fk['id']] ??= $table['id'];
            }
        }

        $linkOf = fn (array $fk): ?array => $linkById[Ids::link($tableOf[$fk['id']], $fk['foreignTable'], Graph::pairs($fk['columns'], $fk['foreignColumns']))] ?? null;
        $models = array_column($doc['models'], null, 'id');
        $uniqueConstraints = [];
        $uniqueIndexes = [];

        foreach ($tables as $table) {
            foreach ($table['constraints'] as $c) {
                if ($c['kind'] === 'unique') {
                    $uniqueConstraints[$c['id']] = true;
                }
            }

            foreach ($table['indexes'] as $i) {
                if ($i['unique'] === true) {
                    $uniqueIndexes[$i['id']] = true;
                }
            }
        }

        foreach ($doc['inferredAssociations'] as $a) {
            $at = "inferred association {$a['id']}";
            $expected = Ids::inferredAssociation($a['kind'], $a['foreignKeys'], $a['via']);

            if ($a['id'] !== $expected) {
                $errors[] = "Inferred association id is not {$expected}: {$at}";
            }

            $association = $a['kind'] === 'association_table';

            if ($association !== ($a['via'] !== null) || $association !== ($a['cardinality'] === 'many-to-many') || count($a['foreignKeys']) !== ($association ? 2 : 1)) {
                $errors[] = "An association-table inference has via, two foreign keys and many-to-many; a foreign-key inference has none of them and one foreign key: {$at}";
            }

            if (! self::subset([$a['table'], $a['relatedTable'], ...($a['via'] === null ? [] : [$a['via']])], $tables)) {
                $errors[] = "Unknown inferred association table: {$at}";
            }

            if (! in_array($a['table'], $models[$a['model']]['tables'] ?? [], true) || ! in_array($a['relatedTable'], $models[$a['relatedModel']]['tables'] ?? [], true)) {
                $errors[] = "An inferred association's models map its tables: {$at}";
            }

            if (! Ids::isSorted($a['foreignKeys']) || ! Ids::isSorted($a['links']) || ! Ids::isSorted($a['ambiguities'])) {
                $errors[] = "Inferred association foreign keys, links and ambiguities are unique and sorted: {$at}";
            }

            $fkLinks = [];

            foreach ($a['foreignKeys'] as $id) {
                $fk = $fks[$id] ?? null;
                $link = $fk !== null ? $linkOf($fk) : null;

                if ($fk === null || $link === null) {
                    $errors[] = "An inferred association's foreign key exists and has a link: {$at}";
                } elseif ($link['relationships'] !== []) {
                    $errors[] = "A declared relationship covers the inferred association's foreign key {$id}: {$at}";
                } else {
                    $fkLinks[] = $link['id'];
                }
            }

            if ($a['links'] !== Ids::sorted($fkLinks) && count($fkLinks) === count($a['foreignKeys'])) {
                $errors[] = "An inferred association lists the links of its foreign keys: {$at}";
            }

            $resolves = fn (array $e): bool => match ($e['kind']) {
                'foreign_key' => in_array($e['id'], $a['foreignKeys'], true),
                'primary_key' => isset($tables[$e['id']]),
                'unique_constraint' => isset($uniqueConstraints[$e['id']]),
                'unique_index' => isset($uniqueIndexes[$e['id']]),
                default => isset($models[$e['id']]),
            };
            $evidenceFks = array_column(array_filter($a['evidence'], fn (array $e): bool => $e['kind'] === 'foreign_key'), 'id');

            if (array_filter($a['evidence'], fn (array $e): bool => ! $resolves($e)) !== [] || array_diff($a['foreignKeys'], $evidenceFks) !== []) {
                $errors[] = "Inferred association evidence names existing entities, including each of its foreign keys: {$at}";
            }

            if (! Ids::isSorted(array_values(array_map(fn (array $e): string => $e['kind']."\0".$e['id'], $a['evidence'])))) {
                $errors[] = "Inferred association evidence is unique and sorted by kind and id: {$at}";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<string>  $errors
     */
    private static function tableRules(array $table, array &$errors): void
    {
        $names = array_map('strval', array_column($table['columns'], 'name'));
        $columns = array_fill_keys($names, true);

        foreach (self::duplicates($names) as $column) {
            $errors[] = "Duplicate column {$column}: {$table['id']}";
        }

        if (! self::subset($table['primaryKey'], $columns)) {
            $errors[] = "Unknown primary-key column: {$table['id']}";
        }

        $constraints = array_column($table['constraints'], null, 'id');

        foreach ($table['indexes'] as $index) {
            $at = "index {$index['id']}";

            if (array_filter($index['parts'], fn (array $p): bool => ($p['column'] === null) === ($p['expression'] === null)) !== []) {
                $errors[] = "An index part is exactly one of a column or an expression: {$at}";
            }

            $partColumns = array_values(array_filter(array_column($index['parts'], 'column'), fn (mixed $c): bool => $c !== null));

            if (! self::subset([...$partColumns, ...$index['included']], $columns)) {
                $errors[] = "Unknown index column: {$at}";
            }

            if (array_filter($index['parts'], fn (array $p): bool => $p['expression'] !== null) !== [] && $index['expression'] !== true) {
                $errors[] = "An index with expression parts has expression true: {$at}";
            }

            if ($index['partial'] !== true && $index['predicate'] !== null) {
                $errors[] = "Only a partial index has a predicate: {$at}";
            }

            $backs = $index['backs'];

            if (($backs['kind'] ?? null) === 'primary_key' && ($index['unique'] !== true || $index['expression'] === true || $partColumns !== $table['primaryKey'])) {
                $errors[] = "An index backing the primary key is unique and has exactly its columns: {$at}";
            }

            if (($backs['kind'] ?? null) === 'unique_constraint') {
                $backed = $backs['constraint'] !== null ? ($constraints[$backs['constraint']] ?? null) : null;

                if ($index['unique'] !== true || ($backs['constraint'] !== null && ($backed['kind'] ?? null) !== 'unique')) {
                    $errors[] = "An index backing a unique constraint is unique and names a unique constraint of its table: {$at}";
                }
            }
        }

        foreach ($table['constraints'] as $constraint) {
            if (! self::subset($constraint['columns'], $columns)) {
                $errors[] = "Unknown constraint column: constraint {$constraint['id']}";
            }

            if ($constraint['kind'] === 'unique' && $constraint['columns'] === [] && $constraint['expression'] === null) {
                $errors[] = "A unique constraint has columns or an expression: constraint {$constraint['id']}";
            }
        }
    }
}
