<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Laranex\LaravelSchemaViewer\SchemaViewer;

/**
 * The schema document, read fresh on every request.
 */
final class SchemaController
{
    public function __invoke(SchemaViewer $viewer): JsonResponse
    {
        return new JsonResponse($viewer->document(), 200, ['Cache-Control' => 'no-store']);
    }
}
