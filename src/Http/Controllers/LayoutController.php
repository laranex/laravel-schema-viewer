<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laranex\LaravelSchemaViewer\SchemaViewer;

/**
 * The shared layout file: table positions per connection, committed with the app.
 */
final class LayoutController
{
    public function show(SchemaViewer $viewer): JsonResponse
    {
        $path = $viewer->layoutPath();
        $layout = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return new JsonResponse(is_array($layout) ? $layout : ['version' => 1, 'connections' => (object) []], 200, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, SchemaViewer $viewer, Application $app): JsonResponse
    {
        // The file is meant to be arranged locally and committed, never written on a server.
        if (! $app->environment('local')) {
            return new JsonResponse(['message' => 'Save the layout in your local environment and commit schema-viewer.json.'], 403);
        }

        $connection = $request->input('connection');
        $positions = $request->input('positions');

        if (! is_string($connection) || $connection === '' || ! is_array($positions)) {
            return new JsonResponse(['message' => 'Send a connection name and the table positions.'], 422);
        }

        $clean = [];

        foreach ($positions as $table => $position) {
            if (is_string($table) && is_array($position) && is_numeric($position['x'] ?? null) && is_numeric($position['y'] ?? null)) {
                $clean[$table] = ['x' => round((float) $position['x']), 'y' => round((float) $position['y'])];
            }
        }

        ksort($clean);

        $path = $viewer->layoutPath();
        $layout = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $layout = is_array($layout) && is_array($layout['connections'] ?? null) ? $layout : ['version' => 1, 'connections' => []];
        $layout['connections'][$connection] = $clean;
        ksort($layout['connections']);

        if (@file_put_contents($path, json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
            return new JsonResponse(['message' => "The layout could not be written to {$path}."], 500);
        }

        return new JsonResponse(['saved' => count($clean)]);
    }
}
