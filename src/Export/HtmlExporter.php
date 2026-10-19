<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Contract\Json;
use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;

/**
 * The whole viewer as one self-contained HTML file, the same page as the shared viewer's Download → "This page as HTML"
 * (a port of laranex-schema-viewer-ui's viewer/offline.ts): the single-file viewer script (offline.js, with the layout
 * engine inlined), its stylesheet with the WOFF2 fonts embedded, the brand mark as its favicon, and the document as
 * data. It opens from file:// with no network access and carries its own Content-Security-Policy: the one script is
 * allowed by its SHA-256 hash, everything else comes from data: URLs. No editor links (they would carry local paths).
 */
final class HtmlExporter
{
    public function __construct(private readonly ViewerAssets $assets = new ViewerAssets) {}

    /**
     * @param  array<string, mixed>  $document  A laranex-schema 2.x document.
     * @param  list<array<string, mixed>>|null  $changes  What changed since the previous build.
     * @param  string|null  $defaultConnection  The connection id to open on; null for all of them.
     */
    public function export(array $document, ?array $changes = null, ?string $defaultConnection = null, string $title = 'Schema Viewer'): string
    {
        // Nothing inside may end its element early: "</script", "<!--" and "<script" become "\x3C…" (the same string in
        // JavaScript), "</style" becomes "<\/style" (the same in CSS), and "<" in the JSON becomes "<".
        $script = (string) preg_replace('/<(\/?script|!--)/i', '\\x3C$1', $this->assets->contents($this->assets->entry('offline')));
        $style = (string) preg_replace('/<\/style/i', '<\\/style', $this->style());
        $data = str_replace('<', '\\u003c', Json::compact([
            'document' => Exporters::normalized($document),
            'changes' => $changes,
            'defaultConnection' => $defaultConnection,
        ]));
        $policy = implode('; ', [
            "default-src 'none'",
            "script-src 'sha256-".base64_encode(hash('sha256', $script, true))."'",
            "style-src 'unsafe-inline'",
            'img-src data: blob:',
            'font-src data:',
            "connect-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
        ]);
        $attribute = fn (string $value): string => str_replace(['&', '"', '<'], ['&amp;', '&quot;', '&lt;'], $value);
        $favicon = 'data:image/svg+xml,'.self::encodeUriComponent(trim($this->assets->contents('favicon.svg')));

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="color-scheme" content="light dark">
            <meta http-equiv="Content-Security-Policy" content="{$policy}">
            <meta name="robots" content="noindex, nofollow">
            <title>{$attribute($title)}</title>
            <link rel="icon" href="{$attribute($favicon)}" type="image/svg+xml">
            <style id="lsv-offline-style">{$style}</style>
            </head>
            <body>
            <div id="schema-viewer"></div>
            <script type="application/json" id="schema-viewer-data">{$data}</script>
            <script type="module" id="lsv-offline-app">{$script}</script>
            </body>
            </html>

            HTML;
    }

    /**
     * The viewer's stylesheet with its WOFF2 fonts as data URLs; the WOFF fallbacks are dropped, since every browser
     * that runs the viewer reads WOFF2.
     */
    private function style(): string
    {
        $css = (string) preg_replace('/,\s*url\(["\']?[^"\')]+\.woff["\']?\)\s*format\(["\']woff["\']\)/', '', $this->assets->contents($this->assets->entry('style')));

        return (string) preg_replace_callback(
            '/url\(["\']?([^"\')]+\.woff2)["\']?\)/',
            fn (array $match): string => 'url(data:font/woff2;base64,'.base64_encode($this->assets->contents((string) preg_replace('#^\./#', '', $match[1]))).')',
            $css,
        );
    }

    /**
     * JavaScript's encodeURIComponent: everything but A-Z a-z 0-9 - _ . ! ~ * ' ( ) percent-encoded as UTF-8.
     */
    private static function encodeUriComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
