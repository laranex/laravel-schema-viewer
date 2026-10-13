<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\SnapshotUnavailable;

/**
 * The viewer page: the built app plus the URLs it talks to, or, without a readable snapshot, what to run to build it.
 */
final class ViewerController
{
    public function __invoke(UrlGenerator $url, SchemaViewer $viewer, Snapshot $snapshot, Application $app): Response
    {
        try {
            $snapshot->etag();
            $missing = null;
        } catch (SnapshotUnavailable $e) {
            $missing = $e->getMessage();
        }

        $version = substr(hash('sha256', (string) @filemtime(__DIR__.'/../../../dist/app.js')), 0, 12);
        $asset = fn (string $file): string => $url->route('schema-viewer.asset', ['file' => $file]).'?v='.$version;

        $config = json_encode([
            'schemaUrl' => $url->route('schema-viewer.schema'),
            'exportUrl' => $url->route('schema-viewer.export'),
            'defaultConnection' => $viewer->defaultConnection(),
            'editor' => $viewer->editorHref(),
            'missing' => $missing,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $css = htmlspecialchars($asset('app.css'), ENT_QUOTES);
        $js = htmlspecialchars($asset('app.js'), ENT_QUOTES);
        $nonce = $this->nonce($app);
        $nonce = $nonce !== null ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"' : '';

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>Schema Viewer</title>
                <link rel="stylesheet" href="{$css}"{$nonce}>
                <script{$nonce}>window.__SCHEMA_VIEWER__ = {$config};</script>
                <script type="module" src="{$js}"{$nonce}></script>
            </head>
            <body>
                <div id="root"></div>
            </body>
            </html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * The CSP nonce the application gave Vite with Vite::useCspNonce(), so a strict Content-Security-Policy lets the
     * page's scripts run. Vite ships with laravel/framework only, so it is looked up, never required.
     */
    private function nonce(Application $app): ?string
    {
        $vite = 'Illuminate\\Foundation\\Vite';

        if (! $app->bound($vite)) {
            return null;
        }

        /** @var mixed $instance */
        $instance = $app->make($vite);
        $nonce = is_object($instance) && method_exists($instance, 'cspNonce') ? $instance->cspNonce() : null;

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
