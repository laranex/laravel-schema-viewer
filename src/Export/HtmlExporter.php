<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Schema\SchemaDocument;
use RuntimeException;

/**
 * The whole viewer as one standalone HTML file: the app, its styles and fonts, the schema and every connection's
 * downloads, inlined. It opens straight from disk or from any static host, without the Laravel app or its API.
 *
 * The app and the schema are gzipped and base64 encoded, a tenth of their size for a large schema; a small module
 * inflates them with the browser's DecompressionStream and starts the app. The viewer's own HTML download builds the
 * same file (ui/src/standalone.ts), with the same loader.
 */
final class HtmlExporter
{
    /**
     * Inflates the config and the app, then starts the app. Kept identical to LOADER in ui/src/standalone.ts.
     */
    public const LOADER = "const unpack = async (id) => new Response(new Blob([Uint8Array.from(atob(document.getElementById(id).textContent.trim()), (c) => c.charCodeAt(0))]).stream().pipeThrough(new DecompressionStream('gzip'))).text();\n"
        ."try {\n"
        ."    window.__SCHEMA_VIEWER__ = JSON.parse(await unpack('schema-viewer-config'));\n"
        ."    await import(URL.createObjectURL(new Blob([await unpack('schema-viewer-app')], { type: 'text/javascript' })));\n"
        ."} catch (e) {\n"
        ."    document.getElementById('root').textContent = 'This page needs a current browser (Chrome, Edge, Firefox or Safari 16.4 or later): ' + e.message;\n"
        .'}';

    public function __construct(private readonly string $dist = __DIR__.'/../../dist') {}

    /**
     * @param  string  $defaultConnection  What the viewer opens on: 'all' or one connection's name.
     */
    public function export(SchemaDocument $document, string $defaultConnection = 'all'): string
    {
        $config = json_encode(
            ['schema' => $document, 'downloads' => self::downloads($document), 'defaultConnection' => $defaultConnection],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $config = base64_encode((string) gzencode($config, 9));
        $js = base64_encode((string) gzencode($this->read('app.js'), 9));
        $loader = self::LOADER;
        // Nothing inlined may close its own tag early; base64 never can.
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
                <script type="module">
            {$loader}
                </script>
            </head>
            <body>
                <div id="root"></div>
                <script type="application/octet-stream" id="schema-viewer-config">{$config}</script>
                <script type="application/octet-stream" id="schema-viewer-app">{$js}</script>
            </body>
            </html>

            HTML;
    }

    /**
     * Every connection's Mermaid, DBML and Markdown files, by connection name.
     *
     * @return array<string, array{mermaid: string, dbml: string, markdown: string}>
     */
    public static function downloads(SchemaDocument $document): array
    {
        $downloads = [];

        foreach ($document->connections as $connection) {
            $downloads[$connection->name] = [
                'mermaid' => (new MermaidExporter)->export($connection),
                'dbml' => (new DbmlExporter)->export($connection),
                'markdown' => (new MarkdownExporter)->export($connection),
            ];
        }

        return $downloads;
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
