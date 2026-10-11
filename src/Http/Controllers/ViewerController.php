<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Response;

/**
 * The viewer page: the built app plus the URLs it talks to.
 */
final class ViewerController
{
    public function __invoke(UrlGenerator $url): Response
    {
        $version = substr(hash('sha256', (string) @filemtime(__DIR__.'/../../../dist/app.js')), 0, 12);
        $asset = fn (string $file): string => $url->route('schema-viewer.asset', ['file' => $file]).'?v='.$version;

        $config = json_encode([
            'schemaUrl' => $url->route('schema-viewer.schema'),
            'exportUrl' => $url->route('schema-viewer.export'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $css = htmlspecialchars($asset('app.css'), ENT_QUOTES);
        $js = htmlspecialchars($asset('app.js'), ENT_QUOTES);

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>Schema Viewer</title>
                <link rel="stylesheet" href="{$css}">
                <script>window.__SCHEMA_VIEWER__ = {$config};</script>
                <script type="module" src="{$js}"></script>
            </head>
            <body>
                <div id="root"></div>
            </body>
            </html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
