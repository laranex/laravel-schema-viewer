<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RuntimeException;

/**
 * The whole viewer as one standalone HTML file: the app, its styles and fonts, the schema and every connection's
 * downloads, inlined. It opens straight from disk or from any static host, without the Laravel app or its API.
 */
final class HtmlExporter
{
    public function __construct(private readonly string $dist = __DIR__.'/../../dist') {}

    /**
     * @param  string  $defaultConnection  What the viewer opens on: 'all' or one connection's name.
     */
    public function export(SchemaDocument $document, string $defaultConnection = 'all'): string
    {
        $downloads = [];

        foreach ($document->connections as $connection) {
            $downloads[$connection->name] = [
                'mermaid' => (new MermaidExporter)->export($connection),
                'dbml' => (new DbmlExporter)->export($connection),
                'markdown' => (new MarkdownExporter)->export($connection),
            ];
        }

        $config = json_encode(
            ['schema' => $document, 'downloads' => $downloads, 'defaultConnection' => $defaultConnection],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // Nothing inlined may close its own tag early.
        $js = str_ireplace('</script', '<\/script', $this->read('app.js'));
        $css = str_ireplace('</style', '<\/style', $this->styles());

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>Schema Viewer</title>
                <style>{$css}</style>
                <script>window.__SCHEMA_VIEWER__ = {$config};</script>
                <script type="module">{$js}</script>
            </head>
            <body>
                <div id="root"></div>
            </body>
            </html>

            HTML;
    }

    /**
     * The stylesheet with its WOFF2 fonts embedded; the WOFF fallbacks are dropped, since every browser that runs the
     * viewer reads WOFF2.
     */
    private function styles(): string
    {
        $css = (string) preg_replace('/,\s*url\(\.\/assets\/[\w.-]+\.woff\)\s*format\("woff"\)/', '', $this->read('app.css'));

        return (string) preg_replace_callback(
            '/url\(\.\/assets\/([\w.-]+\.woff2)\)/',
            fn (array $match): string => 'url(data:font/woff2;base64,'.base64_encode($this->read('assets/'.$match[1])).')',
            $css,
        );
    }

    private function read(string $file): string
    {
        $contents = @file_get_contents($this->dist.'/'.$file);

        if ($contents === false) {
            throw new RuntimeException("The viewer asset [{$file}] is missing from [{$this->dist}].");
        }

        return $contents;
    }
}
