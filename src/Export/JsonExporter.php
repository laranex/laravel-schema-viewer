<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Contract\Graph;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Validator;
use RuntimeException;

/**
 * The document itself, or a laranex-schema document of some of its connections, a port of laranex-schema-viewer-ui's
 * export/json.ts: a scoped document keeps everything that lies wholly inside the chosen connections and nothing that
 * reaches outside them, rebuilds the links with the shared graph rules, and is validated before it is returned.
 */
final class JsonExporter implements Exporter
{
    public function extension(): string
    {
        return 'json';
    }

    public function export(array $document, ?array $connections = null, bool $orm = false): array
    {
        $document = Exporters::normalized($document);
        $everything = $connections === null || array_diff(array_column($document['connections'], 'id'), $connections) === [];

        if ($everything) {
            ExportContext::select($document, $connections);

            return ['content' => Json::pretty($document), 'warnings' => []];
        }

        ['document' => $scoped, 'warnings' => $warnings] = self::scope($document, $connections);

        return ['content' => Json::pretty($scoped), 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $connectionIds
     * @return array{document: array<string, mixed>, warnings: list<array{code: string, subject: array{type: string, id: string|null, label: string|null}, message: string}>}
     */
    public static function scope(array $doc, array $connectionIds): array
    {
        $ctx = new ExportContext($doc, $connectionIds);
        $connections = $ctx->connections;
        $kept = array_fill_keys(array_column($connections, 'id'), true);
        $tables = [];
        $indexes = $foreignKeys = $constraints = [];

        foreach ($connections as $c) {
            foreach ($c['tables'] as $t) {
                $tables[$t['id']] = true;

                foreach ($t['indexes'] as $i) {
                    $indexes[$i['id']] = true;
                }

                foreach ($t['foreignKeys'] as $f) {
                    $foreignKeys[$f['id']] = true;
                }

                foreach ($t['constraints'] as $k) {
                    $constraints[$k['id']] = true;
                }
            }
        }

        $connectionName = fn (string $tableId): string => $ctx->connectionOf[$tableId]['name'] ?? 'an unknown connection';
        $models = [];

        foreach ($doc['models'] as $m) {
            $own = array_values(array_filter($m['tables'], fn (string $t): bool => isset($tables[$t])));

            if ($own === []) {
                continue;
            }

            if (count($own) !== count($m['tables'])) {
                $ctx->warn('lossy_conversion', 'model', $m['id'], $m['className'], "Model {$m['className']} also maps tables on other connections; only its tables here are kept.");
                $m['tables'] = $own;
            }

            $models[] = $m;
        }

        $modelIds = array_fill_keys(array_column($models, 'id'), true);
        $has = fn (?string $t): bool => $t === null || isset($tables[$t]);
        $inside = [];

        foreach ($doc['relationships'] as $r) {
            if (! isset($tables[$r['table']])) {
                continue;
            }

            $within = isset($modelIds[$r['model']]) && $has($r['relatedTable']) && ($r['relatedModel'] === null || isset($modelIds[$r['relatedModel']]))
                && ($r['pivot'] === null || isset($tables[$r['pivot']['table']]))
                && array_filter($r['through'] ?? [], fn (string $t): bool => ! isset($tables[$t])) === []
                && ($r['keys'] === null || (isset($tables[$r['keys']['from']['table']]) && isset($tables[$r['keys']['to']['table']])));

            if (! $within) {
                $outside = null;

                foreach ([$r['relatedTable'], $r['pivot']['table'] ?? null, ...($r['through'] ?? []), $r['keys']['from']['table'] ?? null, $r['keys']['to']['table'] ?? null] as $t) {
                    if ($t !== null && ! isset($tables[$t])) {
                        $outside = $t;

                        break;
                    }
                }

                $ctx->warn('skipped_entity', 'relationship', $r['id'], $r['name'], "Relationship {$r['id']} reaches ".($outside !== null ? 'connection '.$connectionName($outside) : 'outside these connections').'; a document of these connections cannot hold it, so it is left out.');

                continue;
            }

            $inside[] = $r;
        }

        $relationshipIds = array_fill_keys(array_column($inside, 'id'), true);
        $relationships = array_map(function (array $r) use ($relationshipIds, $ctx): array {
            if ($r['inverse'] !== null && ! isset($relationshipIds[$r['inverse']])) {
                $ctx->warn('lossy_conversion', 'relationship', $r['id'], $r['name'], "The inverse of relationship {$r['id']}, {$r['inverse']}, is left out, so its inverse is written as null.");
                $r['inverse'] = null;
            }

            return $r;
        }, $inside);

        $graph = Graph::build(['connections' => $connections, 'relationships' => $relationships]);
        $linkIds = array_fill_keys([...array_column($graph['links'], 'id'), ...array_column($graph['derivedLinks'], 'id')], true);
        $comparisons = array_values(array_filter($doc['comparisons'], fn (array $c): bool => isset($kept[$c['baseline']['connection']], $kept[$c['target']['connection']])));
        $migrations = array_values(array_filter($doc['migrations'], fn (array $m): bool => isset($kept[$m['connection']])));
        $inferred = array_values(array_filter($doc['inferredAssociations'], fn (array $a): bool => array_filter([$a['table'], $a['relatedTable'], ...($a['via'] === null ? [] : [$a['via']])], fn (string $t): bool => ! isset($tables[$t])) === []
            && isset($modelIds[$a['model']], $modelIds[$a['relatedModel']])
            && array_filter($a['links'], fn (string $l): bool => ! isset($linkIds[$l])) === []));
        $subjects = [
            'connection' => $kept, 'table' => $tables, 'column' => $tables, 'index' => $indexes, 'foreignKey' => $foreignKeys,
            'constraint' => $constraints, 'model' => $modelIds, 'relationship' => $relationshipIds, 'link' => $linkIds,
            'comparison' => array_fill_keys(array_column($comparisons, 'id'), true),
            'migration' => array_fill_keys(array_column($migrations, 'id'), true),
            'inferredAssociation' => array_fill_keys(array_column($inferred, 'id'), true),
        ];
        $diagnostics = array_values(array_filter($doc['diagnostics'], fn (array $d): bool => $d['subject']['type'] === 'document' || ($d['subject']['id'] !== null && isset($subjects[$d['subject']['type']][$d['subject']['id']]))));

        $scoped = [
            ...$doc,
            'connections' => $connections,
            'models' => $models,
            'relationships' => $relationships,
            'links' => $graph['links'],
            'derivedLinks' => $graph['derivedLinks'],
            'diagnostics' => $diagnostics,
            'comparisons' => $comparisons,
            'migrations' => $migrations,
            'inferredAssociations' => $inferred,
        ];

        $strict = $doc['version'] === Validator::VERSION;
        $errors = Validator::validate($scoped, $strict);

        if ($errors !== [] && $strict && Validator::validate($scoped) === []) {
            $ctx->warn('lossy_conversion', 'document', null, null, 'The source document is not strictly valid laranex-schema '.Validator::VERSION.'; neither is this file, though it is valid for 2.x readers.');
            $errors = [];
        }

        if ($errors !== []) {
            throw new RuntimeException('The document of these connections is not valid: '.implode('; ', array_slice($errors, 0, 5)));
        }

        return ['document' => $scoped, 'warnings' => $ctx->warnings];
    }
}
