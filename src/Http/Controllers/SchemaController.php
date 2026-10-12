<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\SchemaCache;

/**
 * The schema document, from the cache while nothing it reads has changed; ?refresh=1 reads it again.
 */
final class SchemaController
{
    public function __invoke(Request $request, SchemaCache $cache): Response
    {
        return new Response($cache->json($request->boolean('refresh')), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
