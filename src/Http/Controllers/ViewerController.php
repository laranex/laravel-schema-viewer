<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\SnapshotUnavailable;

/**
 * The viewer page: the built app plus the schema and every connection's downloads from the snapshot, as a static
 * export carries them, or, without a readable snapshot, what to run to build it. No model code runs here.
 */
final class ViewerController
{
    /**
     * Characters escaped in the page's JSON so it cannot end the script element or open a comment in it. They only ever
     * appear inside JSON strings, where the escapes mean the same.
     */
    private const SCRIPT_SAFE = ['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029'];

    public function __invoke(Request $request, UrlGenerator $url, SchemaViewer $viewer, Snapshot $snapshot, Application $app): Response
    {
        try {
            // The document as it was built, so empty objects stay {}.
            $schema = $snapshot->json();
            $downloads = json_encode((object) $snapshot->files(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $missing = null;
        } catch (SnapshotUnavailable $e) {
            $schema = $downloads = null;
            $missing = $e->getMessage();
        }

        $version = substr(hash('sha256', (string) @filemtime(__DIR__.'/../../../dist/app.js')), 0, 12);
        $asset = fn (string $file): string => $url->route('schema-viewer.asset', ['file' => $file]).'?v='.$version;

        $config = json_encode([
            'defaultConnection' => $viewer->defaultConnection(),
            'editor' => $viewer->editorHref(),
            'missing' => $missing,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($schema !== null) {
            $config = substr($config, 0, -1).',"schema":'.$schema.',"downloads":'.$downloads.'}';
        }

        $config = strtr($config, self::SCRIPT_SAFE);

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

        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Vary' => 'Accept-Encoding'];

        // A large schema makes a large page; it compresses well.
        if (str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip') && ($compressed = gzencode($html, 6)) !== false) {
            return new Response($compressed, 200, [...$headers, 'Content-Encoding' => 'gzip']);
        }

        return new Response($html, 200, $headers);
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
