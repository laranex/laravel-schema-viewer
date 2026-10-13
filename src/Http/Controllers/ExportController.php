<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\SnapshotUnavailable;

/**
 * One connection's database schema as a file to download: Mermaid, DBML, Markdown or JSON. Format all answers every
 * connection's Mermaid, DBML and Markdown at once, as JSON, for the viewer's standalone HTML download. Everything comes
 * from the snapshot schema-viewer:build wrote; no model code runs.
 */
final class ExportController
{
    private const FORMATS = [
        'mermaid' => ['mmd', 'text/plain'],
        'dbml' => ['dbml', 'text/plain'],
        'markdown' => ['md', 'text/markdown'],
        'json' => ['json', 'application/json'],
    ];

    public function __invoke(Request $request, Snapshot $snapshot, ConnectionResolverInterface $connections): Response|JsonResponse
    {
        try {
            $files = $snapshot->files();
        } catch (SnapshotUnavailable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 503, ['Cache-Control' => 'no-store']);
        }

        $format = $request->query('format', 'mermaid');
        $requested = $request->query('connection', '');

        if ($format === 'all') {
            $all = array_map(fn (array $downloads): array => ['mermaid' => $downloads['mermaid'], 'dbml' => $downloads['dbml'], 'markdown' => $downloads['markdown']], $files);

            return new JsonResponse($all, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
        }

        if (! is_string($format) || ! isset(self::FORMATS[$format])) {
            return new JsonResponse(['message' => 'Use format mermaid, dbml, markdown or json.'], 422);
        }

        if (! is_string($requested)) {
            return new JsonResponse(['message' => 'Name one connection.'], 422);
        }

        $name = $requested !== '' ? $requested : $connections->getDefaultConnection();
        $content = $files[$name][$format] ?? null;

        if ($content === null) {
            return new JsonResponse(['message' => "The schema has no connection [{$name}]."], 404);
        }

        [$extension, $type] = self::FORMATS[$format];
        $file = 'schema-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $name).'.'.$extension;

        return new Response($content, 200, [
            'Content-Type' => $type.'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
