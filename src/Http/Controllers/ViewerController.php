<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Contract\Producer;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Snapshot;
use Laranex\LaravelSchemaViewer\SnapshotUnavailable;
use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;

/**
 * The viewer page: the shared viewer (laranex-schema-viewer-ui's standalone bundle) with the snapshot's laranex-schema
 * document and the host settings (changes since the previous build, editor links, the connection to open on) as an
 * inline JSON data block, or, without a readable snapshot, what to run to build it. The page runs no inline script, so
 * it works under its own strict Content-Security-Policy. No model code runs here.
 */
final class ViewerController
{
    /**
     * The policy the shared bundle runs under: its own scripts, styles and fonts only (React Flow positions with inline
     * styles), nothing framed, posted or embedded.
     */
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'";

    /**
     * Characters escaped in the page's JSON so it cannot end the data block or open a comment in it. They only ever
     * appear inside JSON strings, where the escapes mean the same.
     */
    private const SCRIPT_SAFE = ['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029'];

    public function __invoke(Request $request, UrlGenerator $url, SchemaViewer $viewer, Snapshot $snapshot, ViewerAssets $assets, Application $app): Response
    {
        $default = $viewer->defaultConnection();
        $settings = [
            'editor' => $viewer->editorHref(),
            'defaultConnection' => $default !== 'all' ? Producer::connectionId($default) : null,
        ];

        try {
            $document = $snapshot->json();
            $config = '{"document":'.$document.',"changes":'.Json::compact($snapshot->changes()).','.substr(Json::compact($settings), 1);
        } catch (SnapshotUnavailable $e) {
            $config = Json::compact(['missing' => $e->getMessage(), ...$settings]);
        }

        $config = strtr($config, self::SCRIPT_SAFE);
        $asset = fn (string $file): string => htmlspecialchars($url->route('schema-viewer.asset', ['version' => $assets->version(), 'file' => $file]), ENT_QUOTES);
        $nonce = $this->nonce($app);
        $nonce = $nonce !== null ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"' : '';

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width,initial-scale=1">
                <meta name="color-scheme" content="light dark">
                <meta name="robots" content="noindex, nofollow">
                <title>Schema Viewer</title>
                <link rel="icon" href="{$asset('favicon.svg')}" type="image/svg+xml">
                <link rel="icon" href="{$asset('favicon.ico')}" sizes="16x16 32x32">
                <link rel="stylesheet" href="{$asset($assets->entry('style'))}"{$nonce}>
                <script type="module" src="{$asset($assets->entry('script'))}"{$nonce}></script>
            </head>
            <body>
                <div id="schema-viewer"></div>
                <script type="application/json" id="schema-viewer-data">{$config}</script>
            </body>
            </html>
            HTML;

        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => self::POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Vary' => 'Accept-Encoding',
        ];

        // A large schema makes a large page; it compresses well.
        if (str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip') && ($compressed = gzencode($html, 6)) !== false) {
            return new Response($compressed, 200, [...$headers, 'Content-Encoding' => 'gzip']);
        }

        return new Response($html, 200, $headers);
    }

    /**
     * The CSP nonce the application gave Vite with Vite::useCspNonce(), for an application whose own policy allows
     * scripts by nonce. Vite ships with laravel/framework only, so it is looked up, never required.
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
