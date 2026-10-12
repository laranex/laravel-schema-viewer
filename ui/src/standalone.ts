import type { StaticDownloads } from './layout';
import type { SchemaDocument } from './types';

const FORMATS = ['mermaid', 'dbml', 'markdown'] as const;

// The whole viewer as one HTML file: the same file `php artisan schema-viewer:export --format=html` writes (see
// HtmlExporter), built from what this page already has. Its own script and stylesheet with the fonts inlined, the
// schema it shows, and each connection's downloads, from the export URL or, in a static export, from the page itself.
export async function standaloneHtml(doc: SchemaDocument, downloads: StaticDownloads | undefined, exportUrl: string | undefined, defaultConnection = 'all'): Promise<string> {
    const [css, js, files] = await Promise.all([styles(), script(), downloads ?? fetchDownloads(doc, exportUrl)]);
    const config = JSON.stringify({ schema: doc, downloads: files, defaultConnection })
        .replace(/</g, '\\u003c')
        .replace(/>/g, '\\u003e')
        .replace(/&/g, '\\u0026')
        .replace(/\u2028/g, '\\u2028')
        .replace(/\u2029/g, '\\u2029');

    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Schema Viewer</title>
    <style>${css.replace(/<\/style/gi, '<\\/style')}</style>
    <script>window.__SCHEMA_VIEWER__ = ${config};</script>
    <script type="module">${js.replace(/<\/script/gi, '<\\/script')}</script>
</head>
<body>
    <div id="root"></div>
</body>
</html>
`;
}

async function fetchDownloads(doc: SchemaDocument, exportUrl: string | undefined): Promise<StaticDownloads> {
    if (!exportUrl) {
        throw new Error('This page has no export URL to read the downloads from.');
    }
    const files: StaticDownloads = {};
    await Promise.all(
        doc.connections.flatMap((c) => {
            files[c.name] = { mermaid: '', dbml: '', markdown: '' };
            return FORMATS.map(async (format) => {
                files[c.name][format] = await text(`${exportUrl}?${new URLSearchParams({ format, connection: c.name })}`);
            });
        }),
    );
    return files;
}

// The app's script: inline in a static export, otherwise loaded from the package's asset route.
async function script(): Promise<string> {
    const el = document.querySelector<HTMLScriptElement>('script[type="module"]');
    if (!el) {
        throw new Error('The viewer script was not found on this page.');
    }
    return el.src ? text(el.src) : (el.textContent ?? '');
}

// The stylesheet with its WOFF2 fonts embedded and the WOFF fallbacks dropped, as the exporter does.
async function styles(): Promise<string> {
    const link = document.querySelector<HTMLLinkElement>('link[rel="stylesheet"]');
    if (!link) {
        return document.querySelector('head > style')?.textContent ?? '';
    }
    const css = (await text(link.href)).replace(/,\s*url\(\.\/assets\/[\w.-]+\.woff\)\s*format\("woff"\)/g, '');
    const fonts = [...new Set(css.match(/url\(\.\/assets\/[\w.-]+\.woff2\)/g) ?? [])];
    const inlined = await Promise.all(fonts.map(async (ref) => [ref, `url(${await dataUrl(new URL(ref.slice(4, -1), link.href).href)})`] as const));
    return inlined.reduce((out, [ref, data]) => out.split(ref).join(data), css);
}

async function text(url: string): Promise<string> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`Could not read ${url} (HTTP ${response.status}).`);
    }
    return response.text();
}

async function dataUrl(url: string): Promise<string> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`Could not read ${url} (HTTP ${response.status}).`);
    }
    const blob = new Blob([await response.arrayBuffer()], { type: 'font/woff2' });
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error ?? new Error('Could not read a font.'));
        reader.readAsDataURL(blob);
    });
}
