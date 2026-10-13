<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\SnapshotUnavailable;

/**
 * The schema document, read from the snapshot schema-viewer:build wrote; no model code runs. Compressed with gzip when
 * the browser takes it, and answered with 304 when the browser already has this version.
 */
final class SchemaController
{
    public function __invoke(Request $request, Snapshot $snapshot): Response|JsonResponse
    {
        try {
            $gzip = str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip');
            $etag = '"'.$snapshot->etag().($gzip ? '-gzip' : '').'"';
            $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-cache', 'ETag' => $etag, 'Vary' => 'Accept-Encoding'];

            foreach ($request->getETags() as $tag) {
                if (preg_replace('#^W/#', '', $tag) === $etag) {
                    return new Response('', 304, $headers);
                }
            }

            if ($gzip) {
                return new Response($snapshot->gzip(), 200, [...$headers, 'Content-Encoding' => 'gzip']);
            }

            return new Response($snapshot->json(), 200, $headers);
        } catch (SnapshotUnavailable $e) {
            return new JsonResponse(['message' => $e->getMessage()], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
