<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\Export\DbmlExporter;
use Laranex\LaravelSchemaViewer\Export\MarkdownExporter;
use Laranex\LaravelSchemaViewer\Export\MermaidExporter;
use Laranex\LaravelSchemaViewer\SchemaViewer;

/**
 * One connection's database schema as a file to download: Mermaid, DBML or Markdown.
 */
final class ExportController
{
    private const FORMATS = [
        'mermaid' => ['mmd', 'text/plain'],
        'dbml' => ['dbml', 'text/plain'],
        'markdown' => ['md', 'text/markdown'],
    ];

    public function __invoke(Request $request, SchemaViewer $viewer, ConnectionResolverInterface $connections): Response|JsonResponse
    {
        $format = $request->query('format', 'mermaid');
        $format = is_string($format) ? $format : '';

        if (! isset(self::FORMATS[$format])) {
            return new JsonResponse(['message' => 'Use format mermaid, dbml or markdown.'], 422);
        }

        $document = $viewer->document();
        $requested = $request->query('connection');
        $name = is_string($requested) && $requested !== '' ? $requested : $connections->getDefaultConnection();
        $connection = $document->connection($name);

        if ($connection === null) {
            return new JsonResponse(['message' => "The schema has no connection [{$name}]."], 404);
        }

        $content = match ($format) {
            'mermaid' => (new MermaidExporter)->export($connection),
            'dbml' => (new DbmlExporter)->export($connection),
            default => (new MarkdownExporter)->export($connection),
        };

        [$extension, $type] = self::FORMATS[$format];
        $file = 'schema-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $name).'.'.$extension;

        return new Response($content, 200, [
            'Content-Type' => $type.'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
